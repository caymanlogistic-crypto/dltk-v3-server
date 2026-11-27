<?php
declare(strict_types=1);
require_once __DIR__.'/Workspace.php';
require_once __DIR__.'/WorkspaceTripMargin.php';
require_once __DIR__.'/WorkspaceMarginRules.php';

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
        'O'=>['kind'=>'fleet-vehicles','target'=>'P','source'=>'Из справочника автотранспорта','empty'=>'За машиной не закреплён водитель',
            'phoneTarget'=>'Q','phoneEmpty'=>'У водителя не указан телефон',
            'documentsTarget'=>'R','documentsEmpty'=>'У водителя нет данных для ПД','invalid'=>'Выберите машину из справочника автотранспорта.'],
        'S'=>['kind'=>'carriers','target'=>'AM','meta'=>'catalog_logists_','source'=>'Из справочника перевозчиков','empty'=>'У перевозчика не указан логист','invalid'=>'Выберите перевозчика из справочника.'],
        'T'=>['kind'=>'assignment-statuses','invalid'=>'Выберите статус поручения из справочника.'],
        'U'=>['kind'=>'etrn-statuses','invalid'=>'Выберите статус ЭТрН из справочника.'],
        'U2'=>['kind'=>'etrn-signing-statuses','invalid'=>'Выберите статус подписания ЭТРН из справочника.'],
    ];
    foreach (workspace_edo_party_links() as $field=>[$role,$target]) {
        $definitions[$field]=['kind'=>'edo-counterparties','role'=>$role,
            'invalid'=>'Выберите контрагента с подходящей ролью из справочника.'];
        if ($target!==null) $definitions[$field]+=['target'=>$target,'source'=>'Из справочника контрагентов ЭДО',
            'empty'=>'Оператор ЭДО не определён'];
    }
    $links=[];
    $bindings=wquery("SELECT b.field_key,c.id,c.kind FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id WHERE b.table_id=? AND b.field_key IN ('B','E','I','K','M','O','S','T','U','U2','AA','AC','AE','AG') ORDER BY c.id",[$table['id']])->fetchAll();
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
        foreach(['E','I','K','M','O','S','T','U','U2'] as $field){
            $link=$links[$field]??null;
            if(!$link||!workspace_can('catalog',(string)$link['id'],'view',$table['id']))continue;
            $title=wquery('SELECT title FROM catalogs WHERE id=?',[$link['id']])->fetchColumn();
            $items[]=['id'=>$link['id'],'title'=>$title];
        }
        $additional=wquery("SELECT c.id,c.title,c.kind FROM catalogs c JOIN catalog_bindings b ON c.id=b.catalog_id
            WHERE b.table_id=? AND b.field_key='' AND c.kind IN ('transport','drivers-detailed','drivers-legacy',
                'terminals-detailed','application-contacts','application-checks','etrn-carriers','edo-operators','margin-rules','edo-counterparties') ORDER BY c.title,c.id",
            [$table['id']])->fetchAll();
        $fleetEnabled=isset($links['O']);
        foreach($additional as $catalog){
            if ($fleetEnabled && in_array($catalog['kind'],['transport','drivers-detailed','drivers-legacy'],true)) continue;
            if(workspace_can('catalog',(string)$catalog['id'],'view',$table['id'])) $items[]=$catalog;
        }
        return $items;
    }
    return [];
}

function vitrine_catalog_values(array $link): array {
    if($link['kind']==='clients')return workspace_client_manager_values($link['id']);
    if ($link['kind']==='edo-counterparties') {
        return array_map(static fn(array $profile): string=>workspace_carrier_edo_operator($profile['F']),workspace_edo_party_profiles($link['id']));
    }
    $map=json_decode((string)wquery('SELECT value FROM workspace_meta WHERE `key`=?',[$link['meta'].$link['id']])->fetchColumn(),true);
    return is_array($map)?$map:[];
}

