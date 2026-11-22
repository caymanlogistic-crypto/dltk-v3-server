<?php
declare(strict_types=1);

function workspace_client_manager_values(int $catalogId): array {
    if (workspace_manager_catalog_id()) {
        $names=[];
        foreach (workspace_client_manager_links($catalogId) as $row) $names[$row['client_id']][]=$row['name'];
        return array_map(static fn(array $value): string=>implode(', ',$value),$names);
    }
    return workspace_client_manager_legacy_values($catalogId);
}

function workspace_client_manager_legacy_values(int $catalogId): array {
    $source = workspace_client_catalog_metadata('catalog_managers_'.$catalogId);
    $local = workspace_client_catalog_metadata('catalog_manager_overrides_'.$catalogId);
    return array_replace($source, $local);
}

function workspace_client_catalog_aliases(int $catalogId): array {
    return workspace_client_catalog_metadata('catalog_client_aliases_'.$catalogId);
}

function workspace_client_remember_alias(int $catalogId, int $entryId, string $normalized): void {
    $key = 'catalog_client_aliases_'.$catalogId;
    $aliases = workspace_client_catalog_metadata($key);
    $aliases[$normalized] = $entryId;
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, wjson((object)$aliases)]);
}

function workspace_client_set_manager(int $catalogId, int $entryId, string $manager): void {
    if ($managerCatalog=workspace_manager_catalog_id()) {
        $byName=[];
        foreach (workspace_manager_entries($managerCatalog) as $entry) {
            if ($entry['active']) $byName[vitrine_catalog_key($entry['name'])][]=(int)$entry['id'];
        }
        $ids=[];
        foreach (preg_split('~[/;,\n]+~u',$manager,-1,PREG_SPLIT_NO_EMPTY) as $name) {
            $key=vitrine_catalog_key($name);
            if ($key==='') continue;
            $matches=$byName[$key]??[];
            if (count($matches)!==1) throw new ApiError('invalid_manager','Выберите менеджеров из справочника.');
            $ids[]=$matches[0];
        }
        workspace_client_replace_manager_links($entryId,array_values(array_unique($ids)));
        return;
    }
    $key = 'catalog_manager_overrides_'.$catalogId;
    $values = workspace_client_catalog_metadata($key);
    $values[$entryId] = $manager;
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, wjson((object)$values)]);
}

function workspace_client_manager_links(int $catalogId,?int $entryId=null): array {
    $filter=$entryId===null ? '' : ' AND client.id=?';
    $args=$entryId===null ? [$catalogId] : [$catalogId,$entryId];
    return wquery('SELECT l.client_id,l.manager_id,m.name FROM client_manager_links l
        JOIN catalog_entries client ON client.id=l.client_id JOIN catalog_entries m ON m.id=l.manager_id
        WHERE client.catalog_id=?'.$filter.' ORDER BY l.client_id,l.position',$args)->fetchAll();
}

function workspace_client_replace_manager_links(int $clientId,array $ids): void {
    wquery('DELETE FROM client_manager_links WHERE client_id=?',[$clientId]);
    foreach ($ids as $position=>$managerId) {
        wquery('INSERT INTO client_manager_links(client_id,manager_id,position) VALUES(?,?,?)',[$clientId,$managerId,$position+1]);
    }
}

function workspace_client_manager_card(int $clientId,?array $body=null): array {
    $catalogId=(int)(workspace_client_grid_catalog()['id']??0);
    $managerCatalog=workspace_manager_catalog_id();
    if (!$catalogId || !$managerCatalog) throw new ApiError('not_found','Справочник «Сотрудники» не найден.',404);
    workspace_require('catalog',(string)$managerCatalog,'view');
    if ($body!==null) {
        workspace_require('catalog',(string)$catalogId,'edit');
        workspace_begin();
    }
    try {
        $client=wquery('SELECT name,active,revision FROM catalog_entries WHERE catalog_id=? AND id=?'.
            ($body!==null?' FOR UPDATE':''),[$catalogId,$clientId])->fetch();
        if (!$client) throw new ApiError('not_found','Клиент не найден.',404);
        $canEdit=$client['active'] && workspace_can('catalog',(string)$catalogId,'edit');
        $existing=array_map('intval',array_column(workspace_client_manager_links($catalogId,$clientId),'manager_id'));
        $options=[];
        foreach (workspace_manager_entries($managerCatalog) as $entry) {
            $id=(int)$entry['id'];
            if (!$entry['active'] && !in_array($id,$existing,true)) continue;
            $surname=$entry['surname']==='Неизвестно' ? '' : $entry['surname'];
            $patronymic=$entry['patronymic']==='Неизвестно' ? '' : $entry['patronymic'];
            $label=implode(' ',array_filter([$surname,$entry['name'],$patronymic],'strlen'));
            $options[$id]=['id'=>$id,'name'=>$entry['name'],'label'=>$label,'active'=>(bool)$entry['active']];
        }
        if ($body!==null) {
            if (!$canEdit) throw new ApiError('forbidden','Менеджеры этого клиента доступны только для просмотра.',403);
            if (($body['revision']??null)!==(int)$client['revision']) workspace_directory_conflict();
            $ids=$body['managerIds']??null;
            if (!is_array($ids) || !array_is_list($ids) || count($ids)>150 ||
                array_filter($ids,static fn($id): bool=>!is_int($id)||!isset($options[$id])) ||
                count($ids)!==count(array_unique($ids))) {
                throw new ApiError('invalid_manager','Выберите действующих менеджеров из справочника.');
            }
            if ($ids!==$existing) {
                workspace_client_replace_manager_links($clientId,$ids);
                wquery('UPDATE catalog_entries SET revision=revision+1 WHERE id=?',[$clientId]);
                $client['revision']++;
                workspace_audit(['category'=>'catalog','label'=>'Менеджеры клиента','before'=>['id'=>$clientId,'managerIds'=>$existing],
                    'after'=>['id'=>$clientId,'managerIds'=>$ids],'action'=>'edit']);
                $existing=$ids;
            }
            workspace_db()->commit();
        }
        $names=[];
        foreach ($existing as $id) if (isset($options[$id])) $names[]=$options[$id]['name'];
        return ['client'=>$client['name'],'canEdit'=>$canEdit,'revision'=>(int)$client['revision'],
            'options'=>array_values($options),'managerIds'=>$existing,'summary'=>implode(', ',$names)];
    } catch (Throwable $error) {
        if ($body!==null && workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}

function workspace_client_catalog_metadata(string $key): array {
    $raw = wquery('SELECT value FROM workspace_meta WHERE `key`=?', [$key])->fetchColumn();
    $values = json_decode((string)$raw, true);
    return is_array($values) ? $values : [];
}

function workspace_client_validate_manager(mixed $value): string {
    if (!is_string($value) || mb_strlen($value) > 160 || preg_match('/[\x00-\x1f]/u', $value)) {
        throw new ApiError('invalid_manager', 'Укажите имя менеджера длиной до 160 символов.');
    }
    return trim($value);
}
