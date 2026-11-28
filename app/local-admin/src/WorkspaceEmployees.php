<?php
declare(strict_types=1);

function workspace_account_short_name(string $name): string {
    preg_match('/ · №[0-9]+$/uD',$name,$match);
    $suffix=$match[0]??'';
    $parts=preg_split('/\s+/u',trim(substr($name,0,strlen($name)-strlen($suffix))),-1,PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts)<2 || count($parts)>3) return $name;
    foreach ($parts as $part) {
        if (!preg_match("/^\\p{L}+(?:[-'’]\\p{L}+)*$/uD",$part)) return $name;
    }
    return $parts[1].' '.mb_strtoupper(mb_substr($parts[0],0,1)).'.'.$suffix;
}

function workspace_account_short_names(string $names): string {
    return implode(', ',array_map('workspace_account_short_name',explode(', ',$names)));
}

const WORKSPACE_PRIVATE_EMPLOYEE_ROLES = ['superadmin', 'admin', 'directory_analytics'];
const WORKSPACE_TEAM_EMPLOYEE_ROLES = ['manager','directory_senior_manager','logist','directory_senior_logist'];
const WORKSPACE_EMPLOYEE_ACCESS_KEY = 'employee_access_paused_';
const WORKSPACE_EMPLOYEE_ACCESS_PAUSED_SQL =
    "EXISTS (SELECT 1 FROM workspace_meta p WHERE p.`key`=CONCAT('".WORKSPACE_EMPLOYEE_ACCESS_KEY."',u.id))";

function workspace_employee_role_names(): array {
    return [
        'superadmin'=>'Суперадмин', 'admin'=>'Админ', 'manager'=>'Менеджер',
        'logist'=>'Логист', 'dispatcher'=>'Диспетчер',
    ];
}

function workspace_employee_roles(bool $archive = false, bool $includeAll = false): array {
    $knownNames = workspace_employee_role_names();
    $archived = workspace_client_catalog_metadata('directory_archived_roles');
    $roles = [];
    foreach (wquery("SELECT id,code,name FROM roles r
        WHERE NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('directory_deleted_role_',r.id))
        ORDER BY id")->fetchAll() as $role) {
        $id = (int)$role['id'];
        $code = $role['code'];
        if (!$includeAll && !isset($knownNames[$code]) && !str_starts_with($code,'directory_')) continue;
        if (isset($archived[$id]) !== $archive) continue;
        $roles[] = ['id'=>$id,'code'=>$code,'name'=>$knownNames[$code] ?? $role['name']];
    }
    return $archive ? $roles : array_values(workspace_directory_order_entries($roles,'roles'));
}

function workspace_employee_records(?int $id = null, ?bool $active = null): array {
    $sql = "SELECT u.id,u.name,u.login,u.active,u.auth_version,".WORKSPACE_EMPLOYEE_ACCESS_PAUSED_SQL." AS access_paused,
            r.id AS role_id,r.code,r.name AS role_name
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id";
    $where = ["NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('directory_deleted_user_',u.id))"];
    $args = [];
    if ($id !== null) { $where[] = 'u.id=?'; $args[] = $id; }
    if ($active !== null) { $where[] = 'u.active=?'; $args[] = (int)$active; }
    if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
    $names = workspace_employee_role_names();
    $employees = [];
    foreach (wquery($sql.' ORDER BY u.name,u.id,r.id',$args)->fetchAll() as $row) {
        $userId = (int)$row['id'];
        $employees[$userId] ??= [
            'id'=>$userId, 'name'=>$row['name'], 'login'=>$row['login'],
            'active'=>(bool)$row['active'], 'accessPaused'=>(bool)$row['access_paused'],
            'authVersion'=>(int)$row['auth_version'], 'roles'=>[],
        ];
        if ($row['role_id'] !== null) $employees[$userId]['roles'][] = [
            'id'=>(int)$row['role_id'], 'code'=>$row['code'], 'name'=>$names[$row['code']] ?? $row['role_name'],
        ];
    }
    return array_values($employees);
}

