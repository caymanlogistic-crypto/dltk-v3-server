# Серверный стенд ДЛТК — передача

17 ноября 2026. В подготовке. Репозиторий https://github.com/caymanlogistic-crypto/dltk-v3-server — PRIVATE. Эта папка является отдельной копией; исходный локальный стенд ЛК-ДЛТК V3 не изменяется.

## Разрешённая цель

Пользователь поручил создать отдельную папку/репозиторий, выложить через GitHub на сервер и проверить быстродействие. Уточнил: сервер тот же, что у 1C-ИП v2, но изолированно от других проектов. Из его актуального deployment найдена цель89.208.213.14. Планируемый новый root /srv/dltk-v3-test; существующий /srv/ip_ya и его код/БД/ключи/сервисы не изменять. Наличие старых полномочий 1C-ИП не является разрешением менять тот проект.

## Текущий доступ

GitHubCLIавторизован caymanlogistic-crypto; новый repo создан private. SentinelX/Remote Desktop Commander не имеют onlineсерверов. SSHroot/user ссуществующим erp_planex_remote_commander key и закреплённым hostkey не авторизовались (Permission denied). Другиеключи/пароли не перебирались. Пользователю направлен запрос подключить штатный SSH-agent/указать рабочий профиль; ожидание не является разрешением и не является завершением выкладки. Новыйhostkeyfile копирует закреплённый публичныйключ из 1C-ИП v2/deploy/known_hosts, обходаStrictHostKeyCheckingнебыло. Его privateSSHключи/старыеограниченныеdeploybrokersнеиспользоватьдлячужойцели.

## Владельцы файлов текущего прохода

/root/server_portable_app — app/local-admin/** и config/runtime.example.ini. /root/server_deploy_files — deploy/**, tools/workspace_realtime.py, requirements/compose/Dockerfiles иdocs/DEPLOYMENT.md. Root — GitHub/коммиты/данные/benchmark/docs/внешняяинтеграция. Новыеавторыдолжныпроверитьстатуспреждечемправитьтежефайлы.

## Runtime contract

[workspace] вignoredconfig/runtime.ini илифайлеDLTK_CONFIG_FILE. APP_URL,WS_URL,DB_HOST,DB_PORT,DB_NAME,DB_USER,DB_PASSWORD,PRIVATE_DIR. Серверныйroot-originпоумолчаниюпланируетсяhttps://89.208.213.14:8443,WS /ws; фактическийTLS/ресурсы/порты проверяютсянаживомсервередовыкладки. ДанныеиTLSключиотдельноотGit. Шестьролейпокаравноправны. Сохраняютсяобычныеcs-*селекты,PlanexNotifications,аудит,настройки,виртуализация,rededitpresence иpulsepaste.

## Данные

ИсходнаярабочаяMySQLdltk_v3_workspaceна127.0.0.1:33384,7425исходныхрабочихстрок+4демомодуля. Дляпроизводительности нужнаотдельнаякопияэтойБД,а неограничение50строк/неXLSX. ПриватныйSQLdumpсоздаётtools/workspace_backup.pyвисходномпроекте,вprivate/workspace-backups;невключатьего вGitHubдажевзакрытыйrepo. Серверныеучётки/пароли/SQLпередаватьотдельнопозакреплённомуSSH. Локальныйстенд/Googleнеизменять.

## Перед готовностью

ПроверитьportablePHP/JS/Python,чистыйcommitбезсекретов/данных,GitHubCI,доступксерверу,изолированныекаталог/DB/порты/сервисы,backup/импорт/права,фактическийSHAзапущеннойверсии,HTTP+WS+presence+аудит,замеры15сеансов. Толькофактические serverзамеры называтьserverperformance. Еслидоступещёнет,честнопередатьподготовленныйrepo/инструкциииуказатьблокировку;необъявлятьвыложенным.
