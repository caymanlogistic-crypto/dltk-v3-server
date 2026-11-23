<?php
declare(strict_types=1);
require_once __DIR__.'/DirectoryXlsx.php';
require_once __DIR__.'/WorkspaceAccessExcel.php';

function workspace_directory_excel_supported(?string $directory): bool {
    return $directory!==null;
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
    } elseif ($directory==='roles' || $directory==='employees') {
        $kind=$directory;
        $canEdit=$directory==='roles' ? workspace_directory_roles_editable() : workspace_employee_editor_scope()!==null;
        if (!$canEdit) throw new ApiError('forbidden','Для этого действия нет доступа.',403);
    } else {
        workspace_require('catalog',(string)$id,'edit');
    }
    if (!$id && !in_array($kind,['order-statuses','roles','employees'],true)) throw new ApiError('not_found','Справочник не найден.',404);
    if ($kind==='fleet-vehicles') workspace_require('catalog',(string)workspace_fleet_catalog('fleet-drivers'),'view');
    $labels=array_column($model['columns'],'label','letter');
    if ($kind==='clients') unset($labels['C']);
    if ($kind==='fleet-vehicles') $labels=array_diff_key($labels,workspace_fleet_document_fields());
    if ($kind==='unload-points') $labels['C']='Основной контакт';
    return ['id'=>$id,'kind'=>$kind,'labels'=>$labels,'orderKey'=>$id ?: $directory];
}

function workspace_directory_excel_entries(array $schema): array {
    ['id'=>$id,'kind'=>$kind]=$schema;
    if (in_array($kind,['roles','employees'],true)) return workspace_access_excel_entries($kind);
    if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) {
        $entries=workspace_fleet_entries($kind,$id);
        if ($kind==='fleet-vehicles') $entries=array_filter($entries,static fn(array $entry): bool=>!workspace_fleet_trailer_key($entry['name']));
        return array_map(static fn(array $entry): array=>$entry+['values'=>workspace_fleet_values($kind,$entry)],$entries);
    }
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
        'carriers'=>workspace_carrier_directory_values($id),
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
            'clients'=>$base+['B'=>$detail??''],
            'carriers'=>$base+($detail??workspace_carrier_values([])),
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

function workspace_directory_excel_header(array $labels,array $sheet,string $kind): array {
    $header=array_filter(array_map('trim',$sheet[1]??[]),'strlen');
    $exportedContacts=($labels['C']??'')==='Основной контакт' && in_array('Контакты',$header,true);
    if ($exportedContacts) $labels['C']='Контакты';
    $allowed=[...array_values($labels),'ID записи','Версия записи','Строка'];
    if (in_array('Клиент',$labels,true) && in_array('Менеджер',$labels,true)) $allowed[]='Точки выгрузки';
    if ($kind==='fleet-vehicles') $allowed[]='Тип транспорта';
    $required=array_diff($labels,['Пароль','Доп. телефон']);
    if ($kind==='carriers') unset($required['D']);
    if (count($header)!==count(array_unique($header)) || array_diff($header,$allowed) ||
        array_diff($required,$header) || (in_array('ID записи',$header,true)!==in_array('Версия записи',$header,true))) {
        directory_xlsx_error('Проверьте заголовки первой строки. Нужны столбцы: '.implode(', ',$labels).'.');
    }
    $positions=array_flip($header);
    if ($exportedContacts) $positions['Основной контакт']=$positions['Контакты'];
    return [$positions,$header,$exportedContacts];
}

function workspace_directory_excel_payload(array $schema,array $values,?array $old): array {
    ['id'=>$id,'kind'=>$kind]=$schema;
    $payload=['catalogId'=>$id];
    if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) return [workspace_fleet_values_validated($kind,$values)['key'],$payload];
    if (in_array($kind,['roles','employees'],true)) return workspace_access_excel_payload($kind,$values,$old);
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
        if ($kind==='carriers') {
            $payload['carrierDetails']=workspace_carrier_details(['B'=>$values['B'],'edoId'=>$values['C']]);
            $payload['logist']=workspace_client_validate_manager($values['E']);
        }
        if ($kind==='dropoff-points') $payload['dropoffProfile']=workspace_dropoff_profile($values);
        if ($kind==='status' || $kind==='order-statuses' || ($kind==='terminals-main' && $values['B']!=='')) {
            $payload['color']=workspace_directory_color($values['B']);
        } elseif ($kind==='terminals-main') $payload['color']=null;
    }
    return [$key,$payload];
}

