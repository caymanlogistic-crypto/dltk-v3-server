<?php
declare(strict_types=1);
require_once __DIR__.'/Workspace.php';

function workspace_string(mixed $v): string {if(is_bool($v))return $v?'TRUE':'FALSE';return (string)(visible_value($v)??'');}
function vitrine_data(?array $requestedRows=null): array {
    $t=workspace_table();$columns=array_values(array_filter($t['columns'],fn($f)=>workspace_can('field',$f['letter'],'view',$t['id'])));
    // Own stable IDs, not Google coordinates. Manifest remains capped for the established grid.
    $rowIds=workspace_manifest($t);
    $wanted=$requestedRows===null?array_slice($rowIds,0,100):array_values(array_unique(array_filter($requestedRows,fn($x)=>is_int($x)&&$x>1)));
    $records=$wanted?wquery('SELECT * FROM records WHERE table_id=? AND archived=FALSE AND row_num IN ('.implode(',',array_fill(0,count($wanted),'?')).') ORDER BY row_num DESC',[$t['id'],...$wanted])->fetchAll():[];
    $catalog=workspace_status_catalog($t);$byId=[];$byName=[];foreach($catalog['statuses'] as &$status){$status['id']=(int)$status['id'];$status['revision']=(int)$status['revision'];$byId[$status['id']]=$status;$byName[vitrine_status_key($status['name'])]=$status;}unset($status);
    $boundChoices=[];$boundCatalogs=[];$catalogVisibility=[];
    foreach(wquery("SELECT b.field_key,c.id AS catalog_id,c.kind,e.name FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id LEFT JOIN catalog_entries e ON e.catalog_id=c.id AND e.active=TRUE WHERE b.table_id=? AND b.field_key NOT IN ('','A') AND (?=TRUE OR (?='vyvoz' AND b.field_key='B' AND c.kind='clients')) ORDER BY e.id",[$t['id'],(int)$t['is_demo'],$t['slug']])->fetchAll() as $option){
        $key=$option['field_key'];$catalogId=(int)$option['catalog_id'];
        if($option['kind']==='clients'&&!($catalogVisibility[$catalogId]??=workspace_can('catalog',(string)$catalogId,'view',$t['id'])))continue;
        if($option['name']===null&&$option['kind']!=='clients')continue;
        $boundCatalogs[$key]=$catalogId;
        $boundChoices[$key]??=[];
        if($option['name']!==null)$boundChoices[$key][]=$option['name'];
    }
    $edits=[];if($records){$ids=array_column($records,'id');foreach(wquery('SELECT * FROM cell_versions WHERE record_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll() as $e)$edits[(int)$e['record_id']][$e['field_key']]=$e;}
    $refs=[];if($records){$ids=array_column($records,'id');foreach(wquery('SELECT rr.record_id,rr.field_key,e.id,e.catalog_id,e.name FROM record_references rr JOIN catalog_entries e ON e.id=rr.entry_id WHERE rr.record_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll() as $ref)$refs[(int)$ref['record_id']][$ref['field_key']]=['id'=>(int)$ref['id'],'catalogId'=>(int)$ref['catalog_id'],'name'=>$ref['name']];}
    $rows=[];foreach($records as $r){
        $values=json_decode($r['values_json'],true);$baseline=json_decode($r['baseline_json'],true);$raw=$r['metadata_zlib']?blob_json($r['metadata_zlib']):[];$kinds=[];$choices=[];
        foreach($columns as $f){$key=$f['letter'];$cell=$raw[(string)column_number($key)]??[];$kinds[$key]=$key==='A'?'status':(mb_strtolower(trim($f['label']))==='время'?'time':vitrine_clipboard_kind($cell,$key));
            if(!$raw){$kinds[$key]=match(mb_strtolower($f['label'])){'дата'=>'date','время'=>'time',default=>$key==='A'?'status':'text'};}
            $display=display_cell($cell);if($display!==null){$baseline[$key]=$display;if(!isset($edits[(int)$r['id']][$key]))$values[$key]=$display;}
            if(($cell['dataValidation']['condition']['type']??'')==='ONE_OF_LIST')foreach($cell['dataValidation']['condition']['values']??[] as $o)if(isset($o['userEnteredValue']))$choices[$key][]=(string)$o['userEnteredValue'];
        }
        foreach($values as &$v)$v=workspace_string($v);unset($v);foreach($baseline as &$v)$v=workspace_string($v);unset($v);
        $rowEdits=[];foreach($edits[(int)$r['id']]??[] as $key=>$e){$values[$key]=$e['active']?$e['value']:($baseline[$key]??'');$rowEdits[$key]=['revision'=>(int)$e['revision'],'conflict'=>false,'value'=>$values[$key],'active'=>(bool)$e['active']];}
        foreach($refs[(int)$r['id']]??[] as $key=>$ref)$values[$key]=$ref['name'];
        $choices=array_replace($choices,$boundChoices);
        $statusId=$edits[(int)$r['id']]['A']['status_id']??null;$status=$byId[$statusId]??($byName[vitrine_status_key($values['A']??'')]??null);
        if($status)$values['A']=$status['name'];$choices['A']=array_column($catalog['statuses'],'name');
        $rows[]=['references'=>$refs[(int)$r['id']]??[],'catalogBindings'=>$boundCatalogs,'recordId'=>(int)$r['id'],'rowIndex'=>(int)$r['row_num'],'values'=>$values,'originalValues'=>$baseline,'baseHash'=>hash('sha256',$r['baseline_json']),'choices'=>$choices,'kinds'=>$kinds,'edits'=>$rowEdits,'statusId'=>$status['id']??null,'statusColor'=>$status['color']??null];
    }
    return ['rowIds'=>$rowIds,'columns'=>$columns,'rows'=>$rows,'lastSuccess'=>null,'sheetTitle'=>$t['title'],'sourceKey'=>'workspace','sheetKey'=>$t['slug'],'table'=>$t,'statuses'=>$catalog['statuses'],'palette'=>$catalog['palette']];
}

function vitrine_store(array $body): array {
    $t=workspace_table();
    if($body['action']==='statuses_list')return workspace_status_catalog($t);
    if($body['action']==='status_save'){
        $catalog=workspace_status_catalog($t);workspace_catalog_save($body+['catalogId'=>$catalog['id']]);return workspace_status_catalog($t);
    }
    if($body['action']!=='save')throw new ApiError('invalid_action','Неизвестное действие.');
    workspace_require('table',(string)$t['id'],'edit',$t['id']);workspace_require('field',$body['column'],'edit',$t['id']);$u=workspace_current_user();$request=$body['requestId']??bin2hex(random_bytes(16));
    if(!is_string($request)||!preg_match('/^[a-zA-Z0-9_.:-]{8,100}$/D',$request))throw new ApiError('invalid_request','Некорректный идентификатор сохранения.');
    workspace_begin();try{
        $cached=wquery('SELECT result FROM request_deduplication WHERE user_id=? AND request_id=?',[$u['id'],$request])->fetchColumn();if($cached){workspace_db()->commit();return json_decode($cached,true);}
        $r=wquery('SELECT * FROM records WHERE table_id=? AND row_num=? AND archived=FALSE FOR UPDATE',[$t['id'],$body['row']])->fetch();if(!$r)throw new ApiError('not_found','Строка не найдена.',404);
        $old=wquery('SELECT * FROM cell_versions WHERE record_id=? AND field_key=? FOR UPDATE',[$r['id'],$body['column']])->fetch();$revision=$old?(int)$old['revision']:0;
        if($revision!==$body['revision'])throw new ApiError('revision_conflict','Коллега уже изменил эту ячейку. Ваш ввод сохранён; откройте актуальное значение перед повторным сохранением.',409);
        $values=json_decode($r['values_json'],true);$key=$body['column'];$before=$body['currentValue']??($old?$old['value']:workspace_string($values[$key]??''));
        if($key==='A'&&!empty($old['status_id'])){$latestName=wquery('SELECT name FROM catalog_entries WHERE id=?',[$old['status_id']])->fetchColumn();if($latestName!==false)$before=$latestName;}
        $value=$body['reset']?$body['original']:$body['value'];
        $reference=null;$binding=null;$clientCatalog=false;
        if($t['slug']==='vyvoz'&&$key==='B'){
            $binding=wquery("SELECT c.id FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id WHERE b.table_id=? AND b.field_key='B' AND c.kind='clients'",[$t['id']])->fetchColumn();
            $clientCatalog=(bool)$binding;
            if($clientCatalog){
                workspace_require('catalog',(string)$binding,'view',$t['id']);
                if($value!==''){
                    $normalized=mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)));
                    $client=wquery('SELECT id,name FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=TRUE',[$binding,$normalized])->fetch();
                    if($client){
                        $reference=(int)$client['id'];$value=$client['name'];
                    }elseif(!$body['reset']&&$value!==$before){
                        throw new ApiError('invalid_reference','Выберите клиента из справочника.');
                    }
                }
            }
        }elseif($t['is_demo']&&$key!=='A'){
            $binding=wquery("SELECT catalog_id FROM catalog_bindings WHERE table_id=? AND field_key=? AND field_key<>'' LIMIT 1",[$t['id'],$key])->fetchColumn();
            if($binding&&$value!==''){$reference=wquery('SELECT id FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=TRUE',[$binding,mb_strtolower(trim($value))])->fetchColumn();if(!$reference)throw new ApiError('invalid_reference','Выберите значение из справочника этой таблицы.');}
        }
        $active=$value!==$body['original'];
        $action=$body['editAction']??($body['reset']?'reset':'edit');if(!in_array($action,['edit','paste','undo','reset'],true))$action='edit';
        $operation=bin2hex(random_bytes(16));
        $managerPatch=null;
        if($clientCatalog&&($reference||$value!==$before)){
            $managers=$reference?json_decode((string)wquery('SELECT value FROM workspace_meta WHERE `key`=?',['catalog_managers_'.$binding])->fetchColumn(),true):[];
            $managerPatch=vitrine_update_client_manager($t,$r,$values,$managers[$reference]??'', $u,$action,$request,$operation);
        }
        $derived=$managerPatch&&workspace_can('field','AL','view',$t['id'])?['dependentCell'=>$managerPatch]:[];
        if($before===$value){
            $result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request]+$derived;
            if($clientCatalog&&$reference){
                wquery('INSERT INTO record_references VALUES(?,?,?) ON DUPLICATE KEY UPDATE entry_id=VALUES(entry_id)',[$r['id'],$key,$reference]);
                $result+=['referenceId'=>$reference,'catalogId'=>(int)$binding];
            }
            if($managerPatch)wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$r['id']]);
            wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;
        }
        $revision++;$values[$key]=$value;$statusId=$key==='A'?($body['statusId']??null):null;
        if($statusId){$valid=wquery("SELECT e.id FROM catalog_entries e JOIN catalog_bindings b ON b.catalog_id=e.catalog_id WHERE e.id=? AND e.active=TRUE AND b.table_id=? AND b.field_key='A'",[$statusId,$t['id']])->fetchColumn();if(!$valid)throw new ApiError('invalid_status','Статус не принадлежит справочнику этой таблицы.');}
        wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$r['id']]);
        wquery('INSERT INTO cell_versions(record_id,field_key,value,revision,active,status_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active),status_id=VALUES(status_id)',[$r['id'],$key,$value,$revision,(int)$active,$statusId]);
        if($key!=='A'){wquery('DELETE FROM record_references WHERE record_id=? AND field_key=?',[$r['id'],$key]);if($reference)wquery('INSERT INTO record_references VALUES(?,?,?)',[$r['id'],$key,$reference]);}
        $label=next_label($t['columns'],$key);
        workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$body['row'],'column'=>$key,'label'=>$label,'before'=>$before,'after'=>$value,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        $status=$statusId?wquery('SELECT color FROM catalog_entries WHERE id=?',[$statusId])->fetch():null;$result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request,'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0)]+$derived;
        workspace_event('cell.changed',['tableSlug'=>$t['slug'],'row'=>$body['row'],'column'=>$key,'value'=>$value,'revision'=>$revision,'edited'=>$active,'statusId'=>$statusId,'statusColor'=>$status['color']??null,'original'=>$body['original'],'baseHash'=>hash('sha256',$r['baseline_json']),'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0),'actorId'=>$u['id'],'actorName'=>$u['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        if($key===$t['carrier_column'])workspace_event('row.changed',['tableSlug'=>$t['slug'],'row'=>$body['row']]);
        wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;
    }catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();throw $e;}
}