function workspace_employee_view_scope(): ?string {
    if (!workspace_can('system','users','view')) return null;
    $viewer = workspace_current_user();
    $actor = workspace_employee_records($viewer['id'])[0] ?? null;
    if (!$actor || !$actor['active'] || $actor['accessPaused'] || $actor['authVersion'] !== (int)$viewer['auth_version']) return null;
    $codes = array_column($actor['roles'],'code');
    foreach (['superadmin','directory_analytics','admin','directory_senior_manager','directory_senior_logist'] as $scope) {
        if (in_array($scope,$codes,true)) return $scope;
    }
    return null;
}

function workspace_employee_require_view_scope(): string {
    return workspace_employee_view_scope() ?? throw new ApiError('forbidden','Учётные записи недоступны для вашей роли.',403);
}

function workspace_employee_credential_scope(): ?string {
    return workspace_can('system','users','edit') ? workspace_employee_view_scope() : null;
}

function workspace_employee_editor_scope(): ?string {
    $scope = workspace_employee_credential_scope();
    return in_array($scope,['superadmin','admin'],true) ? $scope : null;
}

function workspace_employee_can_manage_dispatchers(?string $scope): bool {
    return in_array($scope,['directory_senior_manager','directory_senior_logist'],true);
}

function workspace_employee_can_reorder(?string $scope): bool {
    return in_array($scope,['superadmin','admin'],true) || workspace_employee_can_manage_dispatchers($scope);
}

function workspace_employee_is_dispatcher(array $employee): bool {
    return array_column($employee['roles'],'code') === ['dispatcher'];
}

function workspace_employee_can_view(string $scope, array $employee): bool {
    if (in_array($scope,['superadmin','directory_analytics'],true) || $employee['id']===workspace_current_user()['id']) return true;
    if (workspace_employee_can_manage_dispatchers($scope) && workspace_employee_is_dispatcher($employee)) return true;
    $allowed = match ($scope) {
        'admin' => WORKSPACE_TEAM_EMPLOYEE_ROLES,
        'directory_senior_manager' => ['manager'],
        'directory_senior_logist' => ['logist'],
        default => [],
    };
    $roles = array_column($employee['roles'],'code');
    return $roles && !array_diff($roles,$allowed);
}

function workspace_employee_can_edit_field(string $scope, string $field, array $employee): bool {
    return match ($scope) {
        'superadmin','admin' => true,
        'directory_analytics' => $field==='password',
        'directory_senior_manager','directory_senior_logist' =>
            !workspace_employee_is_dispatcher($employee) && in_array($field,['login','password'],true),
        default => false,
    };
}

function workspace_employee_is_private(array $employee): bool {
    return (bool)array_intersect(WORKSPACE_PRIVATE_EMPLOYEE_ROLES,array_column($employee['roles'],'code'));
}

function workspace_employee_visible_records(?bool $active = null): array {
    $scope = workspace_employee_require_view_scope();
    return array_values(array_filter(workspace_employee_records(active:$active),
        static fn(array $employee): bool => workspace_employee_can_view($scope,$employee)));
}

function workspace_employee_can_edit(string $scope, array $employee): bool {
    return in_array($scope,['superadmin','admin'],true) && workspace_employee_can_view($scope,$employee);
}

function workspace_employee_can_delete(string $scope, array $employee): bool {
    return workspace_employee_can_edit($scope,$employee) ||
        (workspace_employee_can_manage_dispatchers($scope) && workspace_employee_is_dispatcher($employee));
}

function workspace_employee_can_set_access(?string $scope, array $employee): bool {
    return $scope!==null && $employee['active'] && $employee['id']!==workspace_current_user()['id'] &&
        workspace_employee_can_edit($scope,$employee);
}

