<?php
declare(strict_types=1);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentUser=workspace_current_user();
$currentTable=$data['table']??workspace_table();
$workspaceTables=workspace_tables();
$preferences=workspace_preferences($currentTable);
$columns = $data['columns'] ?? [];
$rows = $data['rows'] ?? [];
$columnClass = static fn(array $column): string => mb_strtolower(trim($column['label'])) === 'время' ? ' vitrine-time-column' : '';
?>
<!doctype html>
<html lang="ru">
<head><meta name="app-base-path" content="<?= htmlspecialchars(runtime_config()['APP_BASE_PATH'],ENT_QUOTES,'UTF-8') ?>">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="csrf-token" content="<?= $escape(csrf_token()) ?>">
  <meta id="vitrine-status-data" name="vitrine-status-data" content="<?= $escape(json_encode(['statuses'=>$data['statuses'] ?? [], 'palette'=>$data['palette'] ?? []], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">
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
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-paths.js'),ENT_QUOTES,'UTF-8') ?>"></script><script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-notifications.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-display.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-statuses.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-select.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-editor.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/vitrine-rows.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-live.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-grid.js'),ENT_QUOTES,'UTF-8') ?>"></script>
  <script defer src="<?= htmlspecialchars(app_url_path('/assets/workspace-presence.js'),ENT_QUOTES,'UTF-8') ?>"></script>
</head>
<body class="vitrine" data-workspace-user="<?= (int)$currentUser['id'] ?>" data-workspace-table="<?= $escape($currentTable['slug']) ?>">
<a class="vitrine-skip" href="#workspace">К таблице</a>
<div class="app app--workspace">
  <header class="vitrine-topbar">
    <a class="sidebar-brand vitrine-brand" href="<?= htmlspecialchars(app_url_path('/vitrine'),ENT_QUOTES,'UTF-8') ?>"><span class="logo-mark" aria-hidden="true"></span><span class="brand-name">ЛК ДЛТК</span></a>
    <nav class="vitrine-topnav" aria-label="Основная навигация">
      <div class="vitrine-nav-start">
      <?php foreach($workspaceTables as $table): ?>
        <a class="nav-item <?= $currentTable['id']===$table['id']?'is-active':'' ?>" href="<?= htmlspecialchars(app_url_path('/vitrine?table='),ENT_QUOTES,'UTF-8') ?><?= $escape($table['slug']) ?>" title="<?= $escape($table['title']) ?>"><span class="nav-label"><?= $escape($table['title']) ?></span></a>
      <?php endforeach; ?>
      </div>
      <details class="vitrine-folder">
        <summary class="nav-item is-parent"><span class="nav-label">Система</span></summary>
        <div class="vitrine-dropdown">
          <a class="nav-sub-item" href="<?= htmlspecialchars(app_url_path('/workspace?section=history&table='),ENT_QUOTES,'UTF-8') ?><?= $escape($currentTable['slug']) ?>">Журнал изменений</a>
          <a class="nav-sub-item" href="<?= htmlspecialchars(app_url_path('/workspace?section=reports'),ENT_QUOTES,'UTF-8') ?>">Сводные</a>
          <a class="nav-sub-item" href="<?= htmlspecialchars(app_url_path('/workspace?section=users'),ENT_QUOTES,'UTF-8') ?>">Сотрудники и роли</a>
          <button class="nav-sub-item" type="button" id="workspace-new-row">Добавить строку</button>
          <a class="nav-sub-item" href="<?= htmlspecialchars(app_url_path('/workspace?section=history&table='),ENT_QUOTES,'UTF-8') ?><?= $escape($currentTable['slug']) ?>" id="workspace-row-history">История строки</a>
          <div class="nav-sub-item" title="<?= $escape(implode(', ',$currentUser['roles'])) ?>"><?= $escape($currentUser['login']) ?> <span data-workspace-live data-live-status aria-live="polite"></span></div>
          <button class="nav-sub-item" type="button" id="workspace-logout">Выйти</button>
        </div>
      </details>
      <details class="vitrine-folder" id="vitrine-display-menu">
        <summary class="nav-item is-parent"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7V4h6l2 3h10v13H3z"/></svg><span class="nav-label">Отображение данных</span><svg class="vitrine-chevron" aria-hidden="true" viewBox="0 0 16 16"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></summary>
        <div class="vitrine-dropdown" aria-label="Отображение данных">
          <a class="nav-sub-item" href="#column-order" data-display-open="columns">Порядок столбцов</a>
          <a class="nav-sub-item" href="#color-presets" data-display-open="colors">Цветовые пресеты</a>
          <a class="nav-sub-item" href="#table-lines" data-display-open="lines">Линии таблицы</a>
        </div>
      </details>
      <details class="vitrine-folder" id="vitrine-catalogs">
        <summary class="nav-item is-parent"><svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7V4h6l2 3h10v13H3z"/></svg><span class="nav-label">Справочники</span><svg class="vitrine-chevron" aria-hidden="true" viewBox="0 0 16 16"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></summary>
        <div class="vitrine-dropdown" aria-label="Справочники">
          <a class="nav-sub-item" id="vitrine-open-statuses" href="#statuses"><span>Статусы</span></a>
          <a class="nav-sub-item" href="<?= htmlspecialchars(app_url_path('/workspace?section=catalogs&table='),ENT_QUOTES,'UTF-8') ?><?= $escape($currentTable['slug']) ?>">Все справочники</a>
        </div>
      </details>
    </nav>
  </header>
  <main class="content ui-detail-card" id="workspace" tabindex="-1">
    <?php if (!empty($error)): ?>
    <section class="vitrine-message" role="alert"><h2>Не удалось загрузить таблицу</h2><p><?= $escape($error) ?></p><p>Обновите страницу, чтобы повторить загрузку.</p></section>
    <?php else: ?>
    <section class="table-card vitrine-table-card" aria-label="Последние строки Вывоза">
      <div class="table-scroll vitrine-table-scroll" tabindex="0" role="region" aria-label="Таблица, прокрутка по горизонтали и вертикали">
        <table class="table vitrine-table">
          <caption class="vitrine-sr-only">Последние непустые строки листа <?= $escape($data['sheetTitle'] ?? 'ВЫВОЗ') ?>. Все исходные столбцы.</caption>
          <colgroup><col class="vitrine-row-column"><?php foreach ($columns as $column): ?><col class="vitrine-data-column<?= $columnClass($column) ?>" data-column="<?= $escape($column['letter']) ?>"><?php endforeach; ?></colgroup>
          <thead><tr><th scope="col" class="vitrine-row-number">Строка</th>
            <?php foreach ($columns as $column): ?>
              <th scope="col" data-column="<?= $escape($column['letter']) ?>" class="<?= trim($columnClass($column)) ?>" title="<?= $escape($column['label']) ?>">
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
        </table>
        <div class="vitrine-message vitrine-empty" id="vitrine-empty" <?= count($rows) ? 'hidden' : '' ?> role="status"><?= count($rows) ? 'По вашему запросу ничего не найдено.' : 'В таблице пока нет строк для отображения.' ?></div>
      </div>
    </section>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
