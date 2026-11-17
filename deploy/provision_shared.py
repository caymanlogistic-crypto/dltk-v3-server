"""First install using existing Apache, PHP-FPM and MySQL, never reset existing data.

Only the named new schema/account, project directory, PHP pool, Apache site and
WebSocket unit belong to this installer. Shared services require graceful reload.
Run as root with a reviewed GitHub artifact and a private SQL export.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import socket
import tarfile
import time
import urllib.request
from contextlib import contextmanager

from native_templates import render_native_files
from provision_native import ROOT, SHARED, USER, SCHEMA, run, digest, write, pid_snapshot, verify_dump, sql

POOL = Path('/etc/php/8.4/fpm/pool.d/dltk-v3-test.conf')
SITE = Path('/etc/apache2/sites-available/dltk-v3-test.conf')
ENABLED = Path('/etc/apache2/sites-enabled/dltk-v3-test.conf')
UNIT = Path('/etc/systemd/system/dltk-v3-realtime.service')


@contextmanager
def suppress_package_fpm_actions():
    """Block only package-triggered FPM actions; preserve an existing policy."""
    policy=Path('/usr/sbin/policy-rc.d')
    backup=Path('/usr/sbin/.policy-rc.d.dltk-v3-original')
    existed=policy.exists() or policy.is_symlink()
    if backup.exists() or backup.is_symlink():raise RuntimeError('Policy backup already exists')
    if existed:policy.rename(backup)
    delegated=f'exec "{backup}" "$@"\n' if existed else 'exit 0\n'
    try:
        write(policy,'#!/bin/sh\nfor service in "$@"; do\n case "$service" in *php8.4-fpm*) exit 101 ;; esac\ndone\n'+delegated,0o755)
        yield
    finally:
        policy.unlink(missing_ok=True)
        if existed:backup.rename(policy)


def shared_texts(cert, key):
    for value in [str(cert), str(key)]:
        if not value.startswith('/') or any(c in value for c in '\n\r\x00"'):
            raise ValueError('Invalid TLS path')
    public = str(ROOT/'current/app/local-admin/public')
    pool = f'''[dltk-v3-test]
user={USER}
group={USER}
listen=127.0.0.1:33487
listen.allowed_clients=127.0.0.1
pm=ondemand
pm.max_children=8
pm.process_idle_timeout=30s
pm.max_requests=500
clear_env=yes
catch_workers_output=yes
security.limit_extensions=.php
env[DLTK_CONFIG_FILE]={SHARED}/runtime.ini
php_admin_value[session.save_path]={SHARED}/sessions
php_admin_value[error_log]={SHARED}/logs/php-error.log
php_admin_value[memory_limit]=128M
php_admin_value[max_execution_time]=30
php_admin_value[post_max_size]=2M
php_admin_value[upload_max_filesize]=2M
php_admin_flag[display_errors]=off
php_admin_flag[log_errors]=on
php_admin_flag[expose_php]=off
php_admin_flag[session.use_strict_mode]=on
php_admin_flag[session.cookie_httponly]=on
php_admin_value[session.cookie_samesite]=Strict
'''
    site = f'''# DLTK test only; existing ports and virtual hosts stay unchanged.
Listen 8443 https
<VirtualHost *:8443>
    ServerName 89.208.213.14
    SSLEngine On
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCertificateFile "{cert}"
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
    AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json image/svg+xml
    ErrorLog {SHARED}/apache-logs/error.log
    LogFormat "%h %t %>s %b" dltk_v3
    CustomLog {SHARED}/apache-logs/access.log dltk_v3
</VirtualHost>
'''
    unit = render_native_files()['units/dltk-v3-realtime.service']
    unit = unit.replace('dltk-v3-mysql.service', 'mysql.service')
    return pool, site, unit


def configuration_snapshot():
    """Existing pool/site/port file hashes, including symlink targets."""
    files = [Path('/etc/apache2/ports.conf')]
    files += list(Path('/etc/apache2/sites-enabled').glob('*'))
    files += list(Path('/etc/php/8.4/fpm/pool.d').glob('*.conf'))
    return {str(p): digest(p) for p in files if p.is_file() and p not in (POOL, ENABLED)}


def main():
    parser=argparse.ArgumentParser()
    for name in ['archive','sql','tls-cert','tls-key']:
        parser.add_argument('--'+name,required=True,type=Path)
    for name in ['archive-sha','commit','sql-sha']:
        parser.add_argument('--'+name,required=True)
    parser.add_argument('--mysql-socket',default='/var/run/mysqld/mysqld.sock')
    parser.add_argument('--mysql-datadir',default='/srv/ip_ya/mysql/data')
    a=parser.parse_args()
    if os.geteuid()!=0 or ROOT.resolve()!=ROOT: raise RuntimeError('Root and exact project root required')
    if not re.fullmatch('[0-9a-f]{40}',a.commit) or not re.fullmatch('[0-9a-f]{64}',a.archive_sha) or digest(a.archive)!=a.archive_sha:
        raise RuntimeError('Artifact identity mismatch')
    verify_dump(a.sql,a.sql_sha)
    if ROOT.exists() or any(p.exists() or p.is_symlink() for p in [POOL,SITE,ENABLED,UNIT]):
        raise RuntimeError('Existing target installation/configuration; first install refused')
    for path in [a.tls_cert,a.tls_key,Path(a.mysql_socket)]:
        if not path.exists(): raise RuntimeError('Required TLS/socket path unavailable')
    for port in [8443,33486,33487]:
        with socket.socket() as probe:
            if probe.connect_ex(('127.0.0.1',port))==0: raise RuntimeError('Target port occupied: '+str(port))
    for service in ['apache2','php8.4-fpm']:
        run(['systemctl','is-active','--quiet',service])
    before=pid_snapshot(); original=configuration_snapshot()
    ROOT.mkdir(mode=0o755)
    write(ROOT/'.dltk-test-project','dltk-v3-test-shared-v1\n')
    SHARED.mkdir(mode=0o755)
    private=SHARED/'private';private.mkdir(mode=0o700)
    # The admin profile contains no password and never changes global MySQL auth.
    admin=private/'mysql-admin.cnf'
    write(admin,'[client]\nuser=root\nprotocol=socket\nsocket='+a.mysql_socket+'\n')
    identity=sql('SELECT @@socket,@@datadir,@@port',admin).split('\t')
    if len(identity)!=3 or Path(identity[0]).resolve()!=Path(a.mysql_socket).resolve() or Path(identity[1]).resolve()!=Path(a.mysql_datadir).resolve() or identity[2]!='0':
        raise RuntimeError('Shared MySQL identity mismatch; no database changes applied')
    if sql(f"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='{SCHEMA}'",admin)!='0':
        raise RuntimeError('Target schema already exists; refused')
    if sql("SELECT COUNT(*) FROM mysql.user WHERE user='dltk_v3_app'",admin)!='0':
        raise RuntimeError('Target account already exists; refused')
    try:owner=pwd.getpwnam(USER)
    except KeyError:
        run(['useradd','--system','--home-dir',ROOT,'--shell','/usr/sbin/nologin',USER]);owner=pwd.getpwnam(USER)
    for name in ['logs','sessions','app-private']:
        path=SHARED/name;path.mkdir(mode=0o700);os.chown(path,owner.pw_uid,owner.pw_gid)
    (SHARED/'apache-logs').mkdir(mode=0o700)
    release=ROOT/'releases'/a.commit;release.mkdir(parents=True)
    with tarfile.open(a.archive,'r:gz') as archive:
        for member in archive.getmembers():
            if member.name.startswith('/') or '..' in Path(member.name).parts or not(member.isfile() or member.isdir()):
                raise RuntimeError('Unsafe archive member')
        archive.extractall(release,filter='data')
    if not (release/'app/local-admin/public/index.php').is_file():raise RuntimeError('App entry point absent')
    for path in release.rglob('*'):path.chmod(0o755 if path.is_dir() else 0o644)
    (ROOT/'current').symlink_to(release,target_is_directory=True)
    # Refuse unrelated upgrades/removals, including PHP common/FPM binaries.
    package_args=['apt-get','install','-y','--no-remove','--no-upgrade','--no-install-recommends','php8.4-mbstring']
    package_plan=run(package_args[:1]+['--simulate']+package_args[1:],timeout=60)
    for line in package_plan.splitlines():
        if line.startswith('Remv '):raise RuntimeError('Package transaction would remove an existing package')
        if line.startswith('Inst ') and line.split()[1].split(':')[0] not in ('php8.4-mbstring','libonig5'):
            raise RuntimeError('Package transaction would change unrelated PHP/system packages')
    # Package hooks cannot restart FPM; explicitly reload after pool validation.
    with suppress_package_fpm_actions():
        run(package_args,env=dict(os.environ,DEBIAN_FRONTEND='noninteractive',NEEDRESTART_MODE='l'),timeout=240)
    if run(['/usr/bin/php','-r',"echo extension_loaded('mbstring')?'yes':'no';"])!='yes':
        raise RuntimeError('mbstring installation check failed')
    sql(f'CREATE DATABASE `{SCHEMA}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;',admin)
    sql('',admin,database=SCHEMA,source=a.sql)
    if sql("SELECT JSON_UNQUOTE(value) FROM workspace_meta WHERE `key`='marker'",admin,database=SCHEMA)!='dltk-workspace-v1':
        raise RuntimeError('Imported workspace marker mismatch')
    password=secrets.token_urlsafe(36)
    sql("CREATE USER 'dltk_v3_app'@'localhost' IDENTIFIED BY '"+password+"';",admin)
    for table in sql('SHOW TABLES',admin,database=SCHEMA).splitlines():
        if not re.fullmatch('[a-z_]+',table):raise RuntimeError('Unexpected table name')
        privileges='SELECT,INSERT' if table in ['change_history','outbox_events'] else 'SELECT,INSERT,UPDATE,DELETE'
        sql(f"GRANT {privileges} ON `{SCHEMA}`.`{table}` TO 'dltk_v3_app'@'localhost';",admin)
    demo=secrets.token_urlsafe(15);salt=secrets.token_hex(16)
    hashed='pbkdf2_sha256$210000$'+salt+'$'+hashlib.pbkdf2_hmac('sha256',demo.encode(),salt.encode(),210000).hex()
    sql("UPDATE users SET password_hash='"+hashed+"',auth_version=auth_version+1 WHERE login IN ('manager','logist','dispatcher','head','admin','superadmin') AND name LIKE 'Демо — %'; DELETE FROM ws_tickets; DELETE FROM login_attempts;",admin,database=SCHEMA)
    cfg={'APP_URL':'https://89.208.213.14:8443','WS_URL':'wss://89.208.213.14:8443/ws','DB_SOCKET':a.mysql_socket,'DB_HOST':'localhost','DB_PORT':'0','DB_NAME':SCHEMA,'DB_USER':'dltk_v3_app','DB_PASSWORD':password,'PRIVATE_DIR':str(SHARED/'app-private')}
    write(SHARED/'runtime.ini','[workspace]\n'+''.join(k+'='+json.dumps(v,ensure_ascii=False)+'\n' for k,v in cfg.items()),0o640)
    os.chown(SHARED/'runtime.ini',0,owner.pw_gid)
    write(private/'demo-access.txt','ЛК ДЛТК — серверный стенд\n'+cfg['APP_URL']+'/login\nЛогины: manager, logist, dispatcher, head, admin, superadmin\nПароль для демоаккаунтов: '+demo+'\n')
    venv=SHARED/'venv'
    run(['/usr/bin/python3','-m','venv',venv],timeout=120)
    run([venv/'bin/python','-m','pip','install','--quiet','--disable-pip-version-check','-r',release/'requirements.txt'],timeout=240)
    for path in venv.rglob('*'):
        if not path.is_symlink():path.chmod(0o755 if path.is_dir() or os.access(path,os.X_OK) else 0o644)
    pool,site,unit=shared_texts(a.tls_cert,a.tls_key)
    write(POOL,pool,0o644);write(SITE,site,0o644);write(UNIT,unit,0o644)
    # Existing pools/vhosts must remain byte-identical.
    if original!=configuration_snapshot():raise RuntimeError('Existing configuration changed during installation')
    run(['/usr/sbin/php-fpm8.4','--test'])
    run(['a2enmod','proxy_fcgi','proxy_wstunnel'])
    ENABLED.symlink_to(SITE)
    run(['apache2ctl','configtest'])
    run(['systemctl','daemon-reload'])
    run(['systemctl','reload','php8.4-fpm'])
    run(['systemctl','enable','--now','dltk-v3-realtime.service'])
    run(['systemctl','reload','apache2'])
    health=None
    for _ in range(30):
        try:
            with urllib.request.urlopen(cfg['APP_URL']+'/api/health',timeout=5) as response:
                health=json.load(response)
            if health.get('marker')=='dltk-v3-server-v1' and health.get('database')=='ready':break
        except Exception:pass
        time.sleep(1)
    else:raise RuntimeError('HTTPS/database readiness failed; installation retained for inspection')
    receipt={'commit':a.commit,'artifactSha256':a.archive_sha,'sqlSha256':a.sql_sha,'mode':'shared-apache-php-mysql','mysqlIdentity':identity,'existingConfigHashes':original,'oldUnitsBefore':before,'oldUnitsAfter':pid_snapshot(),'utc':dt.datetime.now(dt.timezone.utc).isoformat()}
    write(private/'deployment-receipt.json',json.dumps(receipt,indent=2))
    print(json.dumps({'status':'ready','url':cfg['APP_URL'],'commit':a.commit,'mode':receipt['mode']}))


if __name__=='__main__':
    try:main()
    except Exception as error:
        print(json.dumps({'status':'failed','error':type(error).__name__,'message':str(error)[:240],'note':'Do not rerun first install over partial state; inspect only the new project scope.'}))
        raise SystemExit(1)
