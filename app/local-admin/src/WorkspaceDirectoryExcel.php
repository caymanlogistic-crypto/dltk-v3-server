<?php
declare(strict_types=1);
require_once __DIR__.'/DirectoryXlsx.php';

function workspace_directory_excel_supported(?string $directory): bool {
    return in_array($directory,['entries','clients','flight-statuses','client-order-statuses'],true);
}

function workspace_directory_excel_schema(string $directory): array {
    $model=workspace_directory_grid_model($directory);
    $id=(int)($model['catalogId']??0);
    $kind=$model['catalogKind']??'clients';
    if ($directory==='flight-statuses') {
        [$table,$catalog]=workspace_flight_status_grid();
        $id=$catalog['id'];
        $kind='status';
        workspace_require('catalog',(string)$id,'edit',$table['id']);
    } elseif ($directory==='client-order-statuses') {
        $kind='order-statuses';
        workspace_require('system','client-order-statuses','edit');
    } else {
        workspace_require('catalog',(string)$id,'edit');
    }
    if (!$id && $kind!=='order-statuses') throw new ApiError('not_found','Справочник не найден.',404);
    $labels=array_column($model['columns'],'label','letter');
    if ($kind==='clients') unset($labels['C']);
    if ($kind==='unload-points') $labels['C']='Основной контакт';
    return ['id'=>$id,'kind'=>$kind,'labels'=>$labels];
}

