<?php
declare(strict_types=1);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentUser=workspace_current_user();
$directory = $directory ?? null;
$clientCatalog = $directory === 'clients';
$catalogGrid = $data['catalogGrid'] ?? null;
$canManageDirectory = $catalogGrid['canEdit'] ?? false;
$canManageRows = $canManageDirectory || (!$directory && !empty($data['table']['id']) &&
    workspace_can('table',(string)$data['table']['id'],'edit',$data['table']['id']));
$rowArchive = $catalogGrid['archive'] ?? (($_GET['archive']??null)==='1');
$rowRelatedLabel = $directory ? ($catalogGrid['relatedLabel']??null) : 'История строки';
$showDraftRows = $canManageDirectory && !$catalogGrid['archive'];
$viewUrl = static fn(string $path): string => function_exists('app_url_path') ? app_url_path($path) : $path;
$currentTable = $data['table'] ?? ($directory ? ['id'=>0,'slug'=>'catalog-'.$directory,'title'=>['clients'=>'Клиенты','employees'=>'Учётные записи','roles'=>'Роли','client-order-statuses'=>'Статусы клиентов','flight-statuses'=>'Статусы рейса','entries'=>'Справочник'][$directory]] : workspace_table());
$managementTable = $currentTable['sourceTable'] ?? ($directory ? 'vyvoz' : $currentTable['slug']);
$directoryUrl = $catalogGrid ? '/vitrine?catalog='.$directory.match ($directory) {
    'entries' => '&id='.$catalogGrid['id'],
    'flight-statuses' => '&table='.$managementTable,
    default => '',
} : null;
$workspaceTables=workspace_tables();
$boundCatalogMenu=vitrine_bound_catalog_menu($workspaceTables);
$canOpenStatuses = !$directory || in_array('vyvoz',array_column($workspaceTables,'slug'),true);
$canViewClientColors = workspace_can('system','client-order-statuses','view');
$preferences=empty($error) ? workspace_preferences($currentTable) : [];
$defaultGridWidth = $currentTable['defaultGridWidth'] ?? 'full';
$gridWidth = $preferences['settings']['layoutWidth'] ?? $defaultGridWidth;
$columns = $data['columns'] ?? [];
$rows = $data['rows'] ?? [];
$columnClass = static fn(array $column): string => mb_strtolower(trim($column['label'])) === 'время' ? ' vitrine-time-column' : '';
$navFolder = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2H5a2 2 0 0 1 -2 -2V6a2 2 0 0 1 2 -2z"/></svg>';
$navChevron = '<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6 -6 6"/></svg>';
?>
<!doctype html>
<html lang="ru">
<head><meta name="app-base-path" content="<?= htmlspecialchars(runtime_config()['APP_BASE_PATH'],ENT_QUOTES,'UTF-8') ?>">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="csrf-token" content="<?= $escape(csrf_token()) ?>">
  <meta id="vitrine-status-data" name="vitrine-status-data" content="<?= $escape(json_encode(['statuses'=>$data['statuses'] ?? [], 'palette'=>$data['palette'] ?? []], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
  <meta id="vitrine-order-statuses" content="<?= $escape(wjson(workspace_active_client_order_statuses())) ?>">
  <?php if (!empty($data['dropoffEnabled'])): ?>
  <meta id="vitrine-dropoff-options" content="<?= $escape(wjson($data['dropoffOptions'])) ?>">
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-dropoff.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty(array_filter($data['fleetOptions']??[]))): ?>
  <meta id="vitrine-fleet-options" content="<?= $escape(wjson($data['fleetOptions'])) ?>">
  <?php endif; ?>
  <meta id="vitrine-row-manifest" name="vitrine-row-manifest" content="<?= $escape(json_encode($data['rowIds'] ?? [])) ?>">
  <meta id="workspace-preferences" name="workspace-preferences" content="<?= $escape(wjson($preferences)) ?>">
  <meta name="workspace-event-cursor" content="<?= (int)$eventCursor ?>">
  <meta name="workspace-user" content="<?= (int)$currentUser['id'] ?>">
  <meta name="workspace-table" content="<?= $escape($currentTable['slug']) ?>">
  <meta name="vitrine-load-error" content="<?= $escape($error ?? '') ?>">
  <title><?= $escape($currentTable['title']) ?> — ЛК ДЛТК</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine/palette.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-base.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-editor.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-select.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-statuses.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-display.css'),ENT_QUOTES,'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url_path('/assets/vitrine-rows.css'),ENT_QUOTES,'UTF-8') ?>">
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-grid-scope.js')) ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-paths.js'),ENT_QUOTES,'UTF-8') ?>"></script><script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-notifications.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-display.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (!$directory): ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-statuses.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php endif; ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-select.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-slot.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (($data['table']['catalogKind']??null)==='fleet-vehicles' && in_array('J',array_column($data['columns'],'letter'),true)): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-fleet-documents.js')) ?>"></script>
  <?php endif; ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-editor.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-rows.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-live.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-grid.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php if (!$directory): ?>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-presence.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= $escape($viewUrl('/assets/vitrine-catalog.css')) ?>">
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-catalog-search.js')) ?>"></script>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-table-export.js')) ?>"></script>
  <?php if ($canManageDirectory && workspace_directory_excel_supported($directory)): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-directory-import.js')) ?>"></script>
  <?php endif; ?>
  <?php if ($canManageRows || $rowRelatedLabel): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-directory-actions.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['unloadPoints'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-unload-contacts.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientUnloadCatalog']) || !empty($catalogGrid['clientManagers'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-relations-keyboard.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientUnloadCatalog'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-unload-points.js')) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientManagers'])): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-managers.js')) ?>"></script>
  <?php endif; ?>
  <?php if ($clientCatalog || $directory === 'entries'): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-client-catalog.js')) ?>"></script>
  <?php elseif ($directory === 'employees'): ?>
  <script defer src="<?= $escape($viewUrl('/assets/vitrine-employees.js')) ?>"></script>
  <?php endif; ?>
