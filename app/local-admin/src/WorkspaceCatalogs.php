<?php
declare(strict_types=1);
require_once __DIR__.'/WorkspaceClientManagers.php';
require_once __DIR__.'/WorkspaceManagerDirectory.php';
require_once __DIR__.'/WorkspaceUnloadPoints.php';
require_once __DIR__.'/WorkspaceClientUnloadPoints.php';
require_once __DIR__.'/WorkspaceClientTransfer.php';
require_once __DIR__.'/WorkspacePickupTransfer.php';
require_once __DIR__.'/WorkspaceDropoffPoints.php';
require_once __DIR__.'/WorkspaceCarrierDirectory.php';
require_once __DIR__.'/WorkspaceCarrierFleet.php';
require_once __DIR__.'/WorkspaceEdoOperators.php';
require_once __DIR__.'/WorkspaceEdoCounterparties.php';
require_once __DIR__.'/WorkspaceMarginRules.php';
require_once __DIR__.'/WorkspaceContainerTypeRules.php';

function vitrine_catalog_key(string $name): string {
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name)));
}

function workspace_catalogs(): array {
    $out=[];foreach(wquery('SELECT * FROM catalogs ORDER BY id')->fetchAll() as $c){if(workspace_fleet_retired_kind($c['kind'])||!workspace_can('catalog',(string)$c['id'],'view'))continue;$c['id']=(int)$c['id'];$c['tableIds']=array_map('intval',wquery('SELECT DISTINCT table_id FROM catalog_bindings WHERE catalog_id=?',[$c['id']])->fetchAll(PDO::FETCH_COLUMN));$out[]=$c;}return $out;
}
function workspace_palette(): array {return [['name'=>'Красный','fill'=>'#F4D9DA'],['name'=>'Оранжевый','fill'=>'#F7E1CB'],['name'=>'Жёлтый','fill'=>'#F7F2D2'],['name'=>'Зелёный','fill'=>'#DCEAD4'],['name'=>'Бирюзовый','fill'=>'#CFE9E4'],['name'=>'Синий','fill'=>'#D8E6F4'],['name'=>'Фиолетовый','fill'=>'#DFDBF1'],['name'=>'Розовый','fill'=>'#EFD9E8'],['name'=>'Серый','fill'=>'#E7E7E7']];}
function workspace_is_document_status_catalog(string $kind): bool {
    return in_array($kind,['assignment-statuses','etrn-statuses','etrn-signing-statuses'],true);
}
function workspace_catalog_has_palette(string $kind): bool {
    return in_array($kind,['status','terminals-main'],true) || workspace_is_document_status_catalog($kind);
}
function workspace_status_catalog(array $table): array {
    $id=wquery("SELECT c.id FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id WHERE b.table_id=? AND b.field_key='A' AND c.kind='status' LIMIT 1",[$table['id']])->fetchColumn();
    $statuses=$id ? wquery('SELECT id,name,color,revision FROM catalog_entries WHERE catalog_id=? AND active=TRUE ORDER BY id',[$id])->fetchAll() : [];
    return ['id'=>(int)$id,'statuses'=>array_values(workspace_directory_order_entries($statuses,(int)$id)),'palette'=>workspace_palette()];
}
function workspace_catalog_validate_name(string $value): string {
    $name=trim(preg_replace('/\s+/u',' ',$value));
    if($name===''||mb_strlen($name)>160||preg_match('/[\x00-\x1F]/u',$name))throw new ApiError('invalid_catalog','Укажите название до 160 символов.');
    return $name;
}

function workspace_catalog_save(array $b, ?string $deleteReason=null): array {
    return workspace_transaction(static fn()=>workspace_catalog_write($b,$deleteReason));
}

