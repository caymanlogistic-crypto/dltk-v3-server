<?php
declare(strict_types=1);

function workspace_client_order_statuses(bool $refresh = false): array {
    static $statuses;
    if (isset($statuses) && !$refresh) return $statuses;
    $defaults = [
        'preliminary'=>['id'=>1,'label'=>'Предварительный заказ','color'=>'#F7F2D2'],
        'confirmed'=>['id'=>2,'label'=>'Подтверждённый заказ','color'=>'#DCEAD4'],
    ];
    $saved = workspace_client_catalog_metadata('client_order_statuses');
    foreach ($defaults as $code=>&$status) {
        $status = ($saved[$code] ?? []) + $status + ['revision'=>0];
    }
    unset($status);
    return $statuses = $defaults;
}

function workspace_client_order_status(string $code): array {
    return workspace_client_order_statuses()[$code] ?? ['label'=>'','color'=>''];
}

function workspace_client_order_status_can_view(array $table): bool {
    return $table['slug']==='vyvoz' && workspace_can('system','client-order-statuses','view',$table['id']);
}


function workspace_client_order_status_grid_endpoint(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    workspace_require('system','client-order-statuses','view');
    workspace_require('system','client-order-statuses','edit');
    $body = wbody();
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null,['A','B'],true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit','Некорректные данные статуса заказа.');
    }
    $column = $body['column'];
    $palette = array_column(workspace_palette(),'fill','name');
    $value = $column === 'A' ? workspace_catalog_validate_name($body['value']) : trim($body['value']);
    if ($column === 'B' && !isset($palette[$value])) throw new ApiError('invalid_color','Выберите цвет из системной палитры.');
    if ($pasteCheck) json_response(['accepted'=>true]);

    workspace_begin();
    try {
        $statuses = workspace_client_order_statuses(true);
        $code = null;
        foreach ($statuses as $key=>$item) {
            if ($item['id'] === $body['row']) $code = $key;
            elseif ($column === 'A' && mb_strtolower($item['label']) === mb_strtolower($value)) {
                throw new ApiError('duplicate_status','Такой статус уже есть.',409);
            }
        }
        if (!is_string($code)) throw new ApiError('not_found','Статус не найден.',404);
        $status = $statuses[$code];
        $baseHash = hash('sha256','client-order-status:'.$code);
        if (($body['revision'] ?? null) !== $status['revision'] || ($body['baseHash'] ?? null) !== $baseHash) {
            throw new ApiError('revision_conflict','Статус изменился. Обновите страницу и повторите сохранение.',409);
        }
        $field = $column === 'A' ? 'label' : 'color';
        $newValue = $column === 'A' ? $value : $palette[$value];
        if ($status[$field] !== $newValue) {
            workspace_audit(['category'=>'catalog','column'=>$column,'label'=>'Статусы клиентов',
                'before'=>$status[$field],'after'=>$newValue,'action'=>'edit']);
            $status[$field] = $newValue;
            $status['revision']++;
            $statuses[$code] = $status;
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['client_order_statuses',wjson($statuses)]);
        }
        workspace_db()->commit();
        json_response(['value'=>$value,'original'=>$value,'revision'=>$status['revision'],'baseHash'=>$baseHash,
            'edited'=>false,'orderFill'=>$column==='B' ? $status['color'] : null]);
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
