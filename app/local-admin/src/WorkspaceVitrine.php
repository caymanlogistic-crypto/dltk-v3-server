<?php
declare(strict_types=1);
require_once __DIR__.'/WorkspaceVitrineCatalogLinks.php';

const WORKSPACE_AVTOVYVOZ_PAGE_SIZE = 32;

function vitrine_requested_records(array $table, array $wanted, bool $archive, bool $checkArchiveWindow): array {
    if (!$wanted) return [];
    $args=[$table['id'],(int)$archive,...$wanted];
    $slots=implode(',',array_fill(0,count($wanted),'?'));
    $where="r.table_id=? AND r.archived=? AND r.row_num IN ($slots)";
    if ($checkArchiveWindow) {
        $visible=workspace_archive_row_visible();
        $archiveOffset=WORKSPACE_MANIFEST_LIMIT-1;
        $where.=" AND $visible AND r.row_num>=COALESCE((SELECT r.row_num FROM records r
            WHERE r.table_id=? AND r.archived=1 AND $visible ORDER BY r.row_num DESC LIMIT 1 OFFSET $archiveOffset),0)";
        $args[]=$table['id'];
    }
    return wquery("SELECT r.* FROM records r WHERE $where ORDER BY r.row_num DESC",$args)->fetchAll();
}

