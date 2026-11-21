<?php
declare(strict_types=1);
require_once __DIR__.'/src/bootstrap.php';
try {
    secure_request();
    $path=app_route_path(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
    // Nginx serves these directly; this branch supports PHP development tests.
    if(preg_match('~^/assets/(?:vitrine/)?[a-zA-Z0-9_.-]+\.(?:css|js|svg|ico|woff2)$~',$path)&&is_file(__DIR__.'/public'.$path))return false;
    require_once __DIR__.'/src/Workspace.php';
    if($path==='/api/health'){
        if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true))throw new ApiError('method','Метод не поддерживается.',405);
        workspace_db()->query('SELECT 1');
        json_response(['marker'=>APP_MARKER,'status'=>'ok','database'=>'ready','googleSyncEnabled'=>false]);
    }
    workspace_auth_route($path);
    if(str_starts_with($path,'/api/workspace/'))workspace_api($path);
    if ($path === '/workspace' && ($_GET['section'] ?? null) === 'users') {
        $url = '/vitrine?catalog=employees';
        header('Location: '.(function_exists('app_url_path') ? app_url_path($url) : $url),true,302);
        return;
    }
    if($path==='/workspace'){$currentUser=workspace_current_user();require __DIR__.'/views/workspace.php';return;}
    if($path==='/'||$path==='/index.php'){header('Location: '.app_url_path('/vitrine?table=vyvoz'),true,302);return;}
    if(in_array($path,['/api/vitrine/rows','/api/vitrine/paste-check','/api/vitrine/statuses','/api/vitrine/cell','/vitrine','/vitrine/'],true)){
        require __DIR__.'/src/Vitrine.php';
        match($path){'/api/vitrine/rows'=>vitrine_rows_endpoint(),'/api/vitrine/paste-check'=>vitrine_paste_check(),'/api/vitrine/statuses'=>vitrine_statuses_endpoint(),'/api/vitrine/cell'=>vitrine_save_cell(),default=>render_vitrine()};return;
    }
    throw new ApiError('not_found','Страница не найдена.',404);
}catch(ApiError $e){json_response(['error'=>['code'=>$e->publicCode,'message'=>$e->publicMessage]],$e->httpStatus);}
catch(Throwable $e){json_response(['error'=>['code'=>'server_error','message'=>'Не удалось выполнить запрос. Проверьте рабочую БД сервера.']],503);}
