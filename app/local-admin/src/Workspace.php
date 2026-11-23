<?php
declare(strict_types=1);
require_once __DIR__.'/WorkspaceCatalogs.php';
require_once __DIR__.'/WorkspaceFleet.php';
require_once __DIR__.'/WorkspaceFleetDetails.php';
require_once __DIR__.'/WorkspaceClientCatalogGrid.php';
require_once __DIR__.'/WorkspaceEmployees.php';
require_once __DIR__.'/WorkspaceEmployeeGrid.php';
require_once __DIR__.'/WorkspaceDirectoryGrid.php';
require_once __DIR__.'/WorkspaceDirectoryActions.php';
require_once __DIR__.'/WorkspaceTableRowActions.php';
require_once __DIR__.'/WorkspaceRoleDirectory.php';
require_once __DIR__.'/WorkspaceCatalogDirectory.php';
require_once __DIR__.'/WorkspaceClientOrderStatuses.php';
require_once __DIR__.'/WorkspaceFlightStatusGrid.php';
require_once __DIR__.'/WorkspaceGridPreferences.php';

function workspace_database_dsn(array $config): string {
    $endpoint=($config['DB_SOCKET']??'')!==''?'unix_socket='.$config['DB_SOCKET']:'host='.$config['DB_HOST'].';port='.$config['DB_PORT'];
    return 'mysql:'.$endpoint.';dbname='.$config['DB_NAME'].';charset=utf8mb4';
}
function workspace_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $config=runtime_config();
    $dsn=workspace_database_dsn($config);
    $db=new PDO($dsn,$config['DB_USER'],$config['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
    $db->exec("SET time_zone='+00:00'");
    if (json_decode((string)$db->query("SELECT value FROM workspace_meta WHERE `key`='marker'")->fetchColumn(),true)!=='dltk-workspace-v1') throw new ApiError('workspace_unavailable','Неожиданная рабочая БД.',503);
    return $db;
}
function wquery(string $sql,array $args=[]): PDOStatement { $q=workspace_db()->prepare($sql);$q->execute($args);return $q; }
function wjson(mixed $v): string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);}
function wbody(): array { $raw=file_get_contents('php://input',false,null,0,120000);$body=json_decode((string)$raw,true);if(!is_array($body))throw new ApiError('invalid_body','Некорректный запрос.');return $body; }
function workspace_user(int $id): ?array {
    $u=wquery('SELECT id,login,name,active,auth_version FROM users WHERE id=?',[$id])->fetch();if(!$u)return null;
    $u['id']=(int)$u['id'];$u['active']=(bool)$u['active'];$u['roles']=wquery('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.id',[$id])->fetchAll(PDO::FETCH_COLUMN);
    return $u;
}
function workspace_current_user(): array {
    static $user;
    if($user)return $user;
    $u=isset($_SESSION['user_id'])?workspace_user((int)$_SESSION['user_id']):null;
    if(!$u||!$u['active']||(int)$u['auth_version']!==(int)($_SESSION['auth_version']??0))throw new ApiError('unauthenticated','Войдите в систему.',401);
    return $user=$u;
}
function workspace_password_verify(string $password,string $hash): bool {
    if(str_starts_with($hash,'pbkdf2_sha256$')){ $p=explode('$',$hash);return count($p)===4&&hash_equals($p[3],hash_pbkdf2('sha256',$password,$p[2],(int)$p[1],64)); }
    return password_verify($password,$hash);
}
function workspace_auth_route(string $path): void {
    if($path==='/api/health'||str_starts_with($path,'/assets/'))return;
    if($path==='/login'){
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $b=wbody();$login=mb_strtolower(trim((string)($b['login']??'')));$password=(string)($b['password']??'');
            if(strlen($login)>80||strlen($password)>256)throw new ApiError('invalid_login','Проверьте логин и пароль.',401);
            $attemptKey=hash('sha256',$login.'|'.($_SERVER['REMOTE_ADDR']??''));
            wquery('INSERT INTO login_attempts(login_key,attempts) VALUES(?,1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<UTC_TIMESTAMP()-INTERVAL 5 MINUTE,1,attempts+1),window_start=IF(window_start<UTC_TIMESTAMP()-INTERVAL 5 MINUTE,UTC_TIMESTAMP(),window_start)',[$attemptKey]);
            $count=(int)wquery('SELECT attempts FROM login_attempts WHERE login_key=?',[$attemptKey])->fetchColumn();
            if($count>20)throw new ApiError('login_limited','Слишком много попыток. Повторите через 5 минут.',429);
            $u=wquery('SELECT * FROM users WHERE login=?',[$login])->fetch();
            if(!$u||!$u['active']||!workspace_password_verify($password,$u['password_hash']))throw new ApiError('invalid_login','Неверный логин или пароль.',401);
            wquery('DELETE FROM login_attempts WHERE login_key=?',[$attemptKey]);
            session_start();session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['auth_version']=(int)$u['auth_version'];$_SESSION['csrf']=bin2hex(random_bytes(32));session_write_close();
            json_response(['redirect'=>app_url_path('/vitrine?table=vyvoz')]);
        }
        if($_SERVER['REQUEST_METHOD']!=='GET')throw new ApiError('method','Метод не поддерживается.',405);
        header('Content-Type:text/html;charset=utf-8');require __DIR__.'/../views/login.php';exit;
    }
    if($path==='/logout'){
        if($_SERVER['REQUEST_METHOD']!=='POST')throw new ApiError('method','Выход выполняется кнопкой «Выйти».',405);
        session_start();$_SESSION=[];session_destroy();json_response(['redirect'=>app_url_path('/login')]);
    }
    try{workspace_current_user();}catch(ApiError $e){if($e->httpStatus!==401||str_starts_with($path,'/api/'))throw $e;header('Location: '.app_url_path('/login'),true,302);exit;}
}
/** Open business policy now; centralized rules permit future restrictions without UI bypasses. */
function workspace_can(string $type,string $resource,string $action,?int $tableId=null): bool {
    $u=workspace_current_user();
    $rules=wquery('SELECT resource_type,resource_id,action,effect FROM access_rules WHERE user_id=? OR role_id IN (SELECT role_id FROM user_roles WHERE user_id=?)',[$u['id'],$u['id']])->fetchAll();
    foreach($rules as $r){
        $object=$r['resource_type']===$type&&in_array($r['resource_id'],['*',$resource],true);
        $parent=$tableId!==null&&$r['resource_type']==='table'&&in_array($r['resource_id'],['*',(string)$tableId],true);
        if(($object||$parent)&&in_array($r['action'],['*',$action],true)&&$r['effect']==='deny')return false;
    }
    return true;
}
function workspace_require(string $type,string $id,string $action,?int $tableId=null): void {if(!workspace_can($type,$id,$action,$tableId))throw new ApiError('forbidden','Для этого действия нет доступа.',403);}
function workspace_table_display_title(string $slug,string $title): string {
    return $slug==='vyvoz' ? 'Автовывоз' : $title;
}

