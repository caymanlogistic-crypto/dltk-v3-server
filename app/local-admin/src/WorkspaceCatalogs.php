<?php
declare(strict_types=1);
require_once __DIR__.'/WorkspaceClientManagers.php';
require_once __DIR__.'/WorkspaceManagerDirectory.php';
require_once __DIR__.'/WorkspaceUnloadPoints.php';
require_once __DIR__.'/WorkspaceClientUnloadPoints.php';
require_once __DIR__.'/WorkspaceClientTransfer.php';
require_once __DIR__.'/WorkspacePickupTransfer.php';

function vitrine_catalog_key(string $name): string {
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)));
}

function workspace_catalogs(): array {
    $out=[];foreach(wquery('SELECT * FROM catalogs ORDER BY id')->fetchAll() as $c){if(!workspace_can('catalog',(string)$c['id'],'view'))continue;$c['id']=(int)$c['id'];$c['tableIds']=array_map('intval',wquery('SELECT DISTINCT table_id FROM catalog_bindings WHERE catalog_id=?',[$c['id']])->fetchAll(PDO::FETCH_COLUMN));$out[]=$c;}return $out;
}
function workspace_palette(): array {return [['name'=>'Красный','fill'=>'#F4D9DA'],['name'=>'Оранжевый','fill'=>'#F7E1CB'],['name'=>'Жёлтый','fill'=>'#F7F2D2'],['name'=>'Зелёный','fill'=>'#DCEAD4'],['name'=>'Бирюзовый','fill'=>'#CFE9E4'],['name'=>'Синий','fill'=>'#D8E6F4'],['name'=>'Фиолетовый','fill'=>'#DFDBF1'],['name'=>'Розовый','fill'=>'#EFD9E8'],['name'=>'Серый','fill'=>'#E7E7E7']];}
function workspace_catalog_has_palette(string $kind): bool {
    return in_array($kind,['status','terminals-main'],true);
}
function workspace_status_catalog(array $table): array {
    $id=wquery("SELECT c.id FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id WHERE b.table_id=? AND b.field_key='A' AND c.kind='status' LIMIT 1",[$table['id']])->fetchColumn();
    return ['id'=>(int)$id,'statuses'=>$id?wquery('SELECT id,name,color,revision FROM catalog_entries WHERE catalog_id=? AND active=TRUE ORDER BY id',[$id])->fetchAll():[],'palette'=>workspace_palette()];
}
function workspace_catalog_validate_name(string $value): string {
    $name=trim(preg_replace('/\s+/u',' ',$value));
    if($name===''||mb_strlen($name)>160||preg_match('/[\x00-\x1F]/u',$name))throw new ApiError('invalid_catalog','Укажите название до 160 символов.');
    return $name;
}

