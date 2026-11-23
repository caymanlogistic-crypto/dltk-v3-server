<?php
declare(strict_types=1);
require_once __DIR__.'/Workspace.php';

function workspace_string(mixed $v): string {if(is_bool($v))return $v?'TRUE':'FALSE';return (string)(visible_value($v)??'');}

function vitrine_catalog_links(array $table): array {
    if($table['slug']!=='vyvoz')return [];
    static $byTable=[];
    if(isset($byTable[$table['id']]))return $byTable[$table['id']];
    $definitions=[
        'B'=>['kind'=>'clients','target'=>'AL','meta'=>'catalog_managers_','source'=>'Из справочника клиентов','empty'=>'У клиента не указан менеджер','invalid'=>'Выберите клиента из справочника.'],
        'E'=>['kind'=>'terminals-main','invalid'=>'Выберите терминал постановки из справочника.'],
        'I'=>['kind'=>'container-types','invalid'=>'Выберите тип контейнера из справочника.'],
        'K'=>['kind'=>'unload-points','target'=>'L','source'=>'Контакт из справочника точек выгрузки','empty'=>'У точки не указан контакт','invalid'=>'Выберите адрес выгрузки выбранного клиента.'],
        'M'=>['kind'=>'dropoff-points','invalid'=>'Выберите точку сдачи из справочника.'],
        'S'=>['kind'=>'carriers','target'=>'AM','meta'=>'catalog_logists_','source'=>'Из справочника перевозчиков','empty'=>'У перевозчика не указан логист','invalid'=>'Выберите перевозчика из справочника.'],
    ];
    $links=[];
    $bindings=wquery("SELECT b.field_key,c.id,c.kind FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id WHERE b.table_id=? AND b.field_key IN ('B','E','I','K','M','S') ORDER BY c.id",[$table['id']])->fetchAll();
    foreach($bindings as $binding){
        $field=$binding['field_key'];$definition=$definitions[$field];
        if($definition['kind']===$binding['kind']&&!isset($links[$field])){
            $links[$field]=$definition+['id'=>(int)$binding['id']];
        }
    }
    return $byTable[$table['id']]=$links;
}

function vitrine_bound_catalog_menu(array $tables): array {
    foreach($tables as $table){
        if($table['slug']!=='vyvoz')continue;
        $links=vitrine_catalog_links($table);
        $items=[];
        foreach(['E','I','K','M'] as $field){
            $link=$links[$field]??null;
            if(!$link||!workspace_can('catalog',(string)$link['id'],'view',$table['id']))continue;
            $title=wquery('SELECT title FROM catalogs WHERE id=?',[$link['id']])->fetchColumn();
            $items[]=['id'=>$link['id'],'title'=>$title];
        }
        return $items;
    }
    return [];
}

function vitrine_catalog_values(array $link): array {
    if($link['kind']==='clients')return workspace_client_manager_values($link['id']);
    $map=json_decode((string)wquery('SELECT value FROM workspace_meta WHERE `key`=?',[$link['meta'].$link['id']])->fetchColumn(),true);
    return is_array($map)?$map:[];
}

function vitrine_find_catalog_entry(int $catalog,string $name,bool $includeArchived=false): array|false {
    if($name==='')return false;
    $normalized=vitrine_catalog_key($name);
    $entry=wquery('SELECT id,name,color FROM catalog_entries WHERE catalog_id=? AND normalized=? AND (active=TRUE OR ?=TRUE)',[$catalog,$normalized,(int)$includeArchived])->fetch();
    if($entry||!$includeArchived)return $entry;
    $alias=workspace_client_catalog_aliases($catalog)[$normalized]??0;
    return $alias?wquery('SELECT id,name,color FROM catalog_entries WHERE catalog_id=? AND id=?',[$catalog,$alias])->fetch():false;
}

function vitrine_find_unload_point(array $table,array $record,array $values,int $catalogId,string $value): ?array {
    $clientRef=wquery("SELECT entry_id FROM record_references WHERE record_id=? AND field_key='B'",[$record['id']])->fetchColumn();
    $clientName=workspace_string($values['B']??'');
    $clientCatalog=vitrine_catalog_links($table)['B']['id']??0;
    $clientId=$clientRef ? (int)$clientRef : ($clientCatalog?(int)(vitrine_find_catalog_entry($clientCatalog,$clientName,true)['id']??0):0);
    $clientKey=workspace_unload_client_key($clientId?:null,$clientName);
    $input=vitrine_catalog_key($value);
    foreach (workspace_unload_points($catalogId) as $point) {
        if (!$point['active'] || $point['review_required'] || $point['client']==='') continue;
        if ($input!==vitrine_catalog_key($point['address'])) continue;
        $key=workspace_unload_client_key($point['client_id']===null?null:(int)$point['client_id'],$point['client']);
        if ($key===$clientKey && wquery('SELECT active FROM catalog_entries WHERE id=? FOR SHARE',[$point['id']])->fetchColumn()) return $point;
    }
    return null;
}

