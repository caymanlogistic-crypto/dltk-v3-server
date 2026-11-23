<?php
declare(strict_types=1);

function workspace_catalog_directory_model(): array {
    $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $catalog = $id ? wquery('SELECT id,title,kind FROM catalogs WHERE id=?',[$id])->fetch() : null;
    if (!$catalog || workspace_fleet_retired_kind($catalog['kind'])) throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$id,'view');
    $labels = match ($catalog['kind']) {
        'clients'=>['A'=>'Клиент','B'=>'Менеджер'],
        'carriers'=>['A'=>'Перевозчик','B'=>'Логист'],
        'status'=>['A'=>'Статус','B'=>'Цвет'],
        'terminals-main'=>['A'=>'Терминал','B'=>'Цвет'],
        'container-types'=>['A'=>'Тип контейнера'],
        'unload-points'=>['A'=>'Клиент','B'=>'Адрес','C'=>'Контакты'],
        'dropoff-points'=>['A'=>'Точка сдачи','B'=>'Адрес','C'=>'Контакты','D'=>'Постоянная инструкция'],
        'fleet-drivers'=>['A'=>'Фамилия','B'=>'Имя','C'=>'Отчество','D'=>'Телефон','E'=>'Доп. телефон'],
        'fleet-vehicles'=>['B'=>'Марка тягача','A'=>'Госномер тягача','C'=>'Тип транспорта',
            'I'=>'Марка полуприцепа','H'=>'Госномер полуприцепа']+workspace_fleet_driver_columns()+workspace_fleet_document_labels(),
        'managers'=>['A'=>'Фамилия','B'=>'Имя','C'=>'Отчество','D'=>'Роль','E'=>'Телефон','F'=>'Email'],
        default=>['A'=>'Название'],
    };
    $columns = [];
    foreach ($labels as $letter=>$label) {
        $column=compact('letter','label');
        if ($catalog['kind']==='fleet-vehicles' && isset(workspace_fleet_document_fields()[$letter])) $column['export']=false;
        $columns[]=$column;
    }
    if ($catalog['kind']==='unload-points') $columns[2]['kind']='unload-contacts';
    return ['id'=>0,'catalogId'=>(int)$id,'catalogKind'=>$catalog['kind'],'slug'=>'catalog-entries-'.$id,
        'title'=>$catalog['title'],'columns'=>$columns];
}