function vitrine_catalog_reference_label(array $reference,?array $link): string {
    if (($link['kind']??null)!=='edo-counterparties' || $reference['catalogId']!==$link['id']) return $reference['name'];
    return workspace_edo_party_label($reference['name'],workspace_edo_party_profiles($link['id'])[$reference['id']]??[]);
}

function vitrine_apply_catalog_dependents(array &$values,array $references,array $links,array $names,array $dependentValues): void {
    foreach ($links as $source=>$link) {
        if ($link['kind']==='edo-counterparties') {
            $reference=$references[$source]??null;
            if (!$reference || $reference['catalogId']!==$link['id']) continue;
            $entryId=$reference['id'];
        } else {
            $entryId=$names[$source][vitrine_catalog_key($values[$source]??'')]??0;
        }
        $values[$link['target']]=$dependentValues[$source][$entryId]??'';
    }
}

function vitrine_catalog_uses_cell_fill(string $kind): bool {
    return $kind==='terminals-main' || workspace_is_document_status_catalog($kind);
}

function vitrine_find_catalog_entry(int $catalog,string $name,bool $includeArchived=false,?string $normalized=null): array|false {
    if($name==='')return false;
    $normalized??=vitrine_catalog_key($name);
    $entry=wquery('SELECT id,name,color FROM catalog_entries WHERE catalog_id=? AND normalized=? AND (active=TRUE OR ?=TRUE)',[$catalog,$normalized,(int)$includeArchived])->fetch();
    if($entry||!$includeArchived)return $entry;
    $alias=workspace_client_catalog_aliases($catalog)[$normalized]??0;
    return $alias?wquery('SELECT id,name,color FROM catalog_entries WHERE catalog_id=? AND id=?',[$catalog,$alias])->fetch():false;
}

function vitrine_find_fleet_vehicle(int $catalog,string $value): array|false {
    $key=workspace_fleet_trip_vehicle_key($value);
    $entry=vitrine_find_catalog_entry($catalog,$value,normalized:$key);
    if (!preg_match('/^([ABEIKMHOPCTX]{2})([0-9]{4})([0-9])$/D',$key,$belarusian)) return $entry;
    if ($entry && workspace_fleet_plate_key($entry['name'])===$key) return $entry;
    // Older entries may keep the two printed lines in order or use Cyrillic І in their index.
    [, $series,$number,$region]=$belarusian;
    $ordered=$series.$region.$number;
    $olderKeys=array_diff(array_unique([$ordered,str_replace('I','І',$key),str_replace('I','І',$ordered)]),[$key]);
    foreach ($olderKeys as $olderKey) {
        $entry=vitrine_find_catalog_entry($catalog,$value,normalized:$olderKey);
        if ($entry && workspace_fleet_plate_key($entry['name'])===$key) return $entry;
    }
    return false;
}

function vitrine_unload_client_key(array $table,string $clientName,int $clientId=0): string {
    $clientCatalog=vitrine_catalog_links($table)['B']['id']??0;
    if (!$clientId && $clientCatalog) $clientId=(int)(vitrine_find_catalog_entry($clientCatalog,$clientName,true)['id']??0);
    return workspace_unload_client_key($clientId?:null,$clientName);
}

function vitrine_find_unload_point(array $table,array $record,array $values,int $catalogId,string $value,int $pointId=0): ?array {
    $clientRef=(int)wquery("SELECT entry_id FROM record_references WHERE record_id=? AND field_key='B'",[$record['id']])->fetchColumn();
    $clientKey=vitrine_unload_client_key($table,workspace_string($values['B']??''),$clientRef);
    $input=vitrine_catalog_key($value);
    foreach (workspace_unload_points($catalogId) as $point) {
        if ($pointId && (int)$point['id']!==$pointId) continue;
        if (!$point['active'] || $point['review_required'] || $point['client']==='') continue;
        if ($input!==vitrine_catalog_key($point['address'])) continue;
        $key=workspace_unload_client_key($point['client_id']===null?null:(int)$point['client_id'],$point['client']);
        if ($key===$clientKey && wquery('SELECT active FROM catalog_entries WHERE id=? FOR SHARE',[$point['id']])->fetchColumn()) return $point;
    }
    return null;
}

