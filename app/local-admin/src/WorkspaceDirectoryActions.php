<?php
declare(strict_types=1);

function workspace_directory_rows_endpoint(string $directory, array $body): never {
    $action = $body['action'];
    if (!in_array($action,['add','check_delete','delete','archive','restore'],true) &&
        !($directory==='employees' && $action==='set_access')) {
        throw new ApiError('invalid_action','Действие со строкой не поддерживается.');
    }
    if ($action==='delete') $body['reason']=workspace_row_delete_reason($body['reason']??null);
    else unset($body['reason']);
    if ($action === 'add') {
        $values = $body['values'] ?? null;
        if (!is_array($values) || array_filter($values,static fn($value)=>!is_string($value))) {
            throw new ApiError('invalid_row','Проверьте поля новой строки.');
        }
        $body['values'] = $values;
    } elseif (!is_int($body['row'] ?? null) || $body['row'] < 1 || !is_int($body['revision'] ?? null)) {
        throw new ApiError('invalid_row','Выберите сохранённую строку.');
    }
    $result = workspace_order_status_directory($directory)!==null ? workspace_order_status_row_action($body,$directory) : match ($directory) {
        'employees' => workspace_employee_row_action($body),
        'roles' => workspace_role_row_action($body),
        default => workspace_catalog_row_action($directory,$body),
    };
    json_response($result);
}

function workspace_row_delete_reason(mixed $value): string {
    if ($value === null) return '';
    if (!is_string($value) || mb_strlen(trim($value))>1000) {
        throw new ApiError('invalid_delete_reason','Комментарий к удалению должен быть не длиннее 1000 символов.');
    }
    $reason=trim($value);
    return preg_match('/\S/u',$reason) ? $reason : '';
}

function workspace_row_deleted(string $prefix,int $id): bool {
    return (bool)wquery('SELECT 1 FROM workspace_meta WHERE `key`=?',[$prefix.$id])->fetchColumn();
}

function workspace_mark_row_deleted(string $prefix,int $id): void {
    wquery('INSERT INTO workspace_meta (`key`,value) VALUES(?,?)',[$prefix.$id,'true']);
}

function workspace_directory_color(string $name): string {
    $fill = array_column(workspace_palette(),'fill','name')[$name] ?? null;
    if (!$fill) throw new ApiError('invalid_color','Выберите цвет из системной палитры.');
    return $fill;
}

