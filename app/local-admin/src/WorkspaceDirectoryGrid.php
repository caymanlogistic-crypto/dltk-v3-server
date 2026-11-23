<?php
declare(strict_types=1);

function vitrine_directory_requested(): ?string {
    if (!isset($_GET['catalog'])) return null;
    if (!in_array($_GET['catalog'],['clients','employees','roles','client-order-statuses','flight-statuses','entries'],true)) {
        throw new ApiError('not_found','Страница справочника не найдена.',404);
    }
    return $_GET['catalog'];
}

function workspace_directory_row_order_key(int|string $catalogId): string {
    return 'directory_excel_order_'.($catalogId==='client-order-statuses' || !$catalogId ? 'client_order_statuses' : $catalogId);
}

function workspace_directory_row_order(int|string $catalogId): array {
    return workspace_client_catalog_metadata(workspace_directory_row_order_key($catalogId));
}

function workspace_directory_order_entries(array $entries,int|string $catalogId): array {
    $order=workspace_directory_row_order($catalogId);
    if (!$order) return $entries;
    $position=array_flip($order);
    uasort($entries,static fn(array $left,array $right): int =>
        ($position[$left['id']]??PHP_INT_MAX)<=>($position[$right['id']]??PHP_INT_MAX));
    return $entries;
}

function workspace_directory_grid_model(string $directory): array {
    if ($directory === 'entries') return workspace_catalog_directory_model();
    if ($directory === 'clients') return workspace_client_grid_model();
    if ($directory === 'flight-statuses') {
        [$table] = workspace_flight_status_grid();
        $key = $directory.'-'.$table['slug'];
        return ['id'=>0,'directoryKey'=>$key,'slug'=>'catalog-'.$key,'sourceTable'=>$table['slug'],'title'=>'Статусы рейса',
            'columns'=>[['letter'=>'A','label'=>'Статус рейса'],['letter'=>'B','label'=>'Цвет']]];
    }
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
        'title'=>$employees ? 'Учётные записи' : 'Роли', 'columns'=>$columns,
    ];
}

function workspace_directory_grid_data(string $directory, ?array $requestedRows = null): array {
    if ($directory === 'entries') return workspace_catalog_directory_data($requestedRows);
    if ($directory === 'clients') return workspace_client_grid_data($requestedRows);
    $model = workspace_directory_grid_model($directory);
    $employees = $directory === 'employees';
    $archive = ($_GET['archive'] ?? null) === '1';
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']),0,160) : '';
    $flightStatuses = $directory === 'flight-statuses';
    $orderStatuses = $directory === 'client-order-statuses' || $flightStatuses;
    $canEditStatuses = false;
    $entries = $employees ? workspace_employee_records(active: !$archive) : ($orderStatuses ? [] : workspace_employee_roles($archive));
    $palette = workspace_palette();
    if ($orderStatuses) {
        $colorOptions = workspace_directory_palette_options();
        $colorNames = array_column($palette,'name','fill');
        $entries = [];
        if ($flightStatuses) {
            [$table,$catalog] = workspace_flight_status_grid();
            $canEditStatuses = $catalog['id'] && workspace_can('catalog',(string)$catalog['id'],'edit',$table['id']);
            $flightEntries = $archive && $catalog['id']
                ? wquery("SELECT id,name,color,revision FROM catalog_entries e WHERE catalog_id=? AND active=FALSE
                    AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id)) ORDER BY id",[$catalog['id']])->fetchAll()
                : ($archive ? [] : $catalog['statuses']);
            foreach ($flightEntries as $status) {
                $status['id'] = (int)$status['id'];
                $status['baseHash'] = workspace_flight_status_hash($catalog['id'],$status['id']);
                $entries[] = $status;
            }
        } else {
            $canEditStatuses = workspace_can('system','client-order-statuses','edit');
            foreach (workspace_client_order_statuses() as $code=>$status) {
                if (!empty($status['deleted']) || $status['active'] === $archive) continue;
                $entries[] = ['id'=>$status['id'],'name'=>$status['label'],'color'=>$status['color'],
                    'revision'=>$status['revision'],'baseHash'=>hash('sha256','client-order-status:'.$code)];
            }
        }
    }
    if (!$archive) {
        $entries=workspace_directory_order_entries($entries,$flightStatuses ? (int)$catalog['id'] : $directory);
    }
    $scope = $employees ? workspace_employee_editor_scope() : null;
    $roleChoices = $scope === null ? [] : array_column(workspace_employee_role_options($scope),'name');
    $canEdit = $employees ? $scope !== null : ($orderStatuses ? $canEditStatuses : workspace_directory_roles_editable());
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
            'rowActions'=>$canEdit && (!$employees || workspace_employee_can_edit($scope,$entry)),
        ];
        if ($employees) {
            $row['baseHash'] = workspace_employee_revision($entry);
            $row['singleLine'] = ['A'=>true,'B'=>true,'D'=>true];
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
            $row['baseHash'] = $entry['baseHash'];
            $row['singleLine']['A'] = true;
            $row['edits'] = array_fill_keys(['A','B'],['revision'=>(int)$entry['revision']]);
            $row['choices']['B'] = $colorOptions;
            $row['requiredChoices']['B'] = true;
        }
        if ($directory === 'roles') $row['readOnly']['A'] = 'Название роли записывается при создании.';
        $rows[] = $row;
    }
    return [
        'table'=>$model, 'columns'=>$model['columns'], 'rows'=>$rows, 'rowIds'=>$rowIds,
        'palette'=>$palette, 'catalogGrid'=>['archive'=>$archive,'search'=>$search,'canEdit'=>$canEdit,'draftFields'=>workspace_directory_draft_fields($directory,$roleChoices)],
    ];
}


function workspace_directory_palette_options(): array {
    return array_map(static fn(array $color): array=>['value'=>$color['name'],'label'=>$color['name'],'color'=>$color['fill']],workspace_palette());
}

function workspace_directory_draft_fields(string $directory, array $roles = [], string $catalogKind = ''): array {
    $name = ['required'=>true,'type'=>'text'];
    $color = ['required'=>true,'type'=>'choice','choices'=>workspace_directory_palette_options()];
    $optionalAssignment = ['type'=>'text','required'=>true,'allowEmpty'=>true];
    return match ($directory) {
        'flight-statuses','client-order-statuses'=>['A'=>$name,'B'=>$color],
        'employees'=>['A'=>$name,'B'=>$name,'C'=>['required'=>true,'type'=>'password'],'D'=>['type'=>'email'],
            'E'=>['required'=>true,'type'=>'choice','choices'=>$roles]],
        'clients'=>['A'=>$name,'B'=>$optionalAssignment],
        'entries'=>match (true) {
            workspace_catalog_has_palette($catalogKind)=>['A'=>$name,'B'=>$color],
            $catalogKind==='dropoff-points'=>['A'=>$name,'B'=>['type'=>'text','singleLine'=>false,'maxLength'=>1500],
                'C'=>['type'=>'text','singleLine'=>false,'maxLength'=>1500],'D'=>['type'=>'text','singleLine'=>false,'maxLength'=>6000]],
            $catalogKind==='edo-operators'=>['A'=>$name,'B'=>$name],
            $catalogKind==='carriers'=>['A'=>$name,'B'=>$optionalAssignment,'C'=>$optionalAssignment,'E'=>$optionalAssignment],
            $catalogKind==='clients'=>['A'=>$name,'B'=>$optionalAssignment],
            default=>['A'=>$name],
        },
        default=>['A'=>$name],
    };
}
