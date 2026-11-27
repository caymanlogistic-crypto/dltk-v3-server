<?php
declare(strict_types=1);

require_once __DIR__.'/Format.php';
require_once __DIR__.'/VitrineClipboard.php';

require_once __DIR__.'/WorkspaceVitrine.php';
require_once __DIR__.'/VitrineRange.php';
require_once __DIR__.'/WorkspaceVitrineQuickCatalog.php';
require_once __DIR__.'/WorkspaceDirectoryExcel.php';
require_once __DIR__.'/WorkspaceClientCatalogGrid.php';

function vitrine_status_key(string $name): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$name))); }

function vitrine_statuses_endpoint(): never {
    $key = 'workspace';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_response(vitrine_store(['action'=>'statuses_list','source'=>$key]));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    $body = json_decode((string)file_get_contents('php://input',false,null,0,4096),true);
    if (!is_array($body) || !array_key_exists('id',$body) || ($body['id'] !== null && (!is_int($body['id']) || $body['id']<1)) || !is_int($body['revision']??null) || $body['revision']<0 || !is_string($body['name']??null) || mb_strlen($body['name'])>100 || trim($body['name'])==='' || preg_match('/[\x00-\x1F\x7F]/u',$body['name']) || !is_string($body['color']??null)) throw new ApiError('invalid_status','Укажите название до 100 символов и цвет из палитры.');
    json_response(vitrine_store(['action'=>'status_save','source'=>$key,'id'=>$body['id'],'name'=>$body['name'],'color'=>$body['color'],'revision'=>$body['revision']]));
}

function vitrine_edit_row_ids(array $body): array {
    $ids = [$body['row']];
    $sourceRow = $body['paste']['source']['row'] ?? null;
    if (is_int($sourceRow)) $ids[] = $sourceRow;
    return array_values(array_unique($ids));
}

function vitrine_rows_endpoint(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new ApiError('method','Доступен только просмотр.',405);
    if (isset($_GET['quickOptions'])) vitrine_quick_catalog_options();
    $value = $_GET['ids'] ?? '';
    if (!is_string($value) || !preg_match('/^\d+(?:,\d+){0,99}$/D',$value)) throw new ApiError('invalid_rows','Запрашивайте от 1 до 100 строк.');
    $ids = array_map('intval',explode(',',$value));
    $directory = vitrine_directory_requested();
    if (($_GET['tripDriver']??'')==='1') {
        if ($directory!==null || count($ids)!==1) throw new ApiError('invalid_rows','Выберите один рейс.');
        header('Cache-Control: no-store');
        json_response(vitrine_trip_driver_card($ids[0],($_GET['archive']??null)==='1'));
    }
    if (($_GET['fleetDocuments']??'')==='1') {
        if ($directory!==null || count($ids)!==1) throw new ApiError('invalid_rows','Выберите один рейс.');
        $table=workspace_table();
        if (!isset(vitrine_catalog_links($table)['O']['documentsTarget']) || !workspace_fleet_trip_documents_can_view($table['id'])) {
            throw new ApiError('forbidden','Нет доступа к ПД рейса.',403);
        }
        header('Cache-Control: no-store');
        $value=workspace_fleet_trip_documents_for_row($table['id'],$ids[0],($_GET['archive']??null)==='1');
        if ($value===null) throw new ApiError('not_found','Рейс не найден.',404);
        json_response(['value'=>$value]);
    }
    $data = $directory ? workspace_directory_grid_data($directory,$ids) : vitrine_data($ids,($_GET['archive']??null)==='1');
    $catalogGrid = $data['catalogGrid'] ?? null;
    $columns=$data['columns']; $rows=$data['rows'];
    $escape=static fn(mixed $value): string => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $columnClass=static fn(array $column): string => mb_strtolower(trim($column['label']))==='время'?' vitrine-time-column':'';
    ob_start(); require __DIR__.'/../views/vitrine-rows.php'; $html=ob_get_clean();
    json_response(['html'=>$html,'rowIds'=>array_column($rows,'rowIndex')]);
}

