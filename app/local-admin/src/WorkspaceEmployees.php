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

function workspace_employee_role_names(): array {
    return [
        'superadmin'=>'Суперадмин', 'admin'=>'Админ', 'manager'=>'Менеджер',
        'logist'=>'Логист', 'dispatcher'=>'Диспетчер',
    ];
}

function workspace_employee_roles(bool $archive = false): array {
    $knownNames = workspace_employee_role_names();
    $archived = workspace_client_catalog_metadata('directory_archived_roles');
    $roles = [];
    foreach (wquery("SELECT id,code,name FROM roles r
        WHERE NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('directory_deleted_role_',r.id))
        ORDER BY id")->fetchAll() as $role) {
        $id = (int)$role['id'];
        $code = $role['code'];
        if (!isset($knownNames[$code]) && !str_starts_with($code,'directory_')) continue;
        if (isset($archived[$id]) !== $archive) continue;
        $roles[] = ['id'=>$id,'code'=>$code,'name'=>$knownNames[$code] ?? $role['name']];
    }
    return $roles;
}

function workspace_employee_records(?int $id = null, ?bool $active = null): array {
    $sql = "SELECT u.id,u.name,u.login,u.active,u.auth_version,
            COALESCE(JSON_UNQUOTE(m.value),'') AS email,r.id AS role_id,r.code,r.name AS role_name
            FROM users u
            LEFT JOIN workspace_meta m ON m.`key`=CONCAT('employee_email_',u.id)
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
            'id'=>$userId, 'name'=>$row['name'], 'login'=>$row['login'], 'email'=>$row['email'],
            'active'=>(bool)$row['active'], 'authVersion'=>(int)$row['auth_version'], 'roles'=>[],
        ];
        if ($row['role_id'] !== null) $employees[$userId]['roles'][] = [
            'id'=>(int)$row['role_id'], 'code'=>$row['code'], 'name'=>$names[$row['code']] ?? $row['role_name'],
        ];
    }
    return array_values($employees);
}

function workspace_employee_editor_scope(): ?string {
    if (!workspace_can('system','users','edit') || !workspace_can('system','users','view')) return null;
    $actor = workspace_employee_records((int)workspace_current_user()['id'])[0] ?? null;
    if (!$actor || !$actor['active'] || $actor['authVersion'] !== (int)workspace_current_user()['auth_version']) return null;
    $codes = array_column($actor['roles'],'code');
    if (in_array('superadmin',$codes,true)) return 'superadmin';
    return in_array('admin',$codes,true) ? 'admin' : null;
}

function workspace_employee_is_private(array $employee): bool {
    return (bool)array_intersect(WORKSPACE_PRIVATE_EMPLOYEE_ROLES,array_column($employee['roles'],'code'));
}

function workspace_employee_viewer_is_superadmin(): bool {
    return (bool)wquery("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=? AND r.code='superadmin'",[workspace_current_user()['id']])->fetchColumn();
}

function workspace_employee_visible_records(?bool $active = null): array {
    workspace_require('system','users','view');
    $employees = workspace_employee_records(active:$active);
    if (workspace_employee_viewer_is_superadmin()) return $employees;
    return array_values(array_filter($employees,static fn(array $employee): bool => !workspace_employee_is_private($employee)));
}

function workspace_employee_can_edit(string $scope, array $employee): bool {
    return $scope === 'superadmin' || ($scope === 'admin' && !workspace_employee_is_private($employee));
}

function workspace_employee_history_filter(): string {
    if (workspace_employee_viewer_is_superadmin()) return '1=1';
    return "NOT (h.category='management' AND COALESCE(h.column_label,'')='Сотрудник' AND (
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(h.after_value,'$.privateAccount')),'false')='true'
        OR EXISTS (SELECT 1 FROM roles r WHERE r.code IN ('superadmin','admin','directory_analytics')
            AND (JSON_CONTAINS(h.before_value,CAST(r.id AS JSON),'$.roleIds')
                OR JSON_CONTAINS(h.after_value,CAST(r.id AS JSON),'$.roleIds')))
    ))";
}

