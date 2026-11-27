<?php
declare(strict_types=1);

const WORKSPACE_CLIENT_REQUISITE_LABELS = ['D'=>'ИНН','E'=>'Юридическое название'];

function workspace_client_validate_requisites(array $values): array {
    $requisites = [];
    foreach (WORKSPACE_CLIENT_REQUISITE_LABELS as $column=>$label) {
        if (!array_key_exists($column,$values)) continue;
        if (!is_string($values[$column])) throw new ApiError('invalid_client_requisites','Проверьте поле «'.$label.'».');
        $value=trim($values[$column]);
        if ($column==='D' && $value!=='' && !preg_match('/^(?:[0-9]{10}|[0-9]{12})$/D',$value)) {
            throw new ApiError('invalid_inn','ИНН должен содержать 10 или 12 цифр.');
        }
        $requisites[$column]=$column==='E' && $value!=='' ? workspace_catalog_validate_name($value) : $value;
    }
    return $requisites;
}

function workspace_client_requisites(int $catalogId): array {
    $rows=wquery("SELECT e.id,m.value FROM catalog_entries e
        JOIN workspace_meta m ON m.key=CONCAT('client_requisites_',e.id)
        WHERE e.catalog_id=?",[$catalogId])->fetchAll();
    $requisites=[];
    foreach ($rows as $row) $requisites[(int)$row['id']]=json_decode($row['value'],true) ?? [];
    return $requisites;
}

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
    $unloadCatalog=workspace_unload_catalog_id();
    $canViewUnload=$unloadCatalog && workspace_can('catalog',(string)$unloadCatalog,'view');
    $columns=[['letter'=>'A','label'=>'Клиент'],['letter'=>'B','label'=>'Менеджер']];
    $canViewManagers=workspace_client_manager_card_available();
    if ($canViewManagers) $columns[1]['kind']='client-managers';
    if ($canViewUnload) $columns[]=['letter'=>'C','label'=>'Точки выгрузки','kind'=>'client-unload-points'];
    foreach (WORKSPACE_CLIENT_REQUISITE_LABELS as $letter=>$label) $columns[]=compact('letter','label');
    return [
        'id'=>0, 'catalogId'=>(int)$catalog['id'], 'catalogKind'=>'clients', 'slug'=>'catalog-clients', 'title'=>'Клиенты',
        'columns'=>$columns,'unloadCatalogId'=>$canViewUnload ? $unloadCatalog : null,
        'hasManagerCard'=>$canViewManagers,
    ];
}

