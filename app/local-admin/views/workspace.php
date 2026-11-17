<?php
declare(strict_types=1);
$escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= $escape(csrf_token()) ?>"><meta name="workspace-event-cursor" content="<?= (int)wquery('SELECT COALESCE(MAX(id),0) FROM outbox_events')->fetchColumn() ?>"><title>Рабочая система — ЛК ДЛТК</title>
<link rel="stylesheet" href="/assets/vitrine/palette.css"><link rel="stylesheet" href="/assets/vitrine-base.css"><link rel="stylesheet" href="/assets/vitrine.css"><link rel="stylesheet" href="/assets/vitrine-select.css"><link rel="stylesheet" href="/assets/vitrine-statuses.css"><link rel="stylesheet" href="/assets/workspace.css">
<script defer src="/assets/vitrine-notifications.js"></script><script defer src="/assets/vitrine-select.js"></script><script defer src="/assets/workspace.js"></script><script defer src="/assets/workspace-live.js"></script></head>
<body class="vitrine workspace"><header class="ws-header"><a class="ws-brand" href="/vitrine">ЛК ДЛТК</a><nav id="ws-tables" aria-label="Рабочие таблицы"></nav><span class="ws-user" id="ws-user"></span><form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= $escape(csrf_token()) ?>"><button class="btn" type="submit">Выйти</button></form></header>
<nav class="ws-sections" aria-label="Разделы"><a href="/workspace?section=history">Журнал изменений</a><a href="/workspace?section=catalogs">Справочники</a><a href="/workspace?section=reports">Сводные</a><a href="/workspace?section=users">Сотрудники и роли</a></nav>
<main class="ws-main"><div class="ws-caption"><h1 id="ws-title">Загрузка…</h1><span id="ws-connection" data-live-status>Серверный стенд · БД</span></div><div id="ws-content" aria-live="polite"></div></main></body></html>
