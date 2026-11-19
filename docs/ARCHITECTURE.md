# Техническая карта ЛК-ДЛТК V3

Сверено 19 ноября 2026 года. Код сервера сверён с `32dd2b2993cde009efe382d378dd1d1004a8aaee`. [Успешный запуск 44114012056](https://github.com/caymanlogistic-crypto/dltk-v3-server/actions/runs/44114012056) и [публичный SHA](https://89.208.213.14/dltk-v3/assets/deployment-version.json) совпали.

## Два окружения

| Где | Код и процессы | Данные |
| --- | --- | --- |
| Windows, исходная папка «ЛК-ДЛТК V3» | `tools/start_workspace.ps1`, PHP built-in на `127.0.0.1:33385`, Python WS на `33386` | Выделенная MySQL на `33384`, схема `dltk_v3_workspace` |
| [Серверный стенд](https://89.208.213.14/dltk-v3/) | Этот Git-репозиторий. Общие Apache на HTTPS 443, PHP-FPM с отдельным пулом и собственный Python WS `127.0.0.1:33486` | Отдельная схема `dltk_v3_test` в существующей MySQL через Unix socket |
| Worktree задачи | Отдельные PHP и WS на loopback с изолированными сессиями | Временный экземпляр MySQL и синтетическая `dltk_v3_qa` по [локальной инструкции](LOCAL_QA.md) |

Сервер читает `DLTK_CONFIG_FILE` с секцией `[workspace]`. `APP_URL` задаёт origin, `APP_BASE_PATH=/dltk-v3` — префикс URL и cookie, `WS_URL` — внешний WS. `bootstrap.php` и `public/assets/workspace-paths.js` собирают пути с этим префиксом. Для Windows исходный локальный адаптер читает собственный профиль MySQL и ограничен loopback. Первичное заполнение и история экспорта описаны в локальном `project-hub/LOCAL_SYSTEM_HANDOFF.md`. Обновление сервера идёт из этого Git-репозитория. Синхронизация двух папок не автоматическая.

## Маршруты и обработчики

Ниже указаны пути относительно приложения. На сервере к ним добавляется `/dltk-v3`.

| Маршрут | Код и действие |
| --- | --- |
| `GET /api/health` | [router.php](../app/local-admin/router.php) проверяет подключение и marker рабочей БД сервера. |
| `GET/POST /login`, `POST /logout` | `workspace_auth_route` в [Workspace.php](../app/local-admin/src/Workspace.php). PHP-сессия, активный аккаунт, проверка пароля и версии авторизации. `bootstrap.php` проверяет Host, CSRF и Origin для записи. |
| `GET /`, `GET /vitrine?table=vyvoz` | [Vitrine.php](../app/local-admin/src/Vitrine.php) и [WorkspaceVitrine.php](../app/local-admin/src/WorkspaceVitrine.php) выбирают таблицу, строят manifest и начальный HTML. `workspace-grid.js` и `vitrine-rows.js` виртуализируют сетку, порции загружаются через `GET /api/vitrine/rows`. |
| `GET /api/vitrine/statuses`, `POST /api/vitrine/cell`, `POST /api/vitrine/paste-check` | `vitrine_statuses_endpoint`, `vitrine_save_cell` и `vitrine_paste_check`. Форматы в [VitrineClipboard.php](../app/local-admin/src/VitrineClipboard.php). `vitrine_store` записывает ячейку и аудит, сохраняет ревизию и возвращает результат повторного запроса по `requestId`. |
| `GET /workspace?section=history\|catalogs\|users\|reports` | [views/workspace.php](../app/local-admin/views/workspace.php) и `workspace.js` показывают журнал, справочники, сотрудников и сводку. Фильтр `table` и `row` даёт историю строки. |
| `/api/workspace/{bootstrap,manifest,preferences,history,users,catalogs,reports,row}` | `workspace_api` в `Workspace.php` выдаёт профиль, таблицы и справочники, сохраняет настройки, показывает аудит и счётчики. `POST row` создаёт, архивирует и восстанавливает запись. Отдельного экрана архива пока нет. |
| `POST /api/workspace/ws-ticket`, `GET /api/workspace/events?after=…`, `/ws` | PHP выдаёт одноразовый билет на 30 секунд. [workspace_realtime.py](../tools/workspace_realtime.py) авторизует сокет, доставляет outbox и держит присутствие редакторов в памяти. `workspace-live.js` переподключается с cursor и использует HTTP fallback для изменений. |

## Запись и права

- `table_definitions` и `records` хранят независимые наборы полей, JSON значений и исходный снимок. Идентификатор записи — `records.id`, её видимый номер уникален внутри таблицы. `vyvoz` содержит перенесённые данные и 87 полей. Текущая сетка выводит до 6000 строк с перевозчиком и новые записи. `table-2`–`table-5` — демо.
- `catalogs`, `catalog_bindings`, `catalog_entries` и `record_references` описывают справочники и варианты. Статусы сохраняются по ID в `cell_versions`. Активные привязки значений проверяйте в рабочей схеме. Исследования связей старого mirror не устанавливают правила рабочей БД.
- `cell_versions`, `change_history`, `outbox_events` и `request_deduplication` обновляются в транзакции записи. Аудит использует автора из сессии, серверное время и значения до и после. При stale revision сервер возвращает 409, редактор сохраняет черновик. Присутствие идёт по WS отдельным состоянием с heartbeat и сроком жизни. Оно не пишет значения и аудит.
- `user_view_preferences` использует ключ пользователя и таблицы. Текущий клиент сохраняет частичные настройки, оповещения о настройках доставляются владельцу.
- `users`, `roles` и `user_roles` связывают аккаунты с шестью типами сотрудников. Менеджер, Логист, Диспетчер, Руководитель, Администратор и SuperAdmin имеют одинаковый полный доступ, включая управление пользователями. Реализация `workspace_can`, `access_rules` и проверки WS содержат основу для ограничений. Перед их включением предстоит согласовать матрицу и покрыть права API, истории, сводных, справочников и событий.

## Интеграции и путь на сайт

- Работающее приложение использует MySQL, PHP-сессии и WS. Google отключён. Прежние mirror, SQLite, Excel и tldraw служат локальными архивами и источниками согласования. В серверном репозитории Google worker отсутствует.
- Контур.Логистика изучался в рамках чтения и анализа ПЭ и ЭТрН. В текущих маршрутах и зависимостях серверного приложения интеграции Контур и 1С не обнаружены. Тест доступности 1С из отдельного чата не подтверждает связь с этим приложением.
- Путь на стенд проходит через ветку и PR в этом [закрытом Git-репозитории](https://github.com/caymanlogistic-crypto/dltk-v3-server). После команды «выкладывай» изменение попадает в `main`. [check.yml](../.github/workflows/check.yml) проводит проверки и собирает архив точного SHA. `send_release.py` доставляет его в отдельный SSH receiver. Receiver валидирует архив, переключает `current`, плавно перезагружает PHP-FPM и обновляет только собственный WS. Выполняются HTTPS health, login, asset и проверка версии. При сбое после переключения receiver возвращает прежний релиз. [Подробности](AUTODEPLOY.md).

## Проведённые проверки и пробелы

- Подтверждено кодом и текущим GET 19 ноября 2026 года. Локальный `/login` и серверные `/login`, `/api/health` и публичный SHA доступны. Последний GitHub run прошёл оба job. Chrome открыл серверную страницу входа и локальную Витрину.
- Существующие синтетические проверки покрывают формат, runtime-префикс, WS, очередь и откат. Сохранение, двусторонний WS и аудит проверяются [изолированным E2E](LOCAL_QA.md). История внешних замеров на 15 сеансов и E2E сохранена в [evidence](evidence).
- Открытые решения. Схемы четырёх модулей, реальные привязки справочников, формулы и целевые сводные, матрица доступа. Открытые технические проверки. Восстановление из резервной копии и нагрузка сверх исторического ограниченного прогона. Статусы и детали ведутся в [Notion](https://app.notion.com/p/41da64edce32819e82add67138cf52bd?pvs=204).
