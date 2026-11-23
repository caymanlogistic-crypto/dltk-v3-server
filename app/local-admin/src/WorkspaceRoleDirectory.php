<?php
declare(strict_types=1);

function workspace_directory_roles_editable(): bool {
    return workspace_can('system','roles','view') && workspace_can('system','roles','edit')
        && workspace_employee_editor_scope() === 'superadmin';
}

function workspace_role_row_action(array $body): array {
    workspace_begin();
    try {
        $result=workspace_role_row_write($body);
        workspace_db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}

function workspace_assert_role_unused(array $role): void {
    $id=(int)$role['id'];
    $members=(int)wquery('SELECT COUNT(*) FROM user_roles WHERE role_id=?',[$id])->fetchColumn();
    $rules=(int)wquery('SELECT COUNT(*) FROM access_rules WHERE role_id=?',[$id])->fetchColumn();
    $managers=workspace_manager_catalog_id()
        ? (int)wquery('SELECT COUNT(*) FROM manager_profiles WHERE role_id=?',[$id])->fetchColumn() : 0;
    $reasons=[];
    if ($members) $reasons[]='сотрудников с этой ролью — '.$members;
    if ($rules) $reasons[]='правил доступа — '.$rules;
    if ($managers) $reasons[]='менеджеров с этой ролью — '.$managers;
    if (!str_starts_with($role['code'],'directory_')) $reasons[]='роль используется системой';
    if ($reasons) throw new ApiError('entry_in_use','Роль «'.$role['name'].'» используется: '.implode(', ',$reasons).'. Архивировать её нельзя.',409);
}

function workspace_role_row_write(array $body): array {
    if (!workspace_directory_roles_editable()) throw new ApiError('forbidden','Роли редактирует суперадмин.',403);
    $action = $body['action'];
    $archived = workspace_client_catalog_metadata('directory_archived_roles');
    if ($action === 'add') {
        $name = workspace_catalog_validate_name($body['values']['A'] ?? '');
        if (mb_strlen($name)>100) throw new ApiError('invalid_role','Укажите название роли до 100 символов.');
        $roles = workspace_employee_roles();
        if (in_array(vitrine_status_key($name),array_map('vitrine_status_key',array_column($roles,'name')),true)) {
            throw new ApiError('duplicate_role','Такая роль уже существует.',409);
        }
        wquery('INSERT INTO roles(code,name) VALUES(?,?)',['directory_'.bin2hex(random_bytes(12)),$name]);
        $id = (int)workspace_db()->lastInsertId();
        $before = null;
        $after = ['id'=>$id,'name'=>$name,'active'=>true];
    } else {
        $id = $body['row'];
        $role = wquery('SELECT id,name,code FROM roles WHERE id=?',[$id])->fetch();
        if (!$role || workspace_row_deleted('directory_deleted_role_',$id)) throw new ApiError('not_found','Роль не найдена.',404);
        $active = !isset($archived[$id]);
        $restore = $action === 'restore';
        if ((!in_array($action,['delete','check_delete'],true) && $active === $restore) ||
            $body['revision'] !== 0) workspace_directory_conflict();
        $name = workspace_employee_role_names()[$role['code']] ?? $role['name'];
        if (!$restore) {
            workspace_assert_role_unused(['id'=>$id,'code'=>$role['code'],'name'=>$name]);
        } elseif (in_array(vitrine_status_key($name),array_map('vitrine_status_key',array_column(workspace_employee_roles(),'name')),true)) {
            throw new ApiError('duplicate_role','Такая роль уже существует.',409);
        }
        if ($action === 'check_delete') {
            return ['name'=>$name];
        }
        if ($restore) unset($archived[$id]);
        else $archived[$id] = true;
        wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
            ['directory_archived_roles',wjson((object)$archived)]);
        $before = ['id'=>$id,'name'=>$name,'active'=>$active];
        $after = ['id'=>$id,'name'=>$name,'active'=>$restore];
        if ($action==='delete') {
            workspace_mark_row_deleted('directory_deleted_role_',$id);
            $after+=['deleted'=>true,'reason'=>$body['reason']];
        }
    }
    workspace_audit(['category'=>'management','label'=>'Роли','before'=>$before,'after'=>$after,
        'action'=>match($action) {'add'=>'create','restore'=>'restore','delete'=>'delete',default=>'archive'}]);
    workspace_event('roles.changed',['id'=>$id]);
    return ['id'=>$id,'revision'=>0];
}
