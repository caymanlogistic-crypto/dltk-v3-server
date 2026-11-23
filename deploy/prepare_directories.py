"""Prepare the existing DLTK V3 server schema and PHP runtime for directories."""

import argparse
import os
from pathlib import Path
import re
import subprocess

from provision_shared import suppress_package_fpm_actions

ROOT = Path('/srv/dltk-v3-test')
ADMIN = ROOT / 'shared/private/mysql-admin.cnf'
CONFIG = ROOT / 'shared/runtime.ini'
SCHEMA = 'dltk_v3_test'
TABLES = ('manager_profiles', 'client_manager_links', 'unload_points',
          'unload_point_contacts', 'unload_point_origins')
PHP_PACKAGES = ('php8.4-xml', 'php8.4-zip')
HERE = Path(__file__).resolve().parent


def run(command, **options):
    return subprocess.run(command, check=True, text=True, **options)


def query(statement):
    result = run(['mysql', f'--defaults-file={ADMIN}', '--batch', '--skip-column-names',
                  '--default-character-set=utf8mb4', f'--database={SCHEMA}'],
                 input=statement, stdout=subprocess.PIPE)
    return result.stdout.strip()


def php_modules():
    return set(run(['php-fpm8.4', '-m'], stdout=subprocess.PIPE).stdout.lower().splitlines())


def runtime_check():
    if os.geteuid() != 0:
        raise SystemExit('Run as root on the DLTK server.')
    config = CONFIG.read_text(encoding='utf-8')
    for key, value in {'DB_NAME': SCHEMA, 'DB_USER': 'dltk_v3_app',
                       'APP_BASE_PATH': '/dltk-v3'}.items():
        if not re.search(fr'^{key}\s*=\s*"?{re.escape(value)}"?\s*$', config, re.M):
            raise SystemExit('Unexpected DLTK runtime configuration.')
    if query("SELECT JSON_UNQUOTE(value) FROM workspace_meta WHERE workspace_meta.key='marker'") != 'dltk-workspace-v1':
        raise SystemExit('Unexpected database marker.')
    if query("SELECT COUNT(*) FROM mysql.user WHERE user='dltk_v3_app' AND host='localhost'") != '1':
        raise SystemExit('Expected DLTK database user is missing.')


def missing_tables():
    quoted = ','.join(f"'{table}'" for table in TABLES)
    existing = set(query(f"SELECT table_name FROM information_schema.tables "
                         f"WHERE table_schema=DATABASE() AND table_name IN ({quoted})").splitlines())
    return [table for table in TABLES if table not in existing]


def install_php():
    missing = {'dom', 'zip'} - php_modules()
    if not missing:
        return
    packages = [package for module, package in zip(('dom', 'zip'), PHP_PACKAGES) if module in missing]
    command = ['apt-get', 'install', '-y', '--no-remove', '--no-upgrade',
               '--no-install-recommends', *packages]
    plan = run([command[0], '--simulate', *command[1:]], stdout=subprocess.PIPE).stdout
    for line in plan.splitlines():
        if line.startswith('Remv ') or line.startswith('Inst ') and (
            line.split()[1] not in {*PHP_PACKAGES, 'libzip4', 'libzip5', 'libzip4t64'} or line.split()[2].startswith('[')
        ):
            raise SystemExit('Package transaction would change unrelated or installed packages.')
    with suppress_package_fpm_actions():
        run(command, env=dict(os.environ, DEBIAN_FRONTEND='noninteractive', NEEDRESTART_MODE='l'))
    if not {'dom', 'zip'} <= php_modules():
        raise SystemExit('The PHP-FPM configuration still lacks DOM or ZIP.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    runtime_check()
    print('Missing directory tables:', ', '.join(missing_tables()) or 'none')
    print('Missing PHP-FPM modules:', ', '.join(sorted({'dom', 'zip'} - php_modules())) or 'none')
    if not args.apply:
        return
    install_php()
    for filename in ('manager_directory.sql', 'unload_points.sql'):
        query((HERE / filename).read_text(encoding='utf-8'))
    for table in TABLES:
        quote = chr(96)
        target = f'{quote}{SCHEMA}{quote}.{quote}{table}{quote}'
        query(f"GRANT SELECT,INSERT,UPDATE,DELETE ON {target} TO 'dltk_v3_app'@'localhost'")
    if missing_tables():
        raise SystemExit('Directory schema preparation incomplete.')
    print('Directory tables, grants and PHP-FPM configuration are ready.')


if __name__ == '__main__':
    main()