function vitrine_cell_metadata(array $metadata,string $column): array {
    return $column==='U2' ? [] : ($metadata[column_number($column)]??[]);
}

function vitrine_catalog_original_value(array $record,string $column): string {
    $baseline=json_decode($record['baseline_json'],true);
    $metadata=$record['metadata_zlib']?blob_json($record['metadata_zlib']):[];
    return workspace_string(display_cell(vitrine_cell_metadata($metadata,$column))??($baseline[$column]??''));
}

function vitrine_derived_catalog_columns(array $links): array {
    $columns=[];
    foreach ($links as $source=>$link) {
        if (isset($link['target'])) $columns[$link['target']]=$link+['field'=>$source];
        foreach (['phone','documents'] as $field) {
            if (isset($link[$field.'Target'])) $columns[$link[$field.'Target']]=['source'=>$link['source'],'empty'=>$link[$field.'Empty'],'field'=>$source];
        }
    }
    return $columns;
}

function vitrine_require_manual_field(array $table,string $column): void {
    if ($column==='Z' && workspace_trip_margin_enabled($table)) {
        throw new ApiError('derived_field','Маржа рассчитывается автоматически. Эту ячейку нельзя редактировать.',403);
    }
    if (isset(vitrine_derived_catalog_columns(vitrine_catalog_links($table))[$column])) {
        throw new ApiError('derived_field','Данные берутся из справочника. Эту ячейку нельзя редактировать.',403);
    }
}

function vitrine_catalog_options(array $t,array $links): array {
    $boundChoices=[];$boundCatalogs=[];$catalogVisibility=[];$entriesByName=[];
    $catalogIds=array_column($links,'id')?:[0];
    $catalogSlots=implode(',',array_fill(0,count($catalogIds),'?'));
    foreach(wquery("SELECT b.field_key,c.id AS catalog_id,e.id AS entry_id,e.name,e.normalized,e.active FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id LEFT JOIN catalog_entries e ON e.catalog_id=c.id AND (e.active=TRUE OR c.kind IN ('clients','terminals-main')) WHERE b.table_id=? AND b.field_key NOT IN ('','A') AND c.kind<>'unload-points' AND (?=TRUE OR c.id IN ($catalogSlots)) ORDER BY e.name,e.id",[$t['id'],(int)$t['is_demo'],...$catalogIds])->fetchAll() as $option){
        $key=$option['field_key'];$catalogId=(int)$option['catalog_id'];
        $entryId=(int)$option['entry_id'];
        $linked=($links[$key]['id']??0)===$catalogId;
        if ($linked && $links[$key]['kind']==='edo-counterparties') {
            if (!($catalogVisibility[$catalogId]??=workspace_can('catalog',(string)$catalogId,'view',$t['id']))) continue;
            $boundCatalogs[$key]=$catalogId;
            $boundChoices[$key]??=[];
            $profile=workspace_edo_party_profiles($catalogId)[$entryId]??[];
            if ($option['active'] && ($profile[$links[$key]['role']]??'')==='Да') {
                $label=workspace_edo_party_label($option['name'],$profile);
                $entriesByName[$key][vitrine_catalog_key($label)]=$entryId;
                $boundChoices[$key][$entryId]=$label;
            }
            continue;
        }
        if($linked&&$option['entry_id']!==null)$entriesByName[$key][$option['normalized']]=(int)$option['entry_id'];
        if($linked&&!($catalogVisibility[$catalogId]??=workspace_can('catalog',(string)$catalogId,'view',$t['id'])))continue;
        if(!$linked&&(!$t['is_demo']||!$option['active']))continue;
        $boundCatalogs[$key]=$catalogId;
        $boundChoices[$key]??=[];
        if($option['active'])$boundChoices[$key][$entryId]=$option['name'];
    }
    if (isset($links['O'],$boundChoices['O'])) {
        $vehicles=workspace_fleet_entries('fleet-vehicles',$links['O']['id']);
        $driverCatalog=workspace_fleet_catalog('fleet-drivers');
        $canViewDriver=$driverCatalog && workspace_can('catalog',(string)$driverCatalog,'view',$t['id']);
        $available=array_fill_keys($boundChoices['O'],true);
        $boundChoices['O']=[];
        foreach ($vehicles as $vehicle) {
            $plate=$vehicle['name'];
            if (!isset($available[$plate]) || workspace_fleet_trailer_key($plate)) continue;
            $trailer=$vehicle['trailer_plate']??'';
            $value=workspace_fleet_trip_vehicle_label($plate,$trailer);
            $name=$canViewDriver ? trim(implode(' ',[$vehicle['surname']??'',$vehicle['given_name']??'',$vehicle['patronymic']??''])) : '';
            $boundChoices['O'][(int)$vehicle['id']]=['value'=>$value,'driver'=>$name,
                'search'=>workspace_fleet_plate_key($plate).' '.workspace_fleet_plate_key($trailer)];
        }
    }
    foreach(['B','E'] as $key){
        if(isset($links[$key]))$entriesByName[$key]=($entriesByName[$key]??[])+workspace_client_catalog_aliases($links[$key]['id']);
    }
    foreach ($boundChoices as $key=>$options) {
        $entries=[];
        foreach ($options as $id=>$option) $entries[]=['id'=>$id,'option'=>$option];
        $boundChoices[$key]=array_column(workspace_directory_order_entries($entries,$boundCatalogs[$key]),'option');
    }
    return [$boundChoices,$boundCatalogs,$entriesByName];
}

