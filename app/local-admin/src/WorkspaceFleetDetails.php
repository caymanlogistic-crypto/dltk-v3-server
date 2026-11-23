<?php
declare(strict_types=1);

function workspace_fleet_trailer_key(string $plate): string {
    $key=workspace_fleet_plate_key($plate);
    return preg_match('/^[ABEKMHOPCTYX]{2}[0-9]{6,7}$/D',$key) ? $key : '';
}

function workspace_fleet_trailer_values(array $values): array {
    $plate=mb_strtoupper(workspace_unload_text($values['H']??'',28));
    $brand=workspace_unload_text($values['I']??'',160);
    $key=workspace_fleet_trailer_key($plate);
    if (($plate!=='' || $brand!=='') && $key==='') {
        throw new ApiError('invalid_trailer','Укажите госномер полуприцепа в формате АВ123477.');
    }
    return ['key'=>$key,'plate'=>$plate,'brand'=>$brand];
}

function workspace_fleet_write_trailer(int $vehicle,array $trailer): void {
    if (!$trailer['key']) {
        wquery('DELETE FROM fleet_vehicle_trailers WHERE vehicle_id=?',[$vehicle]);
        return;
    }
    wquery('INSERT INTO fleet_trailer_profiles(plate_key,plate,brand) VALUES(?,?,?)
        ON DUPLICATE KEY UPDATE plate=VALUES(plate),brand=VALUES(brand)',
        [$trailer['key'],$trailer['plate'],$trailer['brand']]);
    wquery('INSERT INTO fleet_vehicle_trailers(vehicle_id,trailer_key) VALUES(?,?)
        ON DUPLICATE KEY UPDATE trailer_key=VALUES(trailer_key)',[$vehicle,$trailer['key']]);
}

function workspace_fleet_can_documents(): bool {
    return (bool)wquery("SELECT 1 FROM user_roles u JOIN roles r ON r.id=u.role_id
        WHERE u.user_id=? AND r.code='superadmin'",[workspace_current_user()['id']])->fetchColumn();
}

function workspace_fleet_trip_vehicle_label(string $tractor,?string $trailer): string {
    return $trailer ? $tractor.' / '.$trailer : $tractor;
}

function workspace_fleet_trip_vehicle_key(string $value): string {
    [$tractor,$trailer]=array_pad(explode(' / ',$value,2),2,'');
    return workspace_fleet_plate_key($trailer!=='' && !workspace_fleet_trailer_key($trailer) ? $value : $tractor);
}

function workspace_fleet_retired_kind(string $kind): bool {
    return in_array($kind,['fleet-drivers','transport','drivers-detailed','drivers-legacy'],true)
        && workspace_fleet_catalog('fleet-vehicles')>0;
}

function workspace_fleet_document_labels(): array {
    return ['J'=>'Серия и номер паспорта','N'=>'Кем выдан паспорт','O'=>'Дата выдачи паспорта','P'=>'Дата рождения',
        'K'=>'Серия и номер ВУ','L'=>'Дата выдачи ВУ','M'=>'ВУ действует до'];
}

function workspace_fleet_document_fields(): array {
    return ['J'=>'passport_number','N'=>'passport_issuer','O'=>'passport_issued','P'=>'birth_date',
        'K'=>'license_number','L'=>'license_issued','M'=>'license_expires'];
}

function workspace_fleet_document_text_limits(): array {
    return ['passport_number'=>50,'passport_issuer'=>400,'license_number'=>80];
}

function workspace_fleet_document_target(int $vehicle): array {
    $target=wquery('SELECT p.driver_id,d.name,e.active FROM fleet_vehicle_profiles p
        JOIN catalog_entries e ON e.id=p.entry_id JOIN catalog_entries d ON d.id=p.driver_id
        WHERE p.entry_id=? AND e.catalog_id=? AND NOT EXISTS (
            SELECT 1 FROM workspace_meta m WHERE m.key=CONCAT(\'catalog_deleted_client_\',e.id))',
            [$vehicle,workspace_fleet_catalog('fleet-vehicles')])->fetch();
    if (!$target) throw new ApiError('missing_driver','Сначала укажите водителя в строке машины.',404);
    return $target;
}

function workspace_fleet_documents(?int $driver=null): array {
    $fields=workspace_fleet_document_fields();
    $sql='SELECT driver_id,revision,'.implode(',',$fields).' FROM fleet_driver_documents';
    if ($driver===null) return array_column(wquery($sql)->fetchAll(),null,'driver_id');
    return wquery($sql.' WHERE driver_id=?',[$driver])->fetch()
        ?: ['revision'=>0]+array_fill_keys(array_keys(workspace_fleet_document_text_limits()),'')+array_fill_keys($fields,null);
}

function workspace_fleet_document_state(int $vehicle,int $driver,bool $editable,?array $data=null): array {
    $data??=$driver ? workspace_fleet_documents($driver) : [];
    $values=[];
    $textLimits=workspace_fleet_document_text_limits();
    foreach (workspace_fleet_document_fields() as $column=>$field) {
        $value=(string)($data[$field]??'');
        $values[$column]=!isset($textLimits[$field]) && $value!=='' ? implode('.',array_reverse(explode('-',$value))) : $value;
    }
    return ['values'=>$values,'revision'=>(int)($data['revision']??0),'baseHash'=>hash('sha256',"fleet-documents:$vehicle:$driver"),
        'readOnly'=>$editable && $driver ? '' : ($driver ? 'Запись доступна только для просмотра.' : 'Сначала укажите водителя.')];
}

function workspace_fleet_document_date(string $value,bool $fullYear=false): ?string {
    if ($value==='') return null;
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2}|\d{4})$/D',$value,$parts)) {
        if ($fullYear && strlen($parts[3])===2) throw new ApiError('invalid_document_date','Укажите год рождения четырьмя цифрами.');
        $year=strlen($parts[3])===2 ? '20'.$parts[3] : $parts[3];
        $value=$year.'-'.$parts[2].'-'.$parts[1];
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$parts)
        || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]) || (int)$parts[1]<1900) {
        throw new ApiError('invalid_document_date','Проверьте дату. Формат ДД.ММ.ГГГГ.');
    }
    return $value;
}

