<?php
declare(strict_types=1);

function workspace_catalog_directory_model(): array {
    $id = filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $catalog = $id ? wquery('SELECT id,title,kind FROM catalogs WHERE id=?',[$id])->fetch() : null;
    if (!$catalog || workspace_fleet_retired_kind($catalog['kind'])) throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$id,'view');
    $labels = match ($catalog['kind']) {
        'clients'=>['A'=>'Клиент','B'=>'Менеджер']+WORKSPACE_CLIENT_REQUISITE_LABELS,
        'carriers'=>['A'=>'Перевозчик','B'=>'ИНН','C'=>'Идентификатор ЭДО','D'=>'Оператор ЭДО','E'=>'Логист'],
        'edo-operators'=>['A'=>'Код','B'=>'Оператор ЭДО'],
        'edo-counterparties'=>['A'=>'Название','B'=>'ИНН','C'=>'КПП','D'=>'ОГРН','E'=>'Оператор ЭДО','F'=>'ID ЭДО']+workspace_edo_party_roles(),
        'margin-rules'=>['A'=>'Дата начала','B'=>'Минимум, %','C'=>'Минимум, ₽'],
        'status'=>['A'=>'Статус','B'=>'Цвет'],
        'assignment-statuses','etrn-statuses','etrn-signing-statuses'=>['A'=>'Статус','B'=>'Цвет'],
        'terminals-main'=>['A'=>'Терминал','B'=>'Цвет'],
        'container-types'=>['A'=>'Тип контейнера','B'=>'Цвет типа','C'=>'Порог веса, кг','D'=>'Цвет превышения'],
        'unload-points'=>['A'=>'Клиент','B'=>'Адрес','C'=>'Контакты'],
        'dropoff-points'=>['A'=>'Точка сдачи','B'=>'Адрес','C'=>'Контакты','D'=>'Постоянная инструкция'],
        'fleet-drivers'=>['A'=>'Фамилия','B'=>'Имя','C'=>'Отчество','D'=>'Телефон','E'=>'Доп. телефон']+
            array_map(static fn(array $field): string=>$field[0], workspace_driver_directory_fields()),
        'fleet-vehicles'=>['B'=>'Марка тягача','A'=>'Госномер тягача',
            'I'=>'Марка полуприцепа','H'=>'Госномер полуприцепа']+workspace_fleet_driver_columns()+workspace_fleet_document_labels(),
        'managers'=>['A'=>'Фамилия','B'=>'Имя','C'=>'Отчество','D'=>'Роль','E'=>'Телефон','F'=>'Email'],
        default=>['A'=>'Название'],
    };
    $driverFields=$catalog['kind']==='fleet-drivers' ? workspace_driver_directory_fields() : [];
    $columns = [];
    foreach ($labels as $letter=>$label) {
        $column=compact('letter','label');
        $kind=$catalog['kind'];
        if (($kind==='managers' && $letter==='E') ||
            (in_array($kind,WORKSPACE_FLEET_KINDS,true) && in_array($letter,workspace_fleet_phone_columns($kind),true))) {
            $column['compactInput']='phone';
        }
        if ($catalog['kind']==='margin-rules') {
            $column['kind']=$column['compactInput']=workspace_margin_rule_inputs()[$letter];
        }
        if ($kind==='container-types' && $letter==='C') $column['kind']=$column['compactInput']='weight';
        if (in_array([$catalog['kind'],$letter],[['carriers','D'],['edo-counterparties','E']],true)) {
            $column['derivedSource']='По идентификатору ЭДО';
            $column['derivedEmptyLabel']='Оператор ЭДО не определён';
        }
        if ($catalog['kind']==='fleet-vehicles' && isset(workspace_fleet_document_fields()[$letter])) $column['export']=false;
        if (isset($driverFields[$letter])) {
            $column['export']=false;
            $column['readOnly']='Реквизит доступен для просмотра.';
        }
        $columns[]=$column;
    }
    if ($catalog['kind']==='unload-points') $columns[2]['kind']='unload-contacts';
    return ['id'=>0,'catalogId'=>(int)$id,'catalogKind'=>$catalog['kind'],'slug'=>'catalog-entries-'.$id,
        'title'=>$catalog['title'],'columns'=>$columns]+
        (match ($catalog['kind']) {
            'fleet-vehicles'=>['columnOrderVersion'=>2],
            'carriers'=>['columnOrderVersion'=>2],
            default=>[],
        });
}

