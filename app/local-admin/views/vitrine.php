<?php
declare(strict_types=1);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentUser=workspace_current_user();
$directory = $directory ?? null;
$clientCatalog = $directory === 'clients';
$catalogGrid = $data['catalogGrid'] ?? null;
$terminalTariffs = $data['terminalTariffs'] ?? null;
$canManageDirectory = $catalogGrid['canEdit'] ?? false;
$canReorderCatalogRows = $embeddedCatalog && $canManageDirectory && !in_array($_GET['archive']??null,['1','all'],true);
$canManageRows = $canManageDirectory || (!$directory && !empty($data['table']['id']) &&
    workspace_can('table',(string)$data['table']['id'],'edit',$data['table']['id']));
$rowArchive = $catalogGrid['archive'] ?? (($_GET['archive']??null)==='1');
$rowRelatedLabel = $directory ? ($catalogGrid['relatedLabel']??null) : 'История строки';
$showDraftRows = $canManageDirectory && !$catalogGrid['archive'];
$viewUrl = static fn(string $path): string => function_exists('app_url_path') ? app_url_path($path) : $path;
$currentTable = $data['table'] ?? ($directory ? ['id'=>0,'slug'=>'catalog-'.$directory,'title'=>workspace_order_status_directory($directory)['title']
    ?? ['clients'=>'Клиенты','employees'=>'Учётные записи','roles'=>'Роли','flight-statuses'=>'Статусы рейса','entries'=>'Справочник'][$directory]] : workspace_table());
$managementTable = $currentTable['sourceTable'] ?? ($directory ? 'vyvoz' : $currentTable['slug']);
$directoryUrl = $catalogGrid ? '/vitrine?catalog='.$directory.match ($directory) {
    'entries' => '&id='.$catalogGrid['id'],
    'flight-statuses' => '&table='.$managementTable,
    default => '',
} : null;
$workspaceTables=workspace_tables();
$boundCatalogMenu = $documentColorMenu = $systemCatalogMenu = [];
foreach (vitrine_bound_catalog_menu($workspaceTables) as $item) {
    if (workspace_is_document_status_catalog($item['kind'])) $documentColorMenu[] = $item;
    elseif ($item['kind']==='edo-operators') $systemCatalogMenu[] = $item;
    else $boundCatalogMenu[] = $item;
}
$catalogKind = $currentTable['catalogKind'] ?? '';
$currentCatalogId = $directory==='entries' ? (int)($catalogGrid['id'] ?? 0) : 0;
$systemDirectoryLinks = array_filter(
    ['employees'=>'Учётные записи', 'roles'=>'Роли'],
    static fn(string $key): bool => workspace_can('system',$key==='employees' ? 'users' : 'roles','view'),
    ARRAY_FILTER_USE_KEY
);
$isSystemDirectory = isset($systemDirectoryLinks[$directory]) || ($directory==='entries' && $catalogKind==='edo-operators');
$canOpenStatuses = !$directory || in_array('vyvoz',array_column($workspaceTables,'slug'),true);
$canViewClientColors = workspace_can('system','client-order-statuses','view');
$tripTable = null;
foreach ($workspaceTables as $availableTable) if ($availableTable['slug']==='vyvoz') { $tripTable=$availableTable; break; }
$tripFieldColorMenu = $tripTable ? array_filter(workspace_trip_field_status_directories(),
    static fn(string $column): bool=>workspace_trip_field_status_can($tripTable,$column,'view'),ARRAY_FILTER_USE_KEY) : [];
$tripStatusNavLabels = ['K'=>'Выгрузка', 'M'=>'Сдача', 'F'=>'Дата загрузки', 'G'=>'Время загрузки'];
$documentStatusNavLabels = [
    'assignment-statuses'=>'Поручения', 'etrn-statuses'=>'ЭТрН', 'etrn-signing-statuses'=>'Подписание ЭТРН',
];
$isColorDirectory = $directory==='flight-statuses' || ($directory!==null && workspace_order_status_directory($directory)!==null) ||
    ($directory==='entries' && workspace_is_document_status_catalog($catalogKind));
$preferences=empty($error) ? workspace_preferences($currentTable) : [];
$isAvtovyvoz = !$directory && $currentTable['slug']==='vyvoz';
$showTripRowOrder = $isAvtovyvoz && !$rowArchive && empty($error) &&
    in_array('F',array_column($data['columns']??[],'letter'),true);