function vitrine_unload_original_contact(array $record): string {
    $baseline=json_decode($record['baseline_json'],true);
    $metadata=$record['metadata_zlib']?blob_json($record['metadata_zlib']):[];
    return workspace_string(display_cell($metadata[column_number('L')]??[])??($baseline['L']??''));
}

function vitrine_require_manual_field(array $table,string $column): void {
    if(in_array($column,array_column(vitrine_catalog_links($table),'target'),true)){
        throw new ApiError('derived_field','Данные берутся из справочника. Эту ячейку нельзя редактировать.',403);
    }
}

function vitrine_catalog_options(array $t,array $links): array {
    $boundChoices=[];$boundCatalogs=[];$catalogVisibility=[];$entriesByName=[];
    $catalogIds=array_column($links,'id')?:[0];
    $catalogSlots=implode(',',array_fill(0,count($catalogIds),'?'));
    foreach(wquery("SELECT b.field_key,c.id AS catalog_id,e.id AS entry_id,e.name,e.normalized,e.active FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id LEFT JOIN catalog_entries e ON e.catalog_id=c.id AND (e.active=TRUE OR c.kind IN ('clients','terminals-main')) WHERE b.table_id=? AND b.field_key NOT IN ('','A') AND c.kind<>'unload-points' AND (?=TRUE OR c.id IN ($catalogSlots)) ORDER BY e.id",[$t['id'],(int)$t['is_demo'],...$catalogIds])->fetchAll() as $option){
        $key=$option['field_key'];$catalogId=(int)$option['catalog_id'];
        $linked=($links[$key]['id']??0)===$catalogId;
        if($linked&&$option['entry_id']!==null)$entriesByName[$key][$option['normalized']]=(int)$option['entry_id'];
        if($linked&&!($catalogVisibility[$catalogId]??=workspace_can('catalog',(string)$catalogId,'view',$t['id'])))continue;
        if(!$linked&&(!$t['is_demo']||!$option['active']))continue;
        $boundCatalogs[$key]=$catalogId;
        $boundChoices[$key]??=[];
        if($option['active'])$boundChoices[$key][]=$option['name'];
    }
    foreach(['B','E'] as $key){
        if(isset($links[$key]))$entriesByName[$key]=($entriesByName[$key]??[])+workspace_client_catalog_aliases($links[$key]['id']);
    }
    return [$boundChoices,$boundCatalogs,$entriesByName];
}

function vitrine_update_catalog_value(array $table,array $record,array &$values,string $column,string $value,string $previous,array $user,string $action,string $request,string $operation,bool $requireEdit=true): ?array {
    $old=wquery('SELECT value,revision,active FROM cell_versions WHERE record_id=? AND field_key=? FOR UPDATE',[$record['id'],$column])->fetch();
    $baseline=json_decode($record['baseline_json'],true);
    $metadata=$record['metadata_zlib']?blob_json($record['metadata_zlib']):[];
    $display=display_cell($metadata[column_number($column)]??[]);
    $original=workspace_string($display??($baseline[$column]??''));
    $before=$old?($old['active']?$old['value']:$original):workspace_string($display??($values[$column]??''));
    if($before===$value&&$previous===$value)return null;
    if ($requireEdit) workspace_require('field',$column,'edit',$table['id']);
    $revision=(int)($old['revision']??0)+1;
    $edited=$value!==$original;
    $values[$column]=$value;
    wquery("INSERT INTO cell_versions(record_id,field_key,value,revision,active) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active)",[$record['id'],$column,$value,$revision,(int)$edited]);
    workspace_audit(['tableId'=>$table['id'],'recordId'=>(int)$record['id'],'row'=>(int)$record['row_num'],'column'=>$column,'label'=>next_label($table['columns'],$column),'before'=>$previous!==$value?$previous:$before,'after'=>$value,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
    $patch=['tableSlug'=>$table['slug'],'row'=>(int)$record['row_num'],'column'=>$column,'value'=>$value,'revision'=>$revision,'edited'=>$edited,'original'=>$original,'baseHash'=>hash('sha256',$record['baseline_json']),'actorId'=>$user['id'],'actorName'=>$user['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation];
    if ($column==='K') $patch['referenceId']=0;
    workspace_event('cell.changed',$patch);
    return $patch;
}
function next_label(array $columns,string $key): string {foreach($columns as $f)if($f['letter']===$key)return $f['label'];return $key;}