function workspace_catalog_row_action(string $directory, array $body): array {
    if ($directory === 'flight-statuses') {
        [$table,$catalog] = workspace_flight_status_grid();
        $catalogId = $catalog['id'];
        workspace_require('catalog',(string)$catalogId,'edit',$table['id']);
        $kind = 'status';
    } else {
        $model = workspace_directory_grid_model($directory);
        $catalogId = $model['catalogId'];
        workspace_require('catalog',(string)$catalogId,'edit');
        $kind = $model['catalogKind'] ?? 'clients';
    }
    if (!$catalogId) throw new ApiError('not_found','Справочник не найден.',404);
    $action = $body['action'];
    if ($action === 'add') {
        $values = $body['values'];
        if ($kind==='unload-points') return workspace_unload_save_point($catalogId,$values);
        if ($kind==='managers') return workspace_manager_save($catalogId,$values);
        if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) return workspace_fleet_save($kind,$catalogId,$values);
        $name = workspace_catalog_validate_name($values[$kind==='edo-operators' ? 'B' : 'A'] ?? '');
        $payload = ['catalogId'=>$catalogId,'name'=>$name];
        if ($kind==='margin-rules') {
            $payload['name']=workspace_margin_rule_date($values['A']??'');
            $payload['marginRule']=$values;
            $name=$payload['name'];
        }
        if ($kind==='edo-counterparties') $payload['edoParty']=workspace_edo_party_profile($values);
        if ($kind==='edo-operators') $payload['operatorCode']=workspace_edo_operator_code($values['A']??'');
        if ($kind==='dropoff-points') $payload['dropoffProfile']=workspace_dropoff_profile($values);
        if ($kind==='container-types') $payload['containerRule']=workspace_container_type_input($values,workspace_container_type_profile($name));
        if (workspace_catalog_has_palette($kind)) {
            $color=$values['B']??'';
            $payload['color']=workspace_is_document_status_catalog($kind) && $color==='' ? null : workspace_directory_color($color);
        }
        if ($kind === 'clients') {
            if (trim($values['B']??'')!=='') $payload['manager'] = $values['B'];
            $payload['clientRequisites']=array_filter(
                array_intersect_key($values,WORKSPACE_CLIENT_REQUISITE_LABELS),
                static fn(string $value): bool=>trim($value)!==''
            );
        }
        if ($kind==='carriers') {
            $payload['carrierDetails']=workspace_carrier_details(['B'=>$values['B']??'','edoId'=>$values['C']??'']);
            $payload['logist']=$values['E']??'';
        }
        $previous = wquery('SELECT id,revision FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=FALSE',
            [$catalogId,$kind==='edo-counterparties' ? workspace_edo_party_key($name,$payload['edoParty']) : vitrine_status_key($payload['operatorCode']??$name)])->fetch();
        if ($previous) $payload += ['id'=>(int)$previous['id'],'revision'=>(int)$previous['revision']];
        return workspace_catalog_save($payload);
    }
    $entry = wquery('SELECT * FROM catalog_entries WHERE catalog_id=? AND id=?',[$catalogId,$body['row']])->fetch();
    $deletion=in_array($action,['delete','check_delete'],true);
    if (!$entry || workspace_row_deleted('catalog_deleted_client_',(int)$entry['id']) ||
        (!$deletion && (bool)$entry['active'] === ($action === 'restore'))) {
        throw new ApiError('not_found','Запись справочника не найдена.',404);
    }
    if ((int)$entry['revision'] !== $body['revision']) workspace_directory_conflict();
    if ($action === 'check_delete') {
        workspace_assert_catalog_entry_unused($kind,$catalogId,$entry);
        return ['name'=>$entry['name']];
    }
    return workspace_catalog_save(['catalogId'=>$catalogId,'id'=>$body['row'],'revision'=>$body['revision'],
        'name'=>$entry['name'],'color'=>$entry['color'],'active'=>$action === 'restore','archive'=>$action==='archive'],$body['reason']??null);
}

function workspace_directory_conflict(): never {
    throw new ApiError('revision_conflict','Запись изменилась. Обновите страницу и повторите действие.',409);
}

function workspace_assert_catalog_entry_unused(string $kind, int $catalogId, array $entry): void {
    if ($kind==='unload-points') {
        workspace_assert_unload_point_unused((int)$entry['id'],$entry['name']);
        return;
    }
    if ($kind === 'status') {
        workspace_assert_flight_status_unused($catalogId,$entry);
        return;
    }
    if ($kind==='edo-operators') {
        workspace_edo_operator_assert_unused($entry['normalized']);
        return;
    }
    $id = (int)$entry['id'];
    if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) workspace_fleet_assert_unused($kind,$entry);
    if ($kind==='managers') {
        $clients=(int)wquery('SELECT COUNT(*) FROM client_manager_links WHERE manager_id=?',[$id])->fetchColumn();
        if ($clients) throw new ApiError('entry_in_use','Менеджер назначен у клиентов: '.$clients.'. Удалить его нельзя.',409);
    }
    if ($kind==='clients' && workspace_unload_catalog_id()) {
        $points=(int)wquery('SELECT COUNT(*) FROM unload_points WHERE client_id=?',[$id])->fetchColumn();
        if ($points) throw new ApiError('entry_in_use','У клиента есть точки выгрузки: '.$points.'. Удалить его нельзя.',409);
    }
    $names = [$entry['normalized']];
    foreach (workspace_client_catalog_aliases($catalogId) as $alias=>$owner) {
        if ((int)$owner === $id) $names[] = $alias;
    }
    $placeholders = implode(',',array_fill(0,count($names),'?'));
    $count = (int)wquery("SELECT COUNT(*) FROM records r WHERE
        EXISTS(SELECT 1 FROM record_references ref WHERE ref.record_id=r.id AND ref.entry_id=?)
        OR EXISTS(SELECT 1 FROM catalog_bindings b WHERE b.catalog_id=? AND b.table_id=r.table_id
          AND REGEXP_LIKE(b.field_key,'^[A-Z]{1,8}$') AND (
            LOWER(REGEXP_REPLACE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.values_json,CONCAT('$.',b.field_key)))),'[[:space:]]+',' ')) IN ($placeholders)
            OR LOWER(REGEXP_REPLACE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(r.baseline_json,CONCAT('$.',b.field_key)))),'[[:space:]]+',' ')) IN ($placeholders)
          ))",[$id,$catalogId,...$names,...$names])->fetchColumn();
    if ($count) throw new ApiError('entry_in_use','Запись «'.$entry['name'].'» используется. Связанных строк — '.$count.'. Удалить её нельзя.',409);
}

