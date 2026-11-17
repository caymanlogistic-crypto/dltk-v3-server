<?php
declare(strict_types=1);
const APP_MARKER = 'dltk-v3-server-v1';
function project_root(): string { return dirname(__DIR__,3); }
final class ApiError extends RuntimeException {
    public function __construct(public string $publicCode,public string $publicMessage,public int $httpStatus=400) { parent::__construct($publicCode); }
}
function runtime_config(): array {
    static $config;
    if(is_array($config))return $config;
    $file=getenv('DLTK_CONFIG_FILE') ?: project_root().'/config/runtime.ini';
    if(!is_file($file)||!is_readable($file))throw new ApiError('configuration_unavailable','Серверная конфигурация недоступна.',503);
    $parsed=@parse_ini_file($file,true,INI_SCANNER_RAW);
    if(!is_array($parsed)||!is_array($parsed['workspace']??null))throw new ApiError('configuration_invalid','Серверная конфигурация некорректна.',503);
    $config=$parsed['workspace'];
    foreach(['APP_URL','WS_URL','DB_NAME','DB_USER','DB_PASSWORD','PRIVATE_DIR'] as $key)if(!array_key_exists($key,$config)||!is_string($config[$key])||($key!=='DB_PASSWORD'&&$config[$key]===''))throw new ApiError('configuration_invalid','Не все параметры сервера настроены.',503);
    $config['DB_SOCKET']=$config['DB_SOCKET']??'';
    if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D',$config['DB_NAME'])||!is_string($config['DB_SOCKET']))throw new ApiError('configuration_invalid','Некорректные параметры рабочей БД.',503);
    if($config['DB_SOCKET']!==''){
        if(!str_starts_with($config['DB_SOCKET'],'/')||preg_match('/[;\x00-\x1F\x7F]/',$config['DB_SOCKET']))throw new ApiError('configuration_invalid','DB_SOCKET должен быть абсолютным путём Unix-сокета.',503);
    }else{
        $config['DB_HOST']=$config['DB_HOST']??'127.0.0.1';$config['DB_PORT']=$config['DB_PORT']??'3306';
        if(!is_string($config['DB_HOST'])||$config['DB_HOST']===''||!is_string($config['DB_PORT'])||!ctype_digit($config['DB_PORT'])||(int)$config['DB_PORT']<1||(int)$config['DB_PORT']>65535||preg_match('/[;\x00-\x20]/',$config['DB_HOST']))throw new ApiError('configuration_invalid','Некорректные TCP-параметры рабочей БД.',503);
    }
    $app=parse_url($config['APP_URL']);$ws=parse_url($config['WS_URL']);
    if(!is_array($app)||!in_array($app['scheme']??'',['http','https'],true)||empty($app['host'])||isset($app['user'])||isset($app['pass'])||isset($app['query'])||isset($app['fragment'])||!in_array($app['path']??'',['','/'],true))throw new ApiError('configuration_invalid','APP_URL должен содержать только адрес отдельного сайта.',503);
    if(!is_array($ws)||!in_array($ws['scheme']??'',['ws','wss'],true)||empty($ws['host'])||isset($ws['user'])||isset($ws['pass'])||isset($ws['fragment'])||isset($ws['query'])||($app['scheme']==='https'&&$ws['scheme']!=='wss'))throw new ApiError('configuration_invalid','Некорректный адрес WebSocket.',503);
    return $config;
}
function configured_origin(string $url): string {
    $parts=parse_url($url);$scheme=strtolower($parts['scheme']);$host=strtolower($parts['host']);$port=(int)($parts['port']??0);
    $default=in_array($scheme,['https','wss'],true)?443:80;
    return $scheme.'://'.$host.($port&&$port!==$default?':'.$port:'');
}
function private_dir(): string { return runtime_config()['PRIVATE_DIR']; }
function json_response(array $body,int $status=200): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
}
function secure_request(): void {
    $config=runtime_config();$origin=configured_origin($config['APP_URL']);$authority=substr($origin,strpos($origin,'://')+3);
    if(strtolower($_SERVER['HTTP_HOST']??'')!==$authority)throw new ApiError('invalid_host','Неизвестный адрес сервера.',403);
    header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self' ".configured_origin($config['WS_URL'])."; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    ini_set('display_errors','0');ini_set('log_errors','0');ini_set('session.use_strict_mode','1');
    session_name('dltk_v3_server');
    session_set_cookie_params(['httponly'=>true,'secure'=>str_starts_with($origin,'https://'),'samesite'=>'Strict','path'=>'/']);
    session_start();
    if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','OPTIONS'],true)){
        if(($_SERVER['HTTP_ORIGIN']??'')!==$origin||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')){
            session_write_close();throw new ApiError('csrf','Обновите страницу и повторите действие.',403);
        }
    }
    session_write_close();
}
function csrf_token(): string { return (string)$_SESSION['csrf']; }
function blob_json(?string $blob): array {
    if($blob===null||$blob==='')return [];
    $decoded=@gzuncompress($blob);if($decoded===false)throw new ApiError('invalid_snapshot','Не удалось прочитать сохранённые данные.',500);
    return json_decode($decoded,true,512,JSON_THROW_ON_ERROR);
}
function utc_iso(?string $value): ?string { return $value?str_replace(' ','T',$value).'Z':null; }
function column_letter(int $n): string { $out='';while($n>0){$n--;$out=chr(65+$n%26).$out;$n=intdiv($n,26);}return $out; }
function column_number(string $letter): int {
    if(!preg_match('/^[A-Z]{1,3}$/D',$letter))throw new ApiError('invalid_column','Укажите букву столбца.');
    $out=0;foreach(str_split($letter) as $c)$out=$out*26+ord($c)-64;return $out;
}
function cell_value(array $cell): mixed {
    $value=$cell['effectiveValue']??$cell['userEnteredValue']??[];
    foreach(['stringValue','numberValue','boolValue','errorValue','formulaValue'] as $key)if(array_key_exists($key,$value))return $value[$key];
    return null;
}
function visible_value(mixed $value): mixed { return is_array($value)?($value['message']??$value['type']??json_encode($value,JSON_UNESCAPED_UNICODE)):$value; }
