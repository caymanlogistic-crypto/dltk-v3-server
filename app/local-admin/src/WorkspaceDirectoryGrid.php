<?php
declare(strict_types=1);

function vitrine_directory_requested(): ?string {
    if (!isset($_GET['catalog'])) return null;
    if (!in_array($_GET['catalog'],['clients','employees','roles','client-order-statuses'],true)) {
        throw new ApiError('not_found','Страница справочника не найдена.',404);
    }
    return $_GET['catalog'];
}

function workspace_directory_grid_model(string $directory): array {
    if ($directory === 'clients') return workspace_client_grid_model();
    if ($directory === 'client-order-statuses') {
        workspace_require('system','client-order-statuses','view');
        return ['id'=>0,'directoryKey'=>$directory,'slug'=>'catalog-'.$directory,'title'=>'Статусы клиентов',
            'columns'=>[['letter'=>'A','label'=>'Статус заказа'],['letter'=>'B','label'=>'Цвет']]];
    }
    $employees = $directory === 'employees';
    workspace_require('system',$employees ? 'users' : 'roles','view');
    $labels = $employees ? ['A'=>'ФИО','B'=>'Логин','C'=>'Пароль','D'=>'Email','E'=>'Роль'] : ['A'=>'Роль'];
    $columns = [];
    foreach ($labels as $letter => $label) $columns[] = ['letter'=>$letter,'label'=>$label];
    return [
        'id'=>0, 'directoryKey'=>$directory, 'slug'=>'catalog-'.$directory,
        'title'=>$employees ? 'Сотрудники' : 'Роли', 'columns'=>$columns,
    ];
}

function workspace_directory_grid_data(string $directory, ?array $requestedRows = null): array {
    if ($directory === 'clients') return workspace_client_grid_data($requestedRows);
    $model = workspace_directory_grid_model($directory);
    $employees = $directory === 'employees';
    $archive = $employees && ($_GET['archive'] ?? null) === '1';
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,160) : '';
    $orderStatuses = $directory === 'client-order-statuses';
    $entries = $employees ? workspace_employee_records(active: !$archive) : workspace_employee_roles();
    $palette = workspace_palette();
    if ($orderStatuses) {
        $colorOptions = array_map(static fn(array $color): array=>['value'=>$color['name'],'label'=>$color['name'],'color'=>$color['fill']],$palette);
        $colorNames = array_column($palette,'name','fill');
        $entries = [];
        foreach (workspace_client_order_statuses() as $code=>$status) {
            $entries[] = ['id'=>$status['id'],'name'=>$status['label'],'orderStatus'=>$code,'color'=>$status['color'],'revision'=>$status['revision']];
        }
    }
    $scope = $employees ? workspace_employee_editor_scope() : null;
    $roleChoices = $scope === null ? [] : array_column(workspace_employee_role_options($scope),'name');
    $rowIds = [];
    $rows = [];
    $requested = $requestedRows !== null ? array_fill_keys($requestedRows,true) : null;
    foreach ($entries as $entry) {
        $values = $employees ? workspace_employee_grid_values($entry) : ['A'=>$entry['name']];
        if ($orderStatuses) $values['B']=$colorNames[$entry['color']] ?? '';
        if ($search !== '' && mb_stripos(implode(' ',array_diff_key($values,['C'=>true])),$search) === false) continue;
        $id = $entry['id'];
        $rowIds[] = $id;
        if ($requested === null ? count($rows)>=100 : !isset($requested[$id])) continue;
        $row = [
            'rowIndex'=>$id, 'rowLabel'=>count($rowIds), 'values'=>$values,
            'kinds'=>array_fill_keys(array_keys($values),'text'),
        ];
        if ($employees) {
            $row['baseHash'] = workspace_employee_revision($entry);
            foreach (array_keys($values) as $column) {
                $row['readOnly'][$column] = workspace_employee_grid_readonly($column,$scope,$entry);
                $row['edits'][$column] = ['revision'=>$entry['authVersion']];
            }
            if ($row['readOnly']['E'] === null) {
                $row['choices']['E'] = $roleChoices;
                $row['requiredChoices']['E'] = true;
            }
        }
        if ($orderStatuses) {
            $row['orderFill'] = $entry['color'];
            $row['baseHash'] = hash('sha256','client-order-status:'.$entry['orderStatus']);
            $row['edits'] = array_fill_keys(['A','B'],['revision'=>$entry['revision']]);
            $row['choices']['B'] = $colorOptions;
            $row['requiredChoices']['B'] = true;
        }
        $rows[] = $row;
    }
    return [
        'table'=>$model, 'columns'=>$model['columns'], 'rows'=>$rows, 'rowIds'=>$rowIds,
        'palette'=>$palette, 'catalogGrid'=>['archive'=>$archive,'search'=>$search,'canEdit'=>$scope !== null || ($orderStatuses && workspace_can('system','client-order-statuses','edit'))],
    ];
}
