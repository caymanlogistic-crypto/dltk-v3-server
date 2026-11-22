<?php
declare(strict_types=1);

const PICKUP_DIRECTORY_IMPORT_KEY = 'pickup_directory_import_v1';

function workspace_pickup_import_entries(array $body): array {
    $rows = $body['entries'] ?? null;
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows)>500) {
        throw new ApiError('invalid_import','Передайте от 1 до 500 терминалов постановки.');
    }
    $palette = array_column(workspace_palette(),'fill');
    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['name'] ?? null) || !is_bool($row['active'] ?? null)) {
            throw new ApiError('invalid_import','Некорректная запись терминала.');
        }
        $name = workspace_catalog_validate_name($row['name']);
        $key = mb_strtolower($name);
        if (isset($entries[$key])) throw new ApiError('duplicate_catalog','В снимке есть повторяющиеся названия терминалов.',409);
        $color = $row['color'] ?? null;
        if ($color !== null && !in_array($color,$palette,true)) {
            throw new ApiError('invalid_color','Выберите цвет из палитры.');
        }
        $details = $row['details'] ?? [];
        if (!is_array($details) || !array_is_list($details) || count($details)>20) {
            throw new ApiError('invalid_import','Некорректные реквизиты терминала.');
        }
        foreach ($details as $detail) {
            $values = $detail['values'] ?? null;
            if (!is_int($detail['row'] ?? null) || $detail['row'] < 2 || !is_array($values) || !array_is_list($values)
                || count($values)!==3 || array_filter($values,static fn($value)=>!is_string($value)||mb_strlen($value)>1024)) {
                throw new ApiError('invalid_import','Некорректные реквизиты терминала.');
            }
        }
        $entries[$key] = ['name'=>$name,'active'=>$row['active'],'color'=>$color,'details'=>$details];
    }
    ksort($entries);
    return $entries;
}

function workspace_import_pickup_directory(array $body): array {
    if (workspace_employee_editor_scope() !== 'superadmin') {
        throw new ApiError('forbidden','Перенос доступен только суперадминистратору.',403);
    }
    $entries = workspace_pickup_import_entries($body);
    $fingerprint = hash('sha256',wjson($entries));
    $table = workspace_table('vyvoz');
    workspace_require('table',(string)$table['id'],'edit',$table['id']);
    workspace_require('field','E','edit',$table['id']);
    workspace_require('catalog','*','edit');
    if (mb_strtolower(trim(array_column($table['columns'],'label','letter')['E']??'')) !== 'постановка') {
        throw new ApiError('import_target','В таблице «Вывоз» отсутствует поле E «Постановка».',409);
    }
    workspace_begin();
    try {
        $previous = workspace_client_catalog_metadata(PICKUP_DIRECTORY_IMPORT_KEY);
        if ($previous && hash_equals($previous['fingerprint'],$fingerprint)) {
            workspace_db()->commit();
            return $previous + ['status'=>'unchanged'];
        }
        if ($previous || wquery("SELECT 1 FROM catalogs WHERE kind='terminals-main' LIMIT 1")->fetchColumn()) {
            throw new ApiError('import_exists','Справочник терминалов уже существует. Его данные сохранены.',409);
        }
        $title = 'Терминалы постановки';
        wquery("INSERT INTO catalogs(title,scope,kind) VALUES(?,'local','terminals-main')",[$title]);
        $id = (int)workspace_db()->lastInsertId();
        wquery("INSERT INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(?,?,'E')",[$id,$table['id']]);
        $add = workspace_db()->prepare('INSERT INTO catalog_entries(catalog_id,name,normalized,color,active) VALUES(?,?,?,?,?)');
        $details = [];
        $active = $colored = 0;
        foreach ($entries as $key=>$entry) {
            $add->execute([$id,$entry['name'],(string)$key,$entry['color'],(int)$entry['active']]);
            if ($entry['details']) $details[(int)workspace_db()->lastInsertId()] = $entry['details'];
            $active += (int)$entry['active'];
            $colored += (int)($entry['color'] !== null);
        }
        $receipt = ['fingerprint'=>$fingerprint,'catalogId'=>$id,'active'=>$active,
            'archived'=>count($entries)-$active,'colored'=>$colored,'withDetails'=>count($details)];
        wquery('INSERT INTO workspace_meta VALUES(?,?),(?,?)',[
            PICKUP_DIRECTORY_IMPORT_KEY,wjson($receipt),
            'catalog_details_'.$id,wjson(['columns'=>['Терминалы','Адрес','ИНН'],'entries'=>(object)$details]),
        ]);
        workspace_audit(['tableId'=>$table['id'],'column'=>'E','label'=>$title,'category'=>'catalog',
            'action'=>'import','after'=>$receipt]);
        workspace_event('catalog.changed',['catalogId'=>$id,'action'=>'import',
            'bindings'=>[['tableId'=>$table['id'],'tableSlug'=>$table['slug'],'fieldKey'=>'E']]]);
        workspace_db()->commit();
        return $receipt+['status'=>'imported'];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_catalog','В снимке есть совпадающие названия терминалов.',409);
        }
        throw $error;
    }
}