$canReorderTripRows = $showTripRowOrder && $canManageRows;
$quickCatalogs = $isAvtovyvoz && !$rowArchive && empty($error) ? vitrine_quick_catalogs($currentTable) : [];
$loginArrival = $isAvtovyvoz && !$rowArchive && empty($error) && ($_GET['from']??null)==='login';
$arrivalBlocking = $loginArrival ? ' blocking="render"' : '';
$defaultGridWidth = $currentTable['defaultGridWidth'] ?? 'full';
$gridWidth = $preferences['settings']['layoutWidth'] ?? $defaultGridWidth;
$columns = $data['columns'] ?? [];
$rows = $data['rows'] ?? [];
$tripDraft = $data['tripDraft'] ?? null;
$columnClass = static fn(array $column): string => mb_strtolower(trim($column['label'])) === 'время' ? ' vitrine-time-column' : '';
$navFolder = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2H5a2 2 0 0 1 -2 -2V6a2 2 0 0 1 2 -2z"/></svg>';
$navChevron = '<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6 -6 6"/></svg>';
?>
<!doctype html>
<html lang="ru" data-color-preset="<?= ($preferences['colorPreset']['value']??$preferences['defaultColorPreset']['value']??null)==='night' ? 'night' : 'light' ?>">
<head><meta name="app-base-path" content="<?= htmlspecialchars(runtime_config()['APP_BASE_PATH'],ENT_QUOTES,'UTF-8') ?>">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $escape(csrf_token()) ?>">
  <meta id="vitrine-status-data" name="vitrine-status-data" content="<?= $escape(json_encode(['statuses'=>$data['statuses'] ?? [], 'palette'=>$data['palette'] ?? []], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
  <meta id="vitrine-order-statuses" content="<?= $escape(wjson(workspace_active_client_order_statuses())) ?>">
  <?php if (!empty($data['fieldColorLists'])): ?>
  <meta id="vitrine-field-color-statuses" content="<?= $escape(wjson($data['fieldColorLists'])) ?>">
  <?php endif; ?>
  <?php if ($quickCatalogs): ?>
  <meta id="vitrine-quick-catalogs" content="<?= $escape(wjson($quickCatalogs)) ?>">
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-quick-catalog.js?v=quick-catalog-2')) ?>"></script>
  <?php endif; ?>
  <?php if (($currentTable['catalogKind']??null)==='fleet-vehicles'): ?>
  <meta id="vitrine-fleet-edit-groups" content="<?= $escape(wjson(workspace_fleet_edit_groups())) ?>">
  <?php endif; ?>
  <?php if (!empty($data['dropoffEnabled'])): ?>
  <meta id="vitrine-dropoff-options" content="<?= $escape(wjson($data['dropoffOptions'])) ?>">
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-dropoff.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($data['sharedOptions'])): ?>
  <meta id="vitrine-shared-options" data-catalogs="<?= $escape(wjson($data['sharedOptionCatalogs']??[])) ?>" content="<?= $escape(wjson($data['sharedOptions'])) ?>">
  <?php endif; ?>
  <meta id="vitrine-row-manifest" name="vitrine-row-manifest" content="<?= $escape(json_encode($data['rowIds'] ?? [])) ?>">
  <?php if ($isAvtovyvoz && !$rowArchive): ?>
  <meta id="vitrine-trip-date-groups" content="<?= $escape(wjson($data['tripDateGroups']??[])) ?>">
  <?php endif; ?>
  <?php if (!empty($data['containerTypeRules'])): ?>
  <meta id="vitrine-container-type-rules" content="<?= $escape(wjson($data['containerTypeRules'])) ?>">
  <?php endif; ?>
  <script src="<?= htmlspecialchars(app_url_path('/assets/vitrine-theme.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <meta id="workspace-preferences" name="workspace-preferences" content="<?= $escape(wjson($preferences)) ?>">
  <meta name="workspace-event-cursor" content="<?= (int)$eventCursor ?>">
  <meta name="workspace-user" content="<?= (int)$currentUser['id'] ?>">
  <meta name="workspace-table" content="<?= $escape($currentTable['slug']) ?>">
  <meta name="vitrine-load-error" content="<?= $escape($error ?? '') ?>">
  <title><?= $escape($currentTable['title']) ?> — ЛК ДЛТК</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine.css?v=row-deletion-1'),ENT_QUOTES,'UTF-8') ?>">
  <?php if ($loginArrival): ?>
  <link rel="expect" href="#workspace" blocking="render">
  <?php endif; ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-grid-scope.js?v=2')) ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-paths.js'),ENT_QUOTES,'UTF-8') ?>"></script><script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-notifications.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-clipboard-menu.js?v=account-password-1')) ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer<?= $arrivalBlocking ?> src="<?= htmlspecialchars(app_url_path('/assets/vitrine-display.js?v=layout-defaults-1'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (!empty($data['containerTypeRules'])): ?>
  <script defer<?= $arrivalBlocking ?> src="<?= $escape($viewUrl('/assets/vitrine-container-rules.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!$directory): ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-statuses.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php endif; ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-select.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-slot.js?v=quick-catalog-2'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-time-picker.js')) ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-calendar.js')) ?>"></script>
  <?php if ($isAvtovyvoz): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-person-names.js')) ?>"></script>
  <?php endif; ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-editor.js?v=password-cell-2'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (in_array(true,array_column($columns,'tripDriverCard'),true)): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-trip-driver.js')) ?>"></script>
  <?php endif; ?>
  <?php if ($isAvtovyvoz && !$rowArchive): ?>
  <script defer<?= $arrivalBlocking ?> src="<?= $escape($viewUrl('/assets/vitrine-trip-date-groups.js')) ?>"></script>
  <?php endif; ?>
  <script defer<?= $arrivalBlocking ?> src="<?= htmlspecialchars(app_url_path('/assets/vitrine-rows.js?v=row-deletion-1'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if ($canReorderCatalogRows || $showTripRowOrder): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-catalog-order.js?v=2')) ?>"></script>
  <?php endif; ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-live.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-grid-range.js?v=password-cell-1')) ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-range-paste.js?v=account-password-1')) ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-grid.js?v=row-deletion-1'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (!$directory): ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-presence.js?v=short-names-1'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php endif; ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-catalog-search.js?v=quick-catalog-2')) ?>"></script>
  <?php if ($terminalTariffs !== null): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-terminal-tariffs.js')) ?>"></script>
  <?php endif; ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-table-export.js')) ?>"></script>
  <?php if ($canManageDirectory && workspace_directory_excel_supported($directory)): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-directory-import.js')) ?>"></script>
  <?php endif; ?>
  <?php if ($canManageRows || $rowRelatedLabel): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-directory-actions.js?v=row-deletion-1')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['unloadPoints'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-unload-contacts.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientUnloadCatalog']) || !empty($catalogGrid['clientManagers']) || !empty($catalogGrid['standaloneCard'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-relations-keyboard.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientUnloadCatalog'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-unload-points.js?v=row-deletion-1')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientManagers'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-managers.js?v=row-deletion-1')) ?>"></script>
  <?php endif; ?>
  <?php if ($clientCatalog || $directory === 'entries'): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-catalog.js')) ?>"></script>
  <?php elseif ($directory === 'employees'): ?>
  <script defer src="<?= $escape($viewUrl('/assets/workspace-auth.js?v=5')) ?>"></script>
  <?php endif; ?>
