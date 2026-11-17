"""Explicit first installation of an isolated native test instance, never /srv/ip_ya.

Run as root AFTER reviewing the GitHub CI artifact SHA and private SQL checksum.
Existing owned installations are not reset. Database imports are empty-schema only.
"""
import argparse
import configparser
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import socket
import subprocess
import tarfile
import time
import urllib.request

from native_templates import render_native_files

ROOT=Path('/srv/dltk-v3-test')
SHARED=ROOT/'shared'
USER='dltk-v3-test'
SCHEMA='dltk_v3_test'
OLD_UNITS=['apache2.service','mysql.service','ipya-erp.service','ipya-worker.service']


def run(args,*,input=None,env=None,cwd=None,timeout=180):
    p=subprocess.run(list(map(str,args)),input=input,env=env,cwd=cwd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
    if p.returncode:
        # SQL errors may contain cell contents; never print raw command output.
        raise RuntimeError('Command failed: '+Path(str(args[0])).name+' (exit '+str(p.returncode)+')')
    return p.stdout.decode('utf-8','replace').strip()


def digest(path):
    h=hashlib.sha256()
    with path.open('rb') as source:
        for block in iter(lambda:source.read(1024*1024),b''):h.update(block)
    return h.hexdigest()


def write(path,text,mode=0o600,owner=None):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_text(text,encoding='utf-8');path.chmod(mode)
    if owner:os.chown(path,owner.pw_uid,owner.pw_gid)


def pid_snapshot():
    result={}
    for unit in OLD_UNITS:
        p=subprocess.run(['systemctl','show',unit,'--property=MainPID','--property=ActiveState'],capture_output=True,text=True)
        result[unit]=p.stdout.strip()
    return result


def verify_dump(path,sha):
    if not re.fullmatch('[0-9a-f]{64}',sha) or digest(path)!=sha:raise RuntimeError('SQL checksum mismatch')
    with path.open(encoding='utf-8') as source:
        for line in source:
            if re.match(r'(?i)^\s*(?:USE\s|(?:CREATE|DROP|ALTER)\s+DATABASE|(?:CREATE|ALTER|DROP)\s+USER|GRANT\s|REVOKE\s)',line):
                raise RuntimeError('Dump contains schema/account routing; refused')


def sql(statement,profile,*,database=None,source=None):
    args=['/usr/bin/mysql','--defaults-file='+str(profile),'--no-login-paths','--default-character-set=utf8mb4','--batch','--skip-column-names']
    if database:args+=['--database='+database]
    if source:
        with source.open('rb') as data:
            p=subprocess.run(args,stdin=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=180)
        if p.returncode:raise RuntimeError('SQL import failed; inspect isolated schema before retrying')
        return ''
    return run(args,input=statement.encode())


def extension_runtime():
    available=run(['/usr/bin/php','-r',"echo extension_loaded('mbstring')?'yes':'no';"])
    if available=='yes':return None
    packages=SHARED/'packages';packages.mkdir(exist_ok=True)
    # Download/extract only: no global PHP enabling or service restarts.
    run(['apt-get','-o','APT::Sandbox::User=root','download','php8.4-mbstring','libonig5'],cwd=packages,timeout=120)
    runtime=SHARED/'php-extension-runtime';runtime.mkdir(exist_ok=True)
    for package in packages.glob('*.deb'):run(['dpkg-deb','-x',package,runtime])
    extensions=list(runtime.rglob('mbstring.so'))
    if len(extensions)!=1:raise RuntimeError('Expected one project-local mbstring extension')
    libraries=SHARED/'php-libs';libraries.mkdir(exist_ok=True)
    for lib in runtime.rglob('libonig.so*'):
        if lib.is_file():shutil.copyfile(lib,libraries/lib.name)
    for directory in runtime.rglob('*'):
        if directory.is_dir():directory.chmod(0o755)
        elif directory.is_file():directory.chmod(0o644)
    env=dict(os.environ,LD_LIBRARY_PATH=str(libraries))
    if run(['/usr/bin/php','-d','extension='+str(extensions[0]),'-r',"echo extension_loaded('mbstring')?'yes':'no';"],env=env)!='yes':
        raise RuntimeError('Project-local mbstring did not load')
    return str(extensions[0])


def main():
    p=argparse.ArgumentParser();p.add_argument('--archive',required=True,type=Path);p.add_argument('--archive-sha',required=True);p.add_argument('--commit',required=True);p.add_argument('--sql',required=True,type=Path);p.add_argument('--sql-sha',required=True)
    p.add_argument('--tls-cert',required=True,type=Path);p.add_argument('--tls-key',required=True,type=Path);a=p.parse_args()
    if os.geteuid()!=0 or ROOT.resolve()!=ROOT:raise RuntimeError('Root and the exact isolated root required')
    if not re.fullmatch('[0-9a-f]{40}',a.commit) or digest(a.archive)!=a.archive_sha:raise RuntimeError('Artifact identity mismatch')
    verify_dump(a.sql,a.sql_sha)
    marker=ROOT/'.dltk-test-project'
    if ROOT.exists() and not marker.is_file():raise RuntimeError('Existing unowned directory; refused')
    if marker.exists():raise RuntimeError('Existing owned installation; use reviewed update steps, not first install')
    for port in [8443,33484,33486,33487]:
        with socket.socket() as s:
            if s.connect_ex(('127.0.0.1',port))==0:raise RuntimeError('Target port already occupied: '+str(port))
    before=pid_snapshot()
    ROOT.mkdir(mode=0o755);write(marker,'dltk-v3-test-native-v1\n')
    try:owner=pwd.getpwnam(USER)
    except KeyError:
        run(['useradd','--system','--home-dir',ROOT,'--shell','/usr/sbin/nologin',USER]);owner=pwd.getpwnam(USER)
    SHARED.mkdir(mode=0o755)
    for name in ['apache','apache-run','apache-logs','php','run','logs','sessions','mysql','mysql-files','php-libs','private','app-private','tls']:
        d=SHARED/name;d.mkdir(mode=0o700);os.chown(d,owner.pw_uid,owner.pw_gid)
    for name in ['private','tls','apache-run','apache-logs']:
        os.chown(SHARED/name,0,0);(SHARED/name).chmod(0o700)
    for name in ['apache','php','php-libs']:
        os.chown(SHARED/name,0,owner.pw_gid);(SHARED/name).chmod(0o750)
    for source,target in [(a.tls_cert,SHARED/'tls/fullchain.pem'),(a.tls_key,SHARED/'tls/privkey.pem')]:
        shutil.copyfile(source,target);target.chmod(0o600)
    release=ROOT/'releases'/a.commit;release.mkdir(parents=True)
    with tarfile.open(a.archive,'r:gz') as tar:
        for member in tar.getmembers():
            if member.name.startswith('/') or '..' in Path(member.name).parts or not(member.isfile() or member.isdir()):raise RuntimeError('Unsafe archive member')
        tar.extractall(release,filter='data')
    for path in release.rglob('*'):path.chmod(0o755 if path.is_dir() else 0o644)
    (ROOT/'current').symlink_to(release,target_is_directory=True)
    extension=extension_runtime()
    files=render_native_files(php_extension=extension)
    for name,text in files.items():
        if name=='shared/apache/httpd.conf':text=text.replace(str(SHARED)+'/run/',str(SHARED)+'/apache-run/').replace(str(SHARED)+'/logs/apache-',str(SHARED)+'/apache-logs/apache-')
        if name.endswith('dltk-v3-apache.service'):
            # Parent alone reads root-only TLS keys, workers drop to the project user.
            text=text.replace('User='+USER+'\n','User=root\n',1)
            text=text.replace('Restart=on-failure',f'ExecReload=/usr/sbin/apache2 -f {SHARED}/apache/httpd.conf -k graceful\nRestart=on-failure')
        if name.startswith('units/'):
            target=Path('/etc/systemd/system')/Path(name).name
            if target.exists():raise RuntimeError('Existing target unit; refused')
            write(target,text,0o644)
        else:
            write(ROOT/name,text,0o640);os.chown(ROOT/name,0,owner.pw_gid)
    env=dict(os.environ,LD_LIBRARY_PATH=str(SHARED/'php-libs'))
    run(['/usr/sbin/apache2','-t','-f',SHARED/'apache/httpd.conf'])
    run(['/usr/sbin/php-fpm8.4','-t','-y',SHARED/'php/fpm.conf','-c',SHARED/'php/php.ini'],env=env)
    run(['/usr/sbin/mysqld','--defaults-file='+str(SHARED/'mysql.cnf'),'--initialize-insecure'],timeout=180)
    run(['systemctl','daemon-reload']);run(['systemctl','enable','--now','dltk-v3-mysql.service'])
    initial=SHARED/'private/mysql-initial.cnf';write(initial,'[client]\nuser=root\nsocket='+str(SHARED/'run/mysql.sock')+'\n')
    ready=False
    for _ in range(60):
        try:sql('SELECT 1',initial);ready=True;break
        except RuntimeError:time.sleep(1)
    if not ready:raise RuntimeError('Isolated MySQL readiness failed')
    root_password=secrets.token_urlsafe(36);app_password=secrets.token_urlsafe(36)
    sql("ALTER USER 'root'@'localhost' IDENTIFIED BY '"+root_password+"';",initial)
    admin=SHARED/'private/mysql-admin.cnf';write(admin,'[client]\nuser=root\npassword='+root_password+'\nsocket='+str(SHARED/'run/mysql.sock')+'\n');initial.unlink()
    sql(f'CREATE DATABASE `{SCHEMA}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;',admin)
    if sql(f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{SCHEMA}'",admin)!='0':raise RuntimeError('Import target is not empty')
    sql('',admin,database=SCHEMA,source=a.sql)
    if sql("SELECT JSON_UNQUOTE(value) FROM workspace_meta WHERE `key`='marker'",admin,database=SCHEMA)!='dltk-workspace-v1':raise RuntimeError('Imported marker mismatch')
    sql("CREATE USER 'dltk_v3_app'@'127.0.0.1' IDENTIFIED BY '"+app_password+"';",admin)
    tables=sql('SHOW TABLES',admin,database=SCHEMA).splitlines()
    for table in tables:
        if not re.fullmatch('[a-z_]+',table):raise RuntimeError('Unexpected table name')
        privileges='SELECT,INSERT' if table in ['change_history','outbox_events'] else 'SELECT,INSERT,UPDATE,DELETE'
        sql(f"GRANT {privileges} ON `{SCHEMA}`.`{table}` TO 'dltk_v3_app'@'127.0.0.1';",admin)
    demo_password=secrets.token_urlsafe(15)
    salt=secrets.token_hex(16);h=hashlib.pbkdf2_hmac('sha256',demo_password.encode(),salt.encode(),210000).hex();password_hash='pbkdf2_sha256$210000$'+salt+'$'+h
    sql("UPDATE users SET password_hash='"+password_hash+"',auth_version=auth_version+1 WHERE login IN ('manager','logist','dispatcher','head','admin','superadmin') AND name LIKE 'Демо — %'; DELETE FROM ws_tickets; DELETE FROM login_attempts;",admin,database=SCHEMA)
    access=SHARED/'private/demo-access.txt';write(access,'ЛК ДЛТК — серверный стенд\nhttps://89.208.213.14:8443/login\nЛогины: manager, logist, dispatcher, head, admin, superadmin\nПароль для демоаккаунтов: '+demo_password+'\nВсе роли равноправны.\n')
    cfg={'APP_URL':'https://89.208.213.14:8443','WS_URL':'wss://89.208.213.14:8443/ws','DB_HOST':'127.0.0.1','DB_PORT':'33484','DB_NAME':SCHEMA,'DB_USER':'dltk_v3_app','DB_PASSWORD':app_password,'PRIVATE_DIR':str(SHARED/'app-private')}
    write(SHARED/'runtime.ini','[workspace]\n'+''.join(k+'='+json.dumps(v,ensure_ascii=False)+'\n' for k,v in cfg.items()),0o640);os.chown(SHARED/'runtime.ini',0,owner.pw_gid)
    venv=SHARED/'venv';run(['/usr/bin/python3','-m','venv',venv],timeout=120)
    run([venv/'bin/python','-m','pip','install','--disable-pip-version-check','--quiet','-r',release/'requirements.txt'],timeout=180)
    # The venv is immutable application code; project runtime only reads it.
    for path in venv.rglob('*'):
        if not path.is_symlink():path.chmod(0o755 if path.is_dir() or os.access(path,os.X_OK) else 0o644)
    run(['systemctl','enable','--now','dltk-v3-php.service','dltk-v3-realtime.service','dltk-v3-apache.service'])
    receipt={'commit':a.commit,'artifactSha256':a.archive_sha,'sqlSha256':a.sql_sha,'prefix':str(ROOT),'url':cfg['APP_URL'],'mode':'native','oldUnitsBefore':before,'oldUnitsAfter':pid_snapshot(),'utc':dt.datetime.now(dt.timezone.utc).isoformat()}
    write(SHARED/'private/deployment-receipt.json',json.dumps(receipt,indent=2))
    print(json.dumps({'status':'started','commit':a.commit,'url':cfg['APP_URL'],'otherUnitPidsUnchanged':receipt['oldUnitsBefore']==receipt['oldUnitsAfter']}))


if __name__=='__main__':
    try:main()
    except Exception as e:
        print(json.dumps({'status':'failed','error':type(e).__name__,'message':str(e)[:240],'note':'Existing projects were not targeted; inspect this isolated installation before retrying.'}))
        raise SystemExit(1)