function workspace_employee_row_action(array $body): array {
    if ($body['action']==='set_access') return workspace_employee_set_access($body);
    if ($body['action'] === 'add') {
        $scope = workspace_employee_credential_scope();
        if ($scope === null) throw new ApiError('forbidden','Создание учётных записей недоступно.',403);
        $values = $body['values'];
        $role = array_column(workspace_employee_role_options($scope),'id','name')[$values['E'] ?? ''] ?? null;
        if (!$role) throw new ApiError('invalid_role','Выберите роль из доступного списка.',403);
        return workspace_employee_save(['name'=>$values['A'] ?? '', 'login'=>$values['B'] ?? '',
            'password'=>$values['C'] ?? '', 'roleIds'=>[$role]]);
    }
    $scope = workspace_employee_credential_scope();
    if ($scope === null) throw new ApiError('forbidden','Для этого действия нет доступа.',403);
    $employee = workspace_employee_records($body['row'])[0] ?? null;
    $deletion = in_array($body['action'],['delete','check_delete'],true);
    if (!$employee || !($deletion ? workspace_employee_can_delete($scope,$employee) : workspace_employee_can_edit($scope,$employee))) {
        throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
    }
    if ($employee['authVersion'] !== $body['revision'] || workspace_employee_revision($employee) !== ($body['baseHash'] ?? null)) {
        workspace_directory_conflict();
    }
    $restore = $body['action'] === 'restore';
    if (!$deletion && $employee['active'] === $restore) workspace_directory_conflict();
    if ($body['action'] === 'check_delete') {
        workspace_assert_employee_unused($employee);
        return ['name'=>$employee['name']];
    }
    $payload = ['id'=>$employee['id'],'revision'=>$body['baseHash'],'active'=>$restore];
    if ($body['action'] === 'delete') return workspace_employee_save($payload,deleteReason:$body['reason']);
    return workspace_employee_save($payload+['name'=>$employee['name'],'login'=>$employee['login']]);
}

function workspace_assert_employee_unused(array $employee): void {
    if ($employee['id'] === workspace_current_user()['id']) {
        throw new ApiError('entry_in_use','Свою учётную запись нельзя отправить в архив.',409);
    }
    $names = array_map('vitrine_status_key',[$employee['name'],$employee['login']]);
    $accounts=workspace_client_account_managers_enabled();
    $count=$accounts ? (int)wquery("SELECT COUNT(*) FROM client_account_manager_links l WHERE l.account_id=?
        AND NOT EXISTS (SELECT 1 FROM workspace_meta m WHERE m.key=CONCAT('catalog_deleted_client_',l.client_id))",
        [$employee['id']])->fetchColumn() : 0;
    foreach (wquery("SELECT id,kind FROM catalogs WHERE kind IN ('clients','carriers')")->fetchAll() as $catalog) {
        $catalogId=(int)$catalog['id'];
        if ($catalog['kind']==='carriers') {
            foreach (workspace_carrier_logist_accounts($catalogId) as $carrierId=>$accountId) {
                if ((int)$accountId===$employee['id'] && !workspace_row_deleted('catalog_deleted_client_',(int)$carrierId)) $count++;
            }
        } elseif (!$accounts) {
            foreach (workspace_client_manager_values($catalogId) as $name) {
                if (is_string($name) && in_array(vitrine_status_key($name),$names,true)) $count++;
            }
        }
    }
    if ($count) throw new ApiError('entry_in_use','Сотрудник указан в справочниках клиентов или перевозчиков. Назначений — '.$count.'. Удалить его нельзя.',409);
    $records = (int)wquery("SELECT COUNT(*) FROM records WHERE
        LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.AL')))) IN (?,?)
        OR LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.AM')))) IN (?,?)",
        [...$names,...$names])->fetchColumn();
    if ($records) throw new ApiError('entry_in_use','Сотрудник указан в рейсах. Связанных строк — '.$records.'. Удалить его нельзя.',409);
}