function workspace_catalog_save(array $b): array {
    $cid=(int)($b['catalogId']??0);$catalog=wquery('SELECT * FROM catalogs WHERE id=?',[$cid])->fetch();if(!$catalog)throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$cid,'edit');$name=workspace_catalog_validate_name((string)($b['name']??''));$color=$b['color']??null;
    if(!workspace_catalog_has_palette($catalog['kind']))$color=null;
    if(($catalog['kind']==='status'||$color!==null)&&!in_array($color,array_column(workspace_palette(),'fill'),true)){
        throw new ApiError('invalid_color','Выберите цвет из палитры.');
    }
    $hasManager=$catalog['kind']==='clients'&&array_key_exists('manager',$b);
    $manager=$hasManager?workspace_client_validate_manager($b['manager']):'';
    $hasLogist=$catalog['kind']==='carriers'&&array_key_exists('logist',$b);
    $logist=$hasLogist?workspace_client_validate_manager($b['logist']):'';
    workspace_begin();try{
        $old=isset($b['id'])?wquery('SELECT * FROM catalog_entries WHERE id=? AND catalog_id=? FOR UPDATE',[(int)$b['id'],$cid])->fetch():null;
        if(isset($b['id'])&&!$old)throw new ApiError('not_found','Запись не найдена.',404);
        if($old&&(int)$old['revision']!==(int)($b['revision']??0))throw new ApiError('revision_conflict','Запись уже изменена. Откройте её заново; ваш ввод сохранён.',409);
        $profileCatalog=in_array($catalog['kind'],['unload-points','managers'],true);
        if($profileCatalog&&(!$old||!isset($b['active'])||$name!==$old['name'])){
            throw new ApiError('invalid_action','Откройте справочник для редактирования записи.');
        }
        $active=array_key_exists('active',$b)?(bool)$b['active']:true;
        $norm=$profileCatalog?$old['normalized']:mb_strtolower($name);
        $revision=$old?(int)$old['revision']+1:1;
        if($catalog['kind']==='terminals-main'&&!array_key_exists('color',$b))$color=$old['color']??null;
        if(in_array($catalog['kind'],['clients','terminals-main'],true)){
            $formerOwner=(int)(workspace_client_catalog_aliases($cid)[$norm]??0);
            if($formerOwner&&$formerOwner!==(int)($old['id']??0)){
                $ownerLabel=$catalog['kind']==='clients'?'другого клиента':'другого терминала';
                throw new ApiError('duplicate_catalog','Это название уже использовалось для '.$ownerLabel.'.',409);
            }
        }
        $archive=($b['archive']??null)===true && in_array($catalog['kind'],['clients','managers'],true);
        if ($old && $old['active'] && !$active && !$archive) workspace_assert_catalog_entry_unused($catalog['kind'],$cid,$old);
        $logists=$hasLogist?workspace_client_catalog_metadata('catalog_logists_'.$cid):[];
        $beforeLogist=$old?($logists[$old['id']]??''):'';
        $logistChanged=$hasLogist&&$beforeLogist!==$logist;
        $beforeManager=$old&&$hasManager?(workspace_client_manager_values($cid)[$old['id']]??''):'';
        $managerChanged=$hasManager&&$beforeManager!==$manager;
        if($old&&$old['name']===$name&&$old['color']===$color&&(bool)$old['active']===$active&&!$managerChanged&&!$logistChanged){workspace_db()->commit();return ['id'=>(int)$old['id'],'revision'=>(int)$old['revision']];}
        if($old){wquery('UPDATE catalog_entries SET name=?,normalized=?,color=?,active=?,revision=? WHERE id=?',[$name,$norm,$color,(int)$active,$revision,$old['id']]);$id=(int)$old['id'];}
        else{wquery('INSERT INTO catalog_entries(catalog_id,name,normalized,color,active) VALUES(?,?,?,?,?)',[$cid,$name,$norm,$color,(int)$active]);$id=(int)workspace_db()->lastInsertId();}
        if($managerChanged)workspace_client_set_manager($cid,$id,$manager);
        if($logistChanged){
            $logists[$id]=$logist;
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['catalog_logists_'.$cid,wjson((object)$logists)]);
        }
        if($old&&$catalog['kind']!=='status'&&$old['normalized']!==$norm)workspace_client_remember_alias($cid,$id,$old['normalized']);
        $before=$old?['id'=>$id,'name'=>$old['name'],'color'=>$old['color'],'active'=>(bool)$old['active']]:null;
        $after=['id'=>$id,'name'=>$name,'color'=>$color,'active'=>$active];
        if($hasManager){
            if($before!==null)$before['manager']=$beforeManager;
            $after['manager']=$manager;
        }
        if($hasLogist){
            if($before!==null)$before['logist']=$beforeLogist;
            $after['logist']=$logist;
        }
        $action=!$old?'create':(!$active?'archive':(!$old['active']?'restore':'edit'));
        workspace_audit(['category'=>'catalog','label'=>$catalog['title'],'before'=>$before,'after'=>$after,'action'=>$action]);
        workspace_event('catalog.changed',['catalogId'=>$cid,'id'=>$id,'name'=>$name,'beforeName'=>$old['name']??null,'color'=>$color,'active'=>$active,'revision'=>$revision,'bindings'=>wquery('SELECT b.table_id AS tableId,t.slug AS tableSlug,b.field_key AS fieldKey FROM catalog_bindings b JOIN table_definitions t ON t.id=b.table_id WHERE b.catalog_id=?',[$cid])->fetchAll()]);workspace_db()->commit();return ['id'=>$id,'revision'=>$revision];
    }catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new ApiError('duplicate_catalog','Такое название уже есть в справочнике.',409);throw $e;}
}

