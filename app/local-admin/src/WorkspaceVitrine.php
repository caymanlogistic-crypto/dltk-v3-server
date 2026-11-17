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
    $boundChoices=[];$boundCatalogs=[];if($t['is_demo'])foreach(wquery("SELECT b.field_key,e.name,e.catalog_id FROM catalog_bindings b JOIN catalog_entries e ON e.catalog_id=b.catalog_id WHERE b.table_id=? AND b.field_key<>'' AND b.field_key<>'A' AND e.active=TRUE ORDER BY e.id",[$t['id']])->fetchAll() as $option){$boundChoices[$option['field_key']][]=$option['name'];$boundCatalogs[$option['field_key']]=(int)$option['catalog_id'];}
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
        $value=$body['reset']?$body['original']:$body['value'];$active=$value!==$body['original'];
        $reference=null;$binding=null;
        if($t['is_demo']&&$key!=='A'){
            $binding=wquery("SELECT catalog_id FROM catalog_bindings WHERE table_id=? AND field_key=? AND field_key<>'' LIMIT 1",[$t['id'],$key])->fetchColumn();
            if($binding&&$value!==''){$reference=wquery('SELECT id FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=TRUE',[$binding,mb_strtolower(trim($value))])->fetchColumn();if(!$reference)throw new ApiError('invalid_reference','Выберите значение из справочника этой таблицы.');}
        }
        if($before===$value){$result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request];wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;}
        $revision++;$values[$key]=$value;$statusId=$key==='A'?($body['statusId']??null):null;
        if($statusId){$valid=wquery("SELECT e.id FROM catalog_entries e JOIN catalog_bindings b ON b.catalog_id=e.catalog_id WHERE e.id=? AND e.active=TRUE AND b.table_id=? AND b.field_key='A'",[$statusId,$t['id']])->fetchColumn();if(!$valid)throw new ApiError('invalid_status','Статус не принадлежит справочнику этой таблицы.');}
        wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$r['id']]);
        wquery('INSERT INTO cell_versions(record_id,field_key,value,revision,active,status_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active),status_id=VALUES(status_id)',[$r['id'],$key,$value,$revision,(int)$active,$statusId]);
        if($key!=='A'){wquery('DELETE FROM record_references WHERE record_id=? AND field_key=?',[$r['id'],$key]);if($reference)wquery('INSERT INTO record_references VALUES(?,?,?)',[$r['id'],$key,$reference]);}
        $label=next_label($t['columns'],$key);$action=$body['editAction']??($body['reset']?'reset':'edit');if(!in_array($action,['edit','paste','undo','reset'],true))$action='edit';$operation=bin2hex(random_bytes(16));
        workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$body['row'],'column'=>$key,'label'=>$label,'before'=>$before,'after'=>$value,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        $status=$statusId?wquery('SELECT color FROM catalog_entries WHERE id=?',[$statusId])->fetch():null;$result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request,'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0)];
        workspace_event('cell.changed',['tableSlug'=>$t['slug'],'row'=>$body['row'],'column'=>$key,'value'=>$value,'revision'=>$revision,'edited'=>$active,'statusId'=>$statusId,'statusColor'=>$status['color']??null,'original'=>$body['original'],'baseHash'=>hash('sha256',$r['baseline_json']),'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0),'actorId'=>$u['id'],'actorName'=>$u['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        if($key===$t['carrier_column'])workspace_event('row.changed',['tableSlug'=>$t['slug'],'row'=>$body['row']]);
        wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;
    }catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();throw $e;}
}
function next_label(array $columns,string $key): string {foreach($columns as $f)if($f['letter']===$key)return $f['label'];return $key;}