function workspace_fleet_document_cell(array $body,bool $pasteCheck): never {
    if (!workspace_fleet_can_documents()) throw new ApiError('forbidden','Документы доступны суперадмину.',403);
    workspace_require('catalog',(string)workspace_fleet_catalog('fleet-drivers'),'edit');
    $vehicle=$body['row'];
    $column=$body['column'];
    $field=workspace_fleet_document_fields()[$column];
    workspace_begin();
    try {
        $target=workspace_fleet_document_target($vehicle);
        $driver=(int)$target['driver_id'];
        $previous=workspace_fleet_documents($driver);
        $state=workspace_fleet_document_state($vehicle,$driver,(bool)$target['active'],$previous);
        if ($state['readOnly']!=='' || ($body['baseHash']??null)!==$state['baseHash'] || ($body['revision']??null)!==$state['revision']) {
            workspace_directory_conflict();
        }
        $next=$previous;
        $value=trim($body['value']);
        $limit=workspace_fleet_document_text_limits()[$field]??null;
        if ($limit===null) {
            $next[$field]=workspace_fleet_document_date($value,$field==='birth_date');
            if (in_array($field,['passport_issued','birth_date'],true) && $next[$field]!==null && $next[$field]>date('Y-m-d')) {
                throw new ApiError('invalid_document_date','Дата не может быть позже сегодняшнего дня.');
            }
        } else {
            if (mb_strlen($value)>$limit || preg_match('/[\x00-\x1F]/u',$value)) {
                throw new ApiError('invalid_documents','Проверьте длину и формат данных документов.');
            }
            $next[$field]=$value;
        }
        if ($next['license_issued']!==null && $next['license_expires']!==null && $next['license_expires']<$next['license_issued']) {
            throw new ApiError('invalid_document_date','Дата окончания ВУ должна быть после даты выдачи.');
        }
        if ($pasteCheck) {
            workspace_db()->rollBack();
            json_response(['accepted'=>true]);
        }
        if ($previous!==$next) {
            $next['revision']=$state['revision']+1;
            $columns=['revision',...array_values(workspace_fleet_document_fields())];
            $placeholders=implode(',',array_fill(0,count($columns)+1,'?'));
            $updates=implode(',',array_map(static fn(string $name): string=>$name.'=VALUES('.$name.')',$columns));
            wquery('INSERT INTO fleet_driver_documents(driver_id,'.implode(',',$columns).")
                VALUES($placeholders) ON DUPLICATE KEY UPDATE $updates",
                [$driver,...array_map(static fn(string $name): mixed=>$next[$name],$columns)]);
            workspace_audit(['category'=>'catalog','label'=>'Документы водителя','action'=>'edit',
                'after'=>['driverId'=>$driver,'revision'=>$next['revision']]]);
        }
        workspace_db()->commit();
        $result=workspace_fleet_document_state($vehicle,$driver,true,$next);
        $shown=$result['values'][$column];
        json_response(['value'=>$shown,'original'=>$shown,'baseHash'=>$result['baseHash'],'revision'=>$result['revision'],
            'edited'=>false,'fleetDocumentEdit'=>true,'fleetDocuments'=>$result]);
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