function workspace_catalogs_endpoint(string $method): never {
    if(!in_array($method,['GET','POST'],true))throw new ApiError('method','Метод не поддерживается.',405);
    if($method==='POST'){
        $b=wbody();
        if (($b['action']??'')==='save_client_managers') {
            if (!is_int($b['clientId']??null)) throw new ApiError('invalid_edit','Выберите клиента.');
            json_response(workspace_client_manager_card($b['clientId'],$b));
        }
        if (in_array($b['action']??null,['save_client_unload_point','delete_client_unload_point'],true)) {
            if (!is_int($b['clientId']??null)) throw new ApiError('invalid_edit','Выберите клиента.');
            json_response(workspace_client_unload_points($b['clientId'],$b));
        }
        if (($b['action']??'')==='save_unload_contacts') {
            if (!is_int($b['catalogId']??null) || !is_int($b['entryId']??null)) throw new ApiError('invalid_contacts','Выберите точку выгрузки.');
            json_response(workspace_unload_contacts($b['catalogId'],$b['entryId'],$b));
        }
        if (($b['action'] ?? '') === 'import_pickup_directory') json_response(workspace_import_pickup_directory($b));
        if (($b['action'] ?? '') === 'import_client_directory') json_response(workspace_import_client_directory($b));
        if(($b['action']??'')==='create_catalog'){
        $title=trim((string)($b['title']??''));$ids=array_values(array_unique(array_map('intval',$b['tableIds']??[])));$scope=$b['scope']??'local';$tables=array_column(workspace_tables(),'id');
        if($title===''||mb_strlen($title)>160||!$ids||array_diff($ids,$tables)||!in_array($scope,['local','shared'],true)||($scope==='local'&&count($ids)!==1))throw new ApiError('invalid_catalog','Укажите название и таблицы справочника.');
        workspace_begin();try{wquery('INSERT INTO catalogs(title,scope) VALUES(?,?)',[$title,$scope]);$id=(int)workspace_db()->lastInsertId();foreach($ids as $tid)wquery("INSERT INTO catalog_bindings VALUES(?,?,'')",[$id,$tid]);workspace_audit(['category'=>'catalog','label'=>'Справочник','after'=>['id'=>$id,'title'=>$title,'tableIds'=>$ids],'action'=>'create']);workspace_event('catalog.changed',['catalogId'=>$id]);workspace_db()->commit();json_response(['id'=>$id]);}catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();throw $e;}
    }
    if(($b['action']??'')==='refresh_catalog_source'){
        $catalogId=(int)($b['catalogId']??0);
        if(!$catalogId||!function_exists('workspace_refresh_catalog_source')){
            throw new ApiError('catalog_source_unavailable','Источник справочника недоступен.',409);
        }
        workspace_require('catalog',(string)$catalogId,'edit');
        json_response(workspace_refresh_catalog_source($catalogId,(int)workspace_current_user()['id']));
    }
    json_response(workspace_catalog_save($b));}
    if (isset($_GET['clientManagers'])) {
        $clientId=filter_var($_GET['clientManagers'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$clientId) throw new ApiError('invalid_edit','Выберите клиента.');
        json_response(workspace_client_manager_card($clientId));
    }
    if (isset($_GET['clientUnloadPoints'])) {
        $clientId=filter_var($_GET['clientUnloadPoints'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$clientId) throw new ApiError('invalid_edit','Выберите клиента.');
        json_response(workspace_client_unload_points($clientId));
    }
    $id=(int)($_GET['id']??0);$catalog=null;foreach(workspace_catalogs() as $c)if($c['id']===$id)$catalog=$c;if(!$catalog)throw new ApiError('not_found','Справочник не найден.',404);
    if (isset($_GET['unloadContacts'])) {
        json_response(workspace_unload_contacts($id,(int)$_GET['unloadContacts']));
    }
    $detailsKey='catalog_details_'.$id;
    $unkeyed=($_GET['unkeyed']??null)==='1';
    if(isset($_GET['entryId'])||$unkeyed){
        $entryId=(int)($_GET['entryId']??0);
        if(!$unkeyed&&!wquery('SELECT id FROM catalog_entries WHERE catalog_id=? AND id=?',[$id,$entryId])->fetchColumn()){
            throw new ApiError('not_found','Запись справочника не найдена.',404);
        }
        $path=$unkeyed?'$.unkeyed':'$.entries."'.$entryId.'"';
        $details=wquery("SELECT JSON_EXTRACT(value,'$.columns') AS columns,JSON_EXTRACT(value,?) AS occurrences FROM workspace_meta WHERE `key`=?",[$path,$detailsKey])->fetch();
        json_response([
            'columns'=>json_decode($details['columns']??'[]',true)??[],
            'occurrences'=>json_decode($details['occurrences']??'[]',true)??[],
        ]);
    }
    $detailsSummary=wquery("SELECT COALESCE(JSON_LENGTH(JSON_EXTRACT(value,'$.unkeyed')),0) AS unkeyedCount FROM workspace_meta WHERE `key`=?",[$detailsKey])->fetch();
    $hasDetails=(bool)$detailsSummary;
    $sourceSyncLabel=$hasDetails&&function_exists('workspace_catalog_source_label')&&workspace_can('catalog',(string)$id,'edit')
        ?workspace_catalog_source_label($id):null;
    $entries=wquery('SELECT id,name,color,revision,active FROM catalog_entries WHERE catalog_id=? ORDER BY active DESC,id',[$id])->fetchAll();foreach($entries as &$e){$e['id']=(int)$e['id'];$e['revision']=(int)$e['revision'];$e['active']=(bool)$e['active'];}unset($e);
    json_response(['catalog'=>$catalog,'entries'=>$entries,'hasDetails'=>$hasDetails,'unkeyedCount'=>(int)($detailsSummary['unkeyedCount']??0),'sourceSyncLabel'=>$sourceSyncLabel,'palette'=>array_map(fn($p)=>$p+['key'=>$p['fill'],'hex'=>$p['fill']],workspace_palette())]);
}
