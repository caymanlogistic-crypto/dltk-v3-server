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

function workspace_fleet_document_target(int $vehicle): array {
    $target=wquery('SELECT p.driver_id,d.name,e.active FROM fleet_vehicle_profiles p
        JOIN catalog_entries e ON e.id=p.entry_id JOIN catalog_entries d ON d.id=p.driver_id
        WHERE p.entry_id=? AND e.catalog_id=? AND NOT EXISTS (
            SELECT 1 FROM workspace_meta m WHERE m.key=CONCAT(\'catalog_deleted_client_\',e.id))',
            [$vehicle,workspace_fleet_catalog('fleet-vehicles')])->fetch();
    if (!$target) throw new ApiError('missing_driver','Сначала укажите водителя в строке машины.',404);
    return $target;
}

function workspace_fleet_documents(int $driver): array {
    return wquery('SELECT passport,license_number,license_issued,license_expires,revision
        FROM fleet_driver_documents WHERE driver_id=?',[$driver])->fetch()
        ?: ['passport'=>'','license_number'=>'','license_issued'=>null,'license_expires'=>null,'revision'=>0];
}

function workspace_fleet_document_date(mixed $value): ?string {
    if ($value==='') return null;
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$parts)
        || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]) || (int)$parts[1]<1900) {
        throw new ApiError('invalid_document_date','Проверьте даты водительского удостоверения.');
    }
    return $value;
}

function workspace_fleet_documents_endpoint(): never {
    if (!workspace_fleet_can_documents()) throw new ApiError('forbidden','Для просмотра документов нет доступа.',403);
    $method=$_SERVER['REQUEST_METHOD'];
    if ($method!=='GET' && $method!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $catalog=(string)workspace_fleet_catalog('fleet-vehicles');
    workspace_require('catalog',$catalog,$method==='POST'?'edit':'view');
    workspace_require('catalog',(string)workspace_fleet_catalog('fleet-drivers'),$method==='POST'?'edit':'view');
    $vehicle=filter_var($_GET['vehicle']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$vehicle) throw new ApiError('missing_vehicle','Выберите машину.',404);
    header('Cache-Control: no-store');
    if ($method==='GET') {
        $target=workspace_fleet_document_target($vehicle);
        json_response(['name'=>$target['name'],'driverId'=>(int)$target['driver_id'],'editable'=>(bool)$target['active']
            && workspace_can('catalog',$catalog,'edit') && workspace_can('catalog',(string)workspace_fleet_catalog('fleet-drivers'),'edit'),
            'documents'=>workspace_fleet_documents((int)$target['driver_id'])]);
    }
    $body=wbody();
    foreach (['passport','license_number','license_issued','license_expires'] as $field) {
        if (!is_string($body[$field]??null)) throw new ApiError('invalid_documents','Проверьте данные документов.');
    }
    $passport=trim($body['passport']);
    $number=trim($body['license_number']);
    if (mb_strlen($passport)>500 || mb_strlen($number)>80 || preg_match('/[\x00-\x1F]/u',$passport.$number)) {
        throw new ApiError('invalid_documents','Проверьте длину и формат данных документов.');
    }
    $issued=workspace_fleet_document_date($body['license_issued']);
    $expires=workspace_fleet_document_date($body['license_expires']);
    if ($issued!==null && $expires!==null && $expires<$issued) {
        throw new ApiError('invalid_document_date','Дата окончания ВУ должна быть после даты выдачи.');
    }
    workspace_begin();
    try {
        $target=workspace_fleet_document_target($vehicle);
        $driver=(int)$target['driver_id'];
        $previous=workspace_fleet_documents($driver);
        if (!$target['active'] || ($body['driverId']??null)!==$driver || ($body['revision']??null)!==(int)$previous['revision']) {
            workspace_directory_conflict();
        }
        $revision=(int)$previous['revision']+1;
        wquery('INSERT INTO fleet_driver_documents(driver_id,passport,license_number,license_issued,license_expires,revision)
            VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE passport=VALUES(passport),license_number=VALUES(license_number),
                license_issued=VALUES(license_issued),license_expires=VALUES(license_expires),revision=VALUES(revision)',
            [$driver,$passport,$number,$issued,$expires,$revision]);
        workspace_audit(['category'=>'catalog','label'=>'Документы водителя','action'=>'edit',
            'after'=>['driverId'=>$driver,'revision'=>$revision]]);
        workspace_db()->commit();
        json_response(['saved'=>true,'hasDocuments'=>$passport!=='' || $number!=='' || $issued!==null || $expires!==null]);
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