function workspace_directory_client_filter(int $catalogId): ?array {
    if (!isset($_GET['client'])) return null;
    $id=filter_var($_GET['client'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $client=$id ? wquery('SELECT id,name FROM catalog_entries WHERE catalog_id=? AND id=?',[$catalogId,$id])->fetch() : null;
    if (!$client || workspace_row_deleted('catalog_deleted_client_',(int)$client['id'])) throw new ApiError('not_found','Клиент не найден в справочнике.',404);
    return $client;
}

function workspace_client_grid_data(?array $requestedRows = null): array {
    $table = workspace_client_grid_model();
    $catalogId = $table['catalogId'];
    $canEdit = workspace_can('catalog', (string)$catalogId, 'edit');
    $archive = ($_GET['archive'] ?? null) === '1';
    $showAll = ($_GET['archive'] ?? null) === 'all';
    $clientFilter=workspace_directory_client_filter($catalogId);
    $unloadCatalog=$table['unloadCatalogId'];
    $canViewUnload=$unloadCatalog!==null;
    $pointCounts=$canViewUnload ? workspace_client_unload_counts($unloadCatalog) : [];
    $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 160) : '';
    $managers = workspace_client_manager_values($catalogId);
    $requisites = workspace_client_requisites($catalogId);
    $emptyRequisites = array_fill_keys(array_keys(WORKSPACE_CLIENT_REQUISITE_LABELS),'');
    $entries = wquery(
        "SELECT id,name,revision,active FROM catalog_entries e WHERE catalog_id=?
         AND NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`=CONCAT('catalog_deleted_client_',e.id))".
        ($showAll ? '' : ' AND active=?').' ORDER BY name,id',
        $showAll ? [$catalogId] : [$catalogId, (int)!$archive],
    )->fetchAll();
    if (!$archive) $entries=workspace_directory_order_entries($entries,$catalogId);
    $rows = [];
    $rowIds = [];
    $requested = $requestedRows === null ? null : array_fill_keys($requestedRows, true);
    foreach ($entries as $entry) {
        $entryId = (int)$entry['id'];
        if ($clientFilter && $entryId!==(int)$clientFilter['id']) continue;
        $manager = $managers[$entryId] ?? '';
        $name = $entry['name'];
        $clientRequisites = ($requisites[$entryId] ?? []) + $emptyRequisites;
        $values = ['A'=>$name, 'B'=>$manager] + $clientRequisites;
        if ($search !== '' && mb_stripos(implode(' ', $values), $search) === false) continue;
        $rowIds[] = $entryId;
        if ($requested === null ? count($rows) >= 100 : !isset($requested[$entryId])) continue;
        if ($canViewUnload) $values['C']=workspace_client_unload_count_label($pointCounts[$entryId]??0);
        $kinds = ['A'=>'text','B'=>$table['hasManagerCard']?'client-managers':'text'];
        $version = ['revision'=>(int)$entry['revision']];
        $rows[] = [
            'rowIndex'=>$entryId, 'rowLabel'=>count($rowIds), 'values'=>$values,
            'originalValues'=>$values, 'baseHash'=>workspace_client_grid_hash($entryId),
            'kinds'=>$kinds, 'rowActions'=>$canEdit, 'archived'=>!(bool)$entry['active'],
            'singleLine'=>array_fill_keys(array_keys($values),true),
            'edits'=>array_fill_keys(['A','B',...array_keys(WORKSPACE_CLIENT_REQUISITE_LABELS)],$version),
            'readOnly'=>workspace_manager_catalog_id() && !$table['hasManagerCard']
                ? ['B'=>'Менеджеры назначаются из справочника.'] : [],
        ];
    }
    $syncLabel = $canEdit && function_exists('workspace_catalog_source_label') ? workspace_catalog_source_label($catalogId) : null;
    return [
        'catalogGrid'=>['id'=>$catalogId, 'archive'=>$archive, 'showAll'=>$showAll, 'archiveRows'=>true,'search'=>$search, 'canEdit'=>$canEdit, 'syncLabel'=>$syncLabel,
            'clientFilter'=>$clientFilter,
            'clientUnloadCatalog'=>$canViewUnload ? $unloadCatalog : null,
            'clientManagers'=>$table['hasManagerCard'],
            'draftFields'=>array_diff_key(workspace_directory_draft_fields('clients'),
                (workspace_client_account_managers_enabled() || workspace_manager_catalog_id()) ? ['B'=>true] : [])],
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
    json_response(workspace_client_grid_edit(wbody(),$pasteCheck));
}

function workspace_client_grid_edit(array $body, bool $pasteCheck = false): array {
    $catalog = workspace_client_grid_catalog();
    if (!$catalog) throw new ApiError('not_found', 'Справочник «Клиенты» не найден.', 404);
    $catalogId = (int)$catalog['id'];
    workspace_require('catalog', (string)$catalogId, 'edit');
    if (!is_int($body['row'] ?? null) || !in_array($body['column'] ?? null, ['A','B',...array_keys(WORKSPACE_CLIENT_REQUISITE_LABELS)], true) || !is_string($body['value'] ?? null)) {
        throw new ApiError('invalid_edit', 'Некорректные данные ячейки справочника.');
    }
    if ($body['column']==='B') workspace_client_guard_manager_cell($body,$pasteCheck);
    $entry = wquery('SELECT id,name,active,revision FROM catalog_entries WHERE id=? AND catalog_id=?', [$body['row'], $catalogId])->fetch();
    if (!$entry || !$entry['active']) throw new ApiError('not_found', 'Активная запись справочника не найдена.', 404);
    $column = $body['column'];
    $value = match ($column) {
        'A'=>workspace_catalog_validate_name($body['value']),
        'B'=>workspace_client_validate_manager($body['value']),
        default=>workspace_client_validate_requisites([$column=>$body['value']])[$column],
    };
    if ($pasteCheck) return ['accepted'=>true];
    if (!is_int($body['revision'] ?? null) || ($body['baseHash'] ?? null) !== workspace_client_grid_hash((int)$entry['id'])) {
        throw new ApiError('invalid_edit', 'Обновите справочник и повторите сохранение.');
    }
    $payload = [
        'id'=>(int)$entry['id'], 'catalogId'=>$catalogId, 'active'=>true,
        'revision'=>$body['revision'], 'name'=>$column==='A' ? $value : $entry['name'],
    ];
    if ($column==='B') $payload['manager'] = $value;
    if (isset(WORKSPACE_CLIENT_REQUISITE_LABELS[$column])) $payload['clientRequisites'] = [$column=>$value];
    $result = workspace_catalog_save($payload);
    return $result + ['value'=>$value, 'original'=>$value, 'baseHash'=>$body['baseHash'], 'edited'=>false];
}
