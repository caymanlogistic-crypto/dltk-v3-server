<?php
declare(strict_types=1);

function workspace_employee_grid_readonly(string $column, ?string $scope, array $employee): ?string {
    if ($scope === null || !workspace_employee_can_edit($scope,$employee)) return 'Запись доступна только для просмотра.';
    if ($column === 'F') return 'Статус меняется через меню строки.';
    if ($column === 'C') return 'Пароль меняется в карточке сотрудника.';
    if (!$employee['active']) return 'Сотрудник в архиве. Для изменения откройте карточку.';
    if ($column === 'E' && $employee['id'] === workspace_current_user()['id']) return 'Свою роль изменить нельзя.';
    return null;
}

function workspace_employee_grid_values(array $employee): array {
    return [
        'A'=>$employee['name'], 'B'=>$employee['login'], 'C'=>'Скрыт', 'D'=>$employee['email'],
        'E'=>implode(', ',array_column($employee['roles'],'name')),
    ];
}

function workspace_employee_grid_endpoint(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    $scope = workspace_employee_editor_scope();
    if ($scope === null) throw new ApiError('forbidden','Редактирование доступно администратору.',403);
    $body = wbody();
    if (!is_int($body['row'] ?? null) || $body['row']<1 || !in_array($body['column'] ?? null,['A','B','C','D','E'],true)
        || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit','Некорректные данные ячейки сотрудника.');
    }
    $employee = workspace_employee_records($body['row'])[0] ?? null;
    if (!$employee) throw new ApiError('not_found','Сотрудник не найден.',404);
    $column = $body['column'];
    $readOnly = workspace_employee_grid_readonly($column,$scope,$employee);
    if ($readOnly !== null) throw new ApiError('readonly',$readOnly,403);
    $value = $body['value'];
    $changes = [];
    if ($column === 'E') {
        if ($value !== workspace_employee_grid_values($employee)['E']) {
            $roles = array_column(workspace_employee_role_options($scope),'id','name');
            if (!isset($roles[$value])) throw new ApiError('invalid_role','Выберите роль из справочника.');
            $changes['roleIds'] = [$roles[$value]];
        }
    } else {
        $field = ['A'=>'name','B'=>'login','D'=>'email'][$column];
        $changes[$field] = workspace_employee_profile_value($field,$value);
    }
    if ($pasteCheck) json_response(['accepted'=>true]);

    $baseHash = $body['baseHash'] ?? null;
    if (!is_string($baseHash) || !preg_match('/^[0-9a-f]{64}$/D',$baseHash)) {
        throw new ApiError('invalid_edit','Обновите страницу и повторите сохранение.');
    }
    $saved = workspace_employee_save(['id'=>$employee['id'],'revision'=>$baseHash]+$changes,cellEdit:true);
    $value = workspace_employee_grid_values($saved)[$column];
    json_response([
        'value'=>$value, 'original'=>$value, 'edited'=>false,
        'revision'=>$saved['authVersion'], 'baseHash'=>workspace_employee_revision($saved),
        'reauthenticate'=>$column === 'B' && $saved['id'] === workspace_current_user()['id'] && $saved['login'] !== $employee['login'],
    ]);
}
