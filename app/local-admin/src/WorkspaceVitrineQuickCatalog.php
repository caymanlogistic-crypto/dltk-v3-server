<?php
declare(strict_types=1);

function vitrine_quick_catalog_role(): bool {
    static $allowed = null;
    if ($allowed !== null) return $allowed;
    $codes = wquery('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?',
        [workspace_current_user()['id']])->fetchAll(PDO::FETCH_COLUMN);
    $eligible = array_intersect($codes,['directory_senior_manager','directory_senior_logist','admin','superadmin']);
    return $allowed = $eligible && (bool)array_intersect($eligible,array_column(workspace_employee_roles(),'code'));
}

function vitrine_quick_catalogs(array $table): array {
    if ($table['slug']!=='vyvoz' || !vitrine_quick_catalog_role()
        || !workspace_can('table',(string)$table['id'],'edit',$table['id'])) return [];
    $links = vitrine_catalog_links($table);
    $status = workspace_status_catalog($table);
    if ($status['id']) $links['A'] = ['id'=>$status['id'],'kind'=>'status'];
    $result = [];
    foreach ($links as $field=>$link) {
        $id = (string)$link['id'];
        if (!workspace_can('field',$field,'view',$table['id']) || !workspace_can('field',$field,'edit',$table['id'])
            || !workspace_can('catalog',$id,'view',$table['id']) || !workspace_can('catalog',$id,'edit',$table['id'])) continue;
        $directory = match ($link['kind']) {'status'=>'flight-statuses','clients'=>'clients',default=>'entries'};
        $result[$field] = ['directory'=>$directory,'id'=>$link['id']];
    }
    if (workspace_can('field','B','view',$table['id']) && workspace_can('field','B','edit',$table['id'])
        && workspace_client_order_status_can_view($table) && workspace_can('system','client-order-statuses','edit',$table['id'])) {
        $result['orderStatus'] = ['directory'=>'client-order-statuses','id'=>0];
    }
    foreach (workspace_trip_field_status_directories() as $column=>$details) {
        if (workspace_trip_field_status_can($table,$column,'view') && workspace_trip_field_status_can($table,$column,'edit')) {
            $result['fieldColor'.$column]=['directory'=>$details['directory'],'id'=>0];
        }
    }
    return $result;
}

function vitrine_quick_catalog_require(mixed $key): array {
    $catalog = is_string($key) ? (vitrine_quick_catalogs(workspace_table())[$key]??null) : null;
    if (!$catalog) throw new ApiError('forbidden','Быстрое редактирование недоступно.',403);
    return $catalog;
}

function vitrine_quick_catalog_validate_directory(mixed $key,?string $directory): array {
    $catalog = vitrine_quick_catalog_require($key);
    if ($directory!==$catalog['directory'] || ($directory==='entries' && (string)$catalog['id']!==($_GET['id']??null))) {
        throw new ApiError('forbidden','Этот справочник не связан с ячейкой.',403);
    }
    return $catalog;
}

