"""Export approved local directories and seed missing entries on the DLTK server."""

import argparse
from contextlib import closing
import hashlib
import json
import os
from pathlib import Path
import re

import pymysql

SERVER = Path('/srv/dltk-v3-test/shared/private/mysql-admin.cnf')
BINDINGS = {'container-types': 'I', 'dropoff-points': 'M', 'unload-points': 'K', 'managers': ''}
REQUIRED_KINDS = BINDINGS.keys() - {'managers'}
RECEIPT = 'directory_rollout_20261123'


def connect(profile, schema):
    return pymysql.connect(read_default_file=str(profile), database=schema,
                           charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor,
                           connect_timeout=5, read_timeout=40, write_timeout=40)


def rows(cursor, statement, params=()):
    cursor.execute(statement, params)
    return cursor.fetchall()


def one(cursor, statement, params=()):
    cursor.execute(statement, params)
    return cursor.fetchone()


def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


def verify(cursor, expected):
    identity = one(cursor, "SELECT DATABASE() AS schema_name, @@port AS port")
    marker = one(cursor, "SELECT JSON_UNQUOTE(value) marker FROM workspace_meta m WHERE m.key='marker'")
    if (identity['schema_name'] != expected or marker != {'marker': 'dltk-workspace-v1'}
            or expected == 'dltk_v3_workspace' and identity['port'] != 33384
            or expected == 'dltk_v3_test' and identity['port'] != 0):
        raise RuntimeError('Unexpected MySQL instance or workspace marker.')


def manager_directory_retired(cursor):
    return one(cursor, "SELECT 1 FROM workspace_meta m WHERE m.key='manager_directory_retired_v1'") is not None


def valid_catalogs(catalogs):
    kinds = {catalog['kind'] for catalog in catalogs}
    return len(kinds) == len(catalogs) and REQUIRED_KINDS <= kinds <= BINDINGS.keys()


