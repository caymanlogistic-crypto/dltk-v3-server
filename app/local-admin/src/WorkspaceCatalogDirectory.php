<?php
declare(strict_types=1);

function workspace_catalog_directory_model(): array {
    $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $catalog = $id ? wquery('SELECT id,title,kind FROM catalogs WHERE id=?',[$id])->fetch() : null;
    if (!$catalog) throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$id,'view');
    $labels = match ($catalog['kind']) {
        'clients'=>['A'=>'Клиент','B'=>'Менеджер'],
        'carriers'=>['A'=>'Перевозчик','B'=>'Логист'],
        'status'=>['A'=>'Статус','B'=>'Цвет'],
        'terminals-main'=>['A'=>'Терминал','B'=>'Цвет'],
        'container-types'=>['A'=>'Тип контейнера'],
        'unload-points'=>['A'=>'Клиент','B'=>'Адрес','C'=>'Контакты'],
        'dropoff-points'=>['A'=>'Точка сдачи','B'=>'Адрес','C'=>'Контакты','D'=>'Постоянная инструкция'],
        'managers'=>['A'=>'Фамилия','B'=>'Имя','C'=>'Отчество','D'=>'Роль','E'=>'Телефон','F'=>'Email'],
        default=>['A'=>'Название'],
    };
    $columns = [];
    foreach ($labels as $letter=>$label) $columns[] = compact('letter','label');
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
        default=>wquery("SELECT id,name,color,revision,active FROM catalog_entries e WHERE catalog_id=? AND active=?
            AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id))
            ORDER BY name,id",[$id,(int)!$archive])->fetchAll(),
    };
    $palette = array_column(workspace_palette(),'name','fill');
    $rows = $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows,true);
    foreach ($entries as $entry) {
        if (!$showAll && (bool)$entry['active']===$archive) continue;
        if ($unload && !$archive && $entry['review_required']) continue;
        if ($clientFilter && (int)$entry['client_id']!==(int)$clientFilter['id']) continue;
        $entryId = (int)$entry['id'];
        $values = match (true) {
            $unload=>workspace_unload_point_values($entry),
            $managers=>workspace_manager_values($entry),
            default=>['A'=>$entry['name']],
        };
        if ($unload) $values['C']=workspace_unload_contact_summary(workspace_unload_primary($entry),(int)$entry['contact_count']);
        if ($kind==='dropoff-points') $values+=($dropoffProfiles[$entryId]??workspace_dropoff_profile([]));
        if (workspace_catalog_has_palette($kind)) $values['B'] = $palette[$entry['color']] ?? '';
        if (in_array($kind,['clients','carriers'],true)) $values['B'] = $valuesB[$entryId] ?? '';
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
            'draftVersion'=>$unload ? 'client-address' : '',
            'draftFields'=>$unload ? workspace_unload_draft_fields($clientFilter
                ? array_values(array_intersect($clientChoices,[$clientFilter['name']])) : $clientChoices)
                : ($managers ? workspace_manager_draft_fields($managerRoles) : workspace_directory_draft_fields('entries',catalogKind:$kind))]];
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