function vitrine_quick_catalog_reorder(): never {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $directory = vitrine_directory_requested();
    $employeeOrder = $directory==='employees' && $_GET['quickOrder']==='employees';
    $catalog = $employeeOrder ? ['directory'=>'employees','id'=>0]
        : vitrine_quick_catalog_validate_directory($_GET['quickOrder'],$directory);
    $move = wbody();
    $source = $move['source']??null;
    $target = $move['target']??null;
    $after = $move['after']??null;
    if (!is_int($source) || !is_int($target) || $source===$target || !is_bool($after)) {
        throw new ApiError('invalid_order','Выберите две разные строки справочника.');
    }
    if (in_array($_GET['archive']??null,['1','all'],true)) {
        throw new ApiError('invalid_order','Перенос доступен для активных записей.');
    }
    if (!$employeeOrder) unset($_GET['q']);
    workspace_begin();
    try {
        if ($employeeOrder && !workspace_employee_can_reorder(workspace_employee_credential_scope())) {
            throw new ApiError('forbidden','Изменение порядка учётных записей недоступно.',403);
        }
        $visible = workspace_directory_grid_data($catalog['directory'],[])['rowIds'];
        if (!in_array($source,$visible,true) || !in_array($target,$visible,true)) {
            throw new ApiError('not_found','Строка справочника больше не доступна.',404);
        }
        $next = array_values(array_filter($visible,static fn(int $row): bool=>$row!==$source));
        $position = array_search($target,$next,true)+(int)$after;
        array_splice($next,$position,0,[$source]);
        $changed = $next!==$visible;
        if ($changed) {
            if ($employeeOrder || isset($_GET['client']) || isset($_GET['partyRole'])) {
                if ($employeeOrder) {
                    $all = array_column(workspace_directory_order_entries(workspace_employee_records(active:true),'employees'),'id');
                } else {
                    unset($_GET['client'],$_GET['partyRole']);
                    $all = workspace_directory_grid_data($catalog['directory'],[])['rowIds'];
                }
                $scoped = array_fill_keys($visible,true);
                $index = 0;
                foreach ($all as &$row) {
                    if (isset($scoped[$row])) $row = $next[$index++];
                }
                unset($row);
                $next = $all;
            }
            $key = workspace_directory_row_order_key($catalog['id'] ?: $catalog['directory']);
            wquery('INSERT INTO workspace_meta(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                [$key,wjson($next)]);
            $previousPosition = array_search($source,$visible,true)+1;
            if ($employeeOrder) {
                workspace_audit(['label'=>'Сотрудник','before'=>['id'=>$source,'position'=>$previousPosition],
                    'after'=>['id'=>$source,'position'=>$position+1],'row'=>$source,'action'=>'reorder','category'=>'management']);
            } else {
                workspace_audit(['label'=>'Порядок строк справочника','before'=>$previousPosition,
                    'after'=>$position+1,'row'=>$source,'action'=>'reorder','category'=>'catalog-order']);
            }
        }
        workspace_db()->commit();
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
    json_response(['saved'=>true,'changed'=>$changed]);
}

function vitrine_quick_catalog_options(): never {
    $key = $_GET['quickOptions'];
    $catalog = vitrine_quick_catalog_require($key);
    if (workspace_order_status_directory($catalog['directory'])!==null) {
        json_response(['orderStatuses'=>workspace_active_client_order_statuses(false,$catalog['directory'])]);
    }
    $number = filter_var($_GET['ids']??null,FILTER_VALIDATE_INT);
    if ($number===false || $number===0) throw new ApiError('invalid_rows','Выберите один рейс.');
    $data = vitrine_data([$number]);
    $row = $data['rows'][0]??null;
    if (!$row) throw new ApiError('not_found','Строка не найдена.',404);
    if ($key==='A') json_response(['statuses'=>$data['statuses']]);
    $shared = $row['sharedChoices'][$key]??null;
    $related = array_filter($row['catalogBindings'],static fn(int $id): bool=>$id===$catalog['id']);
    $unloadOptions = $key==='K' ? $data['unloadOptions'] : null;
    $unloadClientId=$key==='K' && workspace_can('field','B','view',$data['table']['id'])
        ? (int)($row['references']['B']['id']??$data['unloadClients'][vitrine_catalog_key($row['values']['B']??'')]??0) : 0;
    $unloadClients = $unloadOptions !== null && isset($data['sharedOptionCatalogs']['B'])
        ? array_filter(
            $data['unloadClients'],
            static fn(int $id,string|int $name): bool=>isset($unloadOptions['id:'.$id]) || isset($unloadOptions['name:'.$name]),
            ARRAY_FILTER_USE_BOTH
        ) : null;
    json_response([
        'choices'=>$shared===null ? ($row['choices'][$key]??[]) : ($data['sharedOptions'][$shared]??[]),
        'relatedChoices'=>array_intersect_key($data['sharedOptions'],$related),
        'dropoffOptions'=>$key==='M' ? $data['dropoffOptions'] : null,
        'unloadOptions'=>$unloadOptions,
        'unloadClientId'=>$unloadClientId,
        'containerTypeRules'=>$key==='I' ? $data['containerTypeRules'] : null,
        'unloadClients'=>$unloadClients,
    ]);
}