function vitrine_validate_single_line(string $value, array $row, string $column): void {
    if (!empty($row['singleLine'][$column]) && preg_match('/\R/u',$value)) {
        throw new ApiError('invalid_single_line','Введите текст одной строкой, без переносов.');
    }
}

function vitrine_require_compact_integer_range(string $value,string $kind): void {
    $bounds=vitrine_compact_integer_bounds($kind);
    if ($bounds===null || vitrine_compact_integer_in_range($value,$bounds)) return;
    $message=match ($kind) {
        'weight'=>'Введите вес от 1 000 до 50 000.',
        'distance'=>'Введите пробег от 1 до 10 000 км.',
        'money'=>'Введите сумму от 1 000 до 9 999 999.',
        'cargo-value'=>'Введите стоимость груза от 1 000 до 99 999 999.',
        'cargo-places'=>'Введите количество мест от 1 до 10 000.',
    };
    throw new ApiError($kind.'_out_of_range',$message);
}

function vitrine_require_slot_date_limit(string $normalized): void {
    if (!vitrine_slot_date_in_window($normalized)) {
        throw new ApiError('slot_date_out_of_range','Укажите дату слота от сегодняшней до 30 календарных дней вперёд включительно.');
    }
}

function vitrine_require_trip_date_limit(string $value): void {
    $normalized = vitrine_compact_input($value,'date');
    if ($normalized === null) {
        throw new ApiError('invalid_date','Введите действительную дату в формате ДДММГГ.');
    }
    if (!vitrine_date_in_window($normalized,10,60)) {
        throw new ApiError('trip_date_out_of_range','Дата рейса может быть не раньше чем 10 дней назад и не позже чем через 60 дней от сегодня.');
    }
}

/** Returns server-verified data that travels with a copied cell. */
function vitrine_validate_paste(array $body, array $data, array $target, ?array $sourceRows=null): array {
    vitrine_require_manual_field($data['table'],$body['column']);
    if ($body['column']==='M' && !empty($target['catalogBindings']['M'])) {
        throw new ApiError('invalid_dropoff','Откройте поле «Сдача» и выберите точку из справочника.');
    }
    $paste = $body['paste'] ?? null;
    if (!is_array($paste) || !array_key_exists('source',$paste)) throw new ApiError('invalid_clipboard','Не удалось определить содержимое буфера обмена.');
    vitrine_validate_single_line($body['value'],$target,$body['column']);
    $kind = $target['kinds'][$body['column']] ?? 'text';
    $compact = isset($target['compactInputs'][$body['column']]);
    if (!$compact && $kind === 'time') $body['value'] = vitrine_time_input($body['value']) ?? $body['value'];
    $labels = ['status'=>'Статус','text'=>'Текст','number'=>'Число','date'=>'Дата','datetime'=>'Дата и время','time'=>'Время','slot'=>'Слот','container'=>'Номер КТК','weight'=>'Вес','distance'=>'КМ','money'=>'Сумма','cargo-value'=>'Стоимость груза','cargo-places'=>'Места','boolean'=>'TRUE/FALSE'];
    $sourceRow = null;
    $source = $paste['source'];
    if ($source !== null) {
        if (!is_array($source) || !is_int($source['row']??null) || !is_string($source['column']??null) || !is_int($source['revision']??null) || !is_string($source['baseHash']??null) || !is_string($source['value']??null)) throw new ApiError('invalid_clipboard','Некорректный формат скопированной ячейки.');
        foreach ($sourceRows??$data['rows'] as $row) if ($row['rowIndex']===$source['row']) $sourceRow=$row;
        if ($sourceRow===null || !isset($sourceRow['kinds'][$source['column']])) throw new ApiError('clipboard_source_missing','Скопированная ячейка больше не входит в текущую выборку. Скопируйте значение заново.',409);
        $sourceKind = $sourceRow['kinds'][$source['column']];
        if ($sourceKind!==$kind) throw new ApiError('clipboard_type_mismatch','Форматы ячеек не совпадают: '.$labels[$sourceKind].' → '.$labels[$kind].'. Вставка отменена.');
    }
    $column=$body['column'];
    $value=$body['value'];
    if ($value!=='' && $data['table']['slug']==='vyvoz' && isset($target['catalogBindings'][$column]) && in_array($column,['E','K'],true)) {
        $verified=$sourceRow!==null && $source['column']===$column && $source['value']===$value &&
            ($sourceRow['values'][$column]??null)===$value && ($sourceRow['edits'][$column]['revision']??0)===$source['revision'] &&
            hash_equals($sourceRow['baseHash'],$source['baseHash']);
        if ($column==='K' && $verified && workspace_can('field','L','view',$data['table']['id'])) {
            return ['copiedUnload'=>['client'=>$sourceRow['values']['B']??'','clientId'=>$sourceRow['references']['B']['id']??0,
                'pointId'=>$sourceRow['references']['K']['id']??0,'contact'=>$sourceRow['values']['L']??'']];
        }
        if ($column==='E') {
            if (in_array($value,$target['choices']['E']??[],true)) return [];
            if ($verified) return ['legacyTerminalPaste'=>true];
            throw new ApiError('invalid_reference','Выберите терминал постановки из справочника.');
        }
    }
    if ($compact && workspace_trip_supports_formula($data['table']['slug'],$column) && str_starts_with(ltrim($value),'=')) {
        $value=WorkspaceTripFormula::evaluate($value);
        vitrine_require_compact_integer_range($value,$kind);
    }
    $normalized = $compact ? vitrine_compact_input($value,$kind) : null;
    if ($normalized !== null) vitrine_require_compact_integer_range($normalized,$kind);
    $accepted = $compact ? $normalized !== null
        : vitrine_clipboard_accepts($body['value'],$kind,$target['choices'][$body['column']]??[]);
    if (!$accepted) throw new ApiError('clipboard_value_mismatch','Значение не подходит для формата «'.$labels[$kind].'» или заданного списка. Вставка отменена.');
    if ($kind === 'slot') vitrine_require_slot_date_limit(vitrine_slot_input($body['value']));
    if ($compact && $kind === 'date' && $data['table']['slug']==='vyvoz' && $body['column']==='F') {
        if ($paste['source']===null && !preg_match('/^[0-9]{6}$/D',$body['value'])) {
            throw new ApiError('invalid_date','Вставьте дату рейса шестью цифрами в формате ДДММГГ.');
        }
        vitrine_require_trip_date_limit($normalized);
    }
    return [];
}

