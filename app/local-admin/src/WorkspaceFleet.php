<?php
declare(strict_types=1);

const WORKSPACE_FLEET_KINDS = ['fleet-drivers','fleet-vehicles'];

function workspace_fleet_catalog(string $kind): int {
    return (int)wquery('SELECT id FROM catalogs WHERE kind=? ORDER BY id LIMIT 1',[$kind])->fetchColumn();
}

function workspace_fleet_entries(string $kind,int $catalogId): array {
    $profile=$kind==='fleet-drivers'
        ? 'JOIN fleet_driver_profiles p ON p.entry_id=e.id'
        : 'JOIN fleet_vehicle_profiles p ON p.entry_id=e.id LEFT JOIN catalog_entries driver ON driver.id=p.driver_id';
    $label=$kind==='fleet-drivers'?'':' ,driver.name AS driver_name';
    return array_column(wquery("SELECT e.id,e.name,e.normalized,e.active,e.revision,p.* $label
        FROM catalog_entries e $profile WHERE e.catalog_id=? AND NOT EXISTS (
        SELECT 1 FROM workspace_meta m WHERE m.key=CONCAT('catalog_deleted_client_',e.id))
        ORDER BY e.name,e.id",[$catalogId])->fetchAll(),null,'id');
}

function workspace_fleet_values(string $kind,array $entry): array {
    if ($kind==='fleet-drivers') return ['A'=>$entry['surname'],'B'=>$entry['given_name'],'C'=>$entry['patronymic'],'D'=>$entry['phone']];
    return ['A'=>$entry['name'],'B'=>$entry['brand'],'C'=>$entry['vehicle_type'],'D'=>$entry['driver_name']??''];
}

function workspace_fleet_driver_choices(): array {
    return array_column(array_filter(workspace_fleet_entries('fleet-drivers',workspace_fleet_catalog('fleet-drivers')),
        static fn(array $driver): bool=>(bool)$driver['active']),'name');
}

function workspace_fleet_draft_fields(string $kind,array $drivers): array {
    $text=['type'=>'text'];
    $required=['required'=>true]+$text;
    if ($kind==='fleet-drivers') return ['A'=>$required,'B'=>$required,'C'=>$text,'D'=>$text];
    return ['A'=>$required,'B'=>$text,'C'=>$text,
        'D'=>['type'=>'choice','choices'=>$drivers,'allowEmpty'=>true]];
}

function workspace_fleet_plate_key(string $value): string {
    $plate=mb_strtoupper(trim($value));
    return strtr(preg_replace('/[\s-]+/u','',$plate),[
        'А'=>'A','В'=>'B','Е'=>'E','К'=>'K','М'=>'M','Н'=>'H','О'=>'O','Р'=>'P','С'=>'C','Т'=>'T','У'=>'Y','Х'=>'X',
    ]);
}

function workspace_fleet_values_validated(string $kind,array $values): array {
    if ($kind==='fleet-drivers') {
        $surname=workspace_unload_text($values['A']??'',65);
        $given=workspace_unload_text($values['B']??'',45);
        $patronymic=workspace_unload_text($values['C']??'',45);
        $phone=workspace_unload_text($values['D']??'',100);
        if ($surname==='' || $given==='') throw new ApiError('invalid_driver','Укажите фамилию и имя водителя.');
        foreach ([$surname,$given,$patronymic] as $part) {
            if ($part!=='' && !preg_match("/^\p{L}[\p{L}\p{M}'’ -]*$/uD",$part)) {
                throw new ApiError('invalid_driver','В полях ФИО допустимы буквы, пробелы и дефисы.');
            }
        }
        $name=implode(' ',array_filter([$surname,$given,$patronymic],'strlen'));
        return ['name'=>$name,'key'=>vitrine_catalog_key($name),'profile'=>[$surname,$given,$patronymic,$phone],
            'values'=>['A'=>$surname,'B'=>$given,'C'=>$patronymic,'D'=>$phone]];
    }
    $plate=mb_strtoupper(workspace_unload_text($values['A']??'',28));
    $key=workspace_fleet_plate_key($plate);
    if (!preg_match('/^(?=[\p{L}\d]{4,14}$)(?=.*\d)(?=.*\p{L})[\p{L}\d]+$/uD',$key)) {
        throw new ApiError('invalid_vehicle','Укажите один госномер с буквами и цифрами.');
    }
    $brand=workspace_unload_text($values['B']??'',160);
    $type=workspace_unload_text($values['C']??'',100);
    $driverName=workspace_unload_text($values['D']??'',160);
    $driver=null;
    if ($driverName!=='') {
        $driverCatalog=workspace_fleet_catalog('fleet-drivers');
        workspace_require('catalog',(string)$driverCatalog,'view');
        $entry=wquery('SELECT id,name FROM catalog_entries WHERE catalog_id=? AND normalized=? AND active=TRUE',
            [$driverCatalog,vitrine_catalog_key($driverName)])->fetch();
        if (!$entry) throw new ApiError('invalid_driver','Выберите действующего водителя из справочника.');
        $driver=(int)$entry['id'];
        $driverName=$entry['name'];
    }
    return ['name'=>$plate,'key'=>$key,'profile'=>[$brand,$type,$driver],
        'values'=>['A'=>$plate,'B'=>$brand,'C'=>$type,'D'=>$driverName]];
}

function workspace_fleet_write(string $kind,int $catalogId,array $values,?int $id=null,?int $revision=null): array {
    if ($catalogId!==workspace_fleet_catalog($kind)) throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$catalogId,'edit');
    $old=$id ? (workspace_fleet_entries($kind,$catalogId)[$id]??null) : null;
    if ($id && (!$old || !$old['active'] || (int)$old['revision']!==$revision)) workspace_directory_conflict();
    $next=workspace_fleet_values_validated($kind,$values);
    $before=$old ? workspace_fleet_values($kind,$old) : null;
    if ($old && $before===$next['values']) return ['id'=>$id,'revision'=>$revision,'values'=>$before];
    try {
        $rev=$old ? $revision+1 : 1;
        if ($old) {
            wquery('UPDATE catalog_entries SET name=?,normalized=?,revision=? WHERE id=?',[$next['name'],$next['key'],$rev,$id]);
        } else {
            wquery('INSERT INTO catalog_entries(catalog_id,name,normalized) VALUES(?,?,?)',[$catalogId,$next['name'],$next['key']]);
            $id=(int)workspace_db()->lastInsertId();
        }
        $sql=$kind==='fleet-drivers'
            ? 'INSERT INTO fleet_driver_profiles(entry_id,surname,given_name,patronymic,phone) VALUES(?,?,?,?,?)
                ON DUPLICATE KEY UPDATE surname=VALUES(surname),given_name=VALUES(given_name),patronymic=VALUES(patronymic),phone=VALUES(phone)'
            : 'INSERT INTO fleet_vehicle_profiles(entry_id,brand,vehicle_type,driver_id) VALUES(?,?,?,?)
                ON DUPLICATE KEY UPDATE brand=VALUES(brand),vehicle_type=VALUES(vehicle_type),driver_id=VALUES(driver_id)';
        wquery($sql,[$id,...$next['profile']]);
        workspace_audit(['category'=>'catalog','label'=>$kind==='fleet-drivers'?'Водители':'Автотранспорт',
            'before'=>$old?['id'=>$id]+$before:null,'after'=>['id'=>$id]+$next['values'],'action'=>$old?'edit':'create']);
        workspace_event('catalog.changed',['catalogId'=>$catalogId,'id'=>$id,'name'=>$next['name'],
            'beforeName'=>$old['name']??null,'active'=>true,'revision'=>$rev]);
        return ['id'=>$id,'revision'=>$rev,'values'=>$next['values']];
    } catch (PDOException $error) {
        if ($error->getCode()==='23000') throw new ApiError('duplicate_catalog','Такая запись уже есть в справочнике.',409);
        throw $error;
    }
}

function workspace_fleet_save(string $kind,int $catalogId,array $values,?int $id=null,?int $revision=null): array {
    workspace_begin();
    try {
        $result=workspace_fleet_write($kind,$catalogId,$values,$id,$revision);
        workspace_db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}

function workspace_fleet_assigned_driver(int $vehicle): ?array {
    if (!$vehicle) return null;
    return wquery('SELECT d.id,d.name FROM fleet_vehicle_profiles v JOIN catalog_entries d ON d.id=v.driver_id
        WHERE v.entry_id=? AND d.active=TRUE',[$vehicle])->fetch() ?: null;
}

function workspace_fleet_assert_unused(string $kind,array $entry): void {
    $trips=(int)wquery('SELECT COUNT(*) FROM fleet_trip_origins WHERE entry_id=?',[$entry['id']])->fetchColumn();
    if ($trips) throw new ApiError('entry_in_use','Запись используется в рейсах — '.$trips.'.',409);
    if ($kind==='fleet-drivers') {
        $vehicles=(int)wquery('SELECT COUNT(*) FROM fleet_vehicle_profiles p JOIN catalog_entries e ON e.id=p.entry_id
            WHERE p.driver_id=? AND e.active=TRUE',[$entry['id']])->fetchColumn();
        if ($vehicles) throw new ApiError('entry_in_use','Водитель закреплён за машинами — '.$vehicles.'.',409);
    }
}
