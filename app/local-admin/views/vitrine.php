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
<head>
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
  <link rel="stylesheet" href="/assets/vitrine/palette.css">
  <link rel="stylesheet" href="/assets/vitrine-base.css">
  <link rel="stylesheet" href="/assets/vitrine.css">
  <link rel="stylesheet" href="/assets/vitrine-editor.css">
  <link rel="stylesheet" href="/assets/vitrine-select.css">
  <link rel="stylesheet" href="/assets/vitrine-statuses.css">
  <link rel="stylesheet" href="/assets/vitrine-display.css">
  <link rel="stylesheet" href="/assets/vitrine-rows.css">
  <script defer src="/assets/vitrine-notifications.js"></script>
  <script defer src="/assets/vitrine.js"></script>
  <script defer src="/assets/vitrine-display.js"></script>
  <script defer src="/assets/vitrine-statuses.js"></script>
  <script defer src="/assets/vitrine-select.js"></script>
  <script defer src="/assets/vitrine-editor.js"></script>
  <script defer src="/assets/vitrine-rows.js"></script>
  <script defer src="/assets/workspace-live.js"></script>
  <script defer src="/assets/workspace-grid.js"></script>
  <script defer src="/assets/workspace-presence.js"></script>
</head>
<body class="vitrine" data-workspace-user="<?= (int)$currentUser['id'] ?>" data-workspace-table="<?= $escape($currentTable['slug']) ?>">
<a class="vitrine-skip" href="#workspace">К таблице</a>
<div class="app app--workspace">
  <header class="vitrine-topbar">
    <a class="sidebar-brand vitrine-brand" href="/vitrine"><span class="logo-mark" aria-hidden="true"></span><span class="brand-name">ЛК ДЛТК</span></a>
    <nav class="vitrine-topnav" aria-label="Основная навигация">
      <div class="vitrine-nav-start">
      <?php foreach($workspaceTables as $table): ?>
        <a class="nav-item <?= $currentTable['id']===$table['id']?'is-active':'' ?>" href="/vitrine?table=<?= $escape($table['slug']) ?>" title="<?= $escape($table['title']) ?>"><span class="nav-label"><?= $escape($table['title']) ?></span></a>
      <?php endforeach; ?>
      </div>
      <details class="vitrine-folder">
        <summary class="nav-item is-parent"><span class="nav-label">Система</span></summary>
        <div class="vitrine-dropdown">
          <a class="nav-sub-item" href="/workspace?section=history&table=<?= $escape($currentTable['slug']) ?>">Журнал изменений</a>
          <a class="nav-sub-item" href="/workspace?section=reports">Сводные</a>
          <a class="nav-sub-item" href="/workspace?section=users">Сотрудники и роли</a>
          <button class="nav-sub-item" type="button" id="workspace-new-row">Добавить строку</button>
          <a class="nav-sub-item" href="/workspace?section=history&table=<?= $escape($currentTable['slug']) ?>" id="workspace-row-history">История строки</a>
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
          <a class="nav-sub-item" href="/workspace?section=catalogs&table=<?= $escape($currentTable['slug']) ?>">Все справочники</a>
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
          <thead><tr><th scope="col" class="vitrine-row-number">Строка</th><?php foreach ($columns as $column): ?><th scope="col" data-column="<?= $escape($column['letter']) ?>" class="<?= trim($columnClass($column)) ?>" title="<?= $escape($column['label']) ?>"><span class="vitrine-column-letter"><?= $escape($column['letter']) ?></span><span class="vitrine-column-label"><?= $escape($column['label']) ?></span></th><?php endforeach; ?></tr></thead>
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