function vitrine_copy_diagnostics_enabled(): bool {
    return in_array(parse_url(runtime_config()['APP_URL'],PHP_URL_HOST),['localhost','127.0.0.1','[::1]'],true);
}

function vitrine_copy_diagnostics(array $body): never {
    if (!vitrine_copy_diagnostics_enabled() || workspace_table()['slug']!=='vyvoz') {
        throw new ApiError('not_found','Страница не найдена.',404);
    }
    $ids=$body['rows']??null;
    $columns=$body['columns']??null;
    if (!is_array($ids) || !$ids || count($ids)>100 ||
        array_filter($ids,static fn($id)=>!is_int($id) || $id<1) ||
        !is_array($columns) || !$columns || count($columns)>100 ||
        array_filter($columns,static fn($column)=>!is_string($column) || !preg_match('/^(?:[A-Z]{1,3}|U2)$/D',$column))) {
        throw new ApiError('invalid_clipboard','Некорректный диапазон.');
    }
    header('Cache-Control: no-store');
    $data=vitrine_data($ids,($_GET['archive']??null)==='1');
    $available=array_column($data['columns'],null,'letter');
    $invalid=[];
    foreach ($data['rows'] as $row) {
        foreach (array_unique($columns) as $column) {
            $value=(string)($row['values'][$column]??'');
            if ($value==='' || !isset($available[$column],$row['kinds'][$column]) ||
                isset($available[$column]['derivedSource']) || isset($row['derivedSources'][$column]) ||
                ($column==='M' && isset($row['catalogBindings']['M']))) continue;
            $source=['row'=>$row['rowIndex'],'column'=>$column,'value'=>$value,
                'revision'=>$row['edits'][$column]['revision']??0,'baseHash'=>$row['baseHash']];
            try {
                vitrine_validate_paste($source+['paste'=>['source'=>$source]],$data,$row,[$row]);
            } catch (ApiError $error) {
                if ($error->httpStatus===400 || $error->httpStatus===422) $invalid[]=['row'=>$row['rowIndex'],'column'=>$column];
            }
        }
    }
    json_response(['invalid'=>$invalid]);
}

