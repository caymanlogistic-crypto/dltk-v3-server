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
