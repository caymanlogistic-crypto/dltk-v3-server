<?php
declare(strict_types=1);

function workspace_client_grid_catalog(): ?array {
    static $catalogs = [];
    if (!array_key_exists('clients', $catalogs)) {
        $catalog = wquery(
            "SELECT c.id,c.title FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id
             JOIN table_definitions t ON t.id=b.table_id
             WHERE t.slug='vyvoz' AND b.field_key='B' AND c.kind='clients' ORDER BY c.id LIMIT 1"
        )->fetch();
        $catalogs['clients'] = $catalog && workspace_can('catalog', (string)$catalog['id'], 'view') ? $catalog : null;
    }
    return $catalogs['clients'];
}

function workspace_client_grid_model(): array {
    $catalog = workspace_client_grid_catalog();
    if (!$catalog) throw new ApiError('not_found', 'Справочник «Клиенты» не найден.', 404);
    return [
        'id'=>0, 'catalogId'=>(int)$catalog['id'], 'slug'=>'catalog-clients', 'title'=>'Клиенты',
        'columns'=>[['letter'=>'A', 'label'=>'Клиент'], ['letter'=>'B', 'label'=>'Менеджер']],
    ];
}

function workspace_client_grid_data(?array $requestedRows = null): array {
    $table = workspace_client_grid_model();
    $catalogId = $table['catalogId'];
    $canEdit = workspace_can('catalog', (string)$catalogId, 'edit');
    $archive = ($_GET['archive'] ?? null) === '1';
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
    $managers = workspace_client_manager_values($catalogId);
    $entries = wquery(
        'SELECT id,name,revision FROM catalog_entries WHERE catalog_id=? AND active=? ORDER BY name,id',
        [$catalogId, (int)!$archive],
    )->fetchAll();
    $rows = [];
    $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows, true);
    foreach ($entries as $entry) {
        $entryId = (int)$entry['id'];
        $manager = $managers[$entryId] ?? '';
        $name = $entry['name'];
        if ($search !== '' && mb_stripos($name, $search) === false && mb_stripos($manager, $search) === false) continue;
        $rowIds[] = $entryId;
        if ($requested === null ? count($rows) >= 100 : !isset($requested[$entryId])) continue;
        $values = ['A'=>$name, 'B'=>$manager];
        $kinds = ['A'=>'text', 'B'=>'text'];
        $version = ['revision'=>(int)$entry['revision']];
        $rows[] = [
            'rowIndex'=>$entryId, 'rowLabel'=>count($rowIds), 'values'=>$values,
            'originalValues'=>$values, 'baseHash'=>workspace_client_grid_hash($entryId),
            'kinds'=>$kinds, 'edits'=>['A'=>$version, 'B'=>$version],
        ];
    }
    $syncLabel = $canEdit && function_exists('workspace_catalog_source_label') ? workspace_catalog_source_label($catalogId) : null;
    return [
        'catalogGrid'=>['id'=>$catalogId, 'archive'=>$archive, 'search'=>$search, 'canEdit'=>$canEdit, 'syncLabel'=>$syncLabel],
        'table'=>$table,
        'sheetTitle'=>$table['title'],
        'columns'=>$table['columns'],
        'palette'=>workspace_palette(),
        'rowIds'=>$rowIds,
        'rows'=>$rows,
    ];
}

function workspace_client_grid_hash(int $entryId): string {
    return hash('sha256', 'client-catalog:'.$entryId);
}

function workspace_client_grid_endpoint(bool $pasteCheck = false): never {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError('method', 'Метод не поддерживается.', 405);
    $catalog = workspace_client_grid_catalog();
    if (!$catalog) throw new ApiError('not_found', 'Справочник «Клиенты» не найден.', 404);
    $catalogId = (int)$catalog['id'];
    workspace_require('catalog', (string)$catalogId, 'edit');
    $body = wbody();
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null, ['A','B'], true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit', 'Некорректные данные ячейки справочника.');
    }
    $entry = wquery('SELECT id,name,active,revision FROM catalog_entries WHERE id=? AND catalog_id=?', [$body['row'], $catalogId])->fetch();
    if (!$entry || !$entry['active']) throw new ApiError('not_found', 'Активная запись справочника не найдена.', 404);
    $isName = $body['column'] === 'A';
    $value = $isName ? workspace_catalog_validate_name($body['value']) : workspace_client_validate_manager($body['value']);
    if ($pasteCheck) json_response(['accepted'=>true]);
    if (!is_int($body['revision'] ?? null) || ($body['baseHash'] ?? null) !== workspace_client_grid_hash((int)$entry['id'])) {
        throw new ApiError('invalid_edit', 'Обновите справочник и повторите сохранение.');
    }
    $payload = [
        'id'=>(int)$entry['id'], 'catalogId'=>$catalogId, 'active'=>true,
        'revision'=>$body['revision'], 'name'=>$isName ? $value : $entry['name'],
    ];
    if (!$isName) $payload['manager'] = $value;
    $result = workspace_catalog_save($payload);
    json_response($result + ['value'=>$value, 'original'=>$value, 'baseHash'=>$body['baseHash'], 'edited'=>false]);
}