function vitrine_update_client_manager(array $table,array $record,array &$values,string $manager,array $user,string $action,string $request,string $operation): ?array {
    $old=wquery("SELECT value,revision,active FROM cell_versions WHERE record_id=? AND field_key='AL' FOR UPDATE",[$record['id']])->fetch();
    $baseline=json_decode($record['baseline_json'],true);
    $metadata=$record['metadata_zlib']?blob_json($record['metadata_zlib']):[];
    $display=display_cell($metadata[column_number('AL')]??[]);
    $original=workspace_string($display??($baseline['AL']??''));
    $before=$old?($old['active']?$old['value']:$original):workspace_string($display??($values['AL']??''));
    if($before===$manager)return null;
    workspace_require('field','AL','edit',$table['id']);
    $revision=(int)($old['revision']??0)+1;
    $edited=$manager!==$original;
    $values['AL']=$manager;
    wquery("INSERT INTO cell_versions(record_id,field_key,value,revision,active) VALUES(?,'AL',?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active)",[$record['id'],$manager,$revision,(int)$edited]);
    workspace_audit(['tableId'=>$table['id'],'recordId'=>(int)$record['id'],'row'=>(int)$record['row_num'],'column'=>'AL','label'=>next_label($table['columns'],'AL'),'before'=>$before,'after'=>$manager,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
    $patch=['tableSlug'=>$table['slug'],'row'=>(int)$record['row_num'],'column'=>'AL','value'=>$manager,'revision'=>$revision,'edited'=>$edited,'original'=>$original,'baseHash'=>hash('sha256',$record['baseline_json']),'actorId'=>$user['id'],'actorName'=>$user['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation];
    workspace_event('cell.changed',$patch);
    return $patch;
}
function next_label(array $columns,string $key): string {foreach($columns as $f)if($f['letter']===$key)return $f['label'];return $key;}
