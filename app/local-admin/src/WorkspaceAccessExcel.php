<?php
declare(strict_types=1);

function workspace_access_excel_entries(string $kind): array {
    $entries=[];
    if ($kind==='roles') {
        foreach ([true,false] as $archive) {
            foreach (workspace_employee_roles($archive) as $role) {
                $entries[$role['id']]=$role+['revision'=>0,'active'=>!$archive,
                    'normalized'=>vitrine_status_key($role['name']),'values'=>['A'=>$role['name']]];
            }
        }
    } else {
        foreach (workspace_employee_records() as $employee) {
            $entries[$employee['id']]=$employee+['revision'=>$employee['authVersion'],
                'normalized'=>$employee['login'],'values'=>workspace_employee_grid_values($employee)];
        }
    }
    return $entries;
}

function workspace_access_excel_payload(string $kind,array $values,?array $old): array {
    if ($kind==='roles') {
        $name=workspace_catalog_validate_name($values['A']);
        if (mb_strlen($name)>100) throw new ApiError('invalid_role','Укажите название роли до 100 символов.');
        if ($old && $old['name']!==$name) throw new ApiError('invalid_role','Название существующей роли закреплено.');
        return [vitrine_status_key($name),['values'=>['A'=>$name]]];
    }
    $scope=workspace_employee_editor_scope();
    if ($scope===null || ($old && !workspace_employee_can_edit($scope,$old))) {
        throw new ApiError('forbidden','Для этой учётной записи нет доступа.',403);
    }
    $name=workspace_employee_profile_value('name',$values['A']);
    $login=workspace_employee_profile_value('login',$values['B']);
    $email=workspace_employee_profile_value('email',$values['D']);
    $payload=['name'=>$name,'login'=>$login,'email'=>$email,'active'=>true];
    $sameRole=$old && $values['E']===$old['values']['E'];
    if ($old && $old['id']===workspace_current_user()['id'] && (!$sameRole || $login!==$old['login'])) {
        throw new ApiError('own_account','Свой логин и роль через Excel менять нельзя.',409);
    }
    if (!$sameRole) {
        $role=array_column(workspace_employee_role_options($scope),'id','name')[$values['E']]??null;
        if (!$role) throw new ApiError('invalid_role','Выберите роль из справочника.');
        $payload['roleIds']=[$role];
    }
    if ($old) $payload+=['id'=>$old['id'],'revision'=>workspace_employee_revision(array_intersect_key($old,
        array_flip(['id','name','login','email','active','authVersion','roles'])))];
    return [$login,$payload];
}
