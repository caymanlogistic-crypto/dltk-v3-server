<?php
declare(strict_types=1);

const CLIENT_DIRECTORY_IMPORT_KEY = 'client_directory_import_v1';

function workspace_client_import_inputs(array $body): array {
    $rows = $body['entries'] ?? null;
    $aliases = $body['aliases'] ?? [];
    if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows)>1000 || !is_array($aliases) || count($aliases)>2000) {
        throw new ApiError('invalid_import','Передайте от 1 до 1000 записей справочника клиентов.');
    }
    $entries = [];
    $normalizedOwners = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_int($row['key'] ?? null) || $row['key'] < 1 || isset($entries[$row['key']])
            || !is_string($row['name'] ?? null) || !is_bool($row['active'] ?? null)) {
            throw new ApiError('invalid_import','Некорректная запись справочника.');
        }
        $name = workspace_catalog_validate_name($row['name']);
        $key = mb_strtolower($name);
        if (isset($normalizedOwners[$key])) throw new ApiError('duplicate_catalog','В снимке есть повторяющиеся названия клиентов.',409);
        $normalizedOwners[$key] = $row['key'];
        $entries[$row['key']] = [
            'name'=>$name, 'normalized'=>$key, 'active'=>$row['active'],
            'manager'=>workspace_client_validate_manager($row['manager'] ?? null),
        ];
    }
    $remap = [];
    foreach ($aliases as $name => $owner) {
        $name = (string)$name;
        $key = mb_strtolower(workspace_catalog_validate_name($name));
        if (!is_int($owner) || !isset($entries[$owner]) || $key !== $name
            || (isset($normalizedOwners[$key]) && $normalizedOwners[$key] !== $owner)) {
            throw new ApiError('invalid_alias','Некорректное прежнее название клиента.');
        }
        $remap[$key] = $owner;
    }
    ksort($entries);
    ksort($remap);
    return [$entries,$remap];
}

function workspace_import_client_directory(array $body): array {
    if (workspace_employee_editor_scope() !== 'superadmin') {
        throw new ApiError('forbidden','Перенос доступен только суперадминистратору.',403);
    }
    [$entries,$aliases] = workspace_client_import_inputs($body);
    $fingerprint = hash('sha256',wjson([$entries,$aliases]));
    $table = workspace_table('vyvoz');
    workspace_require('table',(string)$table['id'],'edit',$table['id']);
    workspace_require('field','B','edit',$table['id']);
    workspace_require('catalog','*','edit');
    if (array_diff(['B','AL'],array_column($table['columns'],'letter'))) {
        throw new ApiError('import_target','В таблице «Вывоз» отсутствуют поля B или AL.',409);
    }

    workspace_begin();
    try {
        if (workspace_employee_editor_scope() !== 'superadmin') {
            throw new ApiError('forbidden','Перенос доступен только суперадминистратору.',403);
        }
        $previous = workspace_client_catalog_metadata(CLIENT_DIRECTORY_IMPORT_KEY);
        if ($previous && hash_equals($previous['fingerprint'],$fingerprint)) {
            workspace_db()->commit();
            return $previous + ['status'=>'unchanged'];
        }
        if ($previous || wquery("SELECT 1 FROM catalogs WHERE kind='clients' LIMIT 1")->fetchColumn()) {
            throw new ApiError('import_exists','Справочник клиентов уже существует. Его данные сохранены.',409);
        }
        wquery("INSERT INTO catalogs(title,scope,kind) VALUES('Клиенты','local','clients')");
        $catalog = (int)workspace_db()->lastInsertId();
        wquery("INSERT INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(?,?,'B')",[$catalog,$table['id']]);
        $insert = workspace_db()->prepare('INSERT INTO catalog_entries(catalog_id,name,normalized,active) VALUES(?,?,?,?)');
        $entryIds = [];
        $managers = [];
        $active = 0;
        foreach ($entries as $sourceId => $entry) {
            $insert->execute([$catalog,$entry['name'],$entry['normalized'],(int)$entry['active']]);
            $id = (int)workspace_db()->lastInsertId();
            $entryIds[$sourceId] = $id;
            $managers[$id] = $entry['manager'];
            $active += (int)$entry['active'];
        }
        $linkedAliases = [];
        foreach ($aliases as $name => $sourceId) $linkedAliases[$name] = $entryIds[$sourceId];
        $assigned = count(array_filter($managers,static fn(string $name): bool => $name !== ''));
        $receipt = [
            'fingerprint'=>$fingerprint,'catalogId'=>$catalog,'active'=>$active,'archived'=>count($entries)-$active,
            'withManager'=>$assigned,'withoutManager'=>count($entries)-$assigned,'aliases'=>count($aliases),
        ];
        wquery('INSERT INTO workspace_meta VALUES(?,?),(?,?),(?,?)',[
            'catalog_managers_'.$catalog,wjson((object)$managers),
            'catalog_client_aliases_'.$catalog,wjson((object)$linkedAliases),
            CLIENT_DIRECTORY_IMPORT_KEY,wjson($receipt),
        ]);
        workspace_audit(['tableId'=>$table['id'],'column'=>'B','label'=>'Клиенты','category'=>'catalog',
            'action'=>'import','after'=>$receipt]);
        workspace_event('catalog.changed',['catalogId'=>$catalog,'action'=>'import',
            'bindings'=>[['tableId'=>$table['id'],'tableSlug'=>$table['slug'],'fieldKey'=>'B']]]);
        workspace_db()->commit();
        return $receipt + ['status'=>'imported'];
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        if ($error instanceof PDOException && $error->getCode()==='23000') {
            throw new ApiError('duplicate_catalog','В снимке есть совпадающие названия клиентов.',409);
        }
        throw $error;
    }
}
