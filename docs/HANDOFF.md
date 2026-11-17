# Серверный стенд ДЛТК — текущая передача

17 ноября 2026: ВНЕШНИЙ ДОСТУП РАБОТАЕТ. Адрес https://89.208.213.14/dltk-v3/ (login /dltk-v3/login). Не просить пользователя открыть8443; прежний запрос отменён. Пользователь потребовал доступ без изменения сетевых настроек/панели хостинга. Добавлен только маршрут своего раздела в существующем HTTPS443; порты, firewall, TLS и MySQL не перенастраивались.

## 17 ноября: исправление421 при входе по IP

После добавления доменных сайтов общим defaultHTTPS443 стал integrations.89.208.213.14.sslip.io. Браузеры при входе по IP не отправляют DNS-SNI: выдавался сертификат доменного default, затем mod_ssl отклонял HostIP с421 (secure policy). Воспроизведено TLS1.2/1.3. Исправление: только existing symlink sites-enabled/ipya-erp-https.conf переименован в000-ipya-erp-https.conf, чтобы действующий IPvhost снова был первым. Сам sites-available/ipya-erp-https.conf byte-identical, другие сайты/сертификаты/маршруты/файлы/данные не изменялись и не удалялись. Configtest+gracefulApache reload PASS; backup shared/private/sni-default-backup.json. SSLVHostSNIPolicy не ослаблялась.

После: TLS1.2/1.3 безSNI даёт правильный доверенный IPcert и302наlogin вместо421; обычный strictTLShealth200, browserIABloginработает. Все /, /lk-dltk-v2/, /dltk-v3/, ui.89.208.213.14.sslip.io иintegrations.89.208.213.14.sslip.io отвечают200настраницахвхода. Важно при добавлении новыхHTTPSvhost сохранять IPvhostпервым для клиентов поIP. Техническийконтекст: https://httpd.apache.org/docs/2.4/mod/mod_ssl.html#sslvhostsnipolicy.

## Код и размещение

PRIVATE GitHub https://github.com/caymanlogistic-crypto/dltk-v3-server, локально C:/Users/Vladimir/Documents/ChatGPT/dltk-v3-server. Исходная ручная версия была928c49582fc7b4562e9d4a3efdf95b819ec14407; теперь current меняется автоматически из main, точный SHA проверять по публичному /assets/deployment-version.json. GitHubCI44102818320 SUCCESS, проверенный artifact SHA256 ef1146499e76d1b36ff8a22a1ee2a45cfd9e36895908d60ea7fd0b2e33bcebc9. Более поздние doc-only commits не означают другую runtimeверсию.

Общие Apache/PHP8.4-FPM/MySQL8.4, собственные папка/схема dltk_v3_test/учёткаБД/PHP-пул33487, WSслужбаdltk-v3-realtime33486. DBчерез/var/run/mysqld/mysqld.sock, TCPне включён. APP_URL=https://89.208.213.14, APP_BASE_PATH=/dltk-v3, WS_URL=wss://89.208.213.14/dltk-v3/ws. Runtime/private/SQLотдельноотGit. Старый8443сайтнеявляетсяадресомпользователя.

Внутрь существующего /etc/apache2/sites-available/ipya-erp-https.conf (enabled: /etc/apache2/sites-enabled/000-ipya-erp-https.conf) передrootProxyPass добавлена одна IncludeOptional /etc/apache2/conf-available/dltk-v3-prefix.conf. Новыйincludeсодержиттолько/dltk-v3маршруты:WSпередисключениемпрефиксаизстарогоrootproxy,Aliaspublic/сFallbackResource+ownFCGI. СтарыйvhostисходныйSHA c4273ba3e012f928996526492dea6bbdebc428389b4ff87785b1de19cf22a2fd. Patcher deploy/mount_shared_prefix.py делаетприватныйbackup/configtest/rollback. Остальнойvhost/маршрутыстарыхпроектовсохранены.

## Проверки

ВсеPHP/JS/Python syntax,66форматов,13runtime/path/DSN и18Python units PASS. Apacheconfigtest иstrictTLShealth200. Извне /dltk-v3/ ->login200, assethelper200, старая/ и/lk-dltk-v2/ по-прежнемуlogin200. ApachePID854/MySQL16352/старыйERP354353 неизменны. БраузерIABоткрылреальнуюстраницувходасправильнымоформлением/полями.

ВнешнийE2Eдвухдемоаккаунтов:presenceдоsave/late snapshot/stop/disconnect;save->чужойWSсавтором->чтениедругимпользователем->точныйаудит. Демозначениевосстановлено,аудитсохранён;реальныйВывознеписался. Save-to-observer102.18ms.15внешнихнезависимыхсеансов,3раунда,6000manifest,15WS:PASS;initialresponsep50/p952330.961/3468.886ms,100строк3601.999/5114.886ms,WSready327.921/506.921ms. Этоограниченныйburst,неSLA/незамеротрисовки. JSONбезперсональныхзначенийвdocs/evidence/2026-11-17-external-*.json.

## Доступы и данные

Серверныйпарольшестидемоаккаунтовтольковshared/private/demo-access.txt;локальнодляпользователяC:/Users/Vladimir/AppData/Local/DltkV3/server-demo-access.txt (адресвфайлеобновлён). Не выводить/коммититьсекреты. RuntimeINIroot:app640,private700;SQLиSSHсекретыотдельноотGit. Шестьролейравноправны,Googleвыключен,локальныйстенднеизменялся.7425исходныхстрокВывоза,6000manifest,4демотаблицы.

## Автодеплой

По поручению пользователя ВКЛЮЧЕНА автоматическая выкладка main после успешного check. ПервыйGitHubrun44106360729/check+deploy SUCCESS, опубликованb839b0aa44987b2876dd7eec22c1c61e82aee3bc. ВнешнийE2EпослеавтовыкладкиPASS, демоячейкавосстановлена. См. AUTODEPLOY.md: отдельный restricted SSH key, root-owned receiver вне releases, SHA/проверки/атомарное переключение/health/откат, без импорта или изменений данных. Настоящий текущий commit проверять по assets/deployment-version.json и Actions; указанный выше928c495 — исходная ручная версия до автодеплоя. Runtime/секреты сохраняются.

## Обновление

Не запускать first-install поверхсуществующейБД. НовыйGitHubCIartifactSHA->новыйreleases/<sha>->атомарныйcurrent, сохраняяshared/БД;gracefulPHPиrestartтолькосвоегоWSпринеобходимости. Приватныйbackupпрефиксногообновления shared/private/prefix-upgrade-backup содержитстарыйruntimeиreceipt; mountbackup prefix-mount-* содержитисходныйvhost. Ниодногоимпорта/сбросаБДприэтомобновлениинебыло. APP_BASE_PATHподдержанвPHP/HTML/JS/benchmark/E2E;неподменятьнаURLrootбезэтойнастройки. ВсеUIошибкиPlanexNotifications,селектыcs-*.

Историяпервичнойустановки/внутреннихзамеров: history/2026-11-17-before-https-prefix.md. Еёстарыеуказанияоткрыть8443иожидатьвнешнийдоступотменены.
