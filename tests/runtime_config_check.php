<?php
declare(strict_types=1);

// Each probe needs a fresh process because runtime_config() caches validated settings.
if (($argv[1] ?? '') === '--probe') {
    putenv('DLTK_CONFIG_FILE='.$argv[2]);
    require __DIR__.'/../app/local-admin/src/bootstrap.php';
    require __DIR__.'/../app/local-admin/src/Workspace.php';
    try {
        $config=runtime_config();$basePath=$config['APP_BASE_PATH'];
        if(app_url_path('/login')!==$basePath.'/login'||app_url_path('/')!==$basePath.'/'||app_route_path($basePath.'/vitrine')!=='/vitrine'||app_route_path($basePath===''?'/':$basePath)!=='/')throw new RuntimeException('Base path mapping failed');
        if($basePath!==''){
            try{app_route_path($basePath.'-other/login');throw new RuntimeException('Prefix boundary accepted');}
            catch(ApiError $error){if($error->httpStatus!==404)throw $error;}
        }
        echo json_encode(['ok'=>true,'dsn'=>workspace_database_dsn($config)], JSON_THROW_ON_ERROR);
    } catch (ApiError $error) {
        echo json_encode(['ok'=>false,'code'=>$error->publicCode], JSON_THROW_ON_ERROR);
    }
    exit;
}

$base = [
    'APP_URL'=>'https://example.invalid:8443',
    'WS_URL'=>'wss://example.invalid:8443/ws',
    'DB_NAME'=>'dltk_v3_test', 'DB_USER'=>'example_user',
    'DB_PASSWORD'=>'EXAMPLE_ONLY', 'PRIVATE_DIR'=>'/var/lib/dltk-private',
];
$cases = [
    ['Unix socket ignores TCP port zero', ['DB_SOCKET'=>'/var/run/mysqld/mysqld.sock','DB_PORT'=>'0'], 'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=dltk_v3_test;charset=utf8mb4'],
    ['Explicit TCP connection', ['DB_SOCKET'=>'','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33384'], 'mysql:host=127.0.0.1;port=33384;dbname=dltk_v3_test;charset=utf8mb4'],
    ['Default TCP connection', [], 'mysql:host=127.0.0.1;port=3306;dbname=dltk_v3_test;charset=utf8mb4'],
    ['Relative socket rejected', ['DB_SOCKET'=>'var/run/mysql.sock'], null],
    ['Socket DSN injection rejected', ['DB_SOCKET'=>'/run/mysql.sock;host=other'], null],
    ['Socket NUL rejected', ['DB_SOCKET'=>"/run/mysql\0.sock"], null],
    ['TCP port zero rejected', ['DB_SOCKET'=>'','DB_PORT'=>'0'], null],
    ['Application subdirectory and boundary', ['APP_BASE_PATH'=>'/dltk-v3'], 'mysql:host=127.0.0.1;port=3306;dbname=dltk_v3_test;charset=utf8mb4'],
    ['Nested application subdirectory', ['APP_BASE_PATH'=>'/demo/dltk-v3'], 'mysql:host=127.0.0.1;port=3306;dbname=dltk_v3_test;charset=utf8mb4'],
    ['Relative application prefix rejected', ['APP_BASE_PATH'=>'dltk-v3'], null],
    ['Trailing slash prefix rejected', ['APP_BASE_PATH'=>'/dltk-v3/'], null],
    ['Traversal prefix rejected', ['APP_BASE_PATH'=>'/demo/../dltk-v3'], null],
    ['Protocol-relative prefix rejected', ['APP_BASE_PATH'=>'//example.invalid'], null],
];
$failed = 0;
foreach ($cases as [$name, $extra, $expectedDsn]) {
    $file = tempnam(sys_get_temp_dir(), 'dltk-config-');
    if ($file === false) throw new RuntimeException('Cannot create temporary configuration');
    try {
        $ini = "[workspace]\n";
        foreach (array_replace($base, $extra) as $key=>$value) $ini .= $key.' = "'.$value."\"\n";
        if (file_put_contents($file, $ini) === false) throw new RuntimeException('Cannot write test configuration');
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--probe', $file], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot run configuration probe');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode($stdout, true);
        $passed = $exitCode === 0 && is_array($result)
            && ($expectedDsn === null
                ? (($result['ok'] ?? null) === false && ($result['code'] ?? '') === 'configuration_invalid')
                : (($result['ok'] ?? null) === true && ($result['dsn'] ?? '') === $expectedDsn));
        echo ($passed ? 'PASS ' : 'FAIL ').$name.PHP_EOL;
        if (!$passed) $failed++;
    } finally {
        unlink($file);
    }
}
echo (count($cases)-$failed).'/'.count($cases).' runtime configuration checks passed; no database connections.'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
