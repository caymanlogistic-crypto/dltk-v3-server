<?php
declare(strict_types=1);

require_once __DIR__.'/Format.php';
require_once __DIR__.'/VitrineClipboard.php';

require_once __DIR__.'/WorkspaceVitrine.php';

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
    $value = $_GET['ids'] ?? '';
    if (!is_string($value) || !preg_match('/^\d+(?:,\d+){0,99}$/D',$value)) throw new ApiError('invalid_rows','Запрашивайте от 1 до 100 строк.');
    $ids = array_map('intval',explode(',',$value));
    $data = vitrine_data($ids);
    $columns=$data['columns']; $rows=$data['rows'];
    $escape=static fn(mixed $value): string => htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $columnClass=static fn(array $column): string => mb_strtolower(trim($column['label']))==='время'?' vitrine-time-column':'';
    ob_start(); require __DIR__.'/../views/vitrine-rows.php'; $html=ob_get_clean();
    json_response(['html'=>$html,'rowIds'=>array_column($rows,'rowIndex')]);
}

function vitrine_validate_paste(array $body, array $data, array $target): void {
    $paste = $body['paste'] ?? null;
    if (!is_array($paste) || !array_key_exists('source',$paste)) throw new ApiError('invalid_clipboard','Не удалось определить содержимое буфера обмена.');
    $kind = $target['kinds'][$body['column']] ?? 'text';
    if ($kind === 'time') $body['value'] = vitrine_time_input($body['value']) ?? $body['value'];
    $labels = ['status'=>'Статус','text'=>'Текст','number'=>'Число','date'=>'Дата','datetime'=>'Дата и время','time'=>'Время','boolean'=>'TRUE/FALSE'];
    if ($paste['source'] !== null) {
        $source = $paste['source'];
        if (!is_array($source) || !is_int($source['row']??null) || !is_string($source['column']??null) || !is_int($source['revision']??null) || !is_string($source['baseHash']??null) || !is_string($source['value']??null)) throw new ApiError('invalid_clipboard','Некорректный формат скопированной ячейки.');
        $sourceRow = null;
        foreach ($data['rows'] as $row) if ($row['rowIndex']===$source['row']) $sourceRow=$row;
        if ($sourceRow===null || !isset($sourceRow['kinds'][$source['column']])) throw new ApiError('clipboard_source_missing','Скопированная ячейка больше не входит в текущую выборку. Скопируйте значение заново.',409);
        $sourceKind = $sourceRow['kinds'][$source['column']];
        if ($sourceKind!==$kind) throw new ApiError('clipboard_type_mismatch','Форматы ячеек не совпадают: '.$labels[$sourceKind].' → '.$labels[$kind].'. Вставка отменена.');
    }
    if (!vitrine_clipboard_accepts($body['value'],$kind,$target['choices'][$body['column']]??[])) throw new ApiError('clipboard_value_mismatch','Значение не подходит для формата «'.$labels[$kind].'» или заданного списка. Вставка отменена.');
}

function vitrine_paste_check(): never {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $body=json_decode((string)file_get_contents('php://input',false,null,0,100000),true);
    if (!is_array($body) || !is_int($body['row']??null) || !is_string($body['column']??null) || !is_string($body['value']??null)) throw new ApiError('invalid_clipboard','Некорректные данные для вставки.');
    $data=vitrine_data(vitrine_edit_row_ids($body)); $target=null;
    foreach ($data['rows'] as $row) if ($row['rowIndex']===$body['row']) $target=$row;
    if (!$target || !isset($target['kinds'][$body['column']])) throw new ApiError('clipboard_target_missing','Ячейка не найдена.',404);
    vitrine_validate_paste($body,$data,$target);
    json_response(['accepted'=>true]);
}

function vitrine_save_cell(): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method','Метод не поддерживается.',405);
    $raw = file_get_contents('php://input', false, null, 0, 100000);
    $body = json_decode((string)$raw, true);
    if (!is_array($body) || !is_int($body['row'] ?? null) || !is_string($body['column'] ?? null) || !is_string($body['value'] ?? null) || mb_strlen($body['value'])>20000 || !is_int($body['revision'] ?? null) || $body['revision']<0 || !is_string($body['baseHash'] ?? null) || !is_bool($body['reset'] ?? null)) throw new ApiError('invalid_edit','Некорректные данные ячейки или текст длиннее 20 000 символов.');
    $data = vitrine_data(vitrine_edit_row_ids($body));
    if (!in_array($body['column'], array_column($data['columns'],'letter'),true)) throw new ApiError('invalid_column','Столбец не найден.',404);
    $current = null;
    foreach ($data['rows'] as $row) if ($row['rowIndex'] === $body['row']) $current = $row;
    if ($current === null) throw new ApiError('row_not_visible','Строка больше не входит в текущую выборку. Обновите страницу.',409);
    if (!$body['reset'] && ($current['kinds'][$body['column']] ?? '') === 'time') {
        $normalized = vitrine_time_input($body['value']);
        if ($normalized === null && $body['value'] !== ($current['values'][$body['column']] ?? '')) throw new ApiError('invalid_time','Введите время: 800 или 0800 → 08:00. Часы 00–23, минуты 00–59.');
        if ($normalized !== null) $body['value'] = $normalized;
    }
    if (isset($body['paste']) && !$body['reset']) vitrine_validate_paste($body,$data,$current);
    $original = $current['originalValues'][$body['column']] ?? '';
    $statusId = null;
    if ($body['column']==='A' && !$body['reset'] && $body['value']!=='') {
        foreach ($data['statuses'] as $status) if (vitrine_status_key($status['name'])===vitrine_status_key($body['value'])) { $statusId=$status['id']; $body['value']=$status['name']; }
        if ($statusId===null && $body['value']!==$original && $body['value']!==($current['values']['A']??'')) throw new ApiError('unknown_status','Выберите статус из справочника или добавьте новый в «Справочники → Статусы».');
    }
    $result = vitrine_store(['action'=>'save','source'=>$data['sourceKey'],'sheet'=>$data['sheetKey'],'row'=>$body['row'],'column'=>$body['column'],'value'=>$body['value'],'revision'=>$body['revision'],'reset'=>$body['reset'],'original'=>$original,'baseHash'=>$body['baseHash'],'statusId'=>$statusId,'requestId'=>$body['requestId']??null,'editAction'=>$body['editAction']??($body['reset']?'reset':(isset($body['paste'])?'paste':'edit')),'currentValue'=>$current['values'][$body['column']]??'']);
    $statusColor = null; $resultStatusId = null;
    if ($body['column']==='A') foreach ($data['statuses'] as $status) if (vitrine_status_key($status['name'])===vitrine_status_key($result['value'])) { $statusColor=$status['color']; $resultStatusId=$status['id']; }
    json_response($result + ['original'=>$original,'baseHash'=>$current['baseHash'],'conflict'=>false,'statusColor'=>$statusColor,'statusId'=>$resultStatusId]);
}

function render_vitrine(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new ApiError('method_not_allowed', 'Доступен только просмотр.', 405);
    }
    $eventCursor=(int)wquery('SELECT COALESCE(MAX(id),0) FROM outbox_events')->fetchColumn();
    $error = null;
    $data = ['columns'=>[], 'rows'=>[], 'lastSuccess'=>null, 'sheetTitle'=>'ВЫВОЗ'];
    try { $data = vitrine_data(); }
    catch (Throwable $e) {
        http_response_code(503);
        $error = $e instanceof ApiError ? $e->publicMessage : 'Не удалось прочитать данные. Проверьте доступность локальной базы и обновите страницу.';
    }
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__.'/../views/vitrine.php';
}