</head>
<body class="vitrine<?= $clientCatalog ? ' vitrine-catalog-page' : '' ?>"<?php if ($directory): ?> data-vitrine-catalog="<?= $escape($directory) ?>"<?php endif; ?> data-workspace-user="<?= (int)$currentUser['id'] ?>" data-workspace-table="<?= $escape($currentTable['slug']) ?>">
<a class="vitrine-skip" href="#workspace">К таблице</a>
<div class="app app--workspace">
  <header class="vitrine-topbar">
    <a class="sidebar-brand vitrine-brand" href="<?= htmlspecialchars(app_url_path('/vitrine'),ENT_QUOTES,'UTF-8') ?>"><span class="logo-mark" aria-hidden="true"></span><span class="brand-name">ЛК ДЛТК</span></a>
    <nav class="vitrine-topnav" aria-label="Основная навигация">
      <div class="vitrine-nav-start">
      <?php foreach($workspaceTables as $table): ?>
        <a class="nav-item <?= $currentTable['id']===$table['id']?'is-active':'' ?>" href="<?= htmlspecialchars(app_url_path('/vitrine?table='),ENT_QUOTES,'UTF-8') ?><?= $escape($table['slug']) ?>" title="<?= $escape($table['title']) ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M10 3v18"/></svg><span class="nav-label"><?= $escape($table['title']) ?></span></a>
      <?php endforeach; ?>
      </div>
      <details class="vitrine-folder">
        <summary class="nav-item is-parent"><?= $navFolder ?><span class="nav-label">Система</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown">
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=history&table='.$managementTable)) ?>">Журнал изменений</a>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=reports')) ?>">Сводные</a>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=catalogs&table='.$managementTable)) ?>">Управление справочниками</a>
          <?php if ($directoryUrl && $directory !== 'clients' && ($currentTable['catalogKind'] ?? '') !== 'managers'): ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl($directoryUrl.($catalogGrid['archive'] ? '' : '&archive=1').(empty($catalogGrid['clientFilter']) ? '' : '&client='.(int)$catalogGrid['clientFilter']['id']))) ?>"><?= $catalogGrid['archive'] ? 'Вернуться к справочнику' : 'Архив справочника' ?></a>
          <?php endif; ?>
          <?php if (!$directory): ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/vitrine?table='.$managementTable.($rowArchive ? '' : '&archive=1'))) ?>"><?= $rowArchive ? 'Вернуться к таблице' : 'Архив строк' ?></a>
          <?php if (!$rowArchive && $canManageRows): ?>
          <button class="nav-sub-item" type="button" id="workspace-new-row">Добавить строку</button>
          <?php endif; ?>
          <a class="nav-sub-item" href="<?= $escape($viewUrl('/workspace?section=history&table='.$managementTable)) ?>" id="workspace-row-history">История строки</a>
          <?php endif; ?>
          <div class="nav-sub-item" title="<?= $escape(implode(', ',$currentUser['roles'])) ?>"><?= $escape($currentUser['login']) ?> <span data-workspace-live data-live-status aria-live="polite"></span></div>
          <button class="nav-sub-item" type="button" id="workspace-logout">Выйти</button>
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
      <?php if ($canOpenStatuses || $canViewClientColors): ?>
      <details class="vitrine-folder" id="vitrine-cell-colors">
        <summary class="nav-item is-parent<?= in_array($directory,['client-order-statuses','flight-statuses'],true) ? ' is-active' : '' ?>"><?= $navFolder ?><span class="nav-label">Цвета ячеек</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Цвета ячеек">
          <?php if ($canOpenStatuses): ?>
          <a class="nav-sub-item<?= $directory === 'flight-statuses' ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=flight-statuses&table='.$managementTable)) ?>"<?= $directory === 'flight-statuses' ? ' aria-current="page"' : '' ?>>Статусы</a>
          <?php endif; ?>
          <?php if ($canViewClientColors): ?>
          <a class="nav-sub-item<?= $directory === 'client-order-statuses' ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=client-order-statuses')) ?>"<?= $directory === 'client-order-statuses' ? ' aria-current="page"' : '' ?>>Клиенты</a>
          <?php endif; ?>
        </div>
      </details>
      <?php endif; ?>
      <details class="vitrine-folder" id="vitrine-catalogs">
        <summary class="nav-item is-parent<?= $directory && !in_array($directory,['client-order-statuses','flight-statuses'],true) ? ' is-active' : '' ?>"><?= $navFolder ?><span class="nav-label">Справочники</span><?= $navChevron ?></summary>
        <div class="vitrine-dropdown" aria-label="Справочники">
          <?php $directoryLinks = ['clients'=>'Клиенты', 'employees'=>'Учётные записи', 'roles'=>'Роли']; ?>
          <?php if (!workspace_client_grid_catalog()) unset($directoryLinks['clients']); ?>
          <?php if (!workspace_can('system','users','view')) unset($directoryLinks['employees']); ?>
          <?php if (!workspace_can('system','roles','view')) unset($directoryLinks['roles']); ?>
          <?php foreach ($directoryLinks as $key => $label): ?>
          <a class="nav-sub-item<?= $directory === $key ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog='.$key)) ?>"<?= $directory === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
          <?php endforeach; ?>
          <?php $managerCatalog=workspace_manager_catalog_id(); if ($managerCatalog && workspace_can('catalog',(string)$managerCatalog,'view')):
              $managerCurrent=$directory==='entries'&&($catalogGrid['id']??0)===$managerCatalog; ?>
          <a class="nav-sub-item<?= $managerCurrent ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$managerCatalog)) ?>"<?= $managerCurrent ? ' aria-current="page"' : '' ?>>Сотрудники</a>
          <?php endif; ?>
          <?php foreach ($boundCatalogMenu as $item):
            $current=$directory==='entries'&&($catalogGrid['id']??0)===$item['id'];
          ?>
          <a class="nav-sub-item<?= $current ? ' is-active' : '' ?>" href="<?= $escape($viewUrl('/vitrine?catalog=entries&id='.$item['id'])) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $escape($item['title']) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    </nav>
  </header>
  <main class="content ui-detail-card" id="workspace" tabindex="-1">
    <?php if (!empty($error)): ?>
    <section class="vitrine-message" role="alert"><h2>Не удалось загрузить таблицу</h2><p><?= $escape($error) ?></p><p>Обновите страницу, чтобы повторить загрузку.</p></section>
    <?php else: ?>
    <?php if ($directory): ?>
      <?php require __DIR__.'/vitrine-directory-toolbar.php'; ?>
    <?php else: ?>
    <section class="vitrine-catalog-toolbar" aria-label="Управление таблицей">
      <h1>Таблица: <strong><?= $escape($currentTable['title']) ?></strong></h1>
      <div class="vitrine-grid-tools">
        <?php require __DIR__.'/vitrine-archive-filter.php'; ?>
        <?php require __DIR__.'/vitrine-export-button.php'; ?>
      </div>
    </section>
    <?php endif; ?>
    <?php if ($canManageRows || $rowRelatedLabel) require __DIR__.'/vitrine-directory-row-actions.php';
    if ($canManageRows) require __DIR__.'/vitrine-directory-row-dialogs.php';
    if (($data['table']['catalogKind']??null)==='fleet-vehicles' && in_array('J',array_column($columns,'letter'),true)) {
        require __DIR__.'/vitrine-fleet-documents.php';
    } ?>
    <div class="vitrine-grid-region" data-grid-width="<?= $escape($gridWidth) ?>" data-grid-default-width="<?= $escape($defaultGridWidth) ?>">
    <section class="table-card vitrine-table-card" aria-label="<?= $escape($directory ? 'Справочник «'.$currentTable['title'].'»' : $currentTable['title']) ?>">
      <div class="table-scroll vitrine-table-scroll" tabindex="0" role="region" aria-label="Таблица, прокрутка по горизонтали и вертикали">
        <table class="table vitrine-table<?= !empty($catalogGrid['unloadPoints']) ? ' vitrine-unload-points' : '' ?>">
          <caption class="vitrine-sr-only"><?php if ($directory): ?>Справочник «<?= $escape($currentTable['title']) ?>»<?php else: ?>Последние непустые строки листа <?= $escape($data['sheetTitle'] ?? 'ВЫВОЗ') ?>. Все исходные столбцы.<?php endif; ?></caption>
          <colgroup><col class="vitrine-row-column"><?php foreach ($columns as $column): ?><col class="vitrine-data-column<?= $columnClass($column) ?>" data-column="<?= $escape($column['letter']) ?>"><?php endforeach; ?></colgroup>
          <thead><tr><th scope="col" class="vitrine-row-number">Строка</th>
            <?php foreach ($columns as $column): ?>
              <th scope="col" data-column="<?= $escape($column['letter']) ?>"<?= ($column['export']??true) ? '' : ' data-no-export' ?> class="<?= trim($columnClass($column)) ?>" title="<?= $escape($column['label']) ?>">
                <span class="vitrine-column-letter"><?= $escape($column['letter']) ?><?php if (isset($column['derivedSource'])): ?>
                  <span class="vitrine-derived-icon" role="img" tabindex="0" aria-label="<?= $escape($column['derivedSource']) ?>" data-source-label="<?= $escape($column['derivedSource']) ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7L12 5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19"/></svg>
                  </span><?php endif; ?></span>
                <span class="vitrine-column-label"><?= $escape($column['label']) ?></span>
              </th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody id="vitrine-rows">
          <?php require __DIR__.'/vitrine-rows.php'; ?>
          </tbody>
          <?php if ($showDraftRows) require __DIR__.'/vitrine-directory-drafts.php'; ?>
        </table>
        <div class="vitrine-message vitrine-empty" id="vitrine-empty" <?= count($rows) || ($showDraftRows && $catalogGrid['search'] === '') ? 'hidden' : '' ?> role="status"><?= ($catalogGrid ? $catalogGrid['search'] !== '' : count($rows) > 0) ? 'По вашему запросу ничего не найдено.' : 'В таблице пока нет строк для отображения.' ?></div>
      </div>
    </section>
    </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
