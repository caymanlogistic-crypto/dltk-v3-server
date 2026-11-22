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
        'unload-points'=>['A'=>'Клиент','B'=>'Название точки','C'=>'Адрес выгрузки','D'=>'Режим работы','E'=>'Инструкция водителю'],
        default=>['A'=>'Название'],
    };
    $columns = [];
    foreach ($labels as $letter=>$label) $columns[] = compact('letter','label');
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
    $clientChoices = $unload ? array_column(workspace_unload_client_entries(true),'name') : [];
    $entries = $unload ? workspace_unload_points($id)
        : wquery('SELECT id,name,color,revision,active FROM catalog_entries WHERE catalog_id=? AND active=? ORDER BY name,id',[$id,(int)!$archive])->fetchAll();
    $palette = array_column(workspace_palette(),'name','fill');
    $rows = $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows,true);
    foreach ($entries as $entry) {
        if ((bool)$entry['active']===$archive) continue;
        $entryId = (int)$entry['id'];
        $values = $unload ? workspace_unload_point_values($entry) : ['A'=>$entry['name']];
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
        if ($unload) {
            $row['singleLine']=['A'=>true,'B'=>true];
            $row['choices']['A']=$clientChoices;
            $row['requiredChoices']['A']=true;
        }
        $rows[] = $row;
    }
    $details = wquery("SELECT COALESCE(JSON_LENGTH(JSON_EXTRACT(value,'$.unkeyed')),0) AS unkeyedCount
        FROM workspace_meta WHERE `key`=?",['catalog_details_'.$id])->fetch();
    return ['table'=>$model,'columns'=>$model['columns'],'rows'=>$rows,'rowIds'=>$rowIds,'palette'=>workspace_palette(),
        'catalogGrid'=>['id'=>$id,'archive'=>$archive,'search'=>$search,'canEdit'=>$canEdit,
            'syncLabel'=>$canEdit && function_exists('workspace_catalog_source_label') ? workspace_catalog_source_label($id) : null,
            'hasDetails'=>(bool)$details,'unkeyedCount'=>(int)($details['unkeyedCount'] ?? 0),
            'unloadPoints'=>$unload,
            'draftFields'=>$unload ? workspace_unload_draft_fields($clientChoices)
                : workspace_directory_draft_fields('entries',catalogKind:$kind)]];
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
    if ($kind==='unload-points') {
        $point=workspace_unload_points($id)[$body['row']]??null;
        if (!$point) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        $values=workspace_unload_point_values($point);
        $values[$column]=$body['value'];
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
