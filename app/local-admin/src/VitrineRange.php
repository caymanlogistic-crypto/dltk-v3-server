<?php
declare(strict_types=1);

function vitrine_save_range(?string $directory, array $body): array {
    $cells = $body['cells'] ?? null;
    $request = $body['requestId'] ?? null;
    $paste = $body['action'] === 'pasteRange';
    if (!is_array($cells) || !array_is_list($cells) || !$cells ||
        !is_string($request) || !preg_match('/^[a-z0-9-]{8,48}$/Di',$request)) {
        throw new ApiError('invalid_range','Проверьте данные диапазона.');
    }
    $ids = $targets = [];
    foreach ($cells as $cell) {
        if (!is_array($cell) || !is_int($cell['row']??null) || $cell['row']===0 ||
            ($cell['row']<0 && (!$paste || $directory!==null || $cell['row'] < -WORKSPACE_AVTOVYVOZ_DRAFT_ROWS)) ||
            !is_string($cell['column']??null) || !preg_match('/^[A-Z]{1,3}$/D',$cell['column']) ||
            !is_string($cell['value']??null) || mb_strlen($cell['value'])>20000 ||
            !is_int($cell['revision']??null) || $cell['revision']<0 ||
            !is_string($cell['baseHash']??null) || !preg_match('/^[0-9a-f]{64}$/D',$cell['baseHash'])) {
            throw new ApiError('invalid_range','Проверьте данные и длину значений в диапазоне.');
        }
        $row = $cell['row'];
        $column = $cell['column'];
        if (isset($targets[$row][$column])) throw new ApiError('invalid_range','Ячейка указана дважды.');
        $targets[$row][$column] = $cell;
        $ids[$row] = $row;
        $source = $cell['source'] ?? null;
        if (is_array($source) && is_int($source['row']??null) && $source['row']>1) $ids[$source['row']] = $source['row'];
    }

    $user = workspace_current_user()['id'];
    return workspace_transaction(static function() use ($directory,$cells,$request,$paste,$ids,$targets,$user) {
        $cached = wquery('SELECT result FROM request_deduplication WHERE user_id=? AND request_id=?',[$user,$request])->fetchColumn();
        if ($cached) return json_decode($cached,true,512,JSON_THROW_ON_ERROR);
        $newRows = [];
        $draftSlots = array_filter(array_keys($targets),static fn(int $row): bool=>$row<0);
        if ($draftSlots) {
            $draftData = vitrine_data([min($draftSlots)]);
            $draft = $draftData['tripDraft'];
            if (!$draft) throw new ApiError('forbidden','Нет доступа к созданию рейсов.',403);
            foreach ($draftSlots as $slot) {
                foreach ($targets[$slot] as $cell) {
                    if ($cell['revision']!==0 || !hash_equals($draft['baseHash'],$cell['baseHash'])) {
                        throw new ApiError('revision_conflict','Шаблон новой строки изменился. Обновите таблицу.',409);
                    }
                    if (trim($cell['value'])!=='') {
                        $newRows[$slot] ??= workspace_create_record($draftData['table']);
                    }
                }
                unset($ids[$slot]);
                if (isset($newRows[$slot])) $ids[$newRows[$slot]] = $newRows[$slot];
            }
        }
        $data = $directory === null ? vitrine_data(array_values($ids)) : null;
        $directoryRows = $directory === null ? [] :
            array_column(workspace_directory_grid_data($directory,array_keys($targets))['rows'],null,'rowIndex');
        $versions = $results = $updated = $refreshUnload = [];

        $order = array_keys($cells);
        if ($directory === null) {
            $rowOrder = array_flip(array_keys($targets));
            // Validate dependent values against the inputs from the same paste, regardless of column positions.
            $priority = ['B'=>-1,'F'=>-1,'X'=>-1,'K'=>1,'Y'=>1];
            usort($order,static fn(int $a,int $b): int=>
                [$rowOrder[$cells[$a]['row']],$priority[$cells[$a]['column']]??0,$a] <=>
                [$rowOrder[$cells[$b]['row']],$priority[$cells[$b]['column']]??0,$b]);
        }
        foreach ($order as $index) {
            $cell = $cells[$index];
            $row = $cell['row'];
            $column = $cell['column'];
            $value = $cell['value'];
            if ($row<0 && trim($value)==='') continue;
            $reset = !$paste && $directory === null && ($cell['reset']??false) === true;
            $clear = $value === '' && !$reset;
            $cell = array_intersect_key($cell,array_flip(['row','column','value','revision','baseHash','source']));
            $cell['requestId'] = $request.':'.$index;
            $cell['reset'] = $reset;
            $cell['editAction'] = $clear ? 'clear' : ($paste ? 'paste' : 'undo');
            unset($cell['paste']);
            if ($paste && !$clear && $directory === null) $cell['paste'] = ['source'=>$cell['source']??null];
            if (!$paste && !$clear && $directory===null && $column==='B' && isset($cells[$index]['orderStatus'])) {
                $cell['orderStatus'] = $cells[$index]['orderStatus'];
            }
            try {
                if ($directory === null) {
                    $cell['row'] = $newRows[$row] ?? $row;
                    if (isset($updated[$row][$column])) $cell['revision'] = $updated[$row][$column];
                    $state = $column==='K' && isset($refreshUnload[$row]) ? vitrine_data(array_values($ids)) : $data;
                    $result = vitrine_table_cell($cell,$state,$data['rows']);
                    if ($column==='B' && isset($targets[$row]['K'])) $refreshUnload[$row] = true;
                    foreach ($result['unloadCells']??[] as $patch) {
                        $next = $targets[$row][$patch['column']]??null;
                        if ($next && $next['revision']+1===$patch['revision']) {
                            $updated[$row][$patch['column']] = $patch['revision'];
                        }
                    }
                } else {
                    $state = $directoryRows[$row]??null;
                    if (!$state || !array_key_exists($column,$state['values']) || !empty($state['readOnly'][$column])) {
                        throw new ApiError('readonly','Ячейка доступна только для просмотра.',403);
                    }
                    $hash = $cell['baseHash'];
                    $version = $versions[$row][$hash]??null;
                    if ($version) {
                        if ($cell['revision']!==$version['before']) workspace_directory_conflict();
                        $cell['revision'] = $version['after'];
                        $cell['baseHash'] = $version['hash'];
                    }
                    $result = match ($directory) {
                        'entries' => workspace_catalog_directory_edit($cell),
                        'clients' => workspace_client_grid_edit($cell),
                        'employees' => workspace_employee_grid_edit($cell),
                        'client-order-statuses' => workspace_client_order_status_grid_edit($cell),
                        'flight-statuses' => workspace_flight_status_grid_edit($cell),
                        default => throw new ApiError('readonly','Запись доступна только для просмотра.',403),
                    };
                    $versions[$row][$hash] = ['before'=>$version['before']??$cell['revision'],
                        'after'=>$result['revision'],'hash'=>$result['baseHash']];
                }
            } catch (ApiError $error) {
                $label = $row<0 ? 'Новая строка '.abs($row).', столбец '.$column : $column.($directoryRows[$row]['rowLabel']??$row);
                throw new ApiError($error->publicCode,$label.' · '.$error->publicMessage,$error->httpStatus);
            }
            $results[$index] = ['row'=>$row,'column'=>$column,'result'=>$result];
        }
        foreach ($newRows as $row) workspace_event('row.changed',['tableSlug'=>$data['table']['slug'],'row'=>$row]);
        ksort($results);
        $result = ['cells'=>array_values($results),'createdRows'=>(object)$newRows];
        wquery('INSERT INTO request_deduplication VALUES(?,?,?)',[$user,$request,wjson($result)]);
        return $result;
    });
}