function vitrine_paste_check(): never {
    $directory = vitrine_directory_requested();
    if ($directory === 'entries') workspace_catalog_directory_cell(true);
    if ($directory === 'clients') workspace_client_grid_endpoint(true);
    if ($directory === 'employees') workspace_employee_grid_endpoint(true);
    if ($directory !== null && workspace_order_status_directory($directory)!==null) workspace_client_order_status_grid_endpoint(true,$directory);
    if ($directory === 'flight-statuses') workspace_flight_status_grid_endpoint(true);
    if ($directory !== null) throw new ApiError('readonly','Запись доступна только для просмотра.',403);
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $body=json_decode((string)file_get_contents('php://input',false,null,0,100000),true);
    if (($body['action']??null)==='copyDiagnostics') vitrine_copy_diagnostics($body);
    if (!is_array($body) || !is_int($body['row']??null) || !is_string($body['column']??null) || !is_string($body['value']??null)) throw new ApiError('invalid_clipboard','Некорректные данные для вставки.');
    $data=vitrine_data(vitrine_edit_row_ids($body)); $target=null;
    foreach ($data['rows'] as $row) if ($row['rowIndex']===$body['row']) $target=$row;
    if (!$target || !isset($target['kinds'][$body['column']])) throw new ApiError('clipboard_target_missing','Ячейка не найдена.',404);
    vitrine_validate_paste($body,$data,$target);
    json_response(['accepted'=>true]);
}

