<?php
declare(strict_types=1);

const WORKSPACE_CLIENT_ACCOUNT_MANAGER_LIMIT=150;

function workspace_client_account_managers_enabled(): bool {
    static $enabled;
    return $enabled ??= (bool)wquery("SELECT 1 FROM workspace_meta WHERE `key`='client_account_managers_v1'")->fetchColumn();
}

function workspace_client_manager_card_available(): bool {
    if (workspace_client_account_managers_enabled()) return true;
    $catalog=workspace_manager_catalog_id();
    return $catalog>0 && workspace_can('catalog',(string)$catalog,'view');
}

function workspace_client_account_manager_eligible(array $account): bool {
    return $account['active'] && (bool)array_intersect(
        ['manager','directory_senior_manager'],array_column($account['roles'],'code')
    ) && !workspace_employee_is_private($account);
}

function workspace_client_guard_manager_cell(array $body,bool $pasteCheck): void {
    $accounts=workspace_client_account_managers_enabled();
    $legacyCatalog=$accounts?0:workspace_manager_catalog_id();
    if (!$accounts && !$legacyCatalog) return;
    if ($pasteCheck || ($body['editAction']??null)!=='clear' || $body['value']!=='') {
        throw new ApiError('manager_editor','Откройте список менеджеров из ячейки.');
    }
    if ($legacyCatalog) workspace_require('catalog',(string)$legacyCatalog,'view');
}

