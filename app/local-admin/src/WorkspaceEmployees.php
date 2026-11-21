<?php
declare(strict_types=1);

function workspace_employee_role_names(): array {
    return [
        'superadmin'=>'Суперадмин', 'admin'=>'Админ', 'manager'=>'Менеджер',
        'logist'=>'Логист', 'dispatcher'=>'Диспетчер',
    ];
}

function workspace_employee_roles(): array {
    $stored = array_column(wquery('SELECT id,code FROM roles')->fetchAll(), 'id', 'code');
    $roles = [];
    foreach (workspace_employee_role_names() as $code => $label) {
        if (isset($stored[$code])) $roles[] = ['id'=>(int)$stored[$code], 'code'=>$code, 'name'=>$label];
    }
    return $roles;
}

function workspace_employee_records(?int $id = null, ?bool $active = null): array {
    $sql = "SELECT u.id,u.name,u.login,u.active,u.auth_version,
            COALESCE(JSON_UNQUOTE(m.value),'') AS email,r.id AS role_id,r.code,r.name AS role_name
            FROM users u
            LEFT JOIN workspace_meta m ON m.`key`=CONCAT('employee_email_',u.id)
            LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id";
    $where = [];
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

function workspace_employee_can_edit(string $scope, array $employee): bool {
    $codes = array_column($employee['roles'],'code');
    if (in_array('superadmin',$codes,true)) return $scope === 'superadmin';
    return $scope === 'superadmin' || !in_array('admin',$codes,true) || $employee['id'] === workspace_current_user()['id'];
}

function workspace_employee_role_options(string $scope): array {
    return array_values(array_filter(workspace_employee_roles(), static fn(array $role): bool =>
        $scope === 'superadmin' || !in_array($role['code'],['superadmin','admin'],true)
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
        $users = array_map(static fn(int $id): ?array => workspace_user($id),
            wquery('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
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

function workspace_employee_save(array $input): array {
    foreach (['name','login','email','password'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new ApiError('invalid_user','Проверьте поля сотрудника.');
    }
    $name = trim($input['name'] ?? '');
    $login = strtolower(trim($input['login'] ?? ''));
    $password = $input['password'] ?? '';
    if ($name === '' || mb_strlen($name)>160 || !preg_match('/^[a-z0-9_.-]{2,60}$/D',$login)) {
        throw new ApiError('invalid_user','Укажите ФИО и логин из 2–60 латинских букв, цифр, точек, дефисов или подчёркиваний.');
    }
    if ($password !== '' && (mb_strlen($password)<8 || strlen($password)>72 || str_contains($password,"\0"))) {
        throw new ApiError('invalid_password','Пароль должен содержать от 8 символов и занимать до 72 байт.');
    }
    $emailInput = isset($input['email']) ? trim($input['email']) : null;
    if ($emailInput !== null && $emailInput !== '' && (strlen($emailInput)>254 || !filter_var($emailInput,FILTER_VALIDATE_EMAIL))) {
        throw new ApiError('invalid_email','Укажите корректный email.');
    }
    $id = $input['id'] ?? null;
    if ($id !== null && (!is_int($id) || $id<1)) throw new ApiError('invalid_user','Некорректный идентификатор сотрудника.');
    $roleIds = $input['roleIds'] ?? [];
    if (!is_array($roleIds) || !array_is_list($roleIds) || count($roleIds)>1 ||
        ($roleIds && (!is_int($roleIds[0]) || $roleIds[0]<1))) {
        throw new ApiError('invalid_role','Выберите одну роль из справочника.');
    }
    $newRole = $roleIds[0] ?? null;
    if ($id === null && ($password === '' || $newRole === null)) {
        throw new ApiError('required_fields','Для нового сотрудника укажите пароль и роль.');
    }
    if (isset($input['active']) && !is_bool($input['active'])) throw new ApiError('invalid_user','Некорректное состояние учётной записи.');
    $passwordHash = $password === '' ? null : password_hash($password,PASSWORD_DEFAULT);

    workspace_begin();
    try {
        $scope = workspace_employee_editor_scope();
        if ($scope === null) throw new ApiError('forbidden','Редактирование доступно администратору.',403);
        $old = $id === null ? null : (workspace_employee_records($id)[0] ?? null);
        if ($id !== null && !$old) throw new ApiError('not_found','Сотрудник не найден.',404);
        if ($old && !workspace_employee_can_edit($scope,$old)) throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
        if ($old && isset($input['revision']) && $input['revision'] !== workspace_employee_revision($old)) {
            throw new ApiError('employee_conflict','Сотрудник уже изменён. Скопируйте черновик и откройте карточку заново.',409);
        }
        $previousRoles = $old ? array_column($old['roles'],'id') : [];
        $nextRoles = $newRole === null ? $previousRoles : [$newRole];
        $roleChanged = $nextRoles !== $previousRoles;
        if ($roleChanged && !in_array($newRole,array_column(workspace_employee_role_options($scope),'id'),true)) {
            throw new ApiError('invalid_role','Эту роль нельзя назначить.',403);
        }
        $active = $input['active'] ?? ($old['active'] ?? true);
        if ($id === workspace_current_user()['id'] && (!$active || $roleChanged)) {
            throw new ApiError('own_account','Свою роль и активность изменить нельзя.');
        }
        $email = $emailInput ?? ($old['email'] ?? '');
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
        workspace_audit(['category'=>'management','label'=>'Сотрудник','before'=>$old ? $audit($old) : null,
            'after'=>$audit($after)+['emailChanged'=>$email !== ($old['email'] ?? ''), 'passwordChanged'=>$passwordHash !== null],
            'action'=>$old ? 'edit' : 'create']);
        workspace_event('users.changed',['id'=>$id]);
        workspace_db()->commit();
        return workspace_employee_form_data($after);
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_login','Этот логин уже используется.',409);
        }
        throw $error;
    }
}
