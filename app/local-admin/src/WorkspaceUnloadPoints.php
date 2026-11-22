<?php
declare(strict_types=1);

function workspace_unload_catalog_id(): int {
    return (int)wquery("SELECT c.id FROM catalogs c JOIN catalog_bindings b ON c.id=b.catalog_id
        JOIN table_definitions t ON t.id=b.table_id
        WHERE t.slug='vyvoz' AND b.field_key='K' AND c.kind='unload-points' ORDER BY c.id LIMIT 1")->fetchColumn();
}

function workspace_unload_points(int $catalogId): array {
    $rows = wquery("SELECT e.id,e.name,e.active,e.revision,p.client_id,
        COALESCE(client.name,p.client_label) AS client,client.active AS client_active,p.address,p.schedule,p.instruction,
        contact.name AS contact_name,contact.phone,contact.email,contact.original,
        (SELECT COUNT(*) FROM unload_point_contacts c WHERE c.entry_id=e.id) AS contact_count
        FROM catalog_entries e JOIN unload_points p ON p.entry_id=e.id
        LEFT JOIN catalog_entries client ON client.id=p.client_id
        LEFT JOIN unload_point_contacts contact ON contact.entry_id=e.id AND contact.is_primary=TRUE
        WHERE e.catalog_id=? ORDER BY COALESCE(client.name,p.client_label),p.address,e.id",[$catalogId])->fetchAll();
    $review=array_fill_keys(workspace_client_catalog_metadata('unload_points_review_'.$catalogId),true);
    foreach ($rows as &$row) $row['review_required']=isset($review[$row['id']]);
    unset($row);
    return array_column($rows,null,'id');
}

function workspace_unload_options_by_client(array $points): array {
    $options=[];
    foreach ($points as $point) {
        if (!$point['active'] || $point['review_required'] || $point['client']==='') continue;
        $client=workspace_unload_client_key($point['client_id']===null?null:(int)$point['client_id'],$point['client']);
        $options[$client][]=$point['address'];
    }
    return $options;
}

function workspace_unload_row_options(array $options,?int $clientId,string $client): array {
    return $options[workspace_unload_client_key($clientId,$client)]??[];
}

function workspace_unload_primary(array $point): string {
    return (string)($point['original'] ?: implode(' · ',array_filter([$point['contact_name'],$point['phone'],$point['email']])));
}

function workspace_unload_contact_summary(string $primary,int $count): string {
    if ($count===0) return '—';
    return $count===1 ? $primary : $primary.' · +'.($count-1);
}

function workspace_unload_client_key(?int $clientId,string $name): string {
    return $clientId ? 'id:'.$clientId : 'name:'.vitrine_catalog_key($name);
}

function workspace_unload_address_key(?int $clientId,string $name,string $address): string {
    return hash('sha256',workspace_unload_client_key($clientId,$name)."\n".vitrine_catalog_key($address));
}

function workspace_unload_client_entries(): array {
    $catalog=(int)(workspace_client_grid_catalog()['id']??0);
    return $catalog ? wquery('SELECT id,name FROM catalog_entries WHERE catalog_id=? AND active=TRUE ORDER BY name',[$catalog])->fetchAll() : [];
}

function workspace_unload_point_values(array $point): array {
    return ['A'=>$point['client'],'B'=>$point['address']];
}

function workspace_unload_draft_fields(array $choices): array {
    return ['A'=>['type'=>'choice','required'=>true,'choices'=>$choices],
        'B'=>['type'=>'text','singleLine'=>false,'required'=>true,'maxLength'=>6000]];
}

function workspace_unload_text(mixed $value,int $limit,bool $multiline=false): string {
    if (!is_string($value)) throw new ApiError('invalid_unload_field','Проверьте данные точки выгрузки.');
    $text=trim($value);
    if (mb_strlen($text)>$limit || preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F]/u':'/[\x00-\x1F]/u',$text)) {
        throw new ApiError('invalid_unload_field','Проверьте длину и формат данных точки выгрузки.');
    }
    return $text;
}

function workspace_unload_validate_point(array $values,?array $old=null): array {
    $address=workspace_unload_text($values['B']??'',6000,true);
    if ($address==='') throw new ApiError('invalid_unload_address','Укажите адрес выгрузки.');
    $name=workspace_unload_text($values['A']??'',160);
    if ($old && $name===$old['client'] && $name!=='') {
        return ['client'=>$name,'client_id'=>$old['client_id'],'address'=>$address];
    }
    foreach (workspace_unload_client_entries() as $client) {
        if ($name===$client['name']) return ['client'=>$name,'client_id'=>(int)$client['id'],'address'=>$address];
    }
    throw new ApiError('invalid_unload_client','Выберите клиента из справочника.');
}

function workspace_unload_save_point(int $catalogId,array $values,?int $entryId=null,?int $revision=null): array {
    workspace_require('catalog',(string)$catalogId,'edit');
    workspace_begin();
    try {
        $old=$entryId ? (workspace_unload_points($catalogId)[$entryId]??null) : null;
        if ($entryId && (!$old || !$old['active'])) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        if ($old && (int)$old['revision']!==$revision) workspace_directory_conflict();
        $point=workspace_unload_validate_point($values,$old);
        $client=$point['client_id']===null ? null : (int)$point['client_id'];
        if ($client!==null && (!$old || $client!==(int)$old['client_id'])) {
            $active=wquery('SELECT active FROM catalog_entries WHERE id=? FOR SHARE',[$client])->fetchColumn();
            if (!$active) throw new ApiError('invalid_unload_client','Выберите действующего клиента.');
        }
        if ($old && workspace_unload_client_key($client,$point['client'])!==
            workspace_unload_client_key($old['client_id']===null?null:(int)$old['client_id'],$old['client'])) {
            workspace_assert_unload_point_unused($entryId,$old['address']);
        }
        $key=workspace_unload_address_key($client,$point['client'],$point['address']);
        $revision=$old ? (int)$old['revision']+1 : 1;
        if ($old) {
            wquery('UPDATE catalog_entries SET normalized=?,revision=? WHERE id=?',[$key,$revision,$entryId]);
            wquery('UPDATE unload_points SET client_id=?,client_label=?,address=? WHERE entry_id=?',
                [$client,$point['client'],$point['address'],$entryId]);
        } else {
            wquery('INSERT INTO catalog_entries(catalog_id,name,normalized) VALUES(?,?,?)',[$catalogId,$point['client'],$key]);
            $entryId=(int)workspace_db()->lastInsertId();
            wquery("INSERT INTO unload_points(entry_id,client_id,client_label,address,schedule,instruction) VALUES(?,?,?,?,'','')",
                [$entryId,$client,$point['client'],$point['address']]);
        }
        workspace_audit(['category'=>'catalog','label'=>'Точки выгрузки','before'=>$old?workspace_unload_point_values($old):null,
            'after'=>['id'=>$entryId]+$point,'action'=>$old?'edit':'create']);
        workspace_db()->commit();
        return ['id'=>$entryId,'revision'=>$revision,'values'=>workspace_unload_point_values($point)];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_catalog','Точка с таким адресом уже есть в справочнике.',409);
        }
        throw $error;
    }
}

