<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0');
ini_set('log_errors','0');

require_once __DIR__.'/../app/local-admin/src/bootstrap.php';
require_once __DIR__.'/../app/local-admin/src/Workspace.php';
require_once __DIR__.'/../app/local-admin/src/Vitrine.php';

const ROSTER_ROLES = [
    'directory_senior_manager'=>'Старший менеджер',
    'directory_senior_logist'=>'Старший логист',
    'directory_analytics'=>'Аналитика',
];

function roster_role_id(string $code): int {
    $name = ROSTER_ROLES[$code] ?? null;
    $role = wquery('SELECT id,code,name FROM roles WHERE code=?',[$code])->fetch();
    foreach (workspace_employee_roles() as $option) {
        if ($option['code'] === $code) return $option['id'];
    }
    if ($role || $name === null) throw new RuntimeException('Role is unavailable.');
    foreach (workspace_employee_roles() as $option) {
        if (vitrine_status_key($option['name']) !== vitrine_status_key($name)) continue;
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
            throw new RuntimeException('Restore the archived role before provisioning.');
        }
    }
    wquery('INSERT INTO roles(code,name) VALUES(?,?)',[$code,$name]);
    $id = (int)workspace_db()->lastInsertId();
    workspace_audit(['category'=>'management','label'=>'Роли','action'=>'create',
        'after'=>['id'=>$id,'name'=>$name,'active'=>true]]);
    workspace_event('roles.changed',['id'=>$id]);
    return $id;
}

try {
    if ($argc !== 3 || (!$schema = $argv[1]) || (!$actorLogin = $argv[2])) {
        throw new RuntimeException('Expected schema and acting superadmin login.');
    }
    $roster = json_decode(stream_get_contents(STDIN,120000),true,flags:JSON_THROW_ON_ERROR);
    if (!is_array($roster) || !array_is_list($roster) || !$roster) throw new RuntimeException('Expected a roster array.');

    $db = workspace_db();
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $schema) throw new RuntimeException('Unexpected database.');
    workspace_begin();
    $actor = wquery('SELECT id,login,auth_version FROM users WHERE login=? AND active=TRUE',[$actorLogin])->fetch();
    if (!$actor) throw new RuntimeException('Acting account is unavailable.');
    $_SESSION = ['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    if (workspace_employee_editor_scope() !== 'superadmin') throw new RuntimeException('Superadmin role required.');

    $otherAccounts = $actingAccount = [];
    foreach ($roster as $index=>$entry) {
        if (!is_array($entry)) throw new RuntimeException('Invalid account.');
        $login = workspace_employee_profile_value('login',$entry['login'] ?? null);
        $previous = isset($entry['previousLogin']) ? workspace_employee_profile_value('login',$entry['previousLogin']) : $login;
        $account = ['login'=>$login,'previousLogin'=>$previous]+$entry;
        // Updating the acting account can invalidate the session used to authorize writes.
        if ($actor['login'] === $login || $actor['login'] === $previous) $actingAccount[$index] = $account;
        else $otherAccounts[$index] = $account;
    }
    $seenLogins = $seenIds = [];
    $result = ['created'=>0,'updated'=>0,'unchanged'=>0];
    foreach ($otherAccounts+$actingAccount as $index=>$entry) {
        ['login'=>$login,'previousLogin'=>$previous]=$entry;
        $user = wquery('SELECT id,password_hash FROM users WHERE login IN (?,?) ORDER BY login=? DESC LIMIT 1',
            [$login,$previous,$login])->fetch();
        $old = $user ? (workspace_employee_records((int)$user['id'])[0] ?? null) : null;
        if ($user && !$old) throw new RuntimeException('Account was deleted.');
        if (isset($seenLogins[$login]) || ($old && isset($seenIds[$old['id']]))) throw new RuntimeException('Duplicate account in roster.');
        $seenLogins[$login] = true;
        $code = $entry['role'] ?? null;
        if (!is_string($code)) throw new RuntimeException('Role code required.');
        $role = roster_role_id($code);
        $name = workspace_employee_profile_value('name',$entry['name'] ?? $old['name'] ?? workspace_employee_role_names()[$code] ?? null);
        $email = workspace_employee_profile_value('email',$entry['email'] ?? (str_contains($login,'@') ? $login : ($old['email'] ?? '')));
        $password = $entry['password'] ?? null;
        if (!is_string($password) || $password === '') throw new RuntimeException('Password required.');
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
    $db->commit();
    echo wjson($result).PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $row = isset($index) && is_int($index) ? ' строка '.($index+1) : '';
    $code = $error instanceof ApiError ? $error->publicCode : ($error instanceof PDOException ? 'database_error' : 'invalid_roster');
    fwrite(STDERR,"Импорт отменён.{$row} ({$code})".PHP_EOL);
    exit(1);
}