function vitrine_save_cell(): never {
    if (isset($_GET['quickOrder'])) vitrine_quick_catalog_reorder();
    if (isset($_GET['tripOrder'])) {
        require_once __DIR__.'/WorkspaceTripRowOrder.php';
        workspace_trip_row_reorder();
    }
    $directory = vitrine_directory_requested();
    if ($directory !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $directoryBody = wbody();
        if (in_array($directoryBody['action']??null,['pasteRange','undoRange'],true)) {
            json_response(vitrine_save_range($directory,$directoryBody));
        }
        if (isset($directoryBody['action'])) workspace_directory_rows_endpoint($directory,$directoryBody);
    }
    if ($directory === 'entries') workspace_catalog_directory_cell();
    if ($directory === 'clients') workspace_client_grid_endpoint();
    if ($directory === 'employees') workspace_employee_grid_endpoint();
    if ($directory !== null && workspace_order_status_directory($directory)!==null) workspace_client_order_status_grid_endpoint(false,$directory);
    if ($directory === 'flight-statuses') workspace_flight_status_grid_endpoint();
    if ($directory !== null) throw new ApiError('readonly','Запись доступна только для просмотра.',403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    $raw = file_get_contents('php://input', false, null, 0, 100000);
    $body = json_decode((string)$raw, true);
    if (in_array($body['action']??null,['pasteRange','undoRange'],true)) json_response(vitrine_save_range(null,$body));
    if (is_array($body) && isset($body['action'])) json_response(workspace_table_row_action($body));
    json_response(vitrine_table_cell($body));
}

function vitrine_table_cell(mixed $body, ?array $data=null, ?array $pasteSourceRows=null): array {
    if (!is_array($body) || !is_int($body['row'] ?? null) || !is_string($body['column'] ?? null) || !is_string($body['value'] ?? null) || mb_strlen($body['value'])>20000 || !is_int($body['revision'] ?? null) || $body['revision']<0 || !is_string($body['baseHash'] ?? null) || !is_bool($body['reset'] ?? null)) throw new ApiError('invalid_edit','Некорректные данные ячейки или текст длиннее 20 000 символов.');
    $rangeEdit = $data !== null;
    $data ??= vitrine_data(vitrine_edit_row_ids($body));
    if (!in_array($body['column'], array_column($data['columns'],'letter'),true)) throw new ApiError('invalid_column','Столбец не найден.',404);
    $current = null;
    foreach ($data['rows'] as $row) if ($row['rowIndex'] === $body['row']) $current = $row;
    if ($current === null) throw new ApiError('row_not_visible','Строка больше не входит в текущую выборку. Обновите страницу.',409);
    $dayDraft=workspace_trip_day_draft_date($body['row'])!==null;
    $clear=($body['editAction']??null)==='clear';
    if ($clear && ($body['value']!=='' || $body['reset'] || isset($body['paste']) ||
        array_key_exists('dropoffDetails',$body) || array_key_exists('orderStatus',$body) || array_key_exists('fieldColorStatus',$body))) {
        throw new ApiError('invalid_edit','Проверьте запрос на очистку ячейки.');
    }
    vitrine_require_manual_field($data['table'],$body['column']);
    $fieldColorEdit=[];
    if (array_key_exists('fieldColorStatus',$body)) {
        if (!is_string($body['fieldColorStatus']) || !workspace_trip_field_status_can($data['table'],$body['column'],'view')) {
            throw new ApiError('invalid_order_status','Статус поля недоступен.',403);
        }
        $fieldColorEdit=['fieldColorStatus'=>$body['fieldColorStatus']];
    }
    if (array_key_exists('fieldColorOnly',$body)) {
        if ($body['fieldColorOnly']!==true || !$fieldColorEdit || $body['row']<=0 || $body['reset'] || $clear ||
            isset($body['paste']) || array_key_exists('dropoffDetails',$body)) {
            throw new ApiError('invalid_order_status','Проверьте данные статуса поля.');
        }
        $result=vitrine_store(['action'=>'save','row'=>$body['row'],'column'=>$body['column'],'revision'=>$body['revision'],
            'original'=>$current['originalValues'][$body['column']]??'','requestId'=>$body['requestId']??null,
            'currentValue'=>$current['values'][$body['column']]??'','fieldColorOnly'=>true]+$fieldColorEdit);
        return $result+['original'=>$current['originalValues'][$body['column']]??'','baseHash'=>$current['baseHash'],'conflict'=>false];
    }
    $compactKind = $current['compactInputs'][$body['column']] ?? null;
    $submittedValue=$body['value'];
    $formulaField=workspace_trip_supports_formula($data['table']['slug'],$body['column']);
    $formula=$formulaField && !$body['reset'] && !$clear && str_starts_with(ltrim($submittedValue),'=')
        ? trim($submittedValue) : null;
    $numericInput=$formula===null ? $submittedValue : WorkspaceTripFormula::evaluate($formula);
    if ($formula!==null && $compactKind!==null) vitrine_require_compact_integer_range($numericInput,$compactKind);
    $compactInteger=$compactKind!==null && vitrine_compact_integer_bounds($compactKind)!==null;
    if (!$clear && !$body['reset'] && $compactKind !== null) {
        $normalized = vitrine_compact_input($numericInput,$compactKind);
        if ($normalized === null && (($compactKind==='container' || $compactInteger)
            || $body['value'] !== ($current['values'][$body['column']] ?? ''))) {
            $message = match ($compactKind) {
                'container'=>'Введите номер ISO 6346: три латинские буквы, U и семь цифр. Проверьте последнюю цифру.',
                'weight'=>'Введите вес целым числом, только цифрами.',
                'distance'=>'Введите пробег целым числом, только цифрами.',
                'money'=>'Введите сумму целым числом, только цифрами.',
                'cargo-value'=>'Введите стоимость груза целым числом, только цифрами.',
                'cargo-places'=>'Введите количество мест целым числом, только цифрами.',
                'date'=>'Введите действительную дату в формате ДДММГГ.',
                'time'=>'Введите время в формате ЧЧММ. Часы 00–23, минуты 00–59.',
            };
            throw new ApiError('invalid_'.$compactKind,$message);
        }
        if ($normalized !== null) {
            vitrine_require_compact_integer_range($normalized,$compactKind);
            $body['value'] = $normalized;
        }
    } elseif (!$clear && !$body['reset'] && ($current['kinds'][$body['column']] ?? '') === 'time') {
        $normalized = vitrine_time_input($body['value']);
        if ($normalized === null && $body['value'] !== ($current['values'][$body['column']] ?? '')) throw new ApiError('invalid_time','Введите время: 800 или 0800 → 08:00. Часы 00–23, минуты 00–59.');
        if ($normalized !== null) $body['value'] = $normalized;
    }
    $original = $current['originalValues'][$body['column']] ?? '';
    if (!$clear && $compactKind==='date' && $data['table']['slug']==='vyvoz' && $body['column']==='F') {
        vitrine_require_trip_date_limit($body['reset'] ? $original : $body['value']);
    }
    if (!$clear && ($current['kinds'][$body['column']] ?? '') === 'slot') {
        $normalized = vitrine_slot_input($body['reset'] ? $original : $body['value']);
        if ($normalized === null) {
            throw new ApiError('invalid_slot','Введите 10 цифр: часы начала и окончания от 00 до 23 и действительную дату ДДММГГ.');
        }
        vitrine_require_slot_date_limit($normalized);
        $body['value'] = $normalized;
    }
    $pasteData = [];
    if (!$body['reset']) {
        if (isset($body['paste'])) $pasteData = vitrine_validate_paste(array_replace($body,['value'=>$formula===null ? $submittedValue : $body['value']]),$data,$current,$pasteSourceRows);
        else vitrine_validate_single_line($body['value'],$current,$body['column']);
    }
    $statusId = null;
    if ($body['column']==='A' && !$body['reset'] && $body['value']!=='') {
        foreach ($data['statuses'] as $status) if (vitrine_status_key($status['name'])===vitrine_status_key($body['value'])) { $statusId=$status['id']; $body['value']=$status['name']; }
        if ($statusId===null && $body['value']!==$original && $body['value']!==($current['values']['A']??'')) throw new ApiError('unknown_status','Выберите статус из справочника или добавьте новый в «Цвета ячеек → Статусы».');
    }
    $orderEdit=[];
    if (array_key_exists('orderStatus',$body)) {
        $code=$body['orderStatus'];
        if ($body['column']!=='B' || !isset(vitrine_catalog_links($data['table'])['B']) || !workspace_client_order_status_can_view($data['table'])) {
            throw new ApiError('invalid_order_status','Статус заказа доступен в поле B «Клиент».',403);
        }
        if (!is_string($code) || ($code!=='' && !isset(workspace_active_client_order_statuses()[$code]))) {
            throw new ApiError('invalid_order_status','Выберите статус из справочника.');
        }
        $orderEdit=['orderStatus'=>$code];
    }
    $comparison = $pasteData + ($rangeEdit ? ['rangeEdit'=>true] : []);
    if ($dayDraft) $comparison['draftAnchor']=$body['draftAnchor']??null;
    if ($formulaField) $comparison['tripFormula']=$formula;
    if (!$clear && $body['column']==='M' && !empty($current['catalogBindings']['M']) && !array_key_exists('dropoffDetails',$body)) {
        throw new ApiError('invalid_dropoff','Откройте поле «Сдача» и выберите точку из справочника.');
    }
    if (array_key_exists('dropoffDetails',$body)) {
        if ($body['column']!=='M' || $body['reset'] || empty($current['catalogBindings']['M']) || $body['value']==='') {
            throw new ApiError('invalid_dropoff','Выберите точку сдачи.');
        }
        $comparison['dropoffDetails']=workspace_dropoff_flight_details($body['dropoffDetails']);
    }
    if ($compactInteger) $comparison['comparisonOriginal']=$body['reset']
        ? $original : (vitrine_compact_input($original,$compactKind)??$original);
    $result = vitrine_store(['action'=>'save','source'=>$data['sourceKey'],'sheet'=>$data['sheetKey'],'row'=>$body['row'],'column'=>$body['column'],'value'=>$body['value'],'revision'=>$body['revision'],'reset'=>$body['reset'],'original'=>$original,'baseHash'=>$body['baseHash'],'statusId'=>$statusId,'requestId'=>$body['requestId']??null,'editAction'=>$body['editAction']??($body['reset']?'reset':(isset($body['paste'])?'paste':'edit')),'currentValue'=>$current['values'][$body['column']]??'','compactKind'=>$compactKind]+$orderEdit+$fieldColorEdit+$comparison);
    $result['value']=vitrine_compact_display($result['value'],$compactKind);
    if (($current['kinds'][$body['column']] ?? '') === 'slot') {
        $result['value'] = vitrine_slot_input($result['value']) ?? $result['value'];
    }
    if (!empty($result['fleetDocumentsChanged']) && workspace_fleet_trip_documents_can_view($data['table']['id'])) {
        header('Cache-Control: no-store');
        $recordId=isset($result['createdRow']) ? (int)wquery('SELECT id FROM records WHERE table_id=? AND row_num=?',
            [$data['table']['id'],$result['createdRow']])->fetchColumn() : $current['recordId'];
        $documents=workspace_fleet_trip_documents_values([$recordId]);
        $result['fleetDocumentValue']=$documents[$recordId]??($current['originalValues']['R']??'');
    }
    $statusColor = null; $resultStatusId = null;
    if ($body['column']==='A') foreach ($data['statuses'] as $status) if (vitrine_status_key($status['name'])===vitrine_status_key($result['value'])) { $statusColor=$status['color']; $resultStatusId=$status['id']; }
    return $result + ['original'=>$original,'baseHash'=>$current['baseHash'],'conflict'=>false,'statusColor'=>$statusColor,'statusId'=>$resultStatusId];
}

function render_vitrine(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new ApiError('method_not_allowed', 'Доступен только просмотр.', 405);
    }
    $eventCursor=(int)wquery('SELECT COALESCE(MAX(id),0) FROM outbox_events')->fetchColumn();
    $error = null;
    $data = ['columns'=>[], 'rows'=>[], 'lastSuccess'=>null, 'sheetTitle'=>'ВЫВОЗ'];
    $directory = vitrine_directory_requested();
    $embeddedCatalog = isset($_GET['quickCatalog']);
    if ($embeddedCatalog) {
        vitrine_quick_catalog_validate_directory($_GET['quickCatalog'],$directory);
        if ($_GET['quickCatalog']==='K' && !isset($_GET['client'])) {
            throw new ApiError('invalid_unload_client','Откройте точки выгрузки для выбранного клиента.');
        }
        app_frame_headers(true);
    }
    if ($directory==='entries' && is_scalar($_GET['id']??null)) {
        $kind=wquery('SELECT kind FROM catalogs WHERE id=?',[(int)$_GET['id']])->fetchColumn();
        if (is_string($kind) && workspace_fleet_retired_kind($kind)) {
            $url='/vitrine?catalog=entries&id='.workspace_fleet_catalog('fleet-vehicles');
            header('Location: '.(function_exists('app_url_path') ? app_url_path($url) : $url),true,302);
            return;
        }
    }
    try {
        $data = $directory ? workspace_directory_grid_data($directory) : vitrine_data(archive:($_GET['archive']??null)==='1');
        if (($data['table']['catalogKind']??null)==='terminals-main' && ($_GET['tariffs']??null)==='1') {
            require_once __DIR__.'/WorkspaceTerminalTariffs.php';
            $data['table']=workspace_terminal_tariff_grid($data['table']);
            $data['columns']=$data['table']['columns'];
            $data['terminalTariffs']=workspace_terminal_tariffs((int)$data['table']['catalogId'],$_GET);
            $data['rows']=workspace_terminal_tariff_rows($data['terminalTariffs']);
            $data['rowIds']=array_column($data['rows'],'rowIndex');
        }
    }
    catch (Throwable $e) {
        http_response_code($directory && $e instanceof ApiError ? $e->httpStatus : 503);
        $error = $e instanceof ApiError ? $e->publicMessage : 'Не удалось прочитать данные. Проверьте доступность локальной базы и обновите страницу.';
    }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__.'/../views/vitrine.php';
}
