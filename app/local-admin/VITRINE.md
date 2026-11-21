# Server copy of DLTK V3 vitrine

PHP-FPM public root: app/local-admin/public. Requests route through public/index.php; Nginx serves assets directly. Only the working DB is used. Runtime config is in a private [workspace] INI file selected by DLTK_CONFIG_FILE. Keep config/runtime.ini, data exports, passwords and backups out of Git. UI components/styles and current keyboard/editing/live-presence behavior are preserved from the local project.

Runtime contract: [workspace] INI, APP_URL/WS_URL/DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/PRIVATE_DIR. DLTK_CONFIG_FILE selects the private file. APP_URL is a root origin (a separate virtual host/port), not a subdirectory. Preserve the incoming Host header at Nginx. Cookie name is dltk_v3_server and Secure is enabled for HTTPS APP_URL. /api/health returns dltk-v3-server-v1 and checks only the working DB. Unknown legacy source/admin routes return404.

Portable QA: all PHP and JavaScript syntax checks,66 format checks and9 HTTP integration checks passed locally using a dedicated temporary server process. The latter exercised health, distinct cookie, account login,6000-row grid,5 tables/6 roles, configured WebSocket URL, Host/Origin rejection and removed legacy route. No business values were written. Actual Linux deployment/performance remain separate acceptance steps.

## Клиент и менеджер в «Вывозе»

Для включения сценария справочник с `kind='clients'` привязывается к полю B таблицы `vyvoz`. Связь с менеджером хранится в `workspace_meta` под ключом `catalog_managers_<id справочника>` как JSON-объект, где ключи — ID записей справочника, а значения — имена менеджеров. Список использует общий `VitrineSelect`.

Выбранный клиент и вычисленное AL сохраняются в одной транзакции. При чтении AL подставляется из справочника, в том числе для старых строк. Исторические данные при чтении сохраняются. Если клиент или менеджер не указан, значение AL пустое и отображается прочерк. Для AL пишутся отдельные версия, аудит и событие. Ответ сохранения возвращает обновлённую ячейку AL клиенту, который инициировал операцию.

У столбца значок связи с подписью «Из справочника клиентов» при наведении и фокусе. Светлый фон отмечает происхождение значения. AL можно выделять и копировать. Enter и F2 объясняют источник через верхнее уведомление. Ручное сохранение, сброс и вставка блокируются также на сервере.

Пример браузерной проверки на изолированной записи. Для клиента «QA Alpha» в AL2 виден «Manager One», даже если в исходной строке записано другое значение. При выборе «QA Beta» в AL2 появляется «Manager Two». Выбор «QA Empty» показывает прочерк. Во встроенном браузере проверены значок, подсказка при фокусе, фон, выделение и отсутствие ручного редактора. Запросы прямой записи, сброса и вставки возвращают `403 derived_field`. На рабочем локальном стенде проверен вид AL без сохранения рабочих значений.

## Обратная связь для ячеек из справочника

Метка `data-derived-source` включает общий сценарий для ячеек, которые заполняются из справочника. Клик, Enter и F2 вызывают верхнее синее уведомление `PlanexNotifications` с тоном `info`: «Данные берутся из справочника. Эту ячейку нельзя редактировать». Ячейку можно выделять и копировать.

Когда отображаемое значение меняется, редактор запускает общую зелёную обводку `vitrine-cell-saved` с цветом `--p-success`. Правило действует для изменения из текущего окна и обновления из другого сеанса. Повтор без изменения значения не запускает анимацию.

Оформление ячейки и анимация находятся в `public/assets/vitrine-editor.css`. Верхнее уведомление реализовано в `public/assets/vitrine-notifications.js`, его оформление находится в `public/assets/vitrine-base.css`. Общие цвета `--p-info`, `--p-info-fill` и `--p-success` заданы в `public/assets/vitrine/palette.css`.

Пример сверки. В «Вывозе» кликните по AL «Менеджер». На тестовой строке выберите в B другого клиента с другим менеджером и сохраните. В AL видно синее уведомление при клике и зелёную обводку при смене значения. Во встроенном браузере проверены локальный клик, собственное обновление, обновление из второго сеанса и сохранение черновика при клике из открытого редактора.