function workspace_employee_set_access(array $input): array {
    if (!is_bool($input['paused']??null)) throw new ApiError('invalid_user','Укажите состояние доступа.');
    return workspace_transaction(static function () use ($input): array {
        $scope = workspace_employee_credential_scope();
        $employee = workspace_employee_records($input['row'])[0] ?? null;
        if (!$employee || !workspace_employee_can_set_access($scope,$employee)) {
            throw new ApiError('forbidden','Изменение доступа для этой учётной записи недоступно.',403);
        }
        if ($employee['authVersion']!==$input['revision'] || workspace_employee_revision($employee)!==($input['baseHash']??null)) {
            workspace_directory_conflict();
        }
        $paused = $input['paused'];
        if ($employee['accessPaused']!==$paused) {
            $key = WORKSPACE_EMPLOYEE_ACCESS_KEY.$employee['id'];
            if ($paused) wquery("INSERT INTO workspace_meta VALUES(?,'true')",[$key]);
            else wquery('DELETE FROM workspace_meta WHERE `key`=?',[$key]);
            wquery('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$employee['id']]);
            wquery('DELETE FROM ws_tickets WHERE user_id=? AND consumed_at IS NULL',[$employee['id']]);
            workspace_audit(['category'=>'management','label'=>'Сотрудник','action'=>'edit',
                'before'=>['id'=>$employee['id'],'accessPaused'=>$employee['accessPaused']],
                'after'=>['id'=>$employee['id'],'accessPaused'=>$paused]]);
            workspace_event('users.changed',['id'=>$employee['id']]);
            $employee['accessPaused']=$paused;
            ++$employee['authVersion'];
        }
        return ['paused'=>$paused,'revision'=>$employee['authVersion'],'baseHash'=>workspace_employee_revision($employee)];
    });
}

function workspace_employee_history_filter(): string {
    $scope = workspace_employee_view_scope();
    if (in_array($scope,['superadmin','directory_analytics'],true)) return '1=1';
    $otherHistory = "NOT (h.category='management' AND COALESCE(h.column_label,'')='Сотрудник')";
    if ($scope === null) return $otherHistory;
    $ids = implode(',',array_column(workspace_employee_visible_records(),'id'));
    return $ids === '' ? $otherHistory :
        "($otherHistory OR CAST(JSON_UNQUOTE(JSON_EXTRACT(h.after_value,'$.id')) AS UNSIGNED) IN ($ids))";
}

function workspace_employee_role_options(?string $scope): array {
    $allowed = match ($scope) {
        'superadmin','directory_analytics' => null,
        'admin' => WORKSPACE_TEAM_EMPLOYEE_ROLES,
        'directory_senior_manager' => ['manager','directory_senior_manager','dispatcher'],
        'directory_senior_logist' => ['logist','directory_senior_logist','dispatcher'],
        default => [],
    };
    if ($allowed === null) return workspace_employee_roles(includeAll:true);
    if ($allowed === []) return [];
    return array_values(array_filter(workspace_employee_roles(),
        static fn(array $role): bool => in_array($role['code'],$allowed,true)));
}

function workspace_employee_revision(array $employee): string {
    return hash('sha256',wjson($employee));
}

function workspace_employee_form_data(array $employee): array {
    $data = ['revision'=>workspace_employee_revision($employee)] + $employee;
    unset($data['authVersion']);
    return $data;
}

function workspace_employees_endpoint(string $method): never {
    if ($method === 'GET' && !isset($_GET['id'])) {
        $ids = array_column(workspace_employee_visible_records(),'id');
        sort($ids,SORT_NUMERIC);
        $users = array_map('workspace_user',$ids);
        $roles = array_map(static fn(array $role): array=>['id'=>$role['id'],'name'=>$role['name']],
            workspace_employee_role_options(workspace_employee_credential_scope()));
        json_response(['users'=>$users, 'roles'=>$roles]);
    }

    if ($method === 'GET') {
        $scope = workspace_employee_require_view_scope();
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $employee = $id ? (workspace_employee_records($id)[0] ?? null) : null;
        if (!$employee) throw new ApiError('not_found','Сотрудник не найден.',404);
        if (!workspace_employee_can_view($scope,$employee)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
        json_response(['employee'=>workspace_employee_form_data($employee)]);
    }
    if (workspace_employee_editor_scope() === null) throw new ApiError('forbidden','Используйте доступные ячейки учётной записи.',403);
    json_response(['user'=>workspace_employee_save(wbody())]);
}

function workspace_employee_profile_value(string $field, mixed $value, ?string $previousLogin = null): string {
    if (!is_string($value)) throw new ApiError('invalid_user','Проверьте поля сотрудника.');
    $value = trim($value);
    if ($field === 'name' && ($value === '' || mb_strlen($value)>160 || preg_match('/[[:cntrl:]]/u',$value))) {
        throw new ApiError('invalid_user','Укажите ФИО длиной до 160 символов.');
    }
    if ($field === 'login') {
        $value = strtolower($value);
        if ($value !== $previousLogin && (strlen($value)>60 || !filter_var($value,FILTER_VALIDATE_EMAIL))) {
            throw new ApiError('invalid_login','Введите в столбце «Логин» email длиной до 60 символов.');
        }
    }
    return $value;
}

function workspace_employee_save(array $input, bool $cellEdit = false, ?string $deleteReason=null): array {
    return workspace_transaction(static fn()=>workspace_employee_write($input,$cellEdit,$deleteReason));
}

function workspace_employee_write(array $input, bool $cellEdit = false, ?string $deleteReason=null, bool $privateRoster=false): array {
    $scope = workspace_employee_credential_scope();
    if ($scope === null) throw new ApiError('forbidden','Редактирование учётных записей недоступно.',403);
    $id = $input['id'] ?? null;
    if ($id !== null && (!is_int($id) || $id<1)) throw new ApiError('invalid_user','Некорректный идентификатор сотрудника.');
    $old = $id === null ? null : (workspace_employee_records($id)[0] ?? null);
    if ($id !== null && !$old) throw new ApiError('not_found','Сотрудник не найден.',404);
    if ($old && !workspace_employee_can_view($scope,$old)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
    if ($deleteReason !== null) {
        if (!$old || !workspace_employee_can_delete($scope,$old) || ($input['active'] ?? null)!==false ||
            array_diff(array_keys($input),['id','revision','active'])) {
            throw new ApiError('forbidden','Удаление этой учётной записи недоступно.',403);
        }
    } elseif (!in_array($scope,['superadmin','admin'],true) && ($id !== null || $cellEdit)) {
        $changeFields = array_diff(array_keys($input),['id','revision']);
        if (!$cellEdit || !$old || !$changeFields ||
            array_filter($changeFields,static fn(string $field): bool=>!workspace_employee_can_edit_field($scope,$field,$old))) {
            throw new ApiError('forbidden','Для этого поля нет доступа.',403);
        }
    }
    $profile = [];
    foreach (['name','login'] as $field) {
        if (isset($input[$field])) $profile[$field] = workspace_employee_profile_value($field,$input[$field],$old['login']??null);
    }
    if (!$cellEdit && $deleteReason === null && !isset($profile['name'],$profile['login'])) {
        throw new ApiError('invalid_user','Укажите ФИО и логин.');
    }
    $password = $input['password'] ?? '';
    $rosterPin = $privateRoster && is_string($password) && preg_match('/^[0-9]{6}$/D',$password);
    if (!is_string($password) || ($password !== '' &&
        ((!$rosterPin && mb_strlen($password)<8) || strlen($password)>72 || str_contains($password,"\0")))) {
        throw new ApiError('invalid_password','Пароль должен содержать от 8 символов и занимать до 72 байт.');
    }
    $roleIds = $input['roleIds'] ?? [];
    if (!is_array($roleIds) || !array_is_list($roleIds) || count($roleIds)>1 ||
        ($roleIds && (!is_int($roleIds[0]) || $roleIds[0]<1))) {
        throw new ApiError('invalid_role','Выберите одну роль из справочника.');
    }
    $newRole = $roleIds[0] ?? null;
    if ($cellEdit && $id === null) throw new ApiError('invalid_user','Сотрудник не указан.');
    if ($id === null && ($password === '' || $newRole === null)) {
        throw new ApiError('required_fields','Для нового сотрудника укажите пароль и роль.');
    }
    if (isset($input['active']) && !is_bool($input['active'])) throw new ApiError('invalid_user','Некорректное состояние учётной записи.');
    $passwordHash = $password === '' ? null : password_hash($password,PASSWORD_DEFAULT);

    try {
        if ($old && ($cellEdit || isset($input['revision'])) && ($input['revision'] ?? null) !== workspace_employee_revision($old)) {
            $message = $cellEdit
                ? 'Данные сотрудника изменились. Скопируйте черновик и обновите страницу.'
                : 'Сотрудник уже изменён. Скопируйте черновик и откройте карточку заново.';
            throw new ApiError('employee_conflict',$message,409);
        }
        $previousRoles = $old ? array_column($old['roles'],'id') : [];
        $nextRoles = $newRole === null ? $previousRoles : [$newRole];
        $roleChanged = $nextRoles !== $previousRoles;
        if ($roleChanged && !in_array($newRole,array_column(workspace_employee_role_options($scope),'id'),true)) {
            throw new ApiError('invalid_role','Эту роль нельзя назначить.',403);
        }
        $active = $input['active'] ?? ($old['active'] ?? true);
        if ($old && ($deleteReason!==null || $old['active'] && !$active)) workspace_assert_employee_unused($old);
        if ($id === workspace_current_user()['id'] && (!$active || $roleChanged)) {
            throw new ApiError('own_account','Свою роль и активность изменить нельзя.');
        }
        $name = $profile['name'] ?? $old['name'];
        $login = $profile['login'] ?? $old['login'];
        if ($old) {
            $invalidate = $passwordHash !== null || $roleChanged || $active !== $old['active'] || $login !== $old['login'];
            wquery('UPDATE users SET login=?,name=?,active=?,auth_version=auth_version+?,password_hash=COALESCE(?,password_hash) WHERE id=?',
                [$login,$name,(int)$active,(int)$invalidate,$passwordHash,$id]);
        } else {
            wquery('INSERT INTO users(login,name,password_hash,active) VALUES(?,?,?,?)',[$login,$name,$passwordHash,(int)$active]);
            $id = (int)workspace_db()->lastInsertId();
        }
        if ($roleChanged) {
            wquery('DELETE FROM user_roles WHERE user_id=?',[$id]);
            foreach ($nextRoles as $roleId) wquery('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)',[$id,$roleId]);
        }
        $audit = static fn(array $employee): array => [
            'id'=>$employee['id'], 'login'=>$employee['login'], 'name'=>$employee['name'],
            'active'=>$employee['active'], 'roleIds'=>array_column($employee['roles'],'id'),
        ];
        $after = workspace_employee_records($id)[0];
        $deletion=[];
        if ($deleteReason!==null) {
            workspace_mark_row_deleted('directory_deleted_user_',$id);
            $deletion=['deleted'=>true,'reason'=>$deleteReason];
        }
        workspace_audit(['category'=>'management','label'=>'Сотрудник','before'=>$old ? $audit($old) : null,
            'after'=>$audit($after)+['passwordChanged'=>$passwordHash !== null,
                'privateAccount'=>workspace_employee_is_private($after) || ($old && workspace_employee_is_private($old))]+$deletion,
            'action'=>$deleteReason!==null ? 'delete' : ($old ? 'edit' : 'create')]);
        workspace_event('users.changed',['id'=>$id]);
        return $cellEdit ? $after : workspace_employee_form_data($after);
    } catch (Throwable $error) {
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_login','Этот логин уже используется.',409);
        }
        throw $error;
    }
}
