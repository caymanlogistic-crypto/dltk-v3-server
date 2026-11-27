<?php
declare(strict_types=1);

function workspace_client_order_statuses(bool $refresh = false): array {
    static $statuses;
    if (isset($statuses) && !$refresh) return $statuses;
    $defaults = [
        'preliminary'=>['id'=>1,'label'=>'Предварительный заказ','color'=>workspace_directory_color('Жёлтый')],
        'confirmed'=>['id'=>2,'label'=>'Подтверждённый заказ','color'=>workspace_directory_color('Зелёный')],
    ];
    $saved = workspace_client_catalog_metadata('client_order_statuses');
    foreach ($defaults as $code=>$status) $saved[$code] = ($saved[$code] ?? []) + $status;
    foreach ($saved as &$status) $status += ['revision'=>0,'active'=>true];
    unset($status);
    return $statuses = $saved;
}

function workspace_active_client_order_statuses(bool $refresh = false): array {
    $active = array_filter(workspace_client_order_statuses($refresh),static fn(array $status): bool=>$status['active']);
    return workspace_directory_order_entries($active,'client-order-statuses');
}

function workspace_client_order_status(string $code): array {
    return workspace_client_order_statuses()[$code] ?? ['label'=>'','color'=>''];
}

function workspace_client_order_status_can_view(array $table): bool {
    return $table['slug']==='vyvoz' && workspace_can('system','client-order-statuses','view',$table['id']);
}

function workspace_order_status_row_action(array $body): array {
    workspace_require('system','client-order-statuses','view');
    workspace_require('system','client-order-statuses','edit');
    $action = $body['action'];
    workspace_begin();
    try {
        $statuses = workspace_client_order_statuses(true);
        $before = null;
        if ($action === 'add') {
            $label = workspace_catalog_validate_name($body['values']['A'] ?? '');
            $color = workspace_directory_color($body['values']['B'] ?? '');
            workspace_assert_unique_order_status($statuses,$label);
            $id = max(array_column($statuses,'id'))+1;
            $code = 'custom-'.$id;
            $after = ['id'=>$id,'label'=>$label,'color'=>$color,'revision'=>1,'active'=>true];
        } else {
            $code = null;
            foreach ($statuses as $key=>$status) if ($status['id'] === $body['row']) $code = $key;
            if ($code === null) throw new ApiError('not_found','Статус не найден.',404);
            $before = $statuses[$code];
            if (!empty($before['deleted'])) throw new ApiError('not_found','Статус удалён.',404);
            $restore = $action === 'restore';
            if ((!in_array($action,['delete','check_delete'],true) && $before['active'] === $restore) ||
                $before['revision'] !== $body['revision']) workspace_directory_conflict();
            if (!$restore) {
                $count = (int)wquery("SELECT COUNT(*) FROM records WHERE
                    JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.clientOrderStatus'))=?
                    OR JSON_UNQUOTE(JSON_EXTRACT(baseline_json,'$.clientOrderStatus'))=?",[$code,$code])->fetchColumn();
                if ($count) throw new ApiError('entry_in_use','Статус «'.$before['label'].'» используется. Связанных заказов — '.$count.'. Удалить его нельзя.',409);
            } else workspace_assert_unique_order_status($statuses,$before['label'],$code);
            if ($action === 'check_delete') {
                workspace_db()->commit();
                return ['name'=>$before['label']];
            }
            $after = array_replace($before,['active'=>$restore,'revision'=>$before['revision']+1]);
            if ($action==='delete') $after['deleted']=true;
        }
        $statuses[$code] = $after;
        workspace_write_order_statuses($statuses);
        workspace_audit(['category'=>'catalog','label'=>'Статусы клиентов','before'=>$before,
            'after'=>$after+($action==='delete' ? ['reason'=>$body['reason']] : []),
            'action'=>match($action) {'add'=>'create','restore'=>'restore','delete'=>'delete',default=>'archive'}]);
        workspace_db()->commit();
        return ['id'=>$after['id'],'revision'=>$after['revision']];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}

function workspace_assert_unique_order_status(array $statuses,string $label,?string $except = null): void {
    foreach ($statuses as $code=>$status) {
        if ($code !== $except && $status['active'] && vitrine_status_key($status['label']) === vitrine_status_key($label)) {
            throw new ApiError('duplicate_status','Такой статус уже есть.',409);
        }
    }
}

function workspace_write_order_statuses(array $statuses): void {
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
        ['client_order_statuses',wjson($statuses)]);
}

function workspace_client_order_status_grid_endpoint(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    json_response(workspace_client_order_status_grid_edit(wbody(),$pasteCheck));
}

function workspace_client_order_status_grid_edit(array $body, bool $pasteCheck = false): array {
    workspace_require('system','client-order-statuses','view');
    workspace_require('system','client-order-statuses','edit');
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null,['A','B'],true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit','Некорректные данные статуса заказа.');
    }
    $column = $body['column'];
    $value = $column === 'A' ? workspace_catalog_validate_name($body['value']) : trim($body['value']);
    $newValue = $column === 'A' ? $value : workspace_directory_color($value);
    if ($pasteCheck) return ['accepted'=>true];

    return workspace_transaction(static function() use ($body,$column,$value,$newValue) {
        $statuses = workspace_client_order_statuses(true);
        $code = null;
        foreach ($statuses as $key=>$item) if ($item['id'] === $body['row'] && $item['active']) $code = $key;
        if ($code === null) throw new ApiError('not_found','Активный статус не найден.',404);
        if ($column === 'A') workspace_assert_unique_order_status($statuses,$value,$code);
        $status = $statuses[$code];
        $baseHash = hash('sha256','client-order-status:'.$code);
        if (($body['revision'] ?? null) !== $status['revision'] || ($body['baseHash'] ?? null) !== $baseHash) workspace_directory_conflict();
        $field = $column === 'A' ? 'label' : 'color';
        if ($status[$field] !== $newValue) {
            workspace_audit(['category'=>'catalog','column'=>$column,'label'=>'Статусы клиентов',
                'before'=>$status[$field],'after'=>$newValue,'action'=>'edit']);
            $status[$field] = $newValue;
            $status['revision']++;
            $statuses[$code] = $status;
            workspace_write_order_statuses($statuses);
        }

        return ['value'=>$value,'original'=>$value,'revision'=>$status['revision'],'baseHash'=>$baseHash,
            'edited'=>false,'orderFill'=>$column==='B' ? $status['color'] : null];
    });
}