function workspace_catalog_directory_data(?array $requestedRows): array {
    $model = workspace_catalog_directory_model();
    $id = $model['catalogId'];
    $kind = $model['catalogKind'];
    $archive = ($_GET['archive'] ?? '') === '1';
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,160) : '';
    $canEdit = workspace_can('catalog',(string)$id,'edit');
    $choices = workspace_directory_palette_options();
    $valuesB = match ($kind) {
        'carriers'=>workspace_client_catalog_metadata('catalog_logists_'.$id),
        'clients'=>workspace_client_manager_values($id),
        default=>[],
    };
    $unload = $kind==='unload-points';
    $managers=$kind==='managers';
    $fleet=in_array($kind,WORKSPACE_FLEET_KINDS,true);
    $driverCatalog=$kind==='fleet-vehicles' ? workspace_fleet_catalog('fleet-drivers') : 0;
    $showDocuments=$driverCatalog && workspace_fleet_can_documents() && workspace_can('catalog',(string)$driverCatalog,'view');
    $canEditDocuments=$showDocuments && $canEdit && workspace_can('catalog',(string)$driverCatalog,'edit');
    $canViewDrivers=$driverCatalog && workspace_can('catalog',(string)$driverCatalog,'view');
    $dropoffProfiles=$kind==='dropoff-points' ? workspace_dropoff_profiles($id) : [];
    $showAll=$managers && ($_GET['archive'] ?? '')==='all';
    $managerRoles=$managers ? array_keys(workspace_manager_roles()) : [];
    $clientCatalog=$unload ? workspace_client_grid_catalog() : null;
    $clientFilter=$unload && isset($_GET['client'])
        ? workspace_directory_client_filter((int)($clientCatalog['id']??0)) : null;
    $clientChoices=$unload ? array_column(workspace_unload_client_entries(),'name') : [];
    $entries = match (true) {
        $unload=>workspace_unload_points($id),
        $managers=>workspace_manager_entries($id),
        $fleet=>workspace_fleet_entries($kind,$id),
        default=>wquery("SELECT id,name,color,revision,active FROM catalog_entries e WHERE catalog_id=? AND active=?
            AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id))
            ORDER BY name,id",[$id,(int)!$archive])->fetchAll(),
    };
    if ($showDocuments) {
        $documentRows=workspace_fleet_documents();
    }
    if (!$archive) $entries=workspace_directory_order_entries($entries,$id);
    $palette = array_column(workspace_palette(),'name','fill');
    $rows = $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows,true);
    foreach ($entries as $entry) {
        if (!$showAll && (bool)$entry['active']===$archive) continue;
        if ($kind==='fleet-vehicles' && workspace_fleet_trailer_key($entry['name'])) continue;
        if ($unload && !$archive && $entry['review_required']) continue;
        if ($clientFilter && (int)$entry['client_id']!==(int)$clientFilter['id']) continue;
        $entryId = (int)$entry['id'];
        $values = match (true) {
            $unload=>workspace_unload_point_values($entry),
            $managers=>workspace_manager_values($entry),
            $fleet=>workspace_fleet_values($kind,$entry),
            default=>['A'=>$entry['name']],
        };
        if ($unload) $values['C']=workspace_unload_contact_summary(workspace_unload_primary($entry),(int)$entry['contact_count']);
        if ($kind==='dropoff-points') $values+=($dropoffProfiles[$entryId]??workspace_dropoff_profile([]));
        if (workspace_catalog_has_palette($kind)) $values['B'] = $palette[$entry['color']] ?? '';
        if (in_array($kind,['clients','carriers'],true)) $values['B'] = $valuesB[$entryId] ?? '';
        if ($driverCatalog && !$canViewDrivers) {
            $values=array_replace($values,array_fill_keys(array_keys(workspace_fleet_driver_columns()),''));
        }
        if ($search !== '' && mb_stripos(implode(' ',$values),$search) === false) continue;
        $rowIds[] = $entryId;
        if ($requested === null ? count($rows)>=100 : !isset($requested[$entryId])) continue;
        $row = ['rowIndex'=>$entryId,'rowLabel'=>count($rowIds),'values'=>$values,'rowActions'=>$canEdit,
            'singleLine'=>array_fill_keys(array_keys($values),true),'baseHash'=>workspace_catalog_directory_hash($id,$entryId),
            'edits'=>array_fill_keys(array_keys($values),['revision'=>(int)$entry['revision']])];
        if (workspace_catalog_has_palette($kind)) {
            $row['orderFill']=$entry['color'];
            $row['choices']['B']=$choices;
            $row['requiredChoices']['B']=true;
        }
        if ($managers) {
            $row['archived']=!(bool)$entry['active'];
            $row['actionLabel']=implode(' ',array_filter([$values['A'],$values['B'],$values['C']],
                static fn(string $part): bool=>$part!=='' && $part!=='Неизвестно'));
            $row['choices']['D']=$managerRoles;
            $row['requiredChoices']['D']=true;
        }
        if ($kind==='fleet-vehicles') {
            $driver=(int)$entry['driver_id'];
            $documentState=$showDocuments
                ? workspace_fleet_document_state($entryId,$driver,$canEditDocuments && !$archive,$documentRows[$driver]??[])
                : null;
            foreach (workspace_fleet_document_fields() as $column=>$_field) {
                $row['values'][$column]=$documentState['values'][$column]??'';
                $row['singleLine'][$column]=true;
                $row['kinds'][$column]='fleet-document';
                $row['edits'][$column]=['revision'=>$documentState['revision']??0,'baseHash'=>$documentState['baseHash']??''];
                $row['readOnly'][$column]=$documentState['readOnly']??'Документы доступны суперадмину.';
            }
            foreach (array_keys(workspace_fleet_driver_columns()) as $column) {
                if ($canViewDrivers) $row['kinds'][$column]='fleet-driver';
                else $row['readOnly'][$column]='Нет доступа к данным водителя.';
            }
        }
        if ($kind==='dropoff-points') $row['singleLine']=['A'=>true];
        if ($unload) {
            $row['singleLine']=['A'=>true];
            $row['readOnly']['A']='Клиент сохранённой точки закреплён.';
            if ($clientCatalog && $entry['client_id']) {
                $row['relatedUrl']='/vitrine?catalog=clients&client='.(int)$entry['client_id'].($entry['client_active']?'':'&archive=1');
                $row['rowActions']=true;
            }
        }
        $rows[] = $row;
    }
    $details = wquery("SELECT COALESCE(JSON_LENGTH(JSON_EXTRACT(value,'$.unkeyed')),0) AS unkeyedCount
        FROM workspace_meta WHERE `key`=?",['catalog_details_'.$id])->fetch();
    return ['table'=>$model,'columns'=>$model['columns'],'rows'=>$rows,'rowIds'=>$rowIds,'palette'=>workspace_palette(),
        'catalogGrid'=>['id'=>$id,'archive'=>$archive,'showAll'=>$showAll,'search'=>$search,'canEdit'=>$canEdit,
            'syncLabel'=>$canEdit && function_exists('workspace_catalog_source_label') ? workspace_catalog_source_label($id) : null,
            'hasDetails'=>(bool)$details,'unkeyedCount'=>(int)($details['unkeyedCount'] ?? 0),
            'unloadPoints'=>$unload,
            'relatedLabel'=>$clientCatalog ? 'Открыть клиента' : null,
            'clientFilter'=>$clientFilter,
            'draftVersion'=>$unload ? 'client-address' : ($kind==='fleet-vehicles' ? 'vehicle-driver' : ''),
            'draftFields'=>$unload ? workspace_unload_draft_fields($clientFilter
                ? array_values(array_intersect($clientChoices,[$clientFilter['name']])) : $clientChoices)
                : match (true) {
                    $managers=>workspace_manager_draft_fields($managerRoles),
                    $fleet=>workspace_fleet_draft_fields($kind),
                    default=>workspace_directory_draft_fields('entries',catalogKind:$kind),
                }]];
}

