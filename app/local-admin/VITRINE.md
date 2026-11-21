# Server copy of DLTK V3 vitrine

PHP-FPM public root: app/local-admin/public. Requests route through public/index.php; Nginx serves assets directly. Only the working DB is used. Runtime config is in a private [workspace] INI file selected by DLTK_CONFIG_FILE. Keep config/runtime.ini, data exports, passwords and backups out of Git. UI components/styles and current keyboard/editing/live-presence behavior are preserved from the local project.

Runtime contract: [workspace] INI, APP_URL/WS_URL/DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/PRIVATE_DIR. DLTK_CONFIG_FILE selects the private file. APP_URL is a root origin (a separate virtual host/port), not a subdirectory. Preserve the incoming Host header at Nginx. Cookie name is dltk_v3_server and Secure is enabled for HTTPS APP_URL. /api/health returns dltk-v3-server-v1 and checks only the working DB. Unknown legacy source/admin routes return404.

Portable QA: all PHP and JavaScript syntax checks,66 format checks and9 HTTP integration checks passed locally using a dedicated temporary server process. The latter exercised health, distinct cookie, account login,6000-row grid,5 tables/6 roles, configured WebSocket URL, Host/Origin rejection and removed legacy route. No business values were written. Actual Linux deployment/performance remain separate acceptance steps.

## Клиент и менеджер в «Вывозе»

Для включения сценария справочник с `kind='clients'` привязывается к полю B таблицы `vyvoz`. Связь с менеджером хранится в `workspace_meta` под ключом `catalog_managers_<id справочника>` как JSON-объект, где ключи — ID записей справочника, а значения — имена менеджеров. Список использует общий `VitrineSelect`.

Выбранный клиент и вычисленное AL сохраняются в одной транзакции. При пустом B или отсутствии менеджера AL очищается. Существующая строка пересчитывается при сохранении B. Для AL пишутся отдельные версия, аудит и событие. Ответ сохранения возвращает обновлённую ячейку AL клиенту, который инициировал операцию.

Пример браузерной проверки на изолированной записи. Выберите «QA Alpha» в B2 и сохраните. В AL2 появляется «Manager One». Выберите «QA Beta» и получите «Manager Two». Выбор «QA Empty» и очистка B оставляют AL пустым. Эти сценарии проверены во встроенном браузере 21 ноября 2026 года, включая чтение после перезагрузки. В БД проверены пары B и AL в аудите и отдельные события AL.
