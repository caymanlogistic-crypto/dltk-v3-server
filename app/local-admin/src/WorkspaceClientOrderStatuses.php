<?php
declare(strict_types=1);

function workspace_trip_field_status_directories(): array {
    return [
        'K'=>['directory'=>'unload-color-statuses','title'=>'Статусы выгрузки','label'=>'Статус выгрузки'],
        'M'=>['directory'=>'dropoff-color-statuses','title'=>'Статусы сдачи','label'=>'Статус сдачи'],
        'F'=>['directory'=>'load-date-color-statuses','title'=>'Статусы даты загрузки','label'=>'Статус даты загрузки'],
        'G'=>['directory'=>'load-time-color-statuses','title'=>'Статусы времени загрузки','label'=>'Статус времени загрузки'],
    ];
}

function workspace_order_status_directory(string $directory): ?array {
    if ($directory==='client-order-statuses') return ['title'=>'Статусы клиентов','label'=>'Статус заказа'];
    foreach (workspace_trip_field_status_directories() as $column=>$details) {
        if ($directory===$details['directory']) return $details+['column'=>$column];
    }
    return null;
}

function workspace_trip_field_status_can(array $table,string $column,string $action): bool {
    $details=workspace_trip_field_status_directories()[$column]??null;
    return $table['slug']==='vyvoz' && $details
        && workspace_can('field',$column,$action,$table['id'])
        && workspace_can('system',$details['directory'],$action,$table['id']);
}

function workspace_order_status_require(string $directory,string $action): void {
    $details=workspace_order_status_directory($directory);
    if (isset($details['column'])) {
        if (!workspace_trip_field_status_can(workspace_table('vyvoz'),$details['column'],$action)) {
            throw new ApiError('forbidden','Для этого действия нет доступа.',403);
        }
    } else {
        workspace_require('system',$directory,$action);
    }
}

function workspace_client_order_statuses(bool $refresh = false,string $directory = 'client-order-statuses'): array {
    static $statuses = [];
    if (isset($statuses[$directory]) && !$refresh) return $statuses[$directory];
    $defaults = $directory==='client-order-statuses' ? [
        'preliminary'=>['id'=>1,'label'=>'Предварительный заказ','color'=>workspace_directory_color('Жёлтый')],
        'confirmed'=>['id'=>2,'label'=>'Подтверждённый заказ','color'=>workspace_directory_color('Зелёный')],
    ] : [];
    $saved = workspace_client_catalog_metadata(str_replace('-','_',$directory));
    foreach ($defaults as $code=>$status) $saved[$code] = ($saved[$code] ?? []) + $status;
    foreach ($saved as &$status) $status += ['revision'=>0,'active'=>true];
    unset($status);
    return $statuses[$directory] = $saved;
}

function workspace_active_client_order_statuses(bool $refresh = false,string $directory = 'client-order-statuses'): array {
    $active = array_filter(workspace_client_order_statuses($refresh,$directory),static fn(array $status): bool=>$status['active']);
    return workspace_directory_order_entries($active,$directory);
}

function workspace_client_order_status(string $code,string $directory = 'client-order-statuses'): array {
    if ($code==='') return ['label'=>'','color'=>''];
    return workspace_client_order_statuses(false,$directory)[$code] ?? ['label'=>'','color'=>''];
}

function workspace_client_order_status_can_view(array $table): bool {
    return $table['slug']==='vyvoz' && workspace_can('system','client-order-statuses','view',$table['id']);
}

function workspace_trip_field_color_change(array $table,string $column,array &$values,?string $selected,bool $clear): array {
    $details=workspace_trip_field_status_directories()[$column]??null;
    $none=['changed'=>false,'result'=>[]];
    if ($table['slug']!=='vyvoz' || $details===null || ($selected===null && !$clear)) return $none;
    $visible=workspace_trip_field_status_can($table,$column,'view');
    if (!$clear && !$visible) throw new ApiError('forbidden','Статус поля недоступен.',403);
    $previous=(string)($values['fieldColorStatuses'][$column]??'');
    $next=$clear ? '' : $selected;
    if ($next!=='' && !isset(workspace_active_client_order_statuses(true,$details['directory'])[$next])) {
        throw new ApiError('invalid_order_status','Выберите действующий статус из справочника.');
    }
    $nextStatus=workspace_client_order_status($next,$details['directory']);
    if ($next==='') {
        unset($values['fieldColorStatuses'][$column]);
        if (empty($values['fieldColorStatuses'])) unset($values['fieldColorStatuses']);
    } else $values['fieldColorStatuses'][$column]=$next;
    return [
        'changed'=>$previous!==$next,
        'label'=>$details['label'],
        'before'=>workspace_client_order_status($previous,$details['directory'])['label'],
        'after'=>$nextStatus['label'],
        'result'=>$visible ? ['fieldColorStatus'=>$next,'fieldColorFill'=>$nextStatus['color']] : [],
    ];
}