def export_data(profile, target):
    with closing(connect(profile, 'dltk_v3_workspace')) as db, db.cursor() as cur:
        verify(cur, 'dltk_v3_workspace')
        catalogs = rows(cur, """SELECT DISTINCT c.id,c.kind,c.title FROM catalogs c
            JOIN catalog_bindings b ON b.catalog_id=c.id JOIN table_definitions t ON t.id=b.table_id
            WHERE t.slug='vyvoz' AND c.kind IN ('container-types','dropoff-points','unload-points','managers')
            ORDER BY c.id""")
        if manager_directory_retired(cur):
            catalogs = [catalog for catalog in catalogs if catalog['kind'] != 'managers']
        if not valid_catalogs(catalogs):
            raise RuntimeError('The expected local directories were not found.')
        for catalog in catalogs:
            catalog['entries'] = rows(cur, """SELECT e.id,e.normalized,e.name,e.active FROM catalog_entries e
                WHERE e.catalog_id=%s AND NOT EXISTS(SELECT 1 FROM workspace_meta m
                WHERE m.key=CONCAT('catalog_deleted_client_',e.id)) ORDER BY e.id""", (catalog['id'],))
        points_id = next(cat['id'] for cat in catalogs if cat['kind'] == 'unload-points')
        rejected = one(cur, "SELECT value FROM workspace_meta m WHERE m.key=%s", (f'unload_points_review_{points_id}',))
        review_ids = set(json.loads(rejected['value']) if rejected else [])
        points = next(cat for cat in catalogs if cat['kind'] == 'unload-points')
        points['entries'] = [entry for entry in points['entries'] if entry['id'] not in review_ids]
        result = {'version': 1, 'catalogs': catalogs}
        result['managers'], result['links'] = [], []
        if any(catalog['kind'] == 'managers' for catalog in catalogs):
            result['managers'] = rows(cur, """SELECT p.entry_id,p.surname,p.patronymic,r.code,p.phone,p.email
                FROM manager_profiles p JOIN roles r ON r.id=p.role_id""")
            result['links'] = rows(cur, """SELECT l.manager_id,l.position,c.normalized client_key,c.name client_label
                FROM client_manager_links l JOIN catalog_entries c ON c.id=l.client_id
                ORDER BY l.client_id,l.position""")
        result['unloads'] = rows(cur, """SELECT p.entry_id,c.normalized client_key,p.client_label,p.address,p.schedule,p.instruction
            FROM unload_points p LEFT JOIN catalog_entries c ON c.id=p.client_id""")
        result['contacts'] = rows(cur, """SELECT entry_id,name,phone,email,original,is_primary
            FROM unload_point_contacts ORDER BY id""")
        result['origins'] = rows(cur, """SELECT o.entry_id,r.row_num,
            JSON_UNQUOTE(JSON_EXTRACT(r.baseline_json,'$.K')) AS original_address
            FROM unload_point_origins o JOIN records r ON r.id=o.record_id
            JOIN table_definitions t ON t.id=r.table_id WHERE t.slug='vyvoz'""")
        result['dropoffs'] = rows(cur, """SELECT e.id entry_id,m.value profile FROM catalog_entries e
            JOIN catalogs c ON c.id=e.catalog_id AND c.kind='dropoff-points'
            JOIN workspace_meta m ON m.key=CONCAT('dropoff_point_',e.id)""")
        included={entry['id'] for catalog in catalogs for entry in catalog['entries']}
        for name in ('managers', 'unloads', 'contacts', 'origins', 'dropoffs'):
            result[name]=[row for row in result[name] if row['entry_id'] in included]
        result['links']=[link for link in result['links'] if link['manager_id'] in included]
    fd = os.open(target, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as out:
        out.write(encode(result))
    print(encode({catalog['kind']: len(catalog['entries']) for catalog in catalogs}))


def catalog_key(value):
    return re.sub(r'\s+', ' ', value).strip().lower()


def unload_key(point, clients):
    client = clients.get(point['client_key'])
    owner = f'id:{client}' if client else 'name:' + catalog_key(point['client_label'])
    return hashlib.sha256((owner + '\n' + catalog_key(point['address'])).encode()).hexdigest()


def create_directories(cur, table, data, clients):
    mapping, created = {}, set()
    catalogs = data['catalogs']
    points = {row['entry_id']: row for row in data['unloads']}
    for catalog in catalogs:
        kind = catalog['kind']
        matches = rows(cur, 'SELECT id FROM catalogs WHERE kind=%s', (kind,))
        if len(matches) > 1:
            raise RuntimeError('Multiple target catalogs of kind ' + kind)
        if matches:
            catalog_id = matches[0]['id']
        else:
            cur.execute("INSERT INTO catalogs(title,scope,kind) VALUES(%s,'local',%s)", (catalog['title'], kind))
            catalog_id = cur.lastrowid
        column = BINDINGS[kind]
        if column:
            conflict = one(cur, """SELECT catalog_id FROM catalog_bindings WHERE table_id=%s
                AND field_key=%s AND catalog_id<>%s LIMIT 1""", (table, column, catalog_id))
            if conflict:
                raise RuntimeError(f'Another catalog already owns column {column}.')
        cur.execute('INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(%s,%s,%s)',
                    (catalog_id, table, column))
        existing = {row['normalized']: row['id'] for row in rows(cur,
                    'SELECT id,normalized FROM catalog_entries WHERE catalog_id=%s', (catalog_id,))}
        for entry in catalog['entries']:
            normalized = unload_key(points[entry['id']], clients) if kind == 'unload-points' else entry['normalized']
            target_id = existing.get(normalized)
            if not target_id:
                cur.execute('INSERT INTO catalog_entries(catalog_id,name,normalized,active) VALUES(%s,%s,%s,%s)',
                            (catalog_id, entry['name'], normalized, entry['active']))
                target_id = cur.lastrowid
                created.add(entry['id'])
            mapping[entry['id']] = target_id
    return mapping, created


def target_clients(cur, table):
    client_catalog = one(cur, """SELECT c.id FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id
        WHERE b.table_id=%s AND b.field_key='B' AND c.kind='clients'""", (table,))
    if not client_catalog:
        raise RuntimeError('The target client directory is missing.')
    clients = {row['normalized']: row['id'] for row in rows(cur, 'SELECT id,normalized FROM catalog_entries WHERE catalog_id=%s',
               (client_catalog['id'],))}
    aliases = one(cur, 'SELECT value FROM workspace_meta m WHERE m.key=%s',
                  (f"catalog_client_aliases_{client_catalog['id']}",))
    if aliases:
        clients.update({alias: int(owner) for alias, owner in json.loads(aliases['value']).items()
                        if int(owner) in clients.values() and alias not in clients})
    return clients


def import_profiles(cur, data, table, mapping, created, clients):
    roles = {row['code']: row['id'] for row in rows(cur, 'SELECT id,code FROM roles')}
    for profile in data['managers']:
        source = profile['entry_id']
        if source in created:
            cur.execute('INSERT INTO manager_profiles VALUES(%s,%s,%s,%s,%s,%s)',
                (mapping[source], profile['surname'], profile['patronymic'], roles[profile['code']], profile['phone'], profile['email']))
    linked_clients = set()
    if data['links']:
        linked_clients = {row['client_id'] for row in rows(cur, 'SELECT DISTINCT client_id FROM client_manager_links')}
    missing_clients = set()
    for link in data['links']:
        client = clients.get(link['client_key'])
        manager = mapping.get(link['manager_id'])
        if client is None:
            missing_clients.add(link['client_key'])
        elif manager and client not in linked_clients:
            cur.execute('INSERT INTO client_manager_links VALUES(%s,%s,%s)', (client, manager, link['position']))
    for point in data['unloads']:
        source = point['entry_id']
        if source not in created:
            continue
        client = clients.get(point['client_key'])
        if point['client_key'] and client is None:
            missing_clients.add(point['client_key'])
        cur.execute('INSERT INTO unload_points VALUES(%s,%s,%s,%s,%s,%s)',
            (mapping[source], client, point['client_label'], point['address'], point['schedule'], point['instruction']))
    for contact in data['contacts']:
        source = contact['entry_id']
        if source in created:
            cur.execute("""INSERT INTO unload_point_contacts(entry_id,name,phone,email,original,is_primary)
                VALUES(%s,%s,%s,%s,%s,%s)""",
                (mapping[source], contact['name'], contact['phone'], contact['email'], contact['original'], contact['is_primary']))
    for profile in data['dropoffs']:
        source = profile['entry_id']
        if source in created:
            cur.execute('INSERT INTO workspace_meta VALUES(%s,%s)', (f'dropoff_point_{mapping[source]}', profile['profile']))
    unmatched_origins = 0
    for origin in data['origins']:
        source = origin['entry_id']
        if source not in created:
            continue
        record = one(cur, """SELECT id,JSON_UNQUOTE(JSON_EXTRACT(baseline_json,'$.K')) AS original_address
            FROM records WHERE table_id=%s AND row_num=%s""", (table, origin['row_num']))
        if record and record['original_address'] == origin['original_address']:
            cur.execute('INSERT IGNORE INTO unload_point_origins VALUES(%s,%s)', (record['id'], mapping[source]))
        else:
            unmatched_origins += 1
    return {'unmatchedClients': len(missing_clients), 'unmatchedOrigins': unmatched_origins}


def import_data(source, apply):
    raw = source.read_bytes()
    data = json.loads(raw)
    if data.get('version') != 1 or not valid_catalogs(data['catalogs']):
        raise RuntimeError('Unexpected directory transfer package.')
    fingerprint = hashlib.sha256(raw).hexdigest()
    with closing(connect(SERVER, 'dltk_v3_test')) as db, db.cursor() as cur:
        verify(cur, 'dltk_v3_test')
        one(cur, "SELECT value FROM workspace_meta m WHERE m.key='marker' FOR UPDATE")
        previous = one(cur, 'SELECT value FROM workspace_meta m WHERE m.key=%s', (RECEIPT,))
        if previous:
            if json.loads(previous['value'])['fingerprint'] != fingerprint:
                raise RuntimeError('A different package was already imported.')
            print('Package already applied.')
            return
        table = one(cur, "SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE")['id']
        if manager_directory_retired(cur):
            data['catalogs'] = [catalog for catalog in data['catalogs'] if catalog['kind'] != 'managers']
            data['managers'], data['links'] = [], []
        clients = target_clients(cur, table)
        mapping, created = create_directories(cur, table, data, clients)
        details = import_profiles(cur, data, table, mapping, created, clients)
        report = {'fingerprint': fingerprint, 'added': len(created), 'existing': len(mapping)-len(created), **details}
        if not apply:
            db.rollback()
            print(encode({'mode': 'preview', **report}))
            return
        if details['unmatchedClients'] or details['unmatchedOrigins']:
            raise RuntimeError('Unmatched client or shipment links. Review preview before applying.')
        cur.execute('INSERT INTO workspace_meta VALUES(%s,%s)', (RECEIPT, encode(report)))
        cur.execute("""INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
            VALUES('system',%s,'Справочники',%s,'import','catalog',%s)""", (table, encode(report), RECEIPT))
        db.commit()
        print(encode({'mode': 'applied', **report}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_subparsers(dest='mode', required=True)
    export = modes.add_parser('export')
    export.add_argument('profile', type=Path)
    export.add_argument('package', type=Path)
    target = modes.add_parser('import')
    target.add_argument('package', type=Path)
    target.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if args.mode == 'export':
        export_data(args.profile, args.package)
    else:
        import_data(args.package, args.apply)


if __name__ == '__main__':
    main()