function workspace_catalog_directory_data(?array $requestedRows): array {
    $model = workspace_catalog_directory_model();
    $id = $model['catalogId'];
    $kind = $model['catalogKind'];
    $archive = ($_GET['archive'] ?? '') === '1';
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,160) : '';
    $canEdit = workspace_can('catalog',(string)$id,'edit');
    $choices = workspace_directory_palette_options();
    $clientManagers=$kind==='clients' ? workspace_client_manager_values($id) : [];
    $clientRequisites=$kind==='clients' ? workspace_client_requisites($id) : [];
    $emptyClientRequisites=array_fill_keys(array_keys(WORKSPACE_CLIENT_REQUISITE_LABELS),'');
    $carrierValues=$kind==='carriers' ? workspace_carrier_directory_values($id) : [];
    $unload = $kind==='unload-points';
    $managers=$kind==='managers';
    $fleet=in_array($kind,WORKSPACE_FLEET_KINDS,true);
    $driverCatalog=$kind==='fleet-vehicles' ? workspace_fleet_catalog('fleet-drivers') : 0;
    $showDocuments=$driverCatalog && workspace_can('catalog',(string)$driverCatalog,'view');
    $canEditDocuments=$showDocuments && $canEdit && workspace_can('catalog',(string)$driverCatalog,'edit');
    $partyRoles=$kind==='edo-counterparties' ? workspace_edo_party_roles() : [];
    $partyProfiles=$partyRoles ? workspace_edo_party_profiles($id) : [];
    $roleFilter=is_string($_GET['partyRole']??null) && isset($partyRoles[$_GET['partyRole']]) ? $_GET['partyRole'] : '';
    $marginRules=$kind==='margin-rules' ? workspace_margin_rule_profiles($id) : [];
    $dropoffProfiles=$kind==='dropoff-points' ? workspace_dropoff_profiles($id) : [];
    $containerRules=$kind==='container-types' ? workspace_container_type_rules($id) : [];
    $showAll=$managers && ($_GET['archive'] ?? '')==='all';
    $managerRoles=$managers ? array_keys(workspace_manager_roles()) : [];
    $clientCatalog=$unload ? workspace_client_grid_catalog() : null;
    $clientFilter=$unload && isset($_GET['client'])
        ? workspace_directory_client_filter((int)($clientCatalog['id']??0)) : null;
    $clientChoices=$unload ? array_column(workspace_unload_client_entries(),'name') : [];
    $entries = match (true) {
        $unload=>workspace_unload_points($id),
        $managers=>workspace_manager_entries($id),
        $fleet=>workspace_fleet_entries($kind,$id),
        default=>wquery("SELECT id,name,normalized,color,revision,active FROM catalog_entries e WHERE catalog_id=? AND active=?
            AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id))
            ORDER BY name,id",[$id,(int)!$archive])->fetchAll(),
    };
    if ($showDocuments) {
        $documentRows=workspace_fleet_documents();
    }
    if (!$archive) $entries=workspace_directory_order_entries($entries,$id);
    $palette = array_column(workspace_palette(),'name','fill');
    $rows = $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows,true);
    foreach ($entries as $entry) {
        if (!$showAll && (bool)$entry['active']===$archive) continue;
        if ($kind==='fleet-vehicles' && workspace_fleet_trailer_key($entry['name'])) continue;
        if ($unload && !$archive && $entry['review_required']) continue;
        if ($clientFilter && (int)$entry['client_id']!==(int)$clientFilter['id']) continue;
        $entryId = (int)$entry['id'];
        $values = match (true) {
            $unload=>workspace_unload_point_values($entry),
            $managers=>workspace_manager_values($entry),
            $fleet=>workspace_fleet_values($kind,$entry),
            $kind==='edo-operators'=>workspace_edo_operator_values($entry),
            $partyRoles!==[]=>workspace_edo_party_values($entry['name'],$partyProfiles[$entryId]??[]),
            $kind==='margin-rules'=>workspace_margin_rule_values($entry['name'],$marginRules[$entryId]??[]),
            default=>['A'=>$entry['name']],
        };
        if ($unload) $values['C']=workspace_unload_contact_summary(workspace_unload_primary($entry),(int)$entry['contact_count']);
        if ($kind==='dropoff-points') $values+=($dropoffProfiles[$entryId]??workspace_dropoff_profile([]));
        if ($kind==='container-types') $values+=workspace_container_type_rule_values($containerRules[$entryId]);
        if (workspace_catalog_has_palette($kind)) $values['B'] = $palette[$entry['color']] ?? '';
        if ($kind==='clients') $values+=['B'=>$clientManagers[$entryId] ?? '']+($clientRequisites[$entryId]??[])+$emptyClientRequisites;
        if ($kind==='carriers') $values+=($carrierValues[$entryId]??workspace_carrier_values([]));
        if ($driverCatalog && !$showDocuments) {
            $values=array_replace($values,array_fill_keys(array_keys(workspace_fleet_driver_columns()),''));
        }
        if ($kind==='fleet-vehicles') unset($values['C']);
        if ($roleFilter!=='' && $values[$roleFilter]!=='Да') continue;
        if ($search !== '' && mb_stripos(implode(' ',$values),$search) === false) {
            $plateQuery=$kind==='fleet-vehicles' ? workspace_fleet_plate_key($search) : '';
            if ($plateQuery==='' || (!str_contains(workspace_fleet_plate_key($values['A']),$plateQuery)
                && !str_contains(workspace_fleet_plate_key($values['H']),$plateQuery))) continue;
        }
        $rowIds[] = $entryId;
        if ($requested === null ? count($rows)>=100 : !isset($requested[$entryId])) continue;
        $row = ['rowIndex'=>$entryId,'rowLabel'=>count($rowIds),'values'=>$values,'rowActions'=>$canEdit,
            'singleLine'=>array_fill_keys(array_keys($values),true),'baseHash'=>workspace_catalog_directory_hash($id,$entryId),
            'edits'=>array_fill_keys(array_keys($values),['revision'=>(int)$entry['revision']])];
        if (workspace_catalog_has_palette($kind)) {
            if (workspace_is_document_status_catalog($kind)) $row['catalogFills']['B']=$entry['color'] ?? '';
            else $row['orderFill']=$entry['color'];
            $row['choices']['B']=$choices;
            $row['requiredChoices']['B']=!workspace_is_document_status_catalog($kind);
        }
        if ($kind==='container-types') {
            $rule=$containerRules[$entryId];
            $row['choices']['B']=$row['choices']['D']=$choices;
            $row['catalogFills']['B']=$rule['typeColor']??'';
            $row['catalogFills']['D']=$rule['warningColor']??'';
            $row['kinds']['C']='weight';
        }
        foreach ($partyRoles as $column=>$_label) {
            $row['choices'][$column]=workspace_edo_party_role_choices();
            $row['requiredChoices'][$column]=true;
            $row['catalogFills'][$column]=workspace_edo_party_role_fill($values[$column]) ?? '';
        }
        if ($managers) {
            $row['archived']=!(bool)$entry['active'];
            $row['actionLabel']=implode(' ',array_filter([$values['A'],$values['B'],$values['C']],
                static fn(string $part): bool=>$part!=='' && $part!=='Неизвестно'));
            $row['choices']['D']=$managerRoles;
            $row['requiredChoices']['D']=true;
        }
        if ($kind==='fleet-vehicles') {
            $driver=(int)$entry['driver_id'];
            $documentState=$showDocuments
                ? workspace_fleet_document_state($entryId,$driver,$canEditDocuments && !$archive,$documentRows[$driver]??[])
                : null;
            foreach (workspace_fleet_document_fields() as $column=>$_field) {
                $row['values'][$column]=$documentState['values'][$column]??'';
                $row['singleLine'][$column]=true;
                $row['kinds'][$column]='fleet-document';
                $row['edits'][$column]=['revision'=>$documentState['revision']??0,'baseHash'=>$documentState['baseHash']??''];
                $row['readOnly'][$column]=$documentState['readOnly']??'Нет доступа к документам водителя.';
            }
            $row['kinds']['P']='fleet-driver';
            $row['edits']['P']=['revision'=>(int)$entry['revision']];
            if ($canEditDocuments && !$archive) $row['readOnly']['P']='';
            foreach (array_keys(workspace_fleet_driver_columns()) as $column) {
                if ($showDocuments) $row['kinds'][$column]='fleet-driver';
                else $row['readOnly'][$column]='Нет доступа к данным водителя.';
            }
        }
        if ($kind==='dropoff-points') $row['singleLine']=['A'=>true];
        if ($unload) {
            $row['singleLine']=['A'=>true];
            $row['readOnly']['A']='Клиент сохранённой точки закреплён.';
            if ($clientCatalog && $entry['client_id']) {
                $row['relatedUrl']='/vitrine?catalog=clients&client='.(int)$entry['client_id'].($entry['client_active']?'':'&archive=1');
                $row['rowActions']=true;
            }
        }
        $rows[] = $row;
    }
    if ($kind==='fleet-drivers') {
        $documents=workspace_driver_directory_requisites(array_column($rows,'rowIndex'));
        foreach ($rows as &$row) {
            $values=$documents[$row['rowIndex']]??[];
            $row['values']+=$values;
            $row['singleLine']+=array_fill_keys(array_keys($values),true);
        }
        unset($row);
    }
    $details = wquery("SELECT COALESCE(JSON_LENGTH(JSON_EXTRACT(value,'$.unkeyed')),0) AS unkeyedCount
        FROM workspace_meta WHERE `key`=?",['catalog_details_'.$id])->fetch();
    return ['table'=>$model,'columns'=>$model['columns'],'rows'=>$rows,'rowIds'=>$rowIds,'palette'=>workspace_palette(),
        'catalogGrid'=>['id'=>$id,'archive'=>$archive,'showAll'=>$showAll,'search'=>$search,'canEdit'=>$canEdit,
            'syncLabel'=>$canEdit && function_exists('workspace_catalog_source_label') ? workspace_catalog_source_label($id) : null,
            'standaloneCard'=>$kind==='carriers',
            'hasDetails'=>(bool)$details,'unkeyedCount'=>(int)($details['unkeyedCount'] ?? 0),
            'unloadPoints'=>$unload,'partyRoles'=>$partyRoles,'partyRole'=>$roleFilter,
            'relatedLabel'=>$clientCatalog ? 'Открыть клиента' : null,
            'clientFilter'=>$clientFilter,
            'draftVersion'=>$unload ? 'client-address' : ($kind==='fleet-vehicles' ? 'vehicle-driver' : ''),
            'draftFields'=>$unload ? workspace_unload_draft_fields($clientFilter
                ? array_values(array_intersect($clientChoices,[$clientFilter['name']])) : $clientChoices)
                : match (true) {
                    $managers=>workspace_manager_draft_fields($managerRoles),
                    $fleet=>workspace_fleet_draft_fields($kind),
                    default=>workspace_directory_draft_fields('entries',catalogKind:$kind),
                }]];
}

