<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors','0');
ini_set('log_errors','0');

require_once __DIR__.'/../app/local-admin/src/bootstrap.php';
require_once __DIR__.'/../app/local-admin/src/Workspace.php';
require_once __DIR__.'/../app/local-admin/src/Vitrine.php';
require_once __DIR__.'/../app/local-admin/src/WorkspaceEmployeeRoster.php';

try {
    if ($argc !== 3 || (!$schema = $argv[1]) || (!$actorLogin = $argv[2])) {
        throw new RuntimeException('Expected schema and acting superadmin login.');
    }
    $roster = json_decode(stream_get_contents(STDIN,120000),true,flags:JSON_THROW_ON_ERROR);

    $db = workspace_db();
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== $schema) throw new RuntimeException('Unexpected database.');
    workspace_begin();
    $actor = wquery('SELECT id,login,auth_version FROM users WHERE login=? AND active=TRUE',[$actorLogin])->fetch();
    if (!$actor) throw new RuntimeException('Acting account is unavailable.');
    $_SESSION = ['user_id'=>$actor['id'],'auth_version'=>$actor['auth_version']];
    if (workspace_employee_editor_scope() !== 'superadmin') throw new RuntimeException('Superadmin role required.');

    $index = null;
    $result = workspace_write_employee_roster($roster,index:$index);
    $db->commit();
    echo wjson($result).PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $row = isset($index) && is_int($index) ? ' строка '.($index+1) : '';
    $code = $error instanceof ApiError ? $error->publicCode : ($error instanceof PDOException ? 'database_error' : 'invalid_roster');
    fwrite(STDERR,"Импорт отменён.{$row} ({$code})".PHP_EOL);
    exit(1);
}