</head>
<body class="vitrine<?= $clientCatalog ? ' vitrine-catalog-page' : '' ?>"<?php if ($directory): ?> data-vitrine-catalog="<?= $escape($directory) ?>" data-vitrine-catalog-kind="<?= $escape($currentTable['catalogKind']??'') ?>"<?php endif; ?><?= $isAvtovyvoz && vitrine_copy_diagnostics_enabled() ? ' data-copy-diagnostics' : '' ?> data-workspace-user="<?= (int)$currentUser['id'] ?>" data-workspace-table="<?= $escape($currentTable['slug']) ?>">
<a class="vitrine-skip" href="#workspace">К таблице</a>
<div class="app app--workspace<?= $embeddedCatalog ? ' vitrine-embedded-catalog' : '' ?>">
  <?php if (!$embeddedCatalog): ?>
  <?php $account = workspace_employee_records((int)$currentUser['id'])[0] ?? []; ?>
  <header class="vitrine-topbar">
    <a class="sidebar-brand vitrine-brand" href="<?= htmlspecialchars(app_url_path('/vitrine'),ENT_QUOTES,'UTF-8') ?>"><span class="logo-mark" aria-hidden="true"></span><span class="brand-name">ЛК ДЛТК</span></a>
    <nav class="vitrine-topnav" aria-label="Основная навигация">
      <div class="vitrine-nav-start">
      <?php foreach($workspaceTables as $table): ?>
        <a class="nav-item <?= $currentTable['id']===$table['id']?'is-active':'' ?>" href="<?= htmlspecialchars(app_url_path('/vitrine?table='),ENT_QUOTES,'UTF-8') ?><?= $escape($table['slug']) ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M10 3v18"/></svg><span class="nav-label"><?= $escape($table['title']) ?></span></a>
      <?php endforeach; ?>
      </div>
      <details class="vitrine-folder">
        <summary class="nav-item is-parent<?= $isSystemDirectory ? ' is-active' : '' ?>"><?= $navFolder ?><span class="nav-label">Система</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Система">
          <?php foreach ($systemDirectoryLinks as $key => $label): ?>
          <a class="nav-sub-item<?= $directory === $key ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog='.$key)) ?>"<?= $directory === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
          <?php endforeach; ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=history&table='.$managementTable)) ?>">Журнал изменений</a>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=reports')) ?>">Сводные</a>
          <?php foreach ($systemCatalogMenu as $item): $current=$currentCatalogId===(int)$item['id']; ?>
          <a class="nav-sub-item<?= $current ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$item['id'])) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $escape($item['title']) ?></a>
          <?php endforeach; ?>
          <?php if (!$directory): ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/vitrine?table='.$managementTable.($rowArchive ? '' : '&archive=1'))) ?>"><?= $rowArchive ? 'Вернуться к таблице' : 'Архив строк' ?></a>
          <?php if (!$rowArchive && $canManageRows): ?>
          <button class="nav-sub-item" type="button" id="workspace-new-row">Добавить строку</button>
          <?php endif; ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=history&table='.$managementTable)) ?>" id="workspace-row-history">История строки</a>
          <?php endif; ?>
        </div>
      </details>
      <details class="vitrine-folder" id="vitrine-display-menu">
        <summary class="nav-item is-parent"><?= $navFolder ?><span class="nav-label">Отображение данных</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Отображение данных">
          <a class="nav-sub-item" href="#column-order" data-display-open="columns">Порядок столбцов</a>
          <a class="nav-sub-item" href="#color-presets" data-display-open="colors">Цветовые пресеты</a>
          <a class="nav-sub-item" href="#table-lines" data-display-open="lines">Линии таблицы</a>
          <a class="nav-sub-item" href="#grid-width" data-display-open="width">Ширина блока</a>
        </div>
      </details>
      <?php if ($canOpenStatuses || $canViewClientColors || $tripFieldColorMenu || $documentColorMenu): ?>
      <details class="vitrine-folder" id="vitrine-cell-colors">
        <summary class="nav-item is-parent<?= $isColorDirectory ? ' is-active' : '' ?>"><?= $navFolder ?><span class="nav-label">Статусы</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Статусы">
          <?php if ($canOpenStatuses): ?>
          <a class="nav-sub-item<?= $directory === 'flight-statuses' ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=flight-statuses&table='.$managementTable)) ?>"<?= $directory === 'flight-statuses' ? ' aria-current="page"' : '' ?>>Рейсы</a>
          <?php endif; ?>
          <?php if ($canViewClientColors): ?>
          <a class="nav-sub-item<?= $directory === 'client-order-statuses' ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=client-order-statuses')) ?>"<?= $directory === 'client-order-statuses' ? ' aria-current="page"' : '' ?>>Клиенты</a>
          <?php endif; ?>
          <?php foreach ($tripFieldColorMenu as $column => $item): ?>
          <a class="nav-sub-item<?= $directory === $item['directory'] ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog='.$item['directory'])) ?>"<?= $directory === $item['directory'] ? ' aria-current="page"' : '' ?>><?= $escape($tripStatusNavLabels[$column]) ?></a>
          <?php endforeach; ?>
          <?php foreach ($documentColorMenu as $item): $current=$currentCatalogId===(int)$item['id']; ?>
          <a class="nav-sub-item<?= $current ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$item['id'])) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $escape($documentStatusNavLabels[$item['kind']]) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <details class="vitrine-folder" id="vitrine-catalogs">
        <summary class="nav-item is-parent<?= $directory && !$isColorDirectory && !$isSystemDirectory ? ' is-active' : '' ?>"><?= $navFolder ?><span class="nav-label">Справочники</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Справочники">
          <?php if (workspace_client_grid_catalog()): ?>
          <a class="nav-sub-item<?= $clientCatalog ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=clients')) ?>"<?= $clientCatalog ? ' aria-current="page"' : '' ?>>Клиенты</a>
          <?php endif; ?>
          <?php $managerCatalog=workspace_manager_catalog_id(); if ($managerCatalog && workspace_can('catalog',(string)$managerCatalog,'view')):
              $managerCurrent=$directory==='entries'&&($catalogGrid['id']??0)===$managerCatalog; ?>
          <a class="nav-sub-item<?= $managerCurrent ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$managerCatalog)) ?>"<?= $managerCurrent ? ' aria-current="page"' : '' ?>>Сотрудники</a>
          <?php endif; ?>
          <?php foreach ($boundCatalogMenu as $item):
            $current=$currentCatalogId===(int)$item['id'];
          ?>
          <a class="nav-sub-item<?= $current ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$item['id'])) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $escape($item['title']) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    </nav>
    <details class="vitrine-folder vitrine-account">
      <summary class="nav-item is-parent">
        <span class="user-info">
          <strong><?= $escape($currentUser['name'] ?: $currentUser['login']) ?></strong>
          <?php if (!empty($account['roles'])): ?><span><?= $escape(implode(', ', array_column($account['roles'],'name'))) ?></span><?php endif; ?>
        </span>
        <?= $navChevron ?>
      </summary>
      <div class="vitrine-dropdown" aria-label="Учётная запись">
        <button class="nav-sub-item" type="button" id="workspace-logout">Выйти</button>
      </div>
    </details>
    <span class="vitrine-sr-only" data-workspace-live data-live-status aria-live="polite"></span>
  </header>
  <?php endif; ?>
  <main class="content ui-detail-card" id="workspace" tabindex="-1">
    <?php if (!empty($error)): ?>
    <section class="vitrine-message" role="alert"><h2>Не удалось загрузить таблицу</h2><p><?= $escape($error) ?></p><p>Обновите страницу, чтобы повторить загрузку.</p></section>
    <?php else: ?>
    <?php if ($terminalTariffs !== null): ?>
      <?php require __DIR__.'/vitrine-terminal-tariffs.php'; ?>
    <?php else: ?>
    <?php if ($directory): ?>
      <?php require __DIR__.'/vitrine-directory-toolbar.php'; ?>
    <?php else: ?>
    <section class="vitrine-catalog-toolbar" aria-label="Управление таблицей">
      <h1>Таблица: <strong><?= $escape($currentTable['title']) ?></strong></h1>
      <?php if ($currentTable['slug']==='vyvoz'): ?>
      <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>">
        <input type="hidden" name="table" value="vyvoz">
        <?php if ($rowArchive): ?><input type="hidden" name="archive" value="1"><?php endif; ?>
        <?php require __DIR__.'/vitrine-archive-filter.php'; ?>
        <?php
        $tripFilters=[
            'tripStatus'=>['A','Статус рейса','Все статусы',array_column($data['statuses'],'name')],
            'tripClient'=>['B','Клиент','Все клиенты',$data['sharedOptions']['B']??[]],
        ];
        foreach ($tripFilters as $parameter=>[$column,$label,$all,$choices]):
            if (!workspace_can('field',$column,'view',$currentTable['id'])) continue;
            $options=[['value'=>'all','label'=>$all]];
            foreach ($choices as $choice) {
                $options[]=is_array($choice) ? $choice : ['value'=>$choice,'label'=>$choice];
            }
        ?>
        <div class="vitrine-directory-visibility" data-vitrine-filter-param="<?= $escape($parameter) ?>"
             data-filter-label="<?= $escape($label) ?>" data-filter-options="<?= $escape(wjson($options)) ?>"></div>
        <?php endforeach; ?>
        <div class="vitrine-search-field">
          <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape(workspace_vyvoz_filter('q')) ?>"
                 aria-label="Поиск по рейсам" placeholder="Поиск по рейсам">
          <span class="vitrine-search-progress" data-vitrine-search-progress role="status" hidden>
            <span class="ui-spinner" aria-hidden="true"></span>
            <span class="vitrine-sr-only">Поиск рейсов…</span>
          </span>
        </div>
        <?php require __DIR__.'/vitrine-export-button.php'; ?>
      </form>
      <?php else: ?>
      <div class="vitrine-grid-tools">
        <?php require __DIR__.'/vitrine-archive-filter.php'; ?>
        <?php require __DIR__.'/vitrine-export-button.php'; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canManageRows || $rowRelatedLabel) require __DIR__.'/vitrine-directory-row-actions.php';
    if ($canManageRows) require __DIR__.'/vitrine-directory-row-dialogs.php';
    require __DIR__.'/vitrine-clear-cell-dialog.php';
    require __DIR__.'/vitrine-hide-column-dialog.php';
    if (in_array(true,array_column($columns,'tripDriverCard'),true)) require __DIR__.'/vitrine-trip-driver-dialog.php'; ?>
    <?php require __DIR__.'/vitrine-table.php'; ?>
    <?php if ($quickCatalogs): ?>
    <dialog class="vitrine-catalog-dialog vitrine-quick-catalog" data-vitrine-quick-catalog aria-label="Быстрое редактирование">
      <iframe title="Справочник"></iframe>
      <footer class="modal-foot"><button type="button" class="btn btn-secondary" data-quick-close autofocus>Закрыть</button></footer>
    </dialog>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