function workspace_catalog_directory_hash(int $catalogId,int $entryId): string {
    return hash('sha256','catalog-directory:'.$catalogId.':'.$entryId);
}

function workspace_catalog_directory_cell(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    $model = workspace_catalog_directory_model();
    $id = $model['catalogId'];
    workspace_require('catalog',(string)$id,'edit');
    $body = wbody();
    $column = $body['column'] ?? '';
    if (!is_int($body['row'] ?? null) || !is_string($body['value'] ?? null)
        || !in_array($column,array_column($model['columns'],'letter'),true)) {
        throw new ApiError('invalid_edit','Проверьте данные ячейки.');
    }
    $entry = wquery('SELECT * FROM catalog_entries WHERE catalog_id=? AND id=? AND active=TRUE',[$id,$body['row']])->fetch();
    if (!$entry) throw new ApiError('not_found','Запись не найдена.',404);
    $kind = $model['catalogKind'];
    if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) {
        if ($kind==='fleet-vehicles' && isset(workspace_fleet_document_fields()[$column])) workspace_fleet_document_cell($body,$pasteCheck);
        $profile=workspace_fleet_entries($kind,$id)[$body['row']]??null;
        if (!$profile) throw new ApiError('not_found','Запись не найдена.',404);
        $values=workspace_fleet_values($kind,$profile);
        $values[$column]=$body['value'];
        if ($kind==='fleet-vehicles' && $column==='H' && workspace_fleet_trailer_key($body['value'])!==workspace_fleet_trailer_key($profile['trailer_plate']??'')) {
            $key=workspace_fleet_trailer_key($body['value']);
            $values['I']=$key ? (string)wquery('SELECT brand FROM fleet_trailer_profiles WHERE plate_key=?',[$key])->fetchColumn() : '';
        }
        if ($kind==='fleet-vehicles' && array_key_exists($column,workspace_fleet_driver_columns()) && isset($body['fleetDriver'])) {
            $driver=$body['fleetDriver'];
            if (!is_array($driver) || array_diff_key($driver,workspace_fleet_driver_columns())
                || array_diff_key(workspace_fleet_driver_columns(),$driver)
                || array_filter($driver,static fn($part)=>!is_string($part))) {
                throw new ApiError('invalid_driver','Проверьте данные водителя.');
            }
            $values=array_replace($values,$driver);
        }
        if ($kind==='fleet-vehicles' && (isset($body['fleetDriver']) || in_array($column,['D','E','F'],true))) {
            $identity=array_flip(['D','E','F']);
            $oldValues=workspace_fleet_values($kind,$profile);
            if (vitrine_catalog_key(implode(' ',array_intersect_key($values,$identity)))!==
                vitrine_catalog_key(implode(' ',array_intersect_key($oldValues,$identity)))) {
                foreach (['G','Q'] as $phoneColumn) if ($values[$phoneColumn]===$oldValues[$phoneColumn]) $values[$phoneColumn]='';
            }
        }
        if ($pasteCheck) {
            workspace_fleet_values_validated($kind,$values);
            json_response(['accepted'=>true]);
        }
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_fleet_save($kind,$id,$values,$body['row'],$body['revision']);
        if ($kind==='fleet-vehicles' && !workspace_can('catalog',(string)workspace_fleet_catalog('fleet-drivers'),'view')) {
            $result['values']=array_diff_key($result['values'],workspace_fleet_driver_columns());
        }
        if ($kind==='fleet-vehicles' && workspace_fleet_can_documents()) {
            $driverCatalog=(string)workspace_fleet_catalog('fleet-drivers');
            if (workspace_can('catalog',$driverCatalog,'view')) {
                $driver=(int)wquery('SELECT driver_id FROM fleet_vehicle_profiles WHERE entry_id=?',[$body['row']])->fetchColumn();
                $result['fleetDocuments']=workspace_fleet_document_state($body['row'],$driver,workspace_can('catalog',$driverCatalog,'edit'));
            }
        }
        $value=$result['values'][$column]??'';
        json_response($result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false]);
    }
    if ($kind==='managers') {
        $manager=array_column(workspace_manager_entries($id),null,'id')[$body['row']]??null;
        if (!$manager) throw new ApiError('not_found','Менеджер не найден.',404);
        $values=workspace_manager_values($manager);
        $values[$column]=$body['value'];
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if ($pasteCheck) {
            workspace_manager_validate($values);
            json_response(['accepted'=>true]);
        }
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_manager_save($id,$values,$body['row'],$body['revision']);
        $value=$result['values'][$column];
        json_response($result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false]);
    }
    if ($kind==='dropoff-points') {
        $values=['A'=>$entry['name']]+(workspace_dropoff_profiles($id)[$body['row']]??workspace_dropoff_profile([]));
        $values[$column]=$body['value'];
        $name=workspace_catalog_validate_name($values['A']);
        $profile=workspace_dropoff_profile($values);
        if ($pasteCheck) json_response(['accepted'=>true]);
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_catalog_save(['catalogId'=>$id,'id'=>$body['row'],'revision'=>$body['revision'],
            'name'=>$name,'dropoffProfile'=>$profile]);
        $value=($profile+['A'=>$name])[$column];
        json_response($result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false]);
    }
    if ($kind==='unload-points') {
        if ($column==='A') throw new ApiError('fixed_unload_client','У сохранённой точки нельзя изменить клиента.',409);
        if ($column==='C') throw new ApiError('contact_editor','Откройте редактор контактов из ячейки «Контакты».');
        $point=workspace_unload_points($id)[$body['row']]??null;
        if (!$point) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        $values=['B'=>$body['value']];
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if ($pasteCheck) {
            workspace_unload_validate_point($values,$point);
            json_response(['accepted'=>true]);
        }
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_unload_save_point($id,$values,$body['row'],$body['revision']);
        $value=$result['values'][$column];
        json_response($result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false]);
    }
    $payload = ['catalogId'=>$id,'id'=>$body['row'],'name'=>$entry['name'],'color'=>$entry['color']];
    $value = $body['value'];
    $fill = null;
    if ($column === 'A') $payload['name'] = $value = workspace_catalog_validate_name($value);
    elseif (workspace_catalog_has_palette($kind)) $payload['color'] = $fill = workspace_directory_color($value);
    else $payload[$kind === 'clients' ? 'manager' : 'logist'] = $value = workspace_client_validate_manager($value);
    if ($pasteCheck) json_response(['accepted'=>true]);
    $baseHash = workspace_catalog_directory_hash($id,$body['row']);
    if (($body['baseHash'] ?? null) !== $baseHash || !is_int($body['revision'] ?? null)) workspace_directory_conflict();
    json_response(workspace_catalog_save($payload+['revision'=>$body['revision']])+[
        'value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false,'orderFill'=>$fill]);
}