function workspace_trip_field_color_audit(array $change,array $table,array $row,string $column,string $action,string $request,string $operation): void {
    if (!$change['changed']) return;
    workspace_audit(['tableId'=>$table['id'],'recordId'=>(int)$row['id'],'row'=>(int)$row['row_num'],'column'=>$column,
        'label'=>$change['label'],'before'=>$change['before'],'after'=>$change['after'],
        'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
}

function workspace_save_trip_field_color_only(array $body,array $table,array $row,array $user,string $request,array|false $old,array $values): array {
    $column=$body['column'];
    $change=workspace_trip_field_color_change($table,$column,$values,$body['fieldColorStatus'],false);
    $revision=(int)($old['revision']??0);
    $active=!empty($old['active']);
    if ($change['changed']) {
        ++$revision;
        wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$row['id']]);
        wquery('INSERT INTO cell_versions(record_id,field_key,value,revision,active,status_id) VALUES(?,?,?,?,?,NULL)
            ON DUPLICATE KEY UPDATE revision=VALUES(revision)',
            [$row['id'],$column,workspace_string($values[$column]??''),$revision,(int)$active]);
        $operation=bin2hex(random_bytes(16));
        workspace_trip_field_color_audit($change,$table,$row,$column,'edit',$request,$operation);
        workspace_event('cell.changed',['tableSlug'=>$table['slug'],'row'=>(int)$row['row_num'],'column'=>$column,
            'value'=>$body['currentValue'],'revision'=>$revision,'edited'=>$active,
            'original'=>$body['original'],'baseHash'=>hash('sha256',$row['baseline_json']),
            'actorId'=>$user['id'],'actorName'=>$user['name'],'action'=>'edit','requestId'=>$request,
            'operationId'=>$operation,'fieldColorOnly'=>true]+$change['result']);
    }
    return ['revision'=>$revision,'value'=>$body['currentValue'],'edited'=>$active,
        'requestId'=>$request,'fieldColorOnly'=>true]+$change['result'];
}

function workspace_order_status_hash(string $directory,string $code): string {
    return hash('sha256',($directory==='client-order-statuses' ? 'client-order-status' : $directory).':'.$code);
}

function workspace_order_status_usage(string $directory,string $code): int {
    $column=workspace_order_status_directory($directory)['column']??null;
    $path=$column===null ? '$.clientOrderStatus' : '$.fieldColorStatuses.'.$column;
    return (int)wquery('SELECT COUNT(*) FROM records WHERE JSON_UNQUOTE(JSON_EXTRACT(values_json,?))=?
        OR JSON_UNQUOTE(JSON_EXTRACT(baseline_json,?))=?',[$path,$code,$path,$code])->fetchColumn();
}

function workspace_order_status_row_action(array $body,string $directory = 'client-order-statuses'): array {
    workspace_order_status_require($directory,'view');
    workspace_order_status_require($directory,'edit');
    $action = $body['action'];
    workspace_begin();
    try {
        $statuses = workspace_client_order_statuses(true,$directory);
        $before = null;
        if ($action === 'add') {
            $label = workspace_catalog_validate_name($body['values']['A'] ?? '');
            $color = workspace_directory_color($body['values']['B'] ?? '');
            workspace_assert_unique_order_status($statuses,$label);
            $id = max([0,...array_column($statuses,'id')])+1;
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
                $count = workspace_order_status_usage($directory,$code);
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
        workspace_write_order_statuses($statuses,$directory);
        workspace_audit(['category'=>'catalog','label'=>workspace_order_status_directory($directory)['title'],'before'=>$before,
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

function workspace_write_order_statuses(array $statuses,string $directory = 'client-order-statuses'): void {
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
        [str_replace('-','_',$directory),wjson($statuses)]);
}

function workspace_client_order_status_grid_endpoint(bool $pasteCheck = false,string $directory = 'client-order-statuses'): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    json_response(workspace_client_order_status_grid_edit(wbody(),$pasteCheck,$directory));
}

function workspace_client_order_status_grid_edit(array $body, bool $pasteCheck = false,string $directory = 'client-order-statuses'): array {
    workspace_order_status_require($directory,'view');
    workspace_order_status_require($directory,'edit');
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null,['A','B'],true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit','Некорректные данные статуса заказа.');
    }
    $column = $body['column'];
    $value = $column === 'A' ? workspace_catalog_validate_name($body['value']) : trim($body['value']);
    $newValue = $column === 'A' ? $value : workspace_directory_color($value);
    if ($pasteCheck) return ['accepted'=>true];

    return workspace_transaction(static function() use ($body,$column,$value,$newValue,$directory) {
        $statuses = workspace_client_order_statuses(true,$directory);
        $code = null;
        foreach ($statuses as $key=>$item) if ($item['id'] === $body['row'] && $item['active']) $code = $key;
        if ($code === null) throw new ApiError('not_found','Активный статус не найден.',404);
        if ($column === 'A') workspace_assert_unique_order_status($statuses,$value,$code);
        $status = $statuses[$code];
        $baseHash = workspace_order_status_hash($directory,$code);
        if (($body['revision'] ?? null) !== $status['revision'] || ($body['baseHash'] ?? null) !== $baseHash) workspace_directory_conflict();
        $field = $column === 'A' ? 'label' : 'color';
        if ($status[$field] !== $newValue) {
            workspace_audit(['category'=>'catalog','column'=>$column,'label'=>workspace_order_status_directory($directory)['title'],
                'before'=>$status[$field],'after'=>$newValue,'action'=>'edit']);
            $status[$field] = $newValue;
            $status['revision']++;
            $statuses[$code] = $status;
            workspace_write_order_statuses($statuses,$directory);
        }

        return ['value'=>$value,'original'=>$value,'revision'=>$status['revision'],'baseHash'=>$baseHash,
            'edited'=>false,'orderFill'=>$column==='B' ? $status['color'] : null];
    });
}
