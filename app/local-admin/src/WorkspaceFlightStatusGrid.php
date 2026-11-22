<?php
declare(strict_types=1);

function workspace_flight_status_grid(): array {
    $table = workspace_table();
    $catalog = workspace_status_catalog($table);
    if ($catalog['id']) workspace_require('catalog',(string)$catalog['id'],'view',$table['id']);
    return [$table,$catalog];
}

function workspace_flight_status_hash(int $catalogId, int $entryId): string {
    return hash('sha256','flight-status:'.$catalogId.':'.$entryId);
}

function workspace_flight_status_grid_endpoint(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    [$table,$catalog] = workspace_flight_status_grid();
    if (!$catalog['id']) throw new ApiError('not_found','Справочник статусов рейса не найден.',404);
    workspace_require('catalog',(string)$catalog['id'],'edit',$table['id']);
    $body = wbody();
    if (!$pasteCheck && isset($body['action'])) workspace_flight_status_row_action($catalog,$body);
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null,['A','B'],true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit','Некорректные данные статуса рейса.');
    }
    $entry = array_column($catalog['statuses'],null,'id')[$body['row']] ?? null;
    if (!$entry) throw new ApiError('not_found','Статус рейса не найден.',404);
    $isName = $body['column']==='A';
    $value = $isName ? workspace_catalog_validate_name($body['value']) : trim($body['value']);
    $colors = array_column(workspace_palette(),'fill','name');
    if (!$isName && !isset($colors[$value])) throw new ApiError('invalid_color','Выберите цвет из системной палитры.');
    if ($pasteCheck) json_response(['accepted'=>true]);
    $hash = workspace_flight_status_hash($catalog['id'],(int)$entry['id']);
    if (!is_int($body['revision'] ?? null) || ($body['baseHash'] ?? null)!==$hash) {
        throw new ApiError('invalid_edit','Обновите страницу и повторите сохранение.');
    }
    $color = $isName ? $entry['color'] : $colors[$value];
    $saved = workspace_catalog_save(['catalogId'=>$catalog['id'],'id'=>(int)$entry['id'],'revision'=>$body['revision'],
        'name'=>$isName ? $value : $entry['name'],'color'=>$color]);
    json_response($saved + ['value'=>$value,'original'=>$value,'baseHash'=>$hash,
        'edited'=>false,'orderFill'=>$isName ? null : $color]);
}


function workspace_assert_flight_status_unused(int $catalogId, array $entry): void {
    $oldNames = wquery("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(before_value,'$.name'))
        FROM change_history WHERE category='catalog' AND JSON_UNQUOTE(JSON_EXTRACT(before_value,'$.id'))=?",
        [(string)$entry['id']])->fetchAll(PDO::FETCH_COLUMN);
    $names = array_values(array_unique(array_map(static fn(string $name): string=>mb_strtolower(trim(preg_replace('/\s+/u',' ',$name))),array_filter([$entry['name'],...$oldNames],'is_string'))));
    $slots = implode(',',array_fill(0,count($names),'?'));
    $used = (int)wquery("SELECT COUNT(*) FROM records r
        WHERE r.table_id IN (SELECT table_id FROM catalog_bindings WHERE catalog_id=? AND field_key='A') AND (
            EXISTS(SELECT 1 FROM cell_versions v WHERE v.record_id=r.id AND v.field_key='A'
                AND (v.status_id=? OR LOWER(REGEXP_REPLACE(TRIM(v.value),'[[:space:]]+',' ')) IN ($slots)))
            OR EXISTS(SELECT 1 FROM record_references ref WHERE ref.record_id=r.id AND ref.field_key='A' AND ref.entry_id=?)
            OR LOWER(REGEXP_REPLACE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.values_json,'$.A'))),'[[:space:]]+',' ')) IN ($slots)
            OR LOWER(REGEXP_REPLACE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.baseline_json,'$.A'))),'[[:space:]]+',' ')) IN ($slots)
        )",[$catalogId,$entry['id'],...$names,$entry['id'],...$names,...$names])->fetchColumn();
    if ($used) throw new ApiError('entry_in_use','Статус «'.$entry['name'].'» используется. Рейсов с этим статусом — '.$used.'. Удалить его нельзя.',409);
}

function workspace_flight_status_row_action(array $catalog, array $body): never {
    $action = $body['action'];
    if ($action === 'add') {
        if (!is_string($body['name'] ?? null) || !is_string($body['color'] ?? null)) {
            throw new ApiError('invalid_status','Укажите название и цвет.');
        }
        $name = workspace_catalog_validate_name($body['name']);
        $archived = wquery('SELECT id,revision FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=FALSE',
            [$catalog['id'],vitrine_status_key($name)])->fetch();
        $data = ['catalogId'=>$catalog['id'],'name'=>$name,'color'=>$body['color']];
        if ($archived) $data += ['id'=>(int)$archived['id'],'revision'=>(int)$archived['revision']];
        json_response(workspace_catalog_save($data));
    }
    if (!in_array($action,['check_delete','delete'],true) || !is_int($body['row'] ?? null) || !is_int($body['revision'] ?? null)) {
        throw new ApiError('invalid_action','Выберите строку справочника.');
    }
    $entry = wquery('SELECT id,name,color,revision FROM catalog_entries WHERE id=? AND catalog_id=? AND active=TRUE',
        [$body['row'],$catalog['id']])->fetch();
    if (!$entry) throw new ApiError('not_found','Статус рейса не найден.',404);
    if ((int)$entry['revision'] !== $body['revision']) {
        throw new ApiError('revision_conflict','Статус изменился. Обновите страницу и повторите действие.',409);
    }
    if ($action === 'delete') {
        json_response(workspace_catalog_save(['catalogId'=>$catalog['id'],'id'=>(int)$entry['id'],
            'name'=>$entry['name'],'color'=>$entry['color'],'revision'=>(int)$entry['revision'],'active'=>false]));
    }
    workspace_assert_flight_status_unused($catalog['id'],$entry);
    json_response(['id'=>(int)$entry['id'],'name'=>$entry['name']]);
}