function workspace_client_account_manager_links(int $catalogId,?int $clientId=null,?array $accounts=null): array {
    $accounts ??= array_column(workspace_employee_records(),null,'id');
    $filter=$clientId===null ? '' : ' AND l.client_id=?';
    $args=$clientId===null ? [$catalogId] : [$catalogId,$clientId];
    $rows=wquery('SELECT l.client_id,l.account_id,l.legacy_manager_id,l.position,l.fallback_label
        FROM client_account_manager_links l JOIN catalog_entries c ON c.id=l.client_id
        WHERE c.catalog_id=?'.$filter.' ORDER BY l.client_id,l.position',$args)->fetchAll();
    foreach($rows as &$row) {
        $account=$accounts[(int)$row['account_id']]??null;
        $visible=$account && !workspace_employee_is_private($account);
        $row['manager_id']=$row['account_id']===null ? -(int)$row['legacy_manager_id'] : (int)$row['account_id'];
        $row['name']=$visible ? $account['name'] : $row['fallback_label'];
    }
    unset($row);
    return $rows;
}

function workspace_client_account_manager_choices(array $accounts,array $links): array {
    $choices=[];
    foreach ($accounts as $account) {
        if (workspace_client_account_manager_eligible($account)) {
            $id=(int)$account['id'];
            $choices[$id]=['id'=>$id,'name'=>$account['name'],'label'=>$account['name'],'active'=>true];
        }
    }
    foreach ($links as $link) {
        $id=$link['manager_id'];
        $choices[$id] ??= ['id'=>$id,'name'=>$link['name'],'label'=>$link['name'],'active'=>false,'legacy'=>$id<0];
    }
    return $choices;
}

function workspace_client_manager_match_names(string $manager,array $choices,array $links): array {
    $byName=[];
    foreach ($choices as $choice) $byName[vitrine_catalog_key($choice['name'])][$choice['id']]=true;
    foreach ($links as $link) $byName[vitrine_catalog_key($link['fallback_label'])][$link['manager_id']]=true;
    $exact=$byName[vitrine_catalog_key($manager)]??[];
    if (count($exact)===1) return [array_key_first($exact)];
    $ids=[];
    foreach (preg_split('~[/;,\n]+~u',$manager,-1,PREG_SPLIT_NO_EMPTY) as $name) {
        $key=vitrine_catalog_key($name);
        if ($key==='') continue;
        $matches=$byName[$key]??[];
        if (count($matches)!==1) throw new ApiError('invalid_manager','Выберите менеджеров из учётных записей.');
        $ids[]=array_key_first($matches);
    }
    $ids=array_values(array_unique($ids));
    if (count($ids)>WORKSPACE_CLIENT_ACCOUNT_MANAGER_LIMIT) {
        throw new ApiError('invalid_manager','Слишком много назначенных менеджеров.');
    }
    return $ids;
}

function workspace_client_account_manager_replace(int $clientId,array $ids,array $choices,array $links): void {
    $fallbacks=array_column($links,'fallback_label','manager_id');
    wquery('DELETE FROM client_account_manager_links WHERE client_id=?',[$clientId]);
    foreach ($ids as $position=>$id) {
        wquery('INSERT INTO client_account_manager_links(client_id,position,account_id,legacy_manager_id,fallback_label)
            VALUES(?,?,?,?,?)',[$clientId,$position+1,$id>0?$id:null,$id<0?-$id:null,$fallbacks[$id]??$choices[$id]['name']]);
    }
}

function workspace_client_account_manager_card(int $catalogId,int $clientId,?array $body): array {
    $read=static function () use ($catalogId,$clientId,$body): array {
        $client=wquery('SELECT name,active,revision FROM catalog_entries WHERE catalog_id=? AND id=?'.
            ($body===null?'':' FOR UPDATE'),[$catalogId,$clientId])->fetch();
        if (!$client || workspace_row_deleted('catalog_deleted_client_',$clientId)) {
            throw new ApiError('not_found','Клиент не найден.',404);
        }
        $canEdit=$client['active'] && workspace_can('catalog',(string)$catalogId,'edit');
        $accounts=array_column(workspace_employee_records(),null,'id');
        $links=workspace_client_account_manager_links($catalogId,$clientId,$accounts);
        $existing=array_column($links,'manager_id');
        $choices=workspace_client_account_manager_choices($accounts,$links);
        if ($body!==null) {
            if (!$canEdit) throw new ApiError('forbidden','Менеджеры этого клиента доступны только для просмотра.',403);
            if (($body['managerSource']??null)!=='accounts' || ($body['revision']??null)!==(int)$client['revision']) {
                workspace_directory_conflict();
            }
            $ids=$body['managerIds']??null;
            if (!is_array($ids) || !array_is_list($ids) || count($ids)>WORKSPACE_CLIENT_ACCOUNT_MANAGER_LIMIT ||
                array_filter($ids,static fn($id): bool=>!is_int($id)||!isset($choices[$id])) ||
                count($ids)!==count(array_unique($ids))) {
                throw new ApiError('invalid_manager','Выберите менеджеров из учётных записей.');
            }
            if ($ids!==$existing) {
                workspace_client_account_manager_replace($clientId,$ids,$choices,$links);
                wquery('UPDATE catalog_entries SET revision=revision+1 WHERE id=?',[$clientId]);
                $client['revision']++;
                workspace_audit(['category'=>'catalog','label'=>'Менеджеры клиента','before'=>['id'=>$clientId,'managerIds'=>$existing,'source'=>'accounts'],
                    'after'=>['id'=>$clientId,'managerIds'=>$ids,'source'=>'accounts'],'action'=>'edit']);
                $existing=$ids;
            }
        }
        $assigned=array_intersect_key($choices,array_fill_keys($existing,true));
        $options=$canEdit ? array_filter($choices,static fn(array $choice): bool=>$choice['active'])+$assigned : $assigned;
        if (workspace_can('system','users','view')) {
            foreach ($existing as $id) {
                $account=$accounts[$id]??null;
                if ($account && !workspace_employee_is_private($account)) $options[$id]['email']=$account['email'];
            }
        }
        $names=array_map(static fn(int $id): string=>$choices[$id]['name'],$existing);
        return ['client'=>$client['name'],'canEdit'=>$canEdit,'canCreate'=>false,'canEditProfiles'=>false,
            'managerSource'=>'accounts','revision'=>(int)$client['revision'],'options'=>array_values($options),
            'managerIds'=>$existing,'summary'=>implode(', ',$names)];
    };
    if ($body===null) return $read();
    workspace_require('catalog',(string)$catalogId,'edit');
    return workspace_transaction($read);
}