function vitrine_trip_driver_card(int $number,bool $archive): array {
    $table=workspace_table();
    if ($table['slug']!=='vyvoz' || (vitrine_catalog_links($table)['O']['kind']??null)!=='fleet-vehicles') {
        throw new ApiError('not_found','Карточка водителя недоступна.',404);
    }
    workspace_require('field','O','view',$table['id']);
    $row=vitrine_data([$number],$archive)['rows'][0]??null;
    if (!$row || empty($row['values']['O'])) throw new ApiError('not_found','Машина в рейсе не указана.',404);
    $name=workspace_can('field','P','view',$table['id']) ? ($row['values']['P']??'') : '';
    $phones=workspace_can('field','Q','view',$table['id']) ? ($row['values']['Q']??'') : '';
    $sections=['Водитель'=>['ФИО'=>$name,'Телефоны'=>$phones],'Автотранспорт'=>['Машина'=>$row['values']['O']]];
    if (workspace_fleet_trip_documents_can_view($table['id'])) {
        $reference=$row['references']['P']??null;
        $drivers=workspace_fleet_catalog('fleet-drivers');
        $driver=($reference['catalogId']??null)===$drivers ? $reference['id'] : 0;
        if (!$driver && empty($row['edits']['O']['active']) && empty($row['edits']['P']['active'])) {
            $driver=(int)wquery("SELECT o.entry_id FROM fleet_trip_origins o JOIN catalog_entries e ON e.id=o.entry_id
                WHERE o.record_id=? AND o.field_key='P' AND e.catalog_id=?",[$row['recordId'],$drivers])->fetchColumn();
        }
        if ($driver) {
            $documents=workspace_fleet_document_state(0,$driver,false)['values'];
            $sections['Водитель']['Дата рождения']=$documents['P'];
            $labels=workspace_fleet_document_labels();
            foreach (['Паспорт'=>['J','N','O'],'Водительское удостоверение'=>['K','L','M']] as $section=>$fields) {
                foreach ($fields as $key) $sections[$section][$labels[$key]]=$documents[$key];
            }
        } elseif (!empty($row['values']['R'])) {
            $sections['Документы из рейса']=['Данные'=>$row['values']['R']];
        }
    }
    return ['sections'=>$sections];
}

function vitrine_data(?array $requestedRows=null,bool $archive=false): array {
    $t=workspace_table();$columns=array_values(array_filter($t['columns'],fn($f)=>workspace_can('field',$f['letter'],'view',$t['id'])));
    $isAvtovyvoz=$t['slug']==='vyvoz';
    $compactInputs=[];
    if($t['slug']==='vyvoz'){
        $labels=array_column($columns,'label','letter');
        if(mb_strtolower(trim($labels['F']??''))==='дата')$compactInputs['F']='date';
        if(mb_strtolower(trim($labels['G']??''))==='время')$compactInputs['G']='time';
        if(mb_strtolower(trim($labels['H']??''))==='номер ктк')$compactInputs['H']='container';
        if(mb_strtolower(trim($labels['J']??''))==='вес (брутто)')$compactInputs['J']='weight';
        if(mb_strtolower(trim($labels['W']??''))==='км')$compactInputs['W']='distance';
        foreach (['X'=>'доход','Y'=>'расход','Z'=>'маржа'] as $letter=>$label) {
            if (mb_strtolower(trim($labels[$letter]??''))===$label) $compactInputs[$letter]='money';
        }
        foreach (['AH'=>'cargo-value','AI'=>'weight','AJ'=>'cargo-places'] as $letter=>$kind) {
            if (isset($labels[$letter])) $compactInputs[$letter]=$kind;
        }
    }
    $slots=array_column(array_filter($columns,static fn(array $column): bool=>mb_strtolower(trim($column['label']))==='слот'),null,'letter');
    $singleLine=array_fill_keys(array_column(array_filter($columns,
        static fn(array $column): bool=>mb_strtolower(trim($column['label']))==='релиз'),'letter'),true);
    $links=vitrine_catalog_links($t);
    $orderStatusEnabled=isset($links['B']) && workspace_client_order_status_can_view($t);
    $derivedByColumn=vitrine_derived_catalog_columns($links);
    $showMargin=isset($compactInputs['Z']) && workspace_trip_margin_enabled($t);
    if ($showMargin) {
        $derivedByColumn['Z']=['source'=>'Доход минус расход','empty'=>'Укажите доход и расход'];
    }
    $documentsColumn=$links['O']['documentsTarget']??null;
    $canViewDocuments=$documentsColumn!==null && workspace_fleet_trip_documents_can_view($t['id']);
    foreach($columns as &$column){
        if ($isAvtovyvoz && $column['letter']==='O' && ($links['O']['kind']??null)==='fleet-vehicles') {
            $column['tripDriverCard']=true;
        }
        if ($column['letter']===$documentsColumn) {
            $column['export']=false;
            $column['fleetDocumentsVisible']=$canViewDocuments;
        }
        if(isset($derivedByColumn[$column['letter']])){
            $link=$derivedByColumn[$column['letter']];
            $column['derivedSource']=$link['source'];
            $column['derivedEmptyLabel']=$link['empty'];
        }
    }
    unset($column);
    $needsManifest=$requestedRows===null || !$isAvtovyvoz;
    $rowIds=$needsManifest ? workspace_manifest($t,$archive) : [];
    $pageSize=$isAvtovyvoz ? WORKSPACE_AVTOVYVOZ_PAGE_SIZE : 100;
    $wanted=$requestedRows===null ? array_slice($rowIds,0,$pageSize)
        : ($archive && $needsManifest ? array_values(array_intersect($requestedRows,$rowIds))
            : array_values(array_unique(array_filter($requestedRows,static fn($id)=>is_int($id)&&($archive||$id>1)))));
    $records=vitrine_requested_records($t,$wanted,$archive,$archive && !$needsManifest);
    $documentSnapshots=$canViewDocuments ? workspace_fleet_trip_documents_values(array_column($records,'id')) : [];
    $canManageRows=workspace_can('table',(string)$t['id'],'edit',$t['id']);
    $readOnly=[];
    foreach ($columns as $column) {
        if (!$canManageRows || !workspace_can('field',$column['letter'],'edit',$t['id'])) {
            $readOnly[$column['letter']]='Эта ячейка доступна только для просмотра.';
        }
    }
    $catalog=workspace_status_catalog($t);$byId=[];$byName=[];foreach($catalog['statuses'] as &$status){$status['id']=(int)$status['id'];$status['revision']=(int)$status['revision'];$byId[$status['id']]=$status;$byName[vitrine_status_key($status['name'])]=$status;}unset($status);
    [$boundChoices,$boundCatalogs,$entriesByName]=vitrine_catalog_options($t,$links);
    $partyColumns=array_keys(workspace_edo_party_links());
    $sharedCatalogs=$isAvtovyvoz ? array_intersect_key($boundCatalogs,
        array_fill_keys(['B','E','I','M','S','T','U'],true), array_fill_keys(array_column($columns,'letter'),true)) : [];
    $sharedChoices=[];
    foreach ($sharedCatalogs as $column=>$_) $sharedChoices[$column]=$column;
    if (isset($boundCatalogs['O'])) $sharedChoices['O']='vehicles';
    foreach ($partyColumns as $field) {
        if (isset($boundCatalogs[$field])) $sharedChoices[$field]=$field;
    }
    foreach (['T','U',...$partyColumns] as $column) {
        if (isset($links[$column]) && !isset($boundCatalogs[$column])) {
            $readOnly[$column]='Нет доступа к справочнику.';
        }
    }
    $dropoffCatalog=in_array('M',array_column($columns,'letter'),true) ? ($boundCatalogs['M']??null) : null;
    $dropoffOptions=[];
    if ($dropoffCatalog) {
        $dropoffProfiles=workspace_dropoff_profiles($dropoffCatalog);
        foreach (($entriesByName['M']??[]) as $normalized=>$entryId) {
            $dropoffOptions[$normalized]=$dropoffProfiles[$entryId]??[];
        }
    }
    $dependentLinks=array_filter($links,static fn(array $link): bool=>isset($link['target'])&&!in_array($link['kind'],['unload-points','fleet-vehicles'],true));
    $unloadLink=$links['K']??null;
    $canChooseUnload=$unloadLink && workspace_can('catalog',(string)$unloadLink['id'],'view',$t['id']);
    $unloadPoints=$unloadLink ? workspace_unload_points($unloadLink['id']) : [];
    $unloadOptions=$canChooseUnload ? workspace_unload_options_by_client($unloadPoints) : [];
    if ($canChooseUnload) $boundCatalogs['K']=$unloadLink['id'];
    $dependentValues=array_map('vitrine_catalog_values',$dependentLinks);
    $colorLinks=array_filter(array_intersect_key($links,$boundCatalogs),
        static fn(array $link): bool=>vitrine_catalog_uses_cell_fill($link['kind']));
    $colorCatalogIds=array_column($colorLinks,'id');
    $catalogColors=$colorCatalogIds
        ? wquery('SELECT id,color FROM catalog_entries WHERE catalog_id IN ('.implode(',',array_fill(0,count($colorCatalogIds),'?')).')
            AND color IS NOT NULL',$colorCatalogIds)->fetchAll(PDO::FETCH_KEY_PAIR)
        : [];
    $sharedOptions=['vehicles'=>$boundChoices['O']??[]]
        +array_intersect_key($boundChoices,$sharedCatalogs+array_fill_keys($partyColumns,true));
    foreach (array_intersect_key($sharedOptions,$colorLinks) as $column=>$labels) {
        $sharedOptions[$column]=array_map(static function(string $label) use ($entriesByName,$catalogColors,$column): array {
            $entryId=$entriesByName[$column][vitrine_catalog_key($label)]??0;
            return ['value'=>$label,'label'=>$label,'color'=>$catalogColors[$entryId]??null];
        },$labels);
    }
    $edits=[];if($records){$ids=array_column($records,'id');foreach(wquery('SELECT * FROM cell_versions WHERE record_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll() as $e)$edits[(int)$e['record_id']][$e['field_key']]=$e;}
    $refs=[];if($records){$ids=array_column($records,'id');foreach(wquery('SELECT rr.record_id,rr.field_key,e.id,e.catalog_id,e.name FROM record_references rr JOIN catalog_entries e ON e.id=rr.entry_id WHERE rr.record_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll() as $ref)$refs[(int)$ref['record_id']][$ref['field_key']]=['id'=>(int)$ref['id'],'catalogId'=>(int)$ref['catalog_id'],'name'=>$ref['name']];}
    $rows=[];foreach($records as $r){
        $values=json_decode($r['values_json'],true);$baseline=json_decode($r['baseline_json'],true);$raw=$r['metadata_zlib']?blob_json($r['metadata_zlib']):[];$kinds=[];$choices=[];
        $tripAmounts=$showMargin ? array_intersect_key($values,['X'=>true,'Y'=>true]) : [];
        $tripOriginals=$showMargin ? array_intersect_key($baseline,['X'=>true,'Y'=>true]) : [];
        $dropoffDetails=$dropoffCatalog ? ($values['dropoffDetails']??[]) : null;
        $orderStatus=$orderStatusEnabled ? (string)($values['clientOrderStatus']??'') : null;
        unset($values['clientOrderStatus'],$values['dropoffDetails']);
        foreach($columns as $f){$key=$f['letter'];$cell=$raw[(string)column_number($key)]??[];$kinds[$key]=$key==='A'?'status':(mb_strtolower(trim($f['label']))==='время'?'time':vitrine_clipboard_kind($cell,$key));
            if(!$raw){$kinds[$key]=match(mb_strtolower($f['label'])){'дата'=>'date','время'=>'time',default=>$key==='A'?'status':'text'};}
            if (isset($compactInputs[$key])) $kinds[$key]=$compactInputs[$key];
            if (in_array($key,['I','K','M','O','T','U',...$partyColumns],true) && isset($links[$key])) $kinds[$key]='text';
            if (isset($slots[$key])) $kinds[$key]='slot';
            if (isset($singleLine[$key])) $kinds[$key]='text';
            if ($t['slug']==='vyvoz' && $key==='AK') $kinds[$key]='text';
            $display=display_cell($cell);if($display!==null){$baseline[$key]=$display;if(!isset($edits[(int)$r['id']][$key]))$values[$key]=$display;}
            if (($t['slug']!=='vyvoz' || $key!=='AK') && ($cell['dataValidation']['condition']['type']??'')==='ONE_OF_LIST') {
                foreach ($cell['dataValidation']['condition']['values']??[] as $o) {
                    if (isset($o['userEnteredValue'])) $choices[$key][]=(string)$o['userEnteredValue'];
                }
            }
        }
        foreach($values as &$v)$v=workspace_string($v);unset($v);foreach($baseline as &$v)$v=workspace_string($v);unset($v);
        $rowEdits=[];foreach($edits[(int)$r['id']]??[] as $key=>$e){$values[$key]=$e['active']?$e['value']:($baseline[$key]??'');$rowEdits[$key]=['revision'=>(int)$e['revision'],'conflict'=>false,'value'=>$values[$key],'active'=>(bool)$e['active']];}
        foreach($refs[(int)$r['id']]??[] as $key=>$ref) {
            if (isset($links['O']) && in_array($key,['O','P',$links['O']['phoneTarget'],$documentsColumn],true)) continue;
            if (($links[$key]['kind']??null)==='edo-counterparties' && $ref['catalogId']===$links[$key]['id']) {
                $profile=workspace_edo_party_profiles($ref['catalogId'])[$ref['id']]??[];
                $values[$key]=workspace_edo_party_label($ref['name'],$profile);
            } else $values[$key]=$ref['name'];
        }
        if ($documentsColumn!==null) {
            if (!$canViewDocuments) {
                unset($values[$documentsColumn],$baseline[$documentsColumn],$rowEdits[$documentsColumn]);
            } elseif (array_key_exists((int)$r['id'],$documentSnapshots)) {
                $values[$documentsColumn]=$documentSnapshots[(int)$r['id']];
            }
        }
        foreach (array_keys($slots) as $key) {
            if (isset($values[$key])) $values[$key] = vitrine_slot_input($values[$key]) ?? $values[$key];
        }
        foreach ($compactInputs as $key=>$kind) {
            if (vitrine_compact_integer_bounds($kind)!==null && isset($values[$key])) {
                $values[$key]=vitrine_compact_input($values[$key],$kind)??$values[$key];
            }
        }
        if ($showMargin) {
            foreach (['X','Y'] as $key) {
                $initial=workspace_trip_amount_minor($tripOriginals[$key]??null);
                if ($initial!==null) $baseline[$key]=workspace_trip_amount_label($initial);
                $source=!empty($rowEdits[$key]['active']) ? ($values[$key]??null) : ($tripAmounts[$key]??$values[$key]??null);
                $amount=workspace_trip_amount_minor($source);
                if ($amount!==null) $values[$key]=workspace_trip_amount_label($amount);
            }
            $values['Z']=workspace_trip_margin($values);
        }
        foreach($dependentLinks as $source=>$link){
            if ($link['kind']==='edo-counterparties') {
                $ref=$refs[(int)$r['id']][$source]??null;
                if ($ref && $ref['catalogId']===$link['id']) $values[$link['target']]=$dependentValues[$source][$ref['id']]??'';
                continue;
            }
            $entryId=$entriesByName[$source][vitrine_catalog_key($values[$source]??'')]??null;
            $values[$link['target']]=$dependentValues[$source][$entryId]??'';
        }
        $choices=array_diff_key(array_replace($choices,$boundChoices),$slots,$singleLine,$compactInputs);
        if ($unloadLink) {
            $point=$unloadPoints[$refs[(int)$r['id']]['K']['id']??0]??null;
            if ($point) {
                $values['K']=$point['address'];
                $values['L']=workspace_unload_primary($point);
            }
            if ($canChooseUnload) {
                $clientName=$values['B']??'';
                $clientId=$refs[(int)$r['id']]['B']['id']??($entriesByName['B'][vitrine_catalog_key($clientName)]??null);
                $choices['K']=workspace_unload_row_options($unloadOptions,$clientId,$clientName);
            }
        }
        $statusId=$edits[(int)$r['id']]['A']['status_id']??null;$status=$byId[$statusId]??($byName[vitrine_status_key($values['A']??'')]??null);
        if($status)$values['A']=$status['name'];$choices['A']=array_column($catalog['statuses'],'name');
        $catalogFills=[];
        foreach ($colorLinks as $column=>$link) {
            $entryId=$refs[(int)$r['id']][$column]['id']??($entriesByName[$column][vitrine_catalog_key($values[$column]??'')]??0);
            $catalogFills[$column]=$catalogColors[$entryId]??'';
        }
        $rows[]=['catalogFills'=>$catalogFills,'references'=>$refs[(int)$r['id']]??[],'catalogBindings'=>$boundCatalogs,'recordId'=>(int)$r['id'],'rowIndex'=>(int)$r['row_num'],'values'=>$values,'originalValues'=>$baseline,'baseHash'=>hash('sha256',$r['baseline_json']),'choices'=>$choices,'kinds'=>$kinds,'compactInputs'=>$compactInputs,'singleLine'=>$singleLine,'edits'=>$rowEdits,'statusId'=>$status['id']??null,'statusColor'=>$status['color']??null,'orderStatus'=>$orderStatus];
        $rows[array_key_last($rows)]+=['rowActions'=>true,'rowToken'=>$canManageRows ? workspace_table_row_token($r) : null,
            'archived'=>$archive,'readOnly'=>$archive ? [] : $readOnly,'relatedUrl'=>'/workspace?section=history&table='.rawurlencode($t['slug']).'&row='.(int)$r['row_num']];
        if ($dropoffDetails!==null) $rows[array_key_last($rows)]['dropoffDetails']=$dropoffDetails;
        $rows[array_key_last($rows)]['sharedChoices']=$sharedChoices;
    }
    return ['rowIds'=>$rowIds,'columns'=>$columns,'rows'=>$rows,'lastSuccess'=>null,'sheetTitle'=>$t['title'],'sourceKey'=>'workspace','sheetKey'=>$t['slug'],'table'=>$t,'statuses'=>$catalog['statuses'],'palette'=>$catalog['palette'],'sharedOptions'=>$sharedOptions,'sharedOptionCatalogs'=>$sharedCatalogs,'dropoffOptions'=>$dropoffOptions,'dropoffEnabled'=>(bool)$dropoffCatalog];
}

function vitrine_store(array $body): array {
    $t=workspace_table();
    if($body['action']==='statuses_list')return workspace_status_catalog($t);
    if($body['action']==='status_save'){
        $catalog=workspace_status_catalog($t);workspace_catalog_save($body+['catalogId'=>$catalog['id']]);return workspace_status_catalog($t);
    }
    if($body['action']!=='save')throw new ApiError('invalid_action','Неизвестное действие.');
    vitrine_require_manual_field($t,$body['column']);
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
        $clear=($body['editAction']??null)==='clear';
        if ($key==='Y' && $value!=='' && $value!==$before && workspace_trip_margin_enabled($t)) {
            workspace_margin_rule_check_expense($t['id'],$values,$value);
        }
        $reference=null;$binding=null;$entry=null;$links=vitrine_catalog_links($t);$link=$links[$key]??null;
        $dropoffResult=[];$dropoffChanged=false;
        if ($key==='M' && ($link['kind']??null)==='dropoff-points') {
            $priorPoint=(int)wquery("SELECT entry_id FROM record_references WHERE record_id=? AND field_key='M'",[$r['id']])->fetchColumn();
            if (isset($body['dropoffDetails']) && !vitrine_find_catalog_entry($link['id'],$value) && (!$priorPoint || $value!==$before)) {
                throw new ApiError('invalid_reference',$link['invalid']);
            }
            if ($clear) {
                $previousDropoff=$values['dropoffDetails']??[];
                $nextDropoff=[];
                unset($values['dropoffDetails']);
                $dropoffResult=['dropoffDetails'=>$nextDropoff];
                $dropoffChanged=$previousDropoff!==$nextDropoff;
            } elseif (isset($body['dropoffDetails'])) {
                [$previousDropoff,$nextDropoff]=workspace_dropoff_update_flight($values,$body,$before,(bool)$priorPoint);
                $dropoffResult=['dropoffDetails'=>$nextDropoff];
                $dropoffChanged=$previousDropoff!==$nextDropoff;
            }
        }
        $resetUnload=$key==='K' && $link && $body['reset'];
        if ($resetUnload) {
            $originalClient=workspace_string(json_decode($r['baseline_json'],true)['B']??'');
            $sameClient=vitrine_catalog_key($originalClient)===vitrine_catalog_key(workspace_string($values['B']??''));
            if (!$sameClient) $value='';
        }
        if($link){
            $binding=$link['id'];
            workspace_require('catalog',(string)$binding,'view',$t['id']);
            $party=$link['kind']==='edo-counterparties';
            if ($party) {
                $oldPartyId=(int)wquery('SELECT entry_id FROM record_references WHERE record_id=? AND field_key=?',[$r['id'],$key])->fetchColumn();
                $partyProfiles=workspace_edo_party_profiles($binding);
            }
            if ($party && $value!=='' && !$body['reset']) {
                [,, $partyOptions]=vitrine_catalog_options($t,[$key=>$link]);
                $reference=$partyOptions[$key][vitrine_catalog_key($value)]??null;
                if (!$reference) throw new ApiError('invalid_reference',$link['invalid']);
                $partyName=wquery('SELECT name FROM catalog_entries WHERE id=?',[$reference])->fetchColumn();
                $value=workspace_edo_party_label($partyName,$partyProfiles[$reference]);
            } elseif (!$party && $value!=='') {
                $unload=$link['kind']==='unload-points';
                $entry=$unload ? ($body['reset']?null:vitrine_find_unload_point($t,$r,$values,$binding,$value))
                    : vitrine_find_catalog_entry($binding,$value,normalized:$key==='O'?workspace_fleet_trip_vehicle_key($value):null);
                if ($key==='O' && $entry && workspace_fleet_trailer_key($entry['name'])) $entry=false;
                if($entry){
                    $reference=(int)$entry['id'];
                    if ($key==='O') {
                        $trailer=wquery('SELECT p.plate FROM fleet_vehicle_trailers l JOIN fleet_trailer_profiles p ON p.plate_key=l.trailer_key
                            WHERE l.vehicle_id=?',[$reference])->fetchColumn();
                        if (!$body['reset']) $value=workspace_fleet_trip_vehicle_label($entry['name'],$trailer ?: null);
                    } else $value=$unload?$entry['address']:$entry['name'];
                }elseif(!$body['reset']&&($unload||$value!==$before)){
                    throw new ApiError('invalid_reference',$link['invalid']);
                }
            }
        }elseif($t['is_demo']&&$key!=='A'){
            $binding=wquery("SELECT catalog_id FROM catalog_bindings WHERE table_id=? AND field_key=? AND field_key<>'' LIMIT 1",[$t['id'],$key])->fetchColumn();
            if($binding&&$value!==''){$reference=wquery('SELECT id FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=TRUE',[$binding,mb_strtolower(trim($value))])->fetchColumn();if(!$reference)throw new ApiError('invalid_reference','Выберите значение из справочника этой таблицы.');}
        }
        $orderResult=[];
        $orderChanged=false;
        if ($key==='B' && $link && ($clear || workspace_client_order_status_can_view($t))) {
            $oldOrderStatus=(string)($values['clientOrderStatus']??'');
            $nextOrderStatus=$clear ? '' : ($body['orderStatus']??$oldOrderStatus);
            if (workspace_client_order_status_can_view($t)) $orderResult=['orderStatus'=>$nextOrderStatus];
            $orderChanged=$nextOrderStatus!==$oldOrderStatus;
            if ($orderChanged && $nextOrderStatus!=='' && !isset(workspace_active_client_order_statuses(true)[$nextOrderStatus])) {
                throw new ApiError('invalid_order_status','Выберите действующий статус из справочника.');
            }
            if ($orderChanged) {
                if ($nextOrderStatus==='') unset($values['clientOrderStatus']);
                else $values['clientOrderStatus']=$nextOrderStatus;
            }
        }
        $unloadResult=[];
        $unloadId=$links['K']['id']??null;
        if ($key==='B' && $unloadId && workspace_can('field','K','view',$t['id'])
            && workspace_can('catalog',(string)$unloadId,'view',$t['id'])) {
            $points=workspace_unload_points($unloadId);
            $unloadResult['unloadChoices']=workspace_unload_row_options(
                workspace_unload_options_by_client($points),$reference,$value);
        }
        $active=$value!==($body['comparisonOriginal']??$body['original']);
        $action=$body['editAction']??($body['reset']?'reset':'edit');if(!in_array($action,['edit','paste','undo','reset','clear'],true))$action='edit';
        $operation=bin2hex(random_bytes(16));
        $unloadChanged=false;
        if ($key==='B' && $unloadId && ($clear || vitrine_catalog_key($before)!==vitrine_catalog_key($value))) {
            wquery("DELETE FROM record_references WHERE record_id=? AND field_key='K'",[$r['id']]);
            foreach (['K','L'] as $column) {
                $patch=vitrine_update_catalog_value($t,$r,$values,$column,'',workspace_string($values[$column]??''),$u,$action,$request,$operation,false);
                $unloadChanged=$unloadChanged || $patch!==null;
                if ($patch && workspace_can('field',$column,'view',$t['id'])) $unloadResult['unloadCells'][]=$patch;
            }
        }
        $dependentPatch=$phonePatch=null;
        $documentsChanged=false;
        if(isset($link['target'])&&($clear||$reference||$value!==$before||(!empty($party)&&$body['reset'])||(in_array($key,['K','O'],true)&&$body['reset']))){
            if ($key==='K') {
                $previous=workspace_string($values['L']??'');
                $next=$resetUnload && $sameClient ? vitrine_catalog_original_value($r,'L') : ($entry?workspace_unload_primary($entry):'');
            } elseif ($key==='O') {
                $previous=workspace_string($values['P']??'');
                $assigned=$reference ? workspace_fleet_assigned_driver($reference) : null;
                $next=$body['reset'] ? workspace_string(json_decode($r['baseline_json'],true)['P']??'') : ($assigned['name']??'');
                wquery("DELETE FROM record_references WHERE record_id=? AND field_key='P'",[$r['id']]);
                if ($assigned && !$body['reset']) wquery('INSERT INTO record_references VALUES(?,?,?)',[$r['id'],'P',$assigned['id']]);
                $phoneColumn=$link['phoneTarget'];
                $phones=$body['reset'] ? vitrine_catalog_original_value($r,$phoneColumn) : ($assigned['phones']??'');
                $phonePatch=vitrine_update_catalog_value($t,$r,$values,$phoneColumn,$phones,
                    workspace_string($values[$phoneColumn]??''),$u,$action,$request,$operation,false);
                $documentsChanged=workspace_fleet_trip_documents_save($t,$r,(int)($assigned['id']??0),
                    $body['reset'],$action,$request,$operation);
            } elseif (!empty($party)) {
                $target=$link['target'];
                $previous=$oldPartyId ? workspace_carrier_edo_operator($partyProfiles[$oldPartyId]['F']??'')
                    : vitrine_catalog_original_value($r,$target);
                $next=$body['reset'] ? vitrine_catalog_original_value($r,$target)
                    : workspace_carrier_edo_operator($partyProfiles[$reference]['F']??'');
            } else {
                $entries=vitrine_catalog_values($link);
                $previousEntry=vitrine_find_catalog_entry($binding,$before,$link['kind']==='clients');
                $previous=$entries[$previousEntry['id']??0]??'';
                $next=$entries[$reference]??'';
            }
            $dependentPatch=vitrine_update_catalog_value($t,$r,$values,$link['target'],$next,$previous,$u,$action,$request,$operation,!$clear&&empty($party)&&!in_array($key,['K','O'],true));
        }
        $catalogStyle=[];
        if(vitrine_catalog_uses_cell_fill($link['kind']??'')){
            if(!$entry&&$value!=='')$entry=vitrine_find_catalog_entry($binding,$value,$link['kind']==='terminals-main');
            $catalogStyle=['catalogFill'=>$entry['color']??null];
        }
        $derived=$dependentPatch&&workspace_can('field',$link['target'],'view',$t['id'])?['dependentCell'=>$dependentPatch]:[];
        if ($phonePatch && workspace_can('field',$phonePatch['column'],'view',$t['id'])) $derived['dependentCells']=[$phonePatch];
        if ($documentsChanged) $derived['fleetDocumentsChanged']=true;
        if($before===$value && !$orderChanged && !$dropoffChanged){
            $result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request]+$derived+$orderResult+$catalogStyle+$unloadResult+$dropoffResult;
            if($link&&$reference){
                wquery('INSERT INTO record_references VALUES(?,?,?) ON DUPLICATE KEY UPDATE entry_id=VALUES(entry_id)',[$r['id'],$key,$reference]);
                $result+=['referenceId'=>$reference,'catalogId'=>(int)$binding];
            }
            if($link && ($clear||((!empty($party)||in_array($key,['K','O'],true))&&$body['reset']))){
                wquery('DELETE FROM record_references WHERE record_id=? AND field_key=?',[$r['id'],$key]);
                $result+=['referenceId'=>0,'catalogId'=>(int)$binding];
            }
            if($dependentPatch || $phonePatch || $unloadChanged)wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$r['id']]);
            wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;
        }
        $updateMargin=$before!==$value && in_array($key,['X','Y'],true) && workspace_trip_margin_enabled($t);
        $previousMargin=$updateMargin ? workspace_trip_margin($values) : '';
        $revision++;if($before!==$value)$values[$key]=$value;$statusId=$key==='A'?($body['statusId']??null):null;
        if ($updateMargin && ($margin=workspace_trip_margin($values))!==$previousMargin) {
            $marginPatch=vitrine_update_catalog_value($t,$r,$values,'Z',$margin,$previousMargin,$u,$action,$request,$operation,false);
            if ($marginPatch && workspace_can('field','Z','view',$t['id'])) $derived['dependentCells'][]=$marginPatch;
        }
        if($statusId){$valid=wquery("SELECT e.id FROM catalog_entries e JOIN catalog_bindings b ON b.catalog_id=e.catalog_id WHERE e.id=? AND e.active=TRUE AND b.table_id=? AND b.field_key='A'",[$statusId,$t['id']])->fetchColumn();if(!$valid)throw new ApiError('invalid_status','Статус не принадлежит справочнику этой таблицы.');}
        wquery('UPDATE records SET values_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[wjson($values),$r['id']]);
        wquery('INSERT INTO cell_versions(record_id,field_key,value,revision,active,status_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),revision=VALUES(revision),active=VALUES(active),status_id=VALUES(status_id)',[$r['id'],$key,$value,$revision,(int)$active,$statusId]);
        if($key!=='A' && ($before!==$value || $reference)){wquery('DELETE FROM record_references WHERE record_id=? AND field_key=?',[$r['id'],$key]);if($reference)wquery('INSERT INTO record_references VALUES(?,?,?)',[$r['id'],$key,$reference]);}
        $label=next_label($t['columns'],$key);
        if ($orderChanged) workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$body['row'],'column'=>'B','label'=>'Статус заказа','before'=>workspace_client_order_status($oldOrderStatus)['label'],'after'=>workspace_client_order_status($nextOrderStatus)['label'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        if ($before!==$value) workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$body['row'],'column'=>$key,'label'=>$label,'before'=>$before,'after'=>$value,'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        if ($dropoffChanged) workspace_audit(['tableId'=>$t['id'],'recordId'=>(int)$r['id'],'row'=>$body['row'],'column'=>'M',
            'label'=>'Данные сдачи по рейсу','before'=>array_diff_key($previousDropoff,['legacy'=>true]),
            'after'=>array_diff_key($nextDropoff,['legacy'=>true]),'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
        if ($key==='M' && !$clear && $value===$before && !$reference && !empty($priorPoint)) $reference=$priorPoint;
        $status=$statusId?wquery('SELECT color FROM catalog_entries WHERE id=?',[$statusId])->fetch():null;$result=['revision'=>$revision,'value'=>$value,'edited'=>$active,'requestId'=>$request,'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0)]+$derived+$orderResult+$catalogStyle+$unloadResult+$dropoffResult;
        $dropoffEvent=$dropoffChanged ? ['dropoffDetails'=>array_diff_key($nextDropoff,['legacy'=>true])] : [];
        workspace_event('cell.changed',['tableSlug'=>$t['slug'],'row'=>$body['row'],'column'=>$key,'value'=>$value,'revision'=>$revision,'edited'=>$active,'statusId'=>$statusId,'statusColor'=>$status['color']??null,'original'=>$body['original'],'baseHash'=>hash('sha256',$r['baseline_json']),'referenceId'=>(int)($reference??0),'catalogId'=>(int)($binding??0),'actorId'=>$u['id'],'actorName'=>$u['name'],'action'=>$action,'requestId'=>$request,'operationId'=>$operation]+$orderResult+$catalogStyle+$dropoffEvent);
        if($key===$t['carrier_column'])workspace_event('row.changed',['tableSlug'=>$t['slug'],'row'=>$body['row']]);
        wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$u['id'],$request,wjson($result)]);workspace_db()->commit();return $result;
    }catch(Throwable $e){if(workspace_db()->inTransaction())workspace_db()->rollBack();throw $e;}
}
