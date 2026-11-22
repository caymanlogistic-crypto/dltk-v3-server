<?php
declare(strict_types=1);

function workspace_unload_points(int $catalogId): array {
    $rows = wquery("SELECT e.id,e.name,e.active,e.revision,p.client_id,
        COALESCE(client.name,p.client_label) AS client,p.address,p.schedule,p.instruction,
        contact.name AS contact_name,contact.phone,contact.email,contact.original
        FROM catalog_entries e JOIN unload_points p ON p.entry_id=e.id
        LEFT JOIN catalog_entries client ON client.id=p.client_id
        LEFT JOIN unload_point_contacts contact ON contact.entry_id=e.id AND contact.is_primary=TRUE
        WHERE e.catalog_id=? ORDER BY e.name,e.id",[$catalogId])->fetchAll();
    return array_column($rows,null,'id');
}

function workspace_unload_option(array $point): string {
    return str_starts_with($point['address'],$point['name']) ? $point['address'] : $point['name'].' · '.$point['address'];
}

function workspace_unload_options_by_client(array $points): array {
    $options=[];
    foreach ($points as $point) {
        if (!$point['active']) continue;
        $client=workspace_unload_client_key($point['client_id']===null?null:(int)$point['client_id'],$point['client']);
        $options[$client][]=workspace_unload_option($point);
    }
    return $options;
}

function workspace_unload_row_options(array $options,?int $clientId,string $client,?array $current): array {
    $choices=$options[workspace_unload_client_key($clientId,$client)]??[];
    if ($current && $current['active']) {
        $label=workspace_unload_option($current);
        if (!in_array($label,$choices,true)) $choices[]=$label;
    }
    return $choices;
}

function workspace_unload_primary(array $point): string {
    return (string)($point['original'] ?: implode(' · ',array_filter([$point['contact_name'],$point['phone'],$point['email']])));
}

function workspace_unload_client_key(?int $clientId,string $name): string {
    return $clientId ? 'id:'.$clientId : 'name:'.vitrine_catalog_key($name);
}

function workspace_unload_address_key(?int $clientId,string $name,string $address): string {
    return hash('sha256',workspace_unload_client_key($clientId,$name)."\n".vitrine_catalog_key($address));
}

function workspace_unload_client_entries(bool $activeOnly): array {
    $catalog=(int)(workspace_client_grid_catalog()['id']??0);
    if (!$catalog) return [];
    return wquery('SELECT id,name,normalized FROM catalog_entries WHERE catalog_id=? AND (?=FALSE OR active=TRUE) ORDER BY name',
        [$catalog,(int)$activeOnly])->fetchAll();
}

function workspace_unload_point_values(array $point): array {
    return ['A'=>$point['client'],'B'=>$point['name'],'C'=>$point['address'],'D'=>$point['schedule'],'E'=>$point['instruction']];
}

function workspace_unload_draft_fields(array $clients): array {
    $optional=['type'=>'text','required'=>false];
    $multiline=['type'=>'text','singleLine'=>false];
    return ['A'=>['type'=>'choice','required'=>true,'choices'=>$clients],'B'=>$optional,
        'C'=>$multiline+['required'=>true,'maxLength'=>6000],
        'D'=>$multiline+['required'=>false,'maxLength'=>1000],
        'E'=>$multiline+['required'=>false,'maxLength'=>6000]];
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
    $clientName=workspace_unload_text($values['A']??'',160);
    $clientId=$old && $clientName===$old['client'] && $old['client_id'] ? (int)$old['client_id'] : null;
    if ($clientId===null) {
        $byName=array_column(workspace_unload_client_entries(true),'id','normalized');
        $clientId=isset($byName[vitrine_catalog_key($clientName)]) ? (int)$byName[vitrine_catalog_key($clientName)] : null;
        if ($clientId===null && (!$old || $clientName!==$old['client'])) {
            throw new ApiError('invalid_unload_client','Выберите клиента из справочника.');
        }
    }
    $address=workspace_unload_text($values['C']??'',6000,true);
    if ($address==='') throw new ApiError('invalid_unload_address','Укажите адрес выгрузки.');
    $name=workspace_unload_text($values['B']??'',160);
    $schedule=workspace_unload_text($values['D']??'',1000,true);
    $instruction=workspace_unload_text($values['E']??'',6000,true);
    if ($name==='') $name=mb_substr(trim(preg_replace('/\s+/u',' ',$address)),0,160);
    return ['id'=>$clientId,'client'=>$clientName,'name'=>$name,'address'=>$address,'schedule'=>$schedule,'instruction'=>$instruction];
}

function workspace_unload_save_point(int $catalogId,array $values,?int $entryId=null,?int $revision=null): array {
    workspace_require('catalog',(string)$catalogId,'edit');
    workspace_begin();
    try {
        $old=$entryId ? (workspace_unload_points($catalogId)[$entryId]??null) : null;
        if ($entryId && (!$old || !$old['active'])) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        if ($old && (int)$old['revision']!==$revision) workspace_directory_conflict();
        $point=workspace_unload_validate_point($values,$old);
        $client=$point['id'];
        if ($old && $client!==($old['client_id']===null?null:(int)$old['client_id'])) {
            workspace_assert_unload_point_unused($entryId,$old['name']);
        }
        $key=workspace_unload_address_key($client,$point['client'],$point['address']);
        $revision=$old ? (int)$old['revision']+1 : 1;
        if ($old) {
            wquery('UPDATE catalog_entries SET name=?,normalized=?,revision=? WHERE id=?',[$point['name'],$key,$revision,$entryId]);
            wquery('UPDATE unload_points SET client_id=?,client_label=?,address=?,schedule=?,instruction=? WHERE entry_id=?',
                [$client,$point['client'],$point['address'],$point['schedule'],$point['instruction'],$entryId]);
        } else {
            wquery('INSERT INTO catalog_entries(catalog_id,name,normalized) VALUES(?,?,?)',[$catalogId,$point['name'],$key]);
            $entryId=(int)workspace_db()->lastInsertId();
            wquery('INSERT INTO unload_points(entry_id,client_id,client_label,address,schedule,instruction) VALUES(?,?,?,?,?,?)',
                [$entryId,$client,$point['client'],$point['address'],$point['schedule'],$point['instruction']]);
        }
        unset($point['id']);
        workspace_audit(['category'=>'catalog','label'=>'Точки выгрузки','before'=>$old?workspace_unload_point_values($old):null,
            'after'=>['id'=>$entryId]+$point,'action'=>$old?'edit':'create']);
        workspace_db()->commit();
        return ['id'=>$entryId,'revision'=>$revision,'values'=>workspace_unload_point_values($point)];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_catalog','У этого клиента уже есть точка с таким адресом.',409);
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
        $entry=wquery("SELECT e.id,e.name,e.revision,e.active FROM catalog_entries e JOIN unload_points p ON p.entry_id=e.id
            WHERE e.catalog_id=? AND e.id=?".($body===null?'':' FOR UPDATE'),[$catalogId,$entryId])->fetch();
        if (!$entry || ($body!==null && !$entry['active'])) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        $read=static fn(): array=>wquery('SELECT id,name,phone,email,original,is_primary FROM unload_point_contacts WHERE entry_id=? ORDER BY is_primary DESC,id',[$entryId])->fetchAll();
        $old=$read();
        if ($body===null) return ['point'=>$entry['name'],'revision'=>(int)$entry['revision'],'contacts'=>$old];
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
        workspace_db()->commit();
        return ['revision'=>(int)$entry['revision']+1];
    } catch (Throwable $error) {
        if ($body!==null && workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