function workspace_catalog_directory_hash(int $catalogId,int $entryId): string {
    return hash('sha256','catalog-directory:'.$catalogId.':'.$entryId);
}

function workspace_catalog_directory_cell(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    json_response(workspace_catalog_directory_edit(wbody(),$pasteCheck));
}

function workspace_catalog_directory_edit(array $body, bool $pasteCheck = false): array {
    $model = workspace_catalog_directory_model();
    $id = $model['catalogId'];
    workspace_require('catalog',(string)$id,'edit');
    $column = $body['column'] ?? '';
    if (!is_int($body['row'] ?? null) || !is_string($body['value'] ?? null)
        || !in_array($column,array_column($model['columns'],'letter'),true)) {
        throw new ApiError('invalid_edit','Проверьте данные ячейки.');
    }
    $entry = wquery('SELECT * FROM catalog_entries WHERE catalog_id=? AND id=? AND active=TRUE',[$id,$body['row']])->fetch();
    if (!$entry) throw new ApiError('not_found','Запись не найдена.',404);
    $kind = $model['catalogKind'];
    if ($kind==='fleet-drivers' && isset(workspace_driver_directory_fields()[$column])) {
        throw new ApiError('forbidden','Реквизит доступен для просмотра.',403);
    }
    if (in_array($kind,WORKSPACE_FLEET_KINDS,true)) {
        $isFleet=$kind==='fleet-vehicles';
        if ($isFleet && isset(workspace_fleet_document_fields()[$column]) && ($column!=='P' || !isset($body['fleetGroup']))) {
            return workspace_fleet_document_cell($body,$pasteCheck);
        }
        $profile=workspace_fleet_entries($kind,$id)[$body['row']]??null;
        if (!$profile) throw new ApiError('not_found','Запись не найдена.',404);
        $oldValues=workspace_fleet_values($kind,$profile);
        $changes=$isFleet ? workspace_fleet_edit_values($body) : [$column=>$body['value']];
        if ($isFleet && !$pasteCheck && ($body['editAction']??null)==='clear' && $body['value']==='' && in_array($column,['D','E'],true)) {
            $changes=array_fill_keys(array_keys(workspace_fleet_driver_columns()),'');
        }
        $birth=null;
        if ($isFleet && array_key_exists('P',$changes)) {
            $driverCatalog=(string)workspace_fleet_catalog('fleet-drivers');
            workspace_require('catalog',$driverCatalog,'view');
            workspace_require('catalog',$driverCatalog,'edit');
            if (!is_int($body['birthRevision']??null)) workspace_directory_conflict();
            $birth=['value'=>$changes['P'],'revision'=>$body['birthRevision']];
            unset($changes['P']);
        }
        $values=array_replace($oldValues,$changes);
        if ($isFleet && isset($changes['H']) && $values['I']===$oldValues['I']
            && workspace_fleet_trailer_profile_key($values['H'])!==workspace_fleet_trailer_profile_key($oldValues['H'])) {
            $key=workspace_fleet_trailer_profile_key($values['H']);
            $values['I']=$key ? (string)wquery('SELECT brand FROM fleet_trailer_profiles WHERE plate_key=?',[$key])->fetchColumn() : '';
        }
        if ($isFleet && array_intersect_key($changes,array_flip(['D','E','F']))) {
            $identity=array_flip(['D','E','F']);
            if (vitrine_catalog_key(implode(' ',array_intersect_key($values,$identity)))!==
                vitrine_catalog_key(implode(' ',array_intersect_key($oldValues,$identity)))) {
                foreach (['G','Q'] as $phoneColumn) if ($values[$phoneColumn]===$oldValues[$phoneColumn]) $values[$phoneColumn]='';
            }
        }
        if ($pasteCheck) {
            $validated=workspace_fleet_values_validated($kind,$values,$oldValues);
            $response=['accepted'=>true];
            if ($isFleet && in_array($column,['A','H'],true)) {
                $plate=$validated['values'][$column];
                $response['editorValue']=workspace_fleet_plate_format($plate,$column==='H')??$plate;
            }
            return $response;
        }
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_fleet_save($kind,$id,$values,$body['row'],$body['revision'],$birth);
        if ($isFleet) {
            $driverCatalog=(string)workspace_fleet_catalog('fleet-drivers');
            if (workspace_can('catalog',$driverCatalog,'view')) {
                $driver=workspace_fleet_driver_id($body['row']);
                $result['fleetBirthEditable']=workspace_can('catalog',$driverCatalog,'edit');
                $result['fleetDocuments']=workspace_fleet_document_state($body['row'],$driver,$result['fleetBirthEditable']);
            } else {
                $result['values']=array_diff_key($result['values'],workspace_fleet_driver_columns());
            }
        }
        $value=$result['values'][$column]??$result['fleetDocuments']['values'][$column]??'';
        return $result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false];
    }
    if ($kind==='managers') {
        $manager=array_column(workspace_manager_entries($id),null,'id')[$body['row']]??null;
        if (!$manager) throw new ApiError('not_found','Менеджер не найден.',404);
        $values=workspace_manager_values($manager);
        $values[$column]=$body['value'];
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if ($pasteCheck) {
            workspace_manager_validate($values,$manager['phone']);
            return ['accepted'=>true];
        }
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_manager_save($id,$values,$body['row'],$body['revision']);
        $value=$result['values'][$column];
        return $result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false];
    }
    if ($kind==='dropoff-points') {
        $values=['A'=>$entry['name']]+(workspace_dropoff_profiles($id)[$body['row']]??workspace_dropoff_profile([]));
        $values[$column]=$body['value'];
        $name=workspace_catalog_validate_name($values['A']);
        $profile=workspace_dropoff_profile($values);
        if ($pasteCheck) return ['accepted'=>true];
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_catalog_save(['catalogId'=>$id,'id'=>$body['row'],'revision'=>$body['revision'],
            'name'=>$name,'dropoffProfile'=>$profile]);
        $value=($profile+['A'=>$name])[$column];
        return $result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false];
    }
    if ($kind==='unload-points') {
        if ($column==='A') throw new ApiError('fixed_unload_client','У сохранённой точки нельзя изменить клиента.',409);
        if ($column==='C') {
            if ($pasteCheck || ($body['editAction']??null)!=='clear' || $body['value']!=='') {
                throw new ApiError('contact_editor','Откройте редактор контактов из ячейки «Контакты».');
            }
            $baseHash=workspace_catalog_directory_hash($id,$body['row']);
            if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
            $saved=workspace_unload_contacts($id,$body['row'],['revision'=>$body['revision'],'contacts'=>[]]);
            return ['value'=>$saved['summary'],'original'=>$saved['summary'],'revision'=>$saved['revision'],
                'baseHash'=>$baseHash,'edited'=>false];
        }
        $point=workspace_unload_points($id)[$body['row']]??null;
        if (!$point) throw new ApiError('not_found','Точка выгрузки не найдена.',404);
        $values=['B'=>$body['value']];
        $baseHash=workspace_catalog_directory_hash($id,$body['row']);
        if ($pasteCheck) {
            workspace_unload_validate_point($values,$point);
            return ['accepted'=>true];
        }
        if (($body['baseHash']??null)!==$baseHash || !is_int($body['revision']??null)) workspace_directory_conflict();
        $result=workspace_unload_save_point($id,$values,$body['row'],$body['revision']);
        $value=$result['values'][$column];
        return $result+['value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false];
    }
    if (in_array([$kind,$column],[['carriers','D'],['edo-counterparties','E']],true)) {
        throw new ApiError('derived_field','Оператор определяется по идентификатору ЭДО.',403);
    }
    $payload = ['catalogId'=>$id,'id'=>$body['row'],'name'=>$entry['name'],'color'=>$entry['color']];
    $updatedValues=[];
    $catalogStyle=[];
    $value = $body['value'];
    $fill = null;
    if ($kind==='edo-counterparties') {
        $profile=workspace_client_catalog_metadata('edo_party_'.$entry['id']);
        if ($column==='A') $payload['name']=$value=workspace_catalog_validate_name($value);
        else $profile[$column]=$value;
        $payload['edoParty']=workspace_edo_party_profile($profile);
        $values=workspace_edo_party_values($payload['name'],$payload['edoParty']);
        $value=$values[$column];
        if ($column==='F') $updatedValues=['E'=>$values['E']];
        if (isset(workspace_edo_party_roles()[$column])) $catalogStyle=['catalogFill'=>workspace_edo_party_role_fill($value)];
    } elseif ($kind==='margin-rules') {
        $stored=workspace_client_catalog_metadata('margin_rule_'.$entry['id']);
        $values=workspace_margin_rule_values($entry['name'],$stored);
        $values[$column]=$value;
        $payload['name']=workspace_margin_rule_date($values['A']);
        $payload['marginRule']=$values;
        $value=workspace_margin_rule_values($payload['name'],workspace_margin_rule_profile($values))[$column];
    } elseif ($kind==='edo-operators') {
        if ($column==='A') $payload['operatorCode']=$value=workspace_edo_operator_code($value);
        else $payload['name']=$value=workspace_catalog_validate_name($value);
    } elseif ($kind==='container-types' && $column!=='A') {
        $previous=workspace_container_type_profile($entry['name'],workspace_client_catalog_metadata('container_type_rule_'.$entry['id']));
        $rule=workspace_container_type_input([$column=>$value],$previous);
        $payload['active']=(bool)$entry['active'];
        $payload['containerRule']=$rule;
        $value=workspace_container_type_rule_values($rule)[$column];
        if ($column!=='C') $catalogStyle=['catalogFill'=>$rule[$column==='B' ? 'typeColor' : 'warningColor']];
    } elseif ($column === 'A') $payload['name'] = $value = workspace_catalog_validate_name($value);
    elseif (workspace_is_document_status_catalog($kind)) {
        $payload['color']=$value==='' ? null : workspace_directory_color($value);
        $catalogStyle=['catalogFill'=>$payload['color']];
    }
    elseif (workspace_catalog_has_palette($kind)) $payload['color'] = $fill = workspace_directory_color($value);
    elseif ($kind==='carriers' && in_array($column,['B','C'],true)) {
        $details=workspace_carrier_entry_details((int)$entry['id']);
        $field=$column==='C' ? 'edoId' : 'B';
        $details[$field]=$value;
        $payload['carrierDetails']=workspace_carrier_details($details);
        $value=$payload['carrierDetails'][$field];
        if ($column==='C') $updatedValues=['D'=>workspace_carrier_edo_operator($value)];
    } elseif ($kind==='clients' && isset(WORKSPACE_CLIENT_REQUISITE_LABELS[$column])) {
        $payload['clientRequisites']=workspace_client_validate_requisites([$column=>$value]);
        $value=$payload['clientRequisites'][$column];
    } else $payload[$kind === 'clients' ? 'manager' : 'logist'] = $value = workspace_client_validate_manager($value);
    if ($pasteCheck) return ['accepted'=>true];
    $baseHash = workspace_catalog_directory_hash($id,$body['row']);
    if (($body['baseHash'] ?? null) !== $baseHash || !is_int($body['revision'] ?? null)) workspace_directory_conflict();
    return workspace_catalog_save($payload+['revision'=>$body['revision']])+[
        'value'=>$value,'original'=>$value,'baseHash'=>$baseHash,'edited'=>false,'orderFill'=>$fill,'values'=>$updatedValues]+$catalogStyle;
}