function workspace_directory_excel_plan(array $schema,array $entries,array $sheet): array {
    $kind=$schema['kind'];
    [$positions,$header,$exportedContacts]=workspace_directory_excel_header($schema['labels'],$sheet,$kind);
    unset($sheet[1]);
    $keys=[];
    foreach ($entries as $id=>$entry) $keys[$entry['normalized']][]=(int)$id;
    $aliases=in_array($kind,['clients','terminals-main'],true) ? workspace_client_catalog_aliases($schema['id']) : [];
    $seen=$names=$operations=[];
    $counts=['added'=>0,'updated'=>0,'archived'=>0,'restored'=>0,'unchanged'=>0];
    foreach ($sheet as $line=>$cells) {
        try {
            foreach (array_diff_key($cells,$header) as $value) {
                if (trim($value)!=='') directory_xlsx_error('Есть данные в столбце без заголовка.');
            }
            $values=[];
            foreach ($schema['labels'] as $letter=>$label) $values[$letter]=trim($cells[$positions[$label]??0]??'');
            if ($kind==='carriers') $values['D']=workspace_carrier_edo_operator(workspace_carrier_edo_id($values['C']));
            if ($kind==='employees') {
                if (!in_array($values['C'],['','Скрыт'],true)) directory_xlsx_error('Пароли сотрудников задаются только в карточке.');
                $values['C']='Скрыт';
            }
            $idText=trim($cells[$positions['ID записи']??0]??'');
            $version=trim($cells[$positions['Версия записи']??0]??'');
            if (($idText==='')!==($version==='') || ($idText!=='' && (!preg_match('/^[1-9][0-9]{0,17}$/D',$idText) || !ctype_digit($version)))) {
                directory_xlsx_error('Проверьте ID и версию записи.');
            }
            $id=(int)$idText;
            $old=$entries[$id]??null;
            if ($id && (!$old || (string)$old['revision']!==$version)) {
                throw new ApiError('revision_conflict','Запись не найдена или её версия устарела.',409);
            }
            if (!$id) {
                $lookup=$kind==='employees' ? $values['B'] : $values['A'];
                if (in_array($kind,['managers','unload-points',...WORKSPACE_FLEET_KINDS],true)) {
                    [$lookup]=workspace_directory_excel_payload($schema,$values,null);
                } else {
                    $lookup=vitrine_status_key($lookup);
                }
                $matches=$keys[$lookup]??[];
                if (count($matches)>1) directory_xlsx_error('Найдено несколько записей с таким названием. Укажите ID записи и её версию.');
                $id=$matches[0]??0;
                $old=$entries[$id]??null;
            }
            if ($kind==='fleet-vehicles' && $old) {
                if (!isset($positions[$schema['labels']['Q']])) $values['Q']=$old['values']['Q'];
                $values=array_replace($old['values'],$values);
            }
            if ($exportedContacts) {
                $primary=$old['values']['C']??'';
                $count=(int)($old['detail']['contact_count']??0);
                if ($values['C']===workspace_unload_contact_summary($primary,$count)) $values['C']=$primary;
            }
            if ($old && $old['active'] && $values===$old['values']) {
                if (isset($seen[$id])) directory_xlsx_error('Повтор записи.');
                $seen[$id]=true;
                $operations[]=['id'=>$id,'unchanged'=>true];
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
            $counts[!$old?'added':($old['active']?'updated':'restored')]++;
        } catch (ApiError $error) {
            throw new ApiError($error->publicCode,'Строка '.$line.'. '.$error->publicMessage,$error->httpStatus);
        }
    }
    foreach ($entries as $id=>$entry) {
        if (!$entry['active'] || isset($seen[$id])) continue;
        workspace_directory_excel_check_archive($schema,$entry);
        $operations[]=['id'=>$id,'archive'=>true];
        $counts['archived']++;
    }
    return [$operations,$counts];
}

function workspace_directory_excel_check_archive(array $schema,array $entry): void {
    $kind=$schema['kind'];
    if (in_array($kind,['clients','managers'],true)) return;
    if ($kind==='roles') {
        workspace_assert_role_unused($entry);
        return;
    }
    if ($kind==='employees') {
        if (!workspace_employee_can_edit(workspace_employee_editor_scope()??'', $entry)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
        workspace_assert_employee_unused($entry);
        return;
    }
    if ($kind==='order-statuses') {
        $linked=(int)wquery("SELECT COUNT(*) FROM records WHERE JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.clientOrderStatus'))=?
            OR JSON_UNQUOTE(JSON_EXTRACT(baseline_json,'$.clientOrderStatus'))=?",[$entry['code'],$entry['code']])->fetchColumn();
        if ($linked) throw new ApiError('entry_in_use','Статус «'.$entry['name'].'» используется. Связанных заказов — '.$linked.'.',409);
        return;
    }
    workspace_assert_catalog_entry_unused($kind,$schema['id'],$entry);
}

function workspace_directory_excel_apply(array $schema,array $entries,array $operations): array {
    ['id'=>$id,'kind'=>$kind]=$schema;
    if ($kind==='order-statuses') return workspace_directory_excel_order_statuses($entries,$operations);
    $order=[];
    foreach ($operations as $operation) {
        $entry=$entries[$operation['id']]??null;
        $entryId=$entry ? (int)$entry['id'] : null;
        $revision=$entry ? (int)$entry['revision'] : null;
        try {
            if (!empty($operation['unchanged'])) {
                $order[]=$entryId;
                continue;
            }
            if ($kind==='roles') {
                $action=!empty($operation['archive'])?'archive':($entry?'restore':'add');
                $result=workspace_role_row_write(['action'=>$action,'row'=>$entryId,'revision'=>0,'values'=>$operation['values']??[]]);
                if ($action!=='archive') $order[]=$result['id'];
                continue;
            }
            if ($kind==='employees') {
                $archive=!empty($operation['archive']);
                $payload=$archive ? ['id'=>$entryId,'name'=>$entry['name'],'login'=>$entry['login'],'active'=>false]
                    : $operation['payload'];
                if (!$entryId) $payload['password']=bin2hex(random_bytes(24));
                $result=workspace_employee_write($payload);
                if (!$archive) $order[]=$result['id'];
                continue;
            }
            if (!empty($operation['archive'])) {
                workspace_catalog_write(['catalogId'=>$id,'id'=>$entryId,'revision'=>$revision,'name'=>$entry['name'],
                    'color'=>$entry['color'],'active'=>false,'archive'=>true]);
                continue;
            }
            if ($entry && !$entry['active'] && in_array($kind,['managers','unload-points',...WORKSPACE_FLEET_KINDS],true)) {
                $restored=workspace_catalog_write(['catalogId'=>$id,'id'=>$entryId,'revision'=>$revision,
                    'name'=>$entry['name'],'active'=>true]);
                $revision=$restored['revision'];
            }
            $result=match($kind) {
                'managers'=>workspace_manager_write($id,$operation['values'],$entryId,$revision),
                'fleet-drivers','fleet-vehicles'=>workspace_fleet_write($kind,$id,$operation['values'],$entryId,$revision),
                'unload-points'=>workspace_unload_write_point($id,$operation['values'],$entryId,$revision,
                    primaryContact:$operation['values']['C']),
                default=>workspace_catalog_write($operation['payload']),
            };
            $order[]=$result['id'];
        } catch (ApiError $error) {
            $line=isset($operation['line']) ? 'Строка '.$operation['line'].'. ' : '';
            throw new ApiError($error->publicCode,$line.$error->publicMessage,$error->httpStatus);
        }
    }
    if ($id) workspace_event('catalog.changed',['catalogId'=>$id,'bulk'=>true]);
    return $order;
}

function workspace_directory_excel_order_statuses(array $entries,array $operations): array {
    $statuses=workspace_client_order_statuses(true);
    $max=max(array_column($statuses,'id'));
    $order=[];
    foreach ($operations as $operation) {
        $entry=$entries[$operation['id']]??null;
        if (!empty($operation['unchanged'])) {
            $order[]=(int)$entry['id'];
            continue;
        }
        $code=$entry['code']??'custom-'.(++$max);
        $before=$statuses[$code]??null;
        if (!empty($operation['archive'])) {
            $after=array_replace($before,['active'=>false,'revision'=>$before['revision']+1]);
            $action='archive';
        } else {
            $payload=$operation['payload'];
            $after=['id'=>$before['id']??$max,'label'=>$payload['name'],'color'=>$payload['color'],
                'revision'=>($before['revision']??0)+1,'active'=>true];
            $action=$before?($before['active']?'edit':'restore'):'create';
            $order[]=(int)$after['id'];
        }
        $statuses[$code]=$after;
        workspace_audit(['category'=>'catalog','label'=>'Статусы клиентов','before'=>$before,'after'=>$after,'action'=>$action]);
    }
    workspace_write_order_statuses($statuses);
    return $order;
}

function workspace_directory_excel_endpoint(): never {
    $directory=vitrine_directory_requested();
    if (!workspace_directory_excel_supported($directory)) throw new ApiError('not_found','Для этого справочника загрузка Excel недоступна.',404);
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $schema=workspace_directory_excel_schema($directory);
    $sheet=directory_xlsx_read($_FILES['workbook']??[]);
    $commit=array_key_exists('token',$_POST);
    if ($commit && !is_string($_POST['token'])) directory_xlsx_error();
    if ($commit) workspace_begin();
    try {
        $entries=workspace_directory_excel_entries($schema);
        [$operations,$counts]=workspace_directory_excel_plan($schema,$entries,$sheet);
        $previousOrder=workspace_directory_row_order($schema['orderKey']);
        $token=hash_hmac('sha256',wjson([$schema,$sheet,$entries,$previousOrder]),csrf_token());
        if ($commit) {
            if (!hash_equals($token,$_POST['token'])) {
                throw new ApiError('revision_conflict','Справочник или файл изменился. Проверьте загрузку ещё раз.',409);
            }
            $order=workspace_directory_excel_apply($schema,$entries,$operations);
            if ($order!==$previousOrder) {
                wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                    [workspace_directory_row_order_key($schema['orderKey']),wjson($order)]);
            }
            workspace_db()->commit();
        }
        json_response(['counts'=>$counts,'token'=>$token]);
    } catch (Throwable $error) {
        if ($commit && workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
