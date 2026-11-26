<?php
declare(strict_types=1);

const WORKSPACE_EMPLOYEE_ROSTER_RECEIPT = 'employee_roster_import_v1';

const WORKSPACE_ROSTER_ROLES = [
    'directory_senior_manager'=>'Старший менеджер',
    'directory_senior_logist'=>'Старший логист',
    'directory_analytics'=>'Аналитика',
];

function workspace_roster_role_id(string $code, bool $createOnly = false): int {
    $name = WORKSPACE_ROSTER_ROLES[$code] ?? null;
    $role = wquery('SELECT id,code,name FROM roles WHERE code=?',[$code])->fetch();
    foreach (workspace_employee_roles() as $option) {
        if ($option['code'] === $code) return $option['id'];
    }
    if ($role || $name === null) throw new ApiError('invalid_role','Эта роль недоступна.');
    foreach (workspace_employee_roles() as $option) {
        if (vitrine_status_key($option['name']) !== vitrine_status_key($name)) continue;
        if ($code === 'directory_analytics' && $createOnly) {
            throw new ApiError('invalid_role','Для аналитики уже существует роль с другим кодом.',409);
        }
        if ($code === 'directory_analytics') {
            wquery('UPDATE roles SET code=? WHERE id=?',[$code,$option['id']]);
            workspace_audit(['category'=>'management','label'=>'Роли','action'=>'edit',
                'before'=>$option,'after'=>array_replace($option,['code'=>$code])]);
            workspace_event('roles.changed',['id'=>$option['id']]);
        }
        return $option['id'];
    }
    foreach (workspace_employee_roles(true) as $option) {
        if (vitrine_status_key($option['name']) === vitrine_status_key($name)) {
            throw new ApiError('invalid_role','Сначала восстановите роль из архива.',409);
        }
    }
    wquery('INSERT INTO roles(code,name) VALUES(?,?)',[$code,$name]);
    $id = (int)workspace_db()->lastInsertId();
    workspace_audit(['category'=>'management','label'=>'Роли','action'=>'create',
        'after'=>['id'=>$id,'name'=>$name,'active'=>true]]);
    workspace_event('roles.changed',['id'=>$id]);
    return $id;
}

function workspace_write_employee_roster(mixed $roster, bool $createOnly=false, ?int &$index=null): array {
    if (!is_array($roster) || !array_is_list($roster) || !$roster) {
        throw new ApiError('invalid_roster','Передайте список учётных записей.');
    }
    $actorLogin = workspace_current_user()['login'];
    $otherAccounts = $actingAccount = [];
    foreach ($roster as $index=>$entry) {
        if (!is_array($entry)) throw new ApiError('invalid_roster','Проверьте строки списка.');
        if ($createOnly && isset($entry['previousLogin'])) {
            throw new ApiError('invalid_roster','При переносе через кабинет переименование недоступно.');
        }
        $login = workspace_employee_profile_value('login',$entry['login'] ?? null);
        $previous = isset($entry['previousLogin']) ? workspace_employee_profile_value('login',$entry['previousLogin']) : $login;
        $account = ['login'=>$login,'previousLogin'=>$previous]+$entry;
        // Updating the acting account can invalidate the session used to authorize writes.
        if ($actorLogin === $login || $actorLogin === $previous) $actingAccount[$index] = $account;
        else $otherAccounts[$index] = $account;
    }
    $seenLogins = $seenIds = [];
    $result = ['created'=>0,'updated'=>0,'unchanged'=>0];
    foreach ($otherAccounts+$actingAccount as $index=>$entry) {
        ['login'=>$login,'previousLogin'=>$previous]=$entry;
        $user = wquery('SELECT id,password_hash FROM users WHERE login IN (?,?) ORDER BY login=? DESC LIMIT 1',
            [$login,$previous,$login])->fetch();
        $old = $user ? (workspace_employee_records((int)$user['id'])[0] ?? null) : null;
        if ($user && !$old) throw new ApiError('invalid_roster','Учётная запись удалена.',409);
        if (isset($seenLogins[$login]) || ($old && isset($seenIds[$old['id']]))) throw new ApiError('duplicate_login','Логин встречается в списке повторно.',409);
        $seenLogins[$login] = true;
        $code = $entry['role'] ?? null;
        if (!is_string($code)) throw new ApiError('invalid_role','Укажите код роли.');
        $role = workspace_roster_role_id($code,$createOnly);
        if ($old && $createOnly) {
            if (!$old['active'] || array_column($old['roles'],'id') !== [$role]) {
                throw new ApiError('employee_conflict','Роль или активность совпавшей записи отличаются.',409);
            }
            $seenIds[$old['id']] = true;
            ++$result['unchanged'];
            continue;
        }
        $name = workspace_employee_profile_value('name',$entry['name'] ?? $old['name'] ?? workspace_employee_role_names()[$code] ?? null);
        $email = workspace_employee_profile_value('email',$entry['email'] ?? (str_contains($login,'@') ? $login : ($old['email'] ?? '')));
        $password = $entry['password'] ?? null;
        if (!is_string($password) || $password === '') throw new ApiError('invalid_password','Укажите пароль для новой учётной записи.');
        $passwordChanged = !$user || !workspace_password_verify($password,$user['password_hash']);
        if ($old && $old['login'] === $login && $old['name'] === $name && $old['email'] === $email &&
            $old['active'] && array_column($old['roles'],'id') === [$role] && !$passwordChanged) {
            $seenIds[$old['id']] = true;
            ++$result['unchanged'];
            continue;
        }
        $saved = workspace_employee_write(['id'=>$old['id'] ?? null,'login'=>$login,'name'=>$name,'email'=>$email,
            'password'=>$passwordChanged ? $password : '', 'active'=>true,'roleIds'=>[$role]],privateRoster:true);
        $seenIds[$saved['id']] = true;
        ++$result[$old ? 'updated' : 'created'];
    }
    return $result;
}

function workspace_employee_roster_endpoint(string $method): never {
    if ($method !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    if (workspace_employee_editor_scope() !== 'superadmin') {
        throw new ApiError('forbidden','Перенос доступен только суперадминистратору.',403);
    }
    $body=wbody();
    $db=workspace_db();
    workspace_begin();
    try {
        if (wquery('SELECT 1 FROM workspace_meta WHERE `key`=?',[WORKSPACE_EMPLOYEE_ROSTER_RECEIPT])->fetchColumn()) {
            throw new ApiError('import_exists','Учётные записи уже перенесены.',409);
        }
        $result=workspace_write_employee_roster($body['employees']??null,createOnly:true);
        wquery('INSERT INTO workspace_meta VALUES(?,?)',[WORKSPACE_EMPLOYEE_ROSTER_RECEIPT,wjson($result)]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    json_response($result);
}
