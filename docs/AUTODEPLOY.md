# Автоматическая выкладка стенда

Push в main запускает .github/workflows/check.yml: check -> artifact точного SHA -> deploy. workflow_dispatch позволяет повторить запуск вручную для main. Другие ветки не выкладываются. Deploy jobs сериализованы, отмена работающей выкладки отключена; receiver дополнительно отвергает устаревший GITHUB_RUN_NUMBER.

Адрес: https://89.208.213.14/dltk-v3/. Проверки PHP/JS/Python, форматов, runtime/path, префикса и broker обязательны. Неуспешные проверки не запускают deploy. Artifact download/upload и checkout закреплены на commit SHA официальных GitHub Actions.

## Доставка и доступ

Runner использует два repository secrets: DLTK_DEPLOY_SSH_KEY и DLTK_DEPLOY_KNOWN_HOSTS. Значения не хранить в коде/документации/логах. Отдельный SSH-пользователь dltk-v3-deploy; root-owned authorized_keys содержит restrict и единственную forced command: sudo -n /usr/bin/python3 /srv/dltk-v3-test/bin/release_receiver.py. Единственное разрешение sudoers соответствует этой команде без аргументов. Shell/SFTP/port-forward/PTY через этот ключ не доступны. SSH host key закреплён; StrictHostKeyChecking=yes. Пароль администратора в GitHub не передаётся.

Receiver установлен root-owned ВНЕ releases, не обновляется содержимым нового архива. Корневые home/.ssh deploy-пользователя755, authorized_keys644: это публичный ключ, доступный sshd при смене UID, но не изменяемый deploy-пользователем. Закрытый ключ только в GitHub secret и закрытой локальной операторской папке вне репозитория.

Runner deploy/send_release.py отправляет stdin JSON(commit,sha256,size,runNumber)+архив; любая SSH-клиентская команда игнорируется receiver. Проверяются SHA256, лимит20MB gzip/~54MB tar-потока/50MB файлов/2000members, пути/типы/дубликаты, root ownership и marker. Symlinks/devices/traversal/private/runtime.ini отвергаются. Архив не запускает shell/deploy scripts/SQL. Все PHP файлы проходят php -n -l. Изменённый requirements.txt автоматически не устанавливается: сначала требуется отдельно подготовить зависимости.

## Применение и откат

Новый код root-owned помещается в /srv/dltk-v3-test/releases/<commit>. Runtime.ini, private, база, справочники, пользователи, их настройки и история остаются на месте. Никакого повторного импорта или сброса БД нет; SQL migrations не выполняются. current переключается атомарно. Общий PHP-FPM получает graceful reload; перезапускается только собственный WS dltk-v3-realtime. Apache/MySQL и чужие службы не перезапускаются и их конфигурации не изменяются при автодеплое.

Проверяются strict TLS health/БД, login, JS asset и публичная версия /dltk-v3/assets/deployment-version.json. Версия содержит только commit. При ошибке после переключения receiver возвращает прежний current, повторяет reload PHP/свой WS и проверяет прежнюю версию; GitHub job завершается ошибкой. Если откат потребовал вмешательства, фиксируется rollback-needs-inspection. Отказ до активации сохраняет прежний стенд.

Один flock исключает параллельные изменения. Повтор успешного коммита проверяет его checksum и здоровье и возвращает unchanged. Другой/непроверенный архив под существующим SHA не перезаписывается. Failed/старые releases сохраняются для диагностики; автоматического удаления нет. Зависимости, DB schema migrations и обновление root broker требуют отдельной подготовленной операции, а не исполнения произвольного файла из архива.

## Где смотреть

GitHub Actions -> Check and deploy server project -> check/deploy. Успешный job печатает только commit/status/URL. Приватные receipts: shared/private/autodeploy/<timestamp>.json, <commit>.json, latest.json; также shared/private/deployment-latest.json. Receipts содержат хеши/статусы/предыдущий commit, без паролей и значений ячеек. Публичная версия позволяет сравнить работающий SHA с GitHub.

Проверены19unit-сценариев broker, включая неправильные/лишние байты протокола, опасные архивы, лимиты, устаревшие runs, неизменность зависимостей и возврат старого current при отказе refresh. Реальный ключ допускает только receiver: команда id с пустым stdin отвергнута, shell не исполнился. Фактический первый автодеплой GitHub Actions44106360729 завершил check и deploy успешно: b839b0aa44987b2876dd7eec22c1c61e82aee3bc, receipt runNumber7/status success. Публичный version marker совпал. После выкладки внешний E2E двух пользователей проверил presence/save/WS/audit, демозначение восстановлено, реальные данные не менялись. Apache/MySQL/старыйERP PID сохранились. Санитизированный отчёт: evidence/2026-11-17-autodeploy-e2e.json. При новых push версия меняется автоматически; актуальный SHA всегда сверять с marker и последним Actions run.

## Цикл агента

Выкладку запускают после команды «выкладывай». Push или merge в `main` запускает её автоматически. Ветка задачи может прогнать проверку вручную с явным `--ref` и без deploy. Workflow текущей версии стартует на push в `main` и по `workflow_dispatch`.

Ответственный агент проверяет текущие запуски в [GitHub Actions](https://github.com/caymanlogistic-crypto/dltk-v3-server/actions/workflows/check.yml) и находит запуск своего SHA. Рабочий deploy job сериализован, но GitHub может заменить ожидающий запуск более новым. Следите за своим SHA и фактической версией, включая случай, когда прошла выкладка более свежего коммита. Повторная отправка старого SHA не подтверждает новую попытку задачи.

```powershell
gh run list --workflow check.yml --commit <SHA>
gh run watch <RUN_ID> --exit-status
curl.exe -fsS https://89.208.213.14/dltk-v3/api/health
curl.exe -fsS https://89.208.213.14/dltk-v3/assets/deployment-version.json
```

После `check` и `deploy` сверьте публичный SHA, откройте страницу изменённой функции и проверьте её поведение. Для UI приложите результат браузерной сверки и ссылку на пример. В текущем цикле карточки укажите ссылку на PR или запуск, SHA и проверку на сайте. При откате или непрошедшей проверке карточка остаётся открытой. [Жизненный цикл карточки](AGENT_WORKFLOW.md#выкладка-и-закрытие).