function workspace_assert_unload_point_unused(int $id,string $name): void {
    $count=(int)wquery("SELECT COUNT(*) FROM (
        SELECT record_id FROM unload_point_origins WHERE entry_id=?
        UNION SELECT record_id FROM record_references WHERE entry_id=?
    ) linked",[$id,$id])->fetchColumn();
    if ($count) throw new ApiError('entry_in_use','Запись «'.$name.'» используется. Связанных строк — '.$count.'. Удалить её нельзя.',409);
}

function workspace_unload_contacts(int $catalogId,int $entryId,?array $body=null): array {
    workspace_require('catalog',(string)$catalogId,$body===null?'view':'edit');
    if ($body!==null) workspace_begin();
    try {
        $entry=wquery("SELECT e.id,p.address,e.revision,e.active FROM catalog_entries e JOIN unload_points p ON p.entry_id=e.id
            WHERE e.catalog_id=? AND e.id=?".($body===null?'':' FOR UPDATE'),[$catalogId,$entryId])->fetch();
        if (!$entry || ($body!==null && !$entry['active'])) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        $read=static fn(): array=>wquery('SELECT id,name,phone,email,original,is_primary FROM unload_point_contacts WHERE entry_id=? ORDER BY is_primary DESC,id',[$entryId])->fetchAll();
        $old=$read();
        if ($body===null) return ['point'=>$entry['address'],'revision'=>(int)$entry['revision'],'contacts'=>$old];
        if (($body['revision']??null)!==(int)$entry['revision']) workspace_directory_conflict();
        $contacts=$body['contacts']??null;
        if (!is_array($contacts) || !array_is_list($contacts) || count($contacts)>150) throw new ApiError('invalid_contacts','Проверьте список контактов.');
        $existing=array_column($old,null,'id');
        $normalized=[];
        $primary=0;
        foreach ($contacts as $row) {
            if (!is_array($row) || !is_bool($row['primary']??null)) throw new ApiError('invalid_contacts','Проверьте данные контакта.');
            $name=workspace_unload_text($row['name']??'',160);
            $phone=workspace_unload_text($row['phone']??'',100);
            $email=workspace_unload_text($row['email']??'',254);
            if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ApiError('invalid_email','Проверьте адрес e-mail.');
            $before=$existing[$row['id']??0]??null;
            $original=$before && [$name,$phone,$email]===[$before['name'],$before['phone'],$before['email']] ? $before['original'] : '';
            if ($name==='' && $phone==='' && $email==='' && $original==='') continue;
            $normalized[]=[$name,$phone,$email,$original,(int)$row['primary']];
            $primary+=(int)$row['primary'];
        }
        if ($normalized && $primary!==1) throw new ApiError('invalid_primary','Укажите один основной контакт.');
        wquery('DELETE FROM unload_point_contacts WHERE entry_id=?',[$entryId]);
        foreach ($normalized as $row) {
            wquery('INSERT INTO unload_point_contacts(entry_id,name,phone,email,original,is_primary) VALUES(?,?,?,?,?,?)',[$entryId,...$row]);
        }
        wquery('UPDATE catalog_entries SET revision=revision+1 WHERE id=?',[$entryId]);
        workspace_audit(['category'=>'catalog','label'=>'Контакты точки выгрузки','before'=>['id'=>$entryId,'contacts'=>$old],
            'after'=>['id'=>$entryId,'contacts'=>$normalized],'action'=>'edit']);
        $saved=$read();
        $primary=$saved ? workspace_unload_primary(['contact_name'=>$saved[0]['name']]+$saved[0]) : '';
        workspace_db()->commit();
        return ['revision'=>(int)$entry['revision']+1,'summary'=>workspace_unload_contact_summary($primary,count($saved))];
    } catch (Throwable $error) {
        if ($body!==null && workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
