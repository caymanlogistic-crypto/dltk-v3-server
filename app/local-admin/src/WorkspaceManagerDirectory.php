<?php
declare(strict_types=1);

function workspace_manager_catalog_id(): int {
    static $id;
    return $id??=(int)wquery("SELECT id FROM catalogs WHERE kind='managers' ORDER BY id LIMIT 1")->fetchColumn();
}

function workspace_manager_roles(): array {
    static $roles;
    return $roles??=array_column(workspace_employee_roles(),null,'name');
}

function workspace_manager_entries(int $catalogId): array {
    return wquery('SELECT e.id,e.name,e.active,e.revision,p.surname,p.patronymic,p.role_id,p.phone,p.email,r.name AS role_name,r.code AS role_code
        FROM catalog_entries e JOIN manager_profiles p ON p.entry_id=e.id JOIN roles r ON r.id=p.role_id
        WHERE e.catalog_id=? ORDER BY p.surname,e.name,p.patronymic,e.id',[$catalogId])->fetchAll();
}

function workspace_manager_values(array $row): array {
    return ['A'=>$row['surname'],'B'=>$row['name'],'C'=>$row['patronymic'],
        'D'=>workspace_employee_role_names()[$row['role_code']??'']??$row['role_name'],'E'=>$row['phone'],'F'=>$row['email']];
}

function workspace_manager_draft_fields(array $roles): array {
    $optional=['type'=>'text'];
    return ['A'=>$optional,'B'=>['type'=>'text','required'=>true],'C'=>$optional,
        'D'=>['type'=>'choice','required'=>true,'choices'=>$roles],
        'E'=>['type'=>'text','maxLength'=>100],'F'=>['type'=>'email']];
}

function workspace_manager_validate(array $values): array {
    $surname=workspace_unload_text($values['A']??'',160);
    $name=workspace_unload_text($values['B']??'',160);
    $patronymic=workspace_unload_text($values['C']??'',160);
    $role=workspace_manager_roles()[$values['D']??'']??null;
    $phone=workspace_unload_text($values['E']??'',100);
    $email=workspace_unload_text($values['F']??'',254);
    if ($name==='') throw new ApiError('invalid_manager','Укажите имя менеджера.');
    if (!$role) throw new ApiError('invalid_role','Выберите роль из справочника.');
    if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ApiError('invalid_email','Проверьте адрес e-mail.');
    return compact('surname','name','patronymic','phone','email')+['role_id'=>$role['id'],'role_name'=>$role['name']];
}

function workspace_manager_save(int $catalogId,array $values,?int $entryId=null,?int $revision=null): array {
    if ($catalogId!==workspace_manager_catalog_id()) throw new ApiError('not_found','Справочник «Сотрудники» не найден.',404);
    workspace_require('catalog',(string)$catalogId,'edit');
    workspace_begin();
    try {
        $old=$entryId ? (array_column(workspace_manager_entries($catalogId),null,'id')[$entryId]??null) : null;
        if ($entryId && (!$old || !$old['active'] || (int)$old['revision']!==$revision)) workspace_directory_conflict();
        $manager=workspace_manager_validate($values);
        $key=hash('sha256',implode("\n",array_map('vitrine_catalog_key',[$manager['surname'],$manager['name'],$manager['patronymic']])));
        $revision=$old ? (int)$old['revision']+1 : 1;
        if ($old) {
            wquery('UPDATE catalog_entries SET name=?, normalized=?, revision=? WHERE id=?',[$manager['name'],$key,$revision,$entryId]);
        } else {
            wquery('INSERT INTO catalog_entries(catalog_id,name,normalized) VALUES(?,?,?)',[$catalogId,$manager['name'],$key]);
            $entryId=(int)workspace_db()->lastInsertId();
        }
        wquery('INSERT INTO manager_profiles(entry_id,surname,patronymic,role_id,phone,email) VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE surname=VALUES(surname),patronymic=VALUES(patronymic),role_id=VALUES(role_id),phone=VALUES(phone),email=VALUES(email)',
            [$entryId,$manager['surname'],$manager['patronymic'],$manager['role_id'],$manager['phone'],$manager['email']]);
        workspace_audit(['category'=>'catalog','label'=>'Сотрудники','before'=>$old?['id'=>$entryId]+workspace_manager_values($old):null,
            'after'=>['id'=>$entryId]+workspace_manager_values($manager),'action'=>$old?'edit':'create']);
        workspace_db()->commit();
        return ['id'=>$entryId,'revision'=>$revision,'values'=>workspace_manager_values($manager)];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_catalog','Менеджер с таким именем уже есть в справочнике.',409);
        }
        throw $error;
    }
}