function vitrine_update_catalog_value(array $table,array $record,array &$values,string $column,string $value,string $previous,array $user,string $action,string $request,string $operation,bool $requireEdit=true,?string $shown=null): ?array {
    $old=wquery('SELECT value,revision,active FROM cell_versions WHERE record_id=? AND field_key=? FOR UPDATE',[$record['id'],$column])->fetch();
    $baseline=json_decode($record['baseline_json'],true);
    $metadata=$record['metadata_zlib']?blob_json($record['metadata_zlib']):[];
    $display=display_cell(vitrine_cell_metadata($metadata,$column));
    $original=workspace_string($display??($baseline[$column]??''));
    $before=$old?($old['active']?$old['value']:$original):workspace_string($display??($values[$column]??''));
    if($before===$value&&$previous===$value)return null;
    if ($requireEdit) workspace_require('field',$column,'edit',$table['id']);
    $revision=(int)($old['revision']??0)+1;
    $edited=$value!==$original;
    $values[$column]=$value;
    wquery("INSERT INTO cell_versions(record_id,field_key,value,revision,active) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active)",[$record['id'],$column,$value,$revision,(int)$edited]);
    workspace_audit(['tableId'=>$table['id'],'recordId'=>(int)$record['id'],'row'=>(int)$record['row_num'],'column'=>$column,'label'=>next_label($table['columns'],$column),'before'=>$previous!==$value?$previous:$before,'after'=>$value,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
    $patch=['tableSlug'=>$table['slug'],'row'=>(int)$record['row_num'],'column'=>$column,'value'=>$shown??$value,'revision'=>$revision,'edited'=>$edited,'original'=>$original,'baseHash'=>hash('sha256',$record['baseline_json']),'actorId'=>$user['id'],'actorName'=>$user['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation];
    if ($column==='K') $patch['referenceId']=0;
    workspace_event('cell.changed',$patch);
    return $patch;
}
function next_label(array $columns,string $key): string {foreach($columns as $f)if($f['letter']===$key)return $f['label'];return $key;}