function workspace_catalog_write(array $b, ?string $deleteReason=null): array {
    $cid=(int)($b['catalogId']??0);$catalog=wquery('SELECT * FROM catalogs WHERE id=?',[$cid])->fetch();if(!$catalog||workspace_fleet_retired_kind($catalog['kind']))throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$cid,'edit');$name=workspace_catalog_validate_name((string)($b['name']??''));$color=$b['color']??null;
    if(!workspace_catalog_has_palette($catalog['kind']))$color=null;
    if(($catalog['kind']==='status'||$color!==null)&&!in_array($color,array_column(workspace_palette(),'fill'),true)){
        throw new ApiError('invalid_color','Выберите цвет из палитры.');
    }
    $hasManager=$catalog['kind']==='clients'&&array_key_exists('manager',$b);
    $manager=$hasManager?workspace_client_validate_manager($b['manager'],workspace_client_account_managers_enabled()):'';
    $clientRequisites=[];
    if ($catalog['kind']==='clients' && array_key_exists('clientRequisites',$b)) {
        if (!is_array($b['clientRequisites'])) throw new ApiError('invalid_client_requisites','Проверьте реквизиты клиента.');
        $clientRequisites=workspace_client_validate_requisites($b['clientRequisites']);
    }
    $hasLogist=$catalog['kind']==='carriers'&&array_key_exists('logist',$b);
    $logist=$hasLogist?workspace_client_validate_manager($b['logist']):'';
    $carrierDetails=$catalog['kind']==='carriers' && isset($b['carrierDetails'])
        ? workspace_carrier_details($b['carrierDetails']) : null;
    $partyCatalog=$catalog['kind']==='edo-counterparties';
    if ($partyCatalog && isset($b['edoParty']) && !is_array($b['edoParty'])) {
        throw new ApiError('invalid_edo_party','Проверьте реквизиты контрагента.');
    }
    $partyProfile=$partyCatalog && isset($b['edoParty']) ? workspace_edo_party_profile($b['edoParty']) : null;
    $marginRule=null;
    if ($catalog['kind']==='margin-rules') {
        $name=workspace_margin_rule_date($name);
        if (array_key_exists('marginRule',$b)) {
            $marginRule=workspace_margin_rule_profile($b['marginRule']);
        } elseif (!isset($b['id']) || !array_key_exists('active',$b)) {
            throw new ApiError('invalid_margin_rule','Заполните дату и оба минимума.');
        }
    }
    $dropoffProfile=null;
    if ($catalog['kind']==='dropoff-points' && array_key_exists('dropoffProfile',$b)) {
        if (!is_array($b['dropoffProfile'])) throw new ApiError('invalid_dropoff','Проверьте данные точки сдачи.');
        $dropoffProfile=workspace_dropoff_profile($b['dropoffProfile']);
    }
    $containerRule=$catalog['kind']==='container-types' && array_key_exists('containerRule',$b)
        ? workspace_container_type_validate_rule($b['containerRule']) : null;
    try{
        $old=isset($b['id'])?wquery('SELECT * FROM catalog_entries WHERE id=? AND catalog_id=? FOR UPDATE',[(int)$b['id'],$cid])->fetch():null;
        if(isset($b['id'])&&!$old)throw new ApiError('not_found','Запись не найдена.',404);
        if ($old && workspace_row_deleted('catalog_deleted_client_',(int)$old['id'])) {
            throw new ApiError('not_found','Запись удалена.',404);
        }
        if($old&&(int)$old['revision']!==(int)($b['revision']??0))throw new ApiError('revision_conflict','Запись уже изменена. Откройте её заново; ваш ввод сохранён.',409);
        $profileCatalog=in_array($catalog['kind'],['unload-points','managers',...WORKSPACE_FLEET_KINDS],true);
        if($profileCatalog&&(!$old||!isset($b['active'])||$name!==$old['name'])){
            throw new ApiError('invalid_action','Откройте справочник для редактирования записи.');
        }
        $active=array_key_exists('active',$b)?(bool)$b['active']:true;
        $edoOperator=$catalog['kind']==='edo-operators';
        $norm=$edoOperator ? strtolower(workspace_edo_operator_code($b['operatorCode']??$old['normalized']??''))
            : ($profileCatalog ? $old['normalized'] : mb_strtolower($name));
        $beforeParty=$partyCatalog && $old ? workspace_client_catalog_metadata('edo_party_'.$old['id']) : [];
        if ($partyCatalog) {
            if (!$old && $partyProfile===null) throw new ApiError('invalid_edo_party','Укажите реквизиты контрагента.');
            $norm=$partyProfile===null ? $old['normalized'] : workspace_edo_party_key($name,$partyProfile);
        }
        $partyChanged=$partyProfile!==null && $partyProfile!==$beforeParty;
        if ($partyChanged && $old) workspace_edo_party_assert_roles($beforeParty,$partyProfile,(int)$old['id']);
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
        if ($deleteReason!==null || ($edoOperator && $old && $old['normalized']!==$norm) || ($old && $old['active'] && !$active &&
            (!$archive || !in_array($catalog['kind'],['clients','managers'],true)))) {
            workspace_assert_catalog_entry_unused($catalog['kind'],$cid,$old);
        }
        $logists=$hasLogist?workspace_client_catalog_metadata('catalog_logists_'.$cid):[];
        $logistAccounts=$hasLogist?workspace_carrier_logist_accounts($cid):[];
        $beforeLogist=$old&&$hasLogist?(workspace_carrier_logist_values($cid)[$old['id']]??''):'';
        $beforeAccount=$old?(int)($logistAccounts[$old['id']]??0):0;
        $logistAccount=0;
        if ($hasLogist) [$logist,$logistAccount]=workspace_carrier_logist_input($cid,(int)($old['id']??0),$logist);
        $logistChanged=$hasLogist&&($beforeLogist!==$logist||$beforeAccount!==$logistAccount);
        $beforeManager=$old&&$hasManager?(workspace_client_manager_values($cid)[$old['id']]??''):'';
        $managerChanged=$hasManager&&$beforeManager!==$manager;
        $beforeRequisites=array_fill_keys(array_keys(WORKSPACE_CLIENT_REQUISITE_LABELS),'');
        if ($clientRequisites && $old) {
            $beforeRequisites=workspace_client_catalog_metadata('client_requisites_'.$old['id']) + $beforeRequisites;
        }
        $afterRequisites=array_replace($beforeRequisites,$clientRequisites);
        $requisitesChanged=$afterRequisites!==$beforeRequisites;
        $beforeCarrier=$carrierDetails!==null && $old ? workspace_carrier_entry_details((int)$old['id']) : workspace_carrier_details([]);
        if ($carrierDetails!==null) $carrierDetails['C']=$beforeCarrier['C']; // Preserve the archived source text.
        $carrierChanged=$carrierDetails!==null && $beforeCarrier!==$carrierDetails;
        $beforeRule=$marginRule!==null && $old
            ? workspace_client_catalog_metadata('margin_rule_'.$old['id']) : [];
        $ruleChanged=$marginRule!==null && $marginRule!==$beforeRule;
        $beforeDropoff=$dropoffProfile!==null && $old ? workspace_dropoff_profiles($cid)[(int)$old['id']]??[] : [];
        $dropoffChanged=$dropoffProfile!==null && $beforeDropoff!==$dropoffProfile;
        $beforeContainer=$containerRule!==null && $old ? workspace_container_type_profile($old['name'],
            workspace_client_catalog_metadata('container_type_rule_'.$old['id'])) : null;
        $containerChanged=$containerRule!==null && $containerRule!=$beforeContainer;
        if($deleteReason===null&&$old&&$old['name']===$name&&$old['normalized']===$norm&&$old['color']===$color&&(bool)$old['active']===$active&&!$managerChanged&&!$requisitesChanged&&!$logistChanged&&!$dropoffChanged&&!$carrierChanged&&!$ruleChanged&&!$partyChanged&&!$containerChanged){return ['id'=>(int)$old['id'],'revision'=>(int)$old['revision']];}
        if($old){wquery('UPDATE catalog_entries SET name=?,normalized=?,color=?,active=?,revision=? WHERE id=?',[$name,$norm,$color,(int)$active,$revision,$old['id']]);$id=(int)$old['id'];}
        else{wquery('INSERT INTO catalog_entries(catalog_id,name,normalized,color,active) VALUES(?,?,?,?,?)',[$cid,$name,$norm,$color,(int)$active]);$id=(int)workspace_db()->lastInsertId();}
        if($managerChanged)workspace_client_set_manager($cid,$id,$manager);
        if($logistChanged){
            $logists[$id]=$logist;
            if ($logistAccount) $logistAccounts[$id]=$logistAccount;
            else unset($logistAccounts[$id]);
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['catalog_logists_'.$cid,wjson((object)$logists)]);
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['catalog_logist_accounts_'.$cid,wjson((object)$logistAccounts)]);
        }
        if($old&&!$edoOperator&&!$partyCatalog&&$catalog['kind']!=='status'&&$old['normalized']!==$norm)workspace_client_remember_alias($cid,$id,$old['normalized']);
        $before=$old?['id'=>$id,'name'=>$old['name'],'color'=>$old['color'],'active'=>(bool)$old['active']]:null;
        $after=['id'=>$id,'name'=>$name,'color'=>$color,'active'=>$active];
        if ($edoOperator) {
            if ($before!==null) $before['code']=strtoupper($old['normalized']);
            $after['code']=strtoupper($norm);
        }
        if ($partyChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['edo_party_'.$id,wjson($partyProfile)]);
            if ($before!==null) $before['edoParty']=$beforeParty;
            $after['edoParty']=$partyProfile;
        }
        if ($ruleChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['margin_rule_'.$id,wjson($marginRule)]);
            if ($before!==null) $before['marginRule']=$beforeRule;
            $after['marginRule']=$marginRule;
        }
        if ($carrierChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['carrier_details_'.$id,wjson($carrierDetails)]);
            if ($before!==null) $before['carrierDetails']=$beforeCarrier;
            $after['carrierDetails']=$carrierDetails;
        }
        if ($dropoffChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['dropoff_point_'.$id,wjson($dropoffProfile)]);
            if ($before!==null) $before['profile']=$beforeDropoff;
            $after['profile']=$dropoffProfile;
        }
        if ($containerChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['container_type_rule_'.$id,wjson($containerRule)]);
            if ($before!==null) $before['containerRule']=$beforeContainer;
            $after['containerRule']=$containerRule;
        }
        if ($requisitesChanged) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['client_requisites_'.$id,wjson($afterRequisites)]);
            if ($before!==null) $before['clientRequisites']=$beforeRequisites;
            $after['clientRequisites']=$afterRequisites;
        }
        if($hasManager){
            if($before!==null)$before['manager']=$beforeManager;
            $after['manager']=$manager;
        }
        if($hasLogist){
            if($before!==null) $before+=['logist'=>$beforeLogist,'logistAccountId'=>$beforeAccount ?: null];
            $after+=['logist'=>$logist,'logistAccountId'=>$logistAccount ?: null];
        }
        $action=!$old?'create':(!$active?'archive':(!$old['active']?'restore':'edit'));
        $audit=['category'=>'catalog','label'=>$catalog['title']];
        if ($deleteReason!==null) {
            workspace_mark_row_deleted('catalog_deleted_client_',$id);
            $action='delete';
            $after+=['deleted'=>true,'reason'=>$deleteReason];
            $tableId=wquery('SELECT table_id FROM catalog_bindings WHERE catalog_id=? ORDER BY table_id LIMIT 1',[$cid])->fetchColumn();
            if ($tableId) $audit['tableId']=(int)$tableId;
        }
        workspace_audit($audit+['before'=>$before,'after'=>$after,'action'=>$action]);
        $event=['catalogId'=>$cid,'id'=>$id,'name'=>$name,'beforeName'=>$old['name']??null,'kind'=>$catalog['kind'],'color'=>$color,'active'=>$active,'revision'=>$revision,'bindings'=>wquery('SELECT b.table_id AS tableId,t.slug AS tableSlug,b.field_key AS fieldKey FROM catalog_bindings b JOIN table_definitions t ON t.id=b.table_id WHERE b.catalog_id=?',[$cid])->fetchAll()];
        if ($catalog['kind']==='container-types') {
            $event['containerRule']=$containerRule??workspace_container_type_profile($name,workspace_client_catalog_metadata('container_type_rule_'.$id));
            $event['color']=$event['containerRule']['typeColor'];
        }
        workspace_event('catalog.changed',$event);
        return ['id'=>$id,'revision'=>$revision];
    }catch(Throwable $e){if($e instanceof PDOException&&$e->getCode()==='23000')throw new ApiError('duplicate_catalog',$partyCatalog ? 'Контрагент с такими реквизитами уже есть.' : ($catalog['kind']==='edo-operators' ? 'Этот код уже есть в справочнике операторов ЭДО.' : ($catalog['kind']==='margin-rules' ? 'На эту дату уже есть правило маржи.' : 'Такое название уже есть в справочнике.')),409);throw $e;}
}

function workspace_catalogs_endpoint(string $method): never {
    if(!in_array($method,['GET','POST'],true))throw new ApiError('method','Метод не поддерживается.',405);
    if($method==='POST'){
        $b=wbody();
        if (($b['action']??'')==='save_carrier_fleet') json_response(workspace_carrier_fleet_save($b));
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
    if (isset($_GET['carrierFleet'])) json_response(workspace_carrier_fleet_card($id,(int)$_GET['carrierFleet']));
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
    $entries=wquery("SELECT id,name,color,revision,active FROM catalog_entries e WHERE catalog_id=?
        AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id))
        ORDER BY active DESC,id",[$id])->fetchAll();foreach($entries as &$e){$e['id']=(int)$e['id'];$e['revision']=(int)$e['revision'];$e['active']=(bool)$e['active'];}unset($e);
    json_response(['catalog'=>$catalog,'entries'=>$entries,'hasDetails'=>$hasDetails,'unkeyedCount'=>(int)($detailsSummary['unkeyedCount']??0),'sourceSyncLabel'=>$sourceSyncLabel,'palette'=>array_map(fn($p)=>$p+['key'=>$p['fill'],'hex'=>$p['fill']],workspace_palette())]);
}