function workspace_employee_role_options(string $scope): array {
    return array_values(array_filter(workspace_employee_roles(), static fn(array $role): bool =>
        $scope === 'superadmin' || !in_array($role['code'],WORKSPACE_PRIVATE_EMPLOYEE_ROLES,true)
    ));
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
        json_response(['users'=>$users, 'roles'=>wquery('SELECT id,name FROM roles ORDER BY id')->fetchAll()]);
    }

    $scope = workspace_employee_editor_scope();
    if ($scope === null) throw new ApiError('forbidden','Редактирование доступно администратору.',403);
    if ($method === 'GET') {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $employee = $id ? (workspace_employee_records($id)[0] ?? null) : null;
        if (!$employee) throw new ApiError('not_found','Сотрудник не найден.',404);
        if (!workspace_employee_can_edit($scope,$employee)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
        json_response(['employee'=>workspace_employee_form_data($employee)]);
    }
    json_response(['user'=>workspace_employee_save(wbody())]);
}

function workspace_employee_profile_value(string $field, mixed $value): string {
    if (!is_string($value)) throw new ApiError('invalid_user','Проверьте поля сотрудника.');
    $value = trim($value);
    if ($field === 'name' && ($value === '' || mb_strlen($value)>160 || preg_match('/[[:cntrl:]]/u',$value))) {
        throw new ApiError('invalid_user','Укажите ФИО длиной до 160 символов.');
    }
    if ($field === 'login') {
        $value = strtolower($value);
        if (!preg_match('/^[a-z0-9_.-]{2,60}$/D',$value) &&
            (strlen($value)>60 || !filter_var($value,FILTER_VALIDATE_EMAIL))) {
            throw new ApiError('invalid_user','Укажите email до 60 символов или логин из 2–60 латинских букв, цифр, точек, дефисов или подчёркиваний.');
        }
    }
    if ($field === 'email' && $value !== '' && (strlen($value)>254 || !filter_var($value,FILTER_VALIDATE_EMAIL))) {
        throw new ApiError('invalid_email','Укажите корректный email.');
    }
    return $value;
}

function workspace_employee_save(array $input, bool $cellEdit = false, ?string $deleteReason=null): array {
    return workspace_transaction(static fn()=>workspace_employee_write($input,$cellEdit,$deleteReason));
}

function workspace_employee_write(array $input, bool $cellEdit = false, ?string $deleteReason=null, bool $privateRoster=false): array {
    $profile = [];
    foreach (['name','login','email'] as $field) {
        if (isset($input[$field])) $profile[$field] = workspace_employee_profile_value($field,$input[$field]);
    }
    if (!$cellEdit && !isset($profile['name'],$profile['login'])) {
        throw new ApiError('invalid_user','Укажите ФИО и логин.');
    }
    $password = $input['password'] ?? '';
    $rosterPin = $privateRoster && is_string($password) && preg_match('/^[0-9]{6}$/D',$password);
    if (!is_string($password) || ($password !== '' &&
        ((!$rosterPin && mb_strlen($password)<8) || strlen($password)>72 || str_contains($password,"\0")))) {
        throw new ApiError('invalid_password','Пароль должен содержать от 8 символов и занимать до 72 байт.');
    }
    $id = $input['id'] ?? null;
    if ($id !== null && (!is_int($id) || $id<1)) throw new ApiError('invalid_user','Некорректный идентификатор сотрудника.');
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
        $scope = workspace_employee_editor_scope();
        if ($scope === null) throw new ApiError('forbidden','Редактирование доступно администратору.',403);
        $old = $id === null ? null : (workspace_employee_records($id)[0] ?? null);
        if ($id !== null && !$old) throw new ApiError('not_found','Сотрудник не найден.',404);
        if ($old && !workspace_employee_can_edit($scope,$old)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
        if ($cellEdit && !$old['active']) throw new ApiError('readonly','Сотрудник в архиве.',403);
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
        $email = $profile['email'] ?? ($old['email'] ?? '');
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
        if ($email !== ($old['email'] ?? '')) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['employee_email_'.$id,wjson($email)]);
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
            'after'=>$audit($after)+['emailChanged'=>$email !== ($old['email'] ?? ''), 'passwordChanged'=>$passwordHash !== null,
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
