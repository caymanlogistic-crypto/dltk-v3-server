"""Pure configuration renderer for the isolated native Debian test stack.

Does not create files, connect to hosts or start services. The provisioner owns
permission checks, secrets, installation and actual readiness verification.
"""
from pathlib import PurePosixPath
from urllib.parse import urlsplit


def render_native_files(prefix='/srv/dltk-v3-test',repo_path=None,
                        tls_certificate=None,tls_key=None,user='dltk-v3-test',
                        php_fpm='/usr/sbin/php-fpm8.4',apache='/usr/sbin/apache2',
                        mysqld='/usr/sbin/mysqld',python=None,php_extension=None,
                        modules='/usr/lib/apache2/modules'):
    if prefix!='/srv/dltk-v3-test':
        raise ValueError('This renderer is scoped only to /srv/dltk-v3-test')
    root=PurePosixPath(prefix);source=PurePosixPath(repo_path or str(root/'current'))
    if root not in source.parents:raise ValueError('Source must stay inside the isolated root')
    def text(value):
        value=str(value)
        if any(c in value for c in ('\n','\r','"',"'")):raise ValueError('Invalid configuration path/value')
        return value
    prefix=text(prefix);source=text(source);user=text(user)
    shared=prefix+'/shared';public=source+'/app/local-admin/public'
    certificate=text(tls_certificate or shared+'/tls/fullchain.pem')
    key=text(tls_key or shared+'/tls/privkey.pem')
    python=text(python or shared+'/venv/bin/python')
    binaries=[text(v) for v in (php_fpm,apache,mysqld)]
    php_fpm,apache,mysqld=binaries
    load=['mpm_event','authz_core','authz_host','dir','mime','filter','deflate','proxy','proxy_http','proxy_fcgi','proxy_wstunnel','socache_shmcb','ssl']
    apache_text='ServerRoot "'+shared+'/apache"\n'
    apache_text+='\n'.join('LoadModule '+name+'_module "'+text(modules)+'/mod_'+name+'.so"' for name in load)+'\n'
    apache_text+=f"""Listen 0.0.0.0:8443 https
ServerName 89.208.213.14:8443
PidFile "{shared}/run/apache.pid"
DefaultRuntimeDir "{shared}/run"
User {user}
Group {user}
ServerTokens Prod
ServerSignature Off
Timeout 35
KeepAlive On
KeepAliveTimeout 5
MaxKeepAliveRequests 100
StartServers 1
ServerLimit 2
ThreadsPerChild 25
MaxRequestWorkers 50
ErrorLog "{shared}/logs/apache-error.log"
LogLevel warn
LogFormat "%h %t %>s %b" dltk
CustomLog "{shared}/logs/apache-access.log" dltk
TypesConfig /etc/mime.types
AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json image/svg+xml
DeflateCompressionLevel 6
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLSessionCache shmcb:{shared}/run/ssl_scache(262144)
<VirtualHost 0.0.0.0:8443>
    SSLEngine On
    SSLCertificateFile "{certificate}"
    SSLCertificateKeyFile "{key}"
    DocumentRoot "{public}"
    DirectoryIndex index.php
    <Directory "{public}">
        Options -Indexes -ExecCGI
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>
    <FilesMatch "\\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:33487"
    </FilesMatch>
    ProxyPreserveHost On
    ProxyPass /ws ws://127.0.0.1:33486 retry=0 timeout=75
    ProxyPassReverse /ws ws://127.0.0.1:33486
</VirtualHost>
"""
    php_ini="""expose_php=Off
display_errors=Off
log_errors=On
memory_limit=128M
max_execution_time=30
post_max_size=2M
upload_max_filesize=2M
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_samesite=Strict
opcache.enable=1
opcache.validate_timestamps=0
"""
    if php_extension:php_ini+='extension="'+text(php_extension)+'"\n'
    fpm=f"""[global]
pid={shared}/run/php-fpm.pid
error_log={shared}/logs/php-fpm.log
daemonize=no
[www]
user={user}
group={user}
listen=127.0.0.1:33487
listen.allowed_clients=127.0.0.1
pm=dynamic
pm.max_children=8
pm.start_servers=2
pm.min_spare_servers=2
pm.max_spare_servers=4
pm.max_requests=500
clear_env=no
catch_workers_output=yes
security.limit_extensions=.php
env[DLTK_CONFIG_FILE]={shared}/runtime.ini
php_admin_value[session.save_path]={shared}/sessions
php_admin_value[error_log]={shared}/logs/php-error.log
"""
    mysql=f"""[mysqld]
user={user}
basedir=/usr
datadir={shared}/mysql
socket={shared}/run/mysql.sock
pid-file={shared}/run/mysql.pid
log-error={shared}/logs/mysql-error.log
port=33484
bind-address=127.0.0.1
mysqlx=0
max-connections=80
innodb-buffer-pool-size=256M
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
skip-name-resolve=ON
local-infile=OFF
secure-file-priv={shared}/mysql-files
skip-log-bin
"""
    def unit(description,command,after='',memory='256M',cpu='100%',extra=''):
        return f"""[Unit]
Description={description}
After=network.target {after}
StartLimitIntervalSec=60
StartLimitBurst=5
[Service]
Type=simple
User={user}
Group={user}
WorkingDirectory={source}
ExecStart={command}
Environment=DLTK_CONFIG_FILE={shared}/runtime.ini
{extra}Restart=on-failure
RestartSec=2
NoNewPrivileges=yes
PrivateTmp=yes
ProtectHome=yes
ProtectSystem=strict
ReadWritePaths={shared}
InaccessiblePaths=-/srv/ip_ya -/srv/lk-dltk-v2
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryMax={memory}
CPUQuota={cpu}
TasksMax=256
UMask=0077
[Install]
WantedBy=multi-user.target
"""
    files={'shared/apache/httpd.conf':apache_text,'shared/php/php.ini':php_ini,
           'shared/php/fpm.conf':fpm,'shared/mysql.cnf':mysql}
    files['units/dltk-v3-mysql.service']=unit('DLTK V3 isolated MySQL',f'{mysqld} --defaults-file={shared}/mysql.cnf',memory='1G',extra='LimitNOFILE=8192\n')
    files['units/dltk-v3-php.service']=unit('DLTK V3 isolated PHP-FPM',f'{php_fpm} -F -y {shared}/php/fpm.conf -c {shared}/php/php.ini','dltk-v3-mysql.service',memory='512M',extra=f'Environment=LD_LIBRARY_PATH={shared}/php-libs\n')
    files['units/dltk-v3-realtime.service']=unit('DLTK V3 isolated WebSocket',f'{python} {source}/tools/workspace_realtime.py','dltk-v3-mysql.service',memory='192M',cpu='50%',extra='Environment=DLTK_WS_BIND=127.0.0.1 DLTK_WS_PORT=33486 PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1\n')
    files['units/dltk-v3-apache.service']=unit('DLTK V3 isolated HTTPS',f'{apache} -f {shared}/apache/httpd.conf -DFOREGROUND','dltk-v3-php.service dltk-v3-realtime.service',memory='192M',cpu='25%')
    return files