function workspace_table(?string $slug=null): array {
    $slug=$slug??(is_string($_GET['table']??null)?$_GET['table']:'vyvoz');
    $t=wquery('SELECT * FROM table_definitions WHERE slug=?',[$slug])->fetch();if(!$t)throw new ApiError('table_not_found','Таблица не найдена.',404);
    $t['id']=(int)$t['id'];workspace_require('table',(string)$t['id'],'view',$t['id']);$t['columns']=json_decode($t['columns_json'],true);
    $t['title']=workspace_table_display_title($t['slug'],$t['title']);
    return $t;
}
function workspace_manifest(array $t, bool $archive=false): array {
    $filter=$archive ? "AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('directory_deleted_record_',r.id))"
        : "AND (metadata_zlib IS NULL OR REGEXP_LIKE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(values_json,?)),''),'[^[:space:] ]'))";
    $args=$archive ? [$t['id'],1] : [$t['id'],0,'$.'.$t['carrier_column']];
    return array_map('intval',wquery("SELECT row_num FROM records r WHERE table_id=? AND archived=? $filter ORDER BY row_num DESC LIMIT 6000",$args)->fetchAll(PDO::FETCH_COLUMN));
}
function workspace_begin(): void {
    workspace_db()->beginTransaction();
    // One short writer lock gives outbox IDs commit order. No locks while the user types.
    wquery("SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE");
}
function workspace_event(string $type,array $payload): int {wquery('INSERT INTO outbox_events(event_type,payload) VALUES(?,?)',[$type,wjson($payload)]);return (int)workspace_db()->lastInsertId();}
function workspace_audit(array $e): int {
    $u=workspace_current_user();
    wquery('INSERT INTO change_history(actor_id,actor_name,table_id,record_id,row_num,field_key,column_label,before_value,after_value,action,category,operation_id,request_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$u['id'],$u['name'],$e['tableId']??null,$e['recordId']??null,$e['row']??null,$e['column']??null,$e['label']??null,wjson($e['before']??null),wjson($e['after']??null),$e['action']??'edit',$e['category']??'cell',$e['operationId']??bin2hex(random_bytes(12)),$e['requestId']??null]);return (int)workspace_db()->lastInsertId();
}
function workspace_tables(): array {
    $tables=wquery('SELECT t.id,t.slug,t.title,t.is_demo,COUNT(r.id) AS rowCount
        FROM table_definitions t LEFT JOIN records r ON r.table_id=t.id AND r.archived=FALSE
        WHERE t.is_demo=FALSE GROUP BY t.id ORDER BY t.id')->fetchAll();
    $visible=[];
    foreach ($tables as $table) {
        $id=(int)$table['id'];
        if (!workspace_can('table',(string)$id,'view',$id)) continue;
        $visible[]=['id'=>$id,'slug'=>$table['slug'],'title'=>workspace_table_display_title($table['slug'],$table['title']),
            'isDemo'=>(bool)$table['is_demo'],'rowCount'=>(int)$table['rowCount']];
    }
    return $visible;
}
function workspace_api(string $path): never {
    $method=$_SERVER['REQUEST_METHOD'];if(!in_array($method,['GET','POST'],true))throw new ApiError('method','Метод не поддерживается.',405);
    if($path==='/api/workspace/bootstrap'&&$method==='GET')json_response(['user'=>workspace_current_user(),'tables'=>workspace_tables(),'catalogs'=>workspace_catalogs(),'roles'=>wquery('SELECT id,name FROM roles ORDER BY id')->fetchAll(),'policyMode'=>'open','googleSyncEnabled'=>false]);
    if($path==='/api/workspace/manifest'&&$method==='GET'){
        $directory=vitrine_directory_requested();
        json_response(['rowIds'=>$directory ? workspace_directory_grid_data($directory,[])['rowIds'] : workspace_manifest(workspace_table(),($_GET['archive']??null)==='1')]);
    }
    if($path==='/api/workspace/preferences'){
        $directory=vitrine_directory_requested();
        $model=$directory ? workspace_directory_grid_model($directory) : workspace_table();
        json_response(workspace_preferences($model,$method==='POST'?wbody():null));
    }
    if($path==='/api/workspace/ws-ticket'&&$method==='POST'){
        $ticket=bin2hex(random_bytes(32));$u=workspace_current_user();wquery('DELETE FROM ws_tickets WHERE expires_at<UTC_TIMESTAMP()-INTERVAL 1 HOUR');wquery('INSERT INTO ws_tickets(token_hash,user_id,expires_at) VALUES(?,?,UTC_TIMESTAMP(6)+INTERVAL 30 SECOND)',[hash('sha256',$ticket),$u['id']]);
        json_response(['ticket'=>$ticket,'url'=>runtime_config()['WS_URL'],'cursor'=>(int)wquery('SELECT COALESCE(MAX(id),0) FROM outbox_events')->fetchColumn(),'userId'=>$u['id']]);
    }
    if($path==='/api/workspace/events'&&$method==='GET'){
        if((int)wquery('SELECT COUNT(*) FROM access_rules')->fetchColumn()>0)throw new ApiError('restricted_events_pending','Передача событий с ограниченными правами ещё не настроена.',403);
        $after=max(0,(int)($_GET['after']??0));$events=[];$cursor=$after;
        foreach(wquery('SELECT id,event_type,payload FROM outbox_events WHERE id>? ORDER BY id LIMIT 200',[$after])->fetchAll() as $e){$cursor=(int)$e['id'];$p=json_decode($e['payload'],true);if(isset($p['userId'])&&(int)$p['userId']!==workspace_current_user()['id'])continue;$events[]=['id'=>$cursor,'event'=>$e['event_type'],'data'=>$p];}
        json_response(['events'=>$events,'cursor'=>$cursor]);
    }
    if($path==='/api/workspace/history'&&$method==='GET'){
        $where=['1=1'];$args=[];
        if(!empty($_GET['table'])&&$_GET['table']!=='all'){$t=workspace_table((string)$_GET['table']);$where[]='h.table_id=?';$args[]=$t['id'];}
        foreach(['row'=>'h.row_num','user'=>'h.actor_id'] as $key=>$field)if(isset($_GET[$key])&&$_GET[$key]!==''){$where[]="$field=?";$args[]=(int)$_GET[$key];}
        if(!empty($_GET['before'])){$where[]='h.id<?';$args[]=(int)$_GET['before'];}
        if(!empty($_GET['column'])){$where[]='h.field_key=?';$args[]=(string)$_GET['column'];}
        if(!empty($_GET['category'])){$where[]='h.category=?';$args[]=(string)$_GET['category'];}
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op)if(!empty($_GET[$key])){ $date=(string)$_GET[$key];if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new ApiError('invalid_date','Некорректная дата фильтра.');$where[]="h.created_at $op ?";$args[]=$date.($key==='to'?' 23:59:59.999999':' 00:00:00'); }
        $limit=max(1,min(100,(int)($_GET['limit']??50)));$rows=wquery('SELECT h.*,t.title AS table_title FROM change_history h LEFT JOIN table_definitions t ON t.id=h.table_id WHERE '.implode(' AND ',$where).' ORDER BY h.id DESC LIMIT '.($limit+1),$args)->fetchAll();$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$events=[];
        foreach($rows as $r){if($r['table_id']&&!workspace_can('table',(string)$r['table_id'],'view',(int)$r['table_id']))continue;$events[]=['id'=>(int)$r['id'],'createdAt'=>utc_iso($r['created_at']),'actorName'=>$r['actor_name'],'actorId'=>$r['actor_id'],'tableTitle'=>$r['table_title']??'Система / справочники','rowNumber'=>$r['row_num'],'recordId'=>$r['record_id'],'columnLabel'=>$r['column_label'],'column'=>$r['field_key'],'beforeValue'=>json_decode($r['before_value']??'null',true),'afterValue'=>json_decode($r['after_value']??'null',true),'action'=>$r['action'],'category'=>$r['category'],'operationId'=>$r['operation_id']];}
        json_response(['events'=>$events,'nextCursor'=>$more&&$rows?(int)end($rows)['id']:null]);
    }
    if($path==='/api/workspace/users')workspace_employees_endpoint($method);
    if($path==='/api/workspace/catalogs')workspace_catalogs_endpoint($method);
    if($path==='/api/workspace/reports'&&$method==='GET'){
        $tables=workspace_tables();json_response(['tables'=>$tables,'totals'=>['tables'=>count($tables),'rows'=>array_sum(array_column($tables,'rowCount')),'changes'=>(int)wquery('SELECT COUNT(*) FROM change_history')->fetchColumn()],'byUser'=>wquery("SELECT actor_name AS name,COUNT(*) AS count FROM change_history GROUP BY actor_name ORDER BY count DESC")->fetchAll(),'byTable'=>wquery('SELECT t.title,COUNT(h.id) AS count FROM table_definitions t LEFT JOIN change_history h ON h.table_id=t.id GROUP BY t.id ORDER BY t.id')->fetchAll()]);
    }
    if($path==='/api/workspace/row'&&$method==='POST'){
        $b=wbody();$t=workspace_table();workspace_require('table',(string)$t['id'],'edit',$t['id']);workspace_begin();try{
            if(($b['action']??'')==='create'){$num=(int)wquery('SELECT COALESCE(MAX(row_num),1)+1 FROM records WHERE table_id=?',[$t['id']])->fetchColumn();$v=[];foreach($t['columns'] as $f)$v[$f['letter']]='';wquery('INSERT INTO records(table_id,row_num,values_json,baseline_json) VALUES(?,?,?,?)',[$t['id'],$num,wjson($v),wjson($v)]);$rid=(int)workspace_db()->lastInsertId();workspace_audit(['tableId'=>$t['id'],'recordId'=>$rid,'row'=>$num,'action'=>'create','category'=>'row','after'=>$v]);}
            else{$num=(int)($b['row']??0);$r=wquery('SELECT * FROM records WHERE table_id=? AND row_num=? FOR UPDATE',[$t['id'],$num])->fetch();if(!$r)throw new ApiError('not_found','Строка не найдена.',404);$archived=($b['action']??'')==='archive';if(!in_array($b['action']??'',['archive','restore'],true))throw new ApiError('invalid_action','Неизвестное действие.');wquery('UPDATE records SET archived=? WHERE id=?',[(int)$archived,$r['id']]);workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$num,'action'=>$archived?'archive':'restore','category'=>'row','before'=>['archived'=>(bool)$r['archived']],'after'=>['archived'=>$archived]]);}
            workspace_event('row.changed',['tableSlug'=>$t['slug'],'row'=>$num]);workspace_db()->commit();json_response(['row'=>$num]);
        }catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();throw $e;}
    }
    throw new ApiError('not_found','Раздел API не найден.',404);
}