function workspace_directory_excel_entries(array $schema): array {
    ['id'=>$id,'kind'=>$kind]=$schema;
    if ($kind==='order-statuses') {
        $entries=[];
        foreach (workspace_client_order_statuses(true) as $code=>$status) {
            if (!empty($status['deleted'])) continue;
            $entries[$status['id']]=array_replace($status,['code'=>$code,'name'=>$status['label'],
                'normalized'=>vitrine_status_key($status['label'])]);
        }
    } else {
        $entries=array_column(wquery("SELECT id,name,normalized,color,revision,active FROM catalog_entries e
            WHERE catalog_id=? AND NOT EXISTS (SELECT 1 FROM workspace_meta m
            WHERE m.key=CONCAT('catalog_deleted_client_',e.id)) ORDER BY id",[$id])->fetchAll(),null,'id');
    }
    $secondary=match($kind) {
        'clients'=>workspace_client_manager_values($id),
        'carriers'=>workspace_client_catalog_metadata('catalog_logists_'.$id),
        'dropoff-points'=>workspace_dropoff_profiles($id),
        'managers'=>array_column(workspace_manager_entries($id),null,'id'),
        'unload-points'=>workspace_unload_points($id),
        default=>[],
    };
    $palette=array_column(workspace_palette(),'name','fill');
    foreach ($entries as $entryId=>&$entry) {
        $detail=$secondary[$entryId]??null;
        if ((in_array($kind,['unload-points','managers'],true) && !$detail) || !empty($detail['review_required'])) {
            unset($entries[$entryId]);
            continue;
        }
        $entry['detail']=$detail;
        $base=['A'=>$entry['name']];
        $entry['values']=match($kind) {
            'clients','carriers'=>$base+['B'=>$detail??''],
            'status','terminals-main','order-statuses'=>$base+['B'=>$palette[$entry['color']]??''],
            'dropoff-points'=>$base+($detail??workspace_dropoff_profile([])),
            'managers'=>workspace_manager_values($detail),
            'unload-points'=>workspace_unload_point_values($detail)+['C'=>workspace_unload_primary($detail)],
            default=>$base,
        };
    }
    unset($entry);
    return $entries;
}

function workspace_directory_excel_header(array $labels,array $sheet): array {
    $header=array_filter(array_map('trim',$sheet[1]??[]),'strlen');
    $exportedContacts=($labels['C']??'')==='Основной контакт' && in_array('Контакты',$header,true);
    if ($exportedContacts) $labels['C']='Контакты';
    $allowed=[...array_values($labels),'ID записи','Версия записи','Строка'];
    if (in_array('Клиент',$labels,true) && in_array('Менеджер',$labels,true)) $allowed[]='Точки выгрузки';
    if (count($header)!==count(array_unique($header)) || array_diff($header,$allowed) ||
        array_diff($labels,$header) || (in_array('ID записи',$header,true)!==in_array('Версия записи',$header,true))) {
        directory_xlsx_error('Проверьте заголовки первой строки. Нужны столбцы: '.implode(', ',$labels).'.');
    }
    $positions=array_flip($header);
    if ($exportedContacts) $positions['Основной контакт']=$positions['Контакты'];
    return [$positions,$header,$exportedContacts];
}

function workspace_directory_excel_payload(array $schema,array $values,?array $old): array {
    ['id'=>$id,'kind'=>$kind]=$schema;
    $payload=['catalogId'=>$id];
    if ($kind==='managers') {
        $profile=workspace_manager_validate($values);
        $key=hash('sha256',implode("\n",array_map('vitrine_catalog_key',[$profile['surname'],$profile['name'],$profile['patronymic']])));
    } elseif ($kind==='unload-points') {
        $point=workspace_unload_validate_point($values,$old['detail']??null);
        $key=workspace_unload_address_key($point['client_id']===null?null:(int)$point['client_id'],$point['client'],$point['address']);
        workspace_unload_text($values['C'],6000,true);
    } else {
        $name=workspace_catalog_validate_name($values['A']);
        $key=mb_strtolower($name);
        $payload['name']=$name;
        if ($kind==='clients') {
            $payload['manager']=workspace_client_validate_manager($values['B']);
            if ($payload['manager']!==($old['values']['B']??'') && workspace_manager_catalog_id()) {
                workspace_client_manager_ids($payload['manager']);
            }
        }
        if ($kind==='carriers') $payload['logist']=workspace_client_validate_manager($values['B']);
        if ($kind==='dropoff-points') $payload['dropoffProfile']=workspace_dropoff_profile($values);
        if ($kind==='status' || $kind==='order-statuses' || ($kind==='terminals-main' && $values['B']!=='')) {
            $payload['color']=workspace_directory_color($values['B']);
        } elseif ($kind==='terminals-main') $payload['color']=null;
    }
    return [$key,$payload];
}

function workspace_directory_excel_plan(array $schema,array $entries,array $sheet,bool $replace): array {
    [$positions,$header,$exportedContacts]=workspace_directory_excel_header($schema['labels'],$sheet);
    unset($sheet[1]);
    if (!$sheet) directory_xlsx_error('Добавьте в Excel хотя бы одну строку справочника.');
    $kind=$schema['kind'];
    $keys=[];
    foreach ($entries as $id=>$entry) $keys[$entry['normalized']][]=(int)$id;
    $aliases=in_array($kind,['clients','terminals-main'],true) ? workspace_client_catalog_aliases($schema['id']) : [];
    $seen=$names=$operations=[];
    $counts=['added'=>0,'updated'=>0,'archived'=>0,'unchanged'=>0];
    foreach ($sheet as $line=>$cells) {
        try {
            foreach (array_diff_key($cells,$header) as $value) {
                if (trim($value)!=='') directory_xlsx_error('Есть данные в столбце без заголовка.');
            }
            $values=[];
            foreach ($schema['labels'] as $letter=>$label) $values[$letter]=trim($cells[$positions[$label]]??'');
            $idText=trim($cells[$positions['ID записи']??0]??'');
            $version=trim($cells[$positions['Версия записи']??0]??'');
            if (($idText==='')!==($version==='') || ($idText!=='' && (!preg_match('/^[1-9][0-9]{0,17}$/D',$idText) || !ctype_digit($version)))) {
                directory_xlsx_error('Проверьте ID и версию записи.');
            }
            $id=(int)$idText;
            $old=$entries[$id]??null;
            if ($id && (!$old || !$old['active'] || (string)$old['revision']!==$version)) {
                throw new ApiError('revision_conflict','Версия записи устарела или запись находится в архиве.',409);
            }
            if (!$id) {
                $lookup=$values['A'];
                if (in_array($kind,['managers','unload-points'],true)) {
                    [$lookup]=workspace_directory_excel_payload($schema,$values,null);
                } else {
                    $lookup=vitrine_status_key($lookup);
                }
                $matches=$keys[$lookup]??[];
                if (count($matches)>1) directory_xlsx_error('Найдено несколько записей с таким названием. Укажите ID записи и её версию.');
                $id=$matches[0]??0;
                $old=$entries[$id]??null;
                if ($old && !$old['active']) directory_xlsx_error('Запись в архиве. Восстановите её перед загрузкой.');
            }
            if ($exportedContacts) {
                $primary=$old['values']['C']??'';
                $count=(int)($old['detail']['contact_count']??0);
                if ($values['C']===workspace_unload_contact_summary($primary,$count)) $values['C']=$primary;
            }
            if ($old && $values===$old['values']) {
                if (isset($seen[$id])) directory_xlsx_error('Повтор записи.');
                $seen[$id]=true;
                $counts['unchanged']++;
                continue;
            }
            [$key,$payload]=workspace_directory_excel_payload($schema,$values,$old);
            $otherOwners=array_diff($keys[$key]??[],[$id]);
            $aliasOwner=(int)($aliases[$key]??0);
            if (($id && isset($seen[$id])) || isset($names[$key]) || $otherOwners || ($aliasOwner && $aliasOwner!==$id)) {
                directory_xlsx_error('Повтор записи или название, закреплённое за другой записью.');
            }
            if ($id) $seen[$id]=true;
            $names[$key]=true;
            if ($old) $payload+=['id'=>$id,'revision'=>(int)$old['revision']];
            $operations[]=['line'=>$line,'id'=>$id,'values'=>$values,'payload'=>$payload];
            $counts[$old?'updated':'added']++;
        } catch (ApiError $error) {
            throw new ApiError($error->publicCode,'Строка '.$line.'. '.$error->publicMessage,$error->httpStatus);
        }
    }
    if ($replace) {
        foreach ($entries as $id=>$entry) {
            if (!$entry['active'] || isset($seen[$id])) continue;
            workspace_directory_excel_check_archive($schema,$entry);
            $operations[]=['id'=>$id,'archive'=>true];
            $counts['archived']++;
        }
    }
    return [$operations,$counts];
}

function workspace_directory_excel_check_archive(array $schema,array $entry): void {
    $kind=$schema['kind'];
    if (in_array($kind,['clients','managers'],true)) return;
    if ($kind==='order-statuses') {
        $linked=(int)wquery("SELECT COUNT(*) FROM records WHERE JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.clientOrderStatus'))=?
            OR JSON_UNQUOTE(JSON_EXTRACT(baseline_json,'$.clientOrderStatus'))=?",[$entry['code'],$entry['code']])->fetchColumn();
        if ($linked) throw new ApiError('entry_in_use','Статус «'.$entry['name'].'» используется. Связанных заказов — '.$linked.'.',409);
        return;
    }
    workspace_assert_catalog_entry_unused($kind,$schema['id'],$entry);
}

function workspace_directory_excel_apply(array $schema,array $entries,array $operations): void {
    ['id'=>$id,'kind'=>$kind]=$schema;
    if ($kind==='order-statuses') {
        workspace_directory_excel_order_statuses($entries,$operations);
        return;
    }
    foreach ($operations as $operation) {
        $entry=$entries[$operation['id']]??null;
        $entryId=$entry ? (int)$entry['id'] : null;
        $revision=$entry ? (int)$entry['revision'] : null;
        try {
            if (!empty($operation['archive'])) {
                workspace_catalog_write(['catalogId'=>$id,'id'=>$entryId,'revision'=>$revision,'name'=>$entry['name'],
                    'color'=>$entry['color'],'active'=>false,'archive'=>true]);
            } elseif ($kind==='managers') {
                workspace_manager_write($id,$operation['values'],$entryId,$revision);
            } elseif ($kind==='unload-points') {
                workspace_unload_write_point($id,$operation['values'],$entryId,$revision,
                    primaryContact:$operation['values']['C']);
            } else {
                workspace_catalog_write($operation['payload']);
            }
        } catch (ApiError $error) {
            $line=isset($operation['line']) ? 'Строка '.$operation['line'].'. ' : '';
            throw new ApiError($error->publicCode,$line.$error->publicMessage,$error->httpStatus);
        }
    }
    workspace_event('catalog.changed',['catalogId'=>$id,'bulk'=>true]);
}

function workspace_directory_excel_order_statuses(array $entries,array $operations): void {
    $statuses=workspace_client_order_statuses(true);
    $max=max(array_column($statuses,'id'));
    foreach ($operations as $operation) {
        $entry=$entries[$operation['id']]??null;
        $code=$entry['code']??'custom-'.(++$max);
        $before=$statuses[$code]??null;
        if (!empty($operation['archive'])) {
            $after=array_replace($before,['active'=>false,'revision'=>$before['revision']+1]);
            $action='archive';
        } else {
            $payload=$operation['payload'];
            $after=['id'=>$before['id']??$max,'label'=>$payload['name'],'color'=>$payload['color'],
                'revision'=>($before['revision']??0)+1,'active'=>true];
            $action=$before?'edit':'create';
        }
        $statuses[$code]=$after;
        workspace_audit(['category'=>'catalog','label'=>'Статусы клиентов','before'=>$before,'after'=>$after,'action'=>$action]);
    }
    workspace_write_order_statuses($statuses);
}

function workspace_directory_excel_endpoint(): never {
    $directory=vitrine_directory_requested();
    if (!workspace_directory_excel_supported($directory)) throw new ApiError('not_found','Для этого справочника загрузка Excel недоступна.',404);
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $schema=workspace_directory_excel_schema($directory);
    $sheet=directory_xlsx_read($_FILES['workbook']??[]);
    $replace=($_POST['replace']??'')==='1';
    $commit=array_key_exists('token',$_POST);
    if ($commit && !is_string($_POST['token'])) directory_xlsx_error();
    if ($commit) workspace_begin();
    try {
        $entries=workspace_directory_excel_entries($schema);
        [$operations,$counts]=workspace_directory_excel_plan($schema,$entries,$sheet,$replace);
        $token=hash_hmac('sha256',wjson([$schema,$sheet,$replace,$entries]),csrf_token());
        if ($commit) {
            if (!hash_equals($token,$_POST['token'])) {
                throw new ApiError('revision_conflict','Справочник или файл изменился. Проверьте загрузку ещё раз.',409);
            }
            if ($operations) workspace_directory_excel_apply($schema,$entries,$operations);
            workspace_db()->commit();
        }
        json_response(['counts'=>$counts,'token'=>$token]);
    } catch (Throwable $error) {
        if ($commit && workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
