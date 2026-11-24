"""Prepare and transfer approved Avtovyvoz directories without replacing live trips."""

import argparse
from contextlib import closing
import json
import os
from pathlib import Path

from transfer_directory_data import SERVER, connect, encode, one, rows, verify
from transfer_supplemental_directories import put_metadata

BINDINGS = {
    'carriers': ('S',), 'fleet-drivers': ('',), 'fleet-vehicles': ('O',),
    'edo-operators': ('',), 'edo-counterparties': ('', 'AA', 'AC', 'AE', 'AG'),
    'assignment-statuses': ('T',), 'etrn-statuses': ('U',), 'margin-rules': ('',),
}
ENTRY_META = {'carrier_details_': 'carriers', 'edo_party_': 'edo-counterparties',
              'margin_rule_': 'margin-rules'}
FLEET = {
    'fleet_driver_profiles': ('entry_id', 'surname', 'given_name', 'patronymic', 'phone', 'additional_phone'),
    'fleet_vehicle_profiles': ('entry_id', 'brand', 'vehicle_type', 'driver_id'),
    'fleet_trailer_profiles': ('plate_key', 'plate', 'brand'),
    'fleet_vehicle_trailers': ('vehicle_id', 'trailer_key'),
    'fleet_driver_documents': ('driver_id', 'passport', 'license_number', 'license_issued', 'license_expires',
                               'revision', 'passport_number', 'passport_issuer', 'passport_issued', 'birth_date'),
}
RECEIPT = 'avtovyvoz_rollout_v1'


def export_data(profile, target):
    with closing(connect(profile, 'dltk_v3_workspace')) as db, db.cursor() as cur:
        verify(cur, 'dltk_v3_workspace')
        catalogs = {}
        for kind in BINDINGS:
            c = one(cur, 'SELECT id,title FROM catalogs WHERE kind=%s', (kind,))
            if not c:
                raise RuntimeError(f'Missing local catalog: {kind}')
            c['entries'] = rows(cur, """SELECT id,name,normalized,color,active FROM catalog_entries e
                WHERE catalog_id=%s AND NOT EXISTS (SELECT 1 FROM workspace_meta m
                WHERE m.key=CONCAT('catalog_deleted_client_',e.id)) ORDER BY id""", (c['id'],))
            catalogs[kind] = c
        metadata = {}
        for prefix, kind in ENTRY_META.items():
            metadata[prefix] = rows(cur, """SELECT e.id, m.value FROM catalog_entries e
                JOIN workspace_meta m ON m.key=CONCAT(%s,e.id) WHERE e.catalog_id=%s""",
                (prefix, catalogs[kind]['id']))
        carrier_logists = one(cur, """SELECT value FROM workspace_meta m
            WHERE m.key=CONCAT('catalog_logists_',%s)""", (catalogs['carriers']['id'],))
        fleet = {table: rows(cur, f"SELECT {','.join(columns)} FROM {table}") for table, columns in FLEET.items()}
        origins = rows(cur, """SELECT r.row_num,o.field_key,o.entry_id,
            JSON_UNQUOTE(JSON_EXTRACT(r.values_json,CONCAT('$.',o.field_key))) cell_value
            FROM fleet_trip_origins o JOIN records r ON r.id=o.record_id
            JOIN table_definitions t ON t.id=r.table_id WHERE t.slug='vyvoz'""")
        documents = rows(cur, """SELECT r.row_num,d.document_text,
            JSON_UNQUOTE(JSON_EXTRACT(r.values_json,'$.O')) vehicle,
            JSON_UNQUOTE(JSON_EXTRACT(r.values_json,'$.P')) driver
            FROM fleet_trip_documents d JOIN records r ON r.id=d.record_id
            JOIN table_definitions t ON t.id=r.table_id WHERE t.slug='vyvoz'""")
    payload = dict(catalogs=catalogs, metadata=metadata, fleet=fleet, origins=origins,
                   documents=documents, carrier_logists=json.loads(carrier_logists['value']) if carrier_logists else {})
    fd = os.open(target, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as file:
        json.dump(payload, file, ensure_ascii=False, default=str)
    print(encode({kind: len(catalog['entries']) for kind, catalog in catalogs.items()}))


def prepare_schema(cur, db):
    here = Path(__file__).resolve().parent
    for script in ('fleet_directory.sql', 'fleet_details.sql', 'fleet_trip_documents.sql'):
        for statement in (here / script).read_text(encoding='utf-8').split(';'):
            if statement.strip():
                cur.execute(statement)
    additions = {
        'fleet_driver_profiles': {'additional_phone': "VARCHAR(100) NOT NULL DEFAULT ''"},
        'fleet_driver_documents': {'passport_number': "VARCHAR(50) NOT NULL DEFAULT ''",
                                   'passport_issuer': "VARCHAR(400) NOT NULL DEFAULT ''",
                                   'passport_issued': 'DATE NULL', 'birth_date': 'DATE NULL'},
    }
    for table, fields in additions.items():
        current = {row['Field'] for row in rows(cur, f'SHOW COLUMNS FROM {table}')}
        for name, ddl in fields.items():
            if name not in current:
                cur.execute(f'ALTER TABLE {table} ADD COLUMN {name} {ddl}')
    for table in (*FLEET, 'fleet_trip_origins', 'fleet_trip_documents'):
        cur.execute(f"GRANT SELECT,INSERT,UPDATE,DELETE ON dltk_v3_test.{table} TO 'dltk_v3_app'@'localhost'")
    db.commit()


def seed_catalogs(cur, table_id, data):
    id_map, catalog_ids, counts = {}, {}, {}
    for kind, source in data.items():
        existing = one(cur, 'SELECT id FROM catalogs WHERE kind=%s', (kind,))
        if existing:
            catalog_id = existing['id']
        else:
            cur.execute("INSERT INTO catalogs(title,scope,kind) VALUES(%s,'local',%s)", (source['title'], kind))
            catalog_id = cur.lastrowid
        catalog_ids[kind] = catalog_id
        for field in BINDINGS[kind]:
            cur.execute('INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(%s,%s,%s)',
                        (catalog_id, table_id, field))
        known = {r['normalized']: r for r in rows(cur,
            'SELECT id,normalized,color FROM catalog_entries WHERE catalog_id=%s', (catalog_id,))}
        added = 0
        for entry in source['entries']:
            match = known.get(entry['normalized'])
            if match:
                target_id = match['id']
                if match['color'] is None and entry['color'] is not None:
                    cur.execute('UPDATE catalog_entries SET color=%s,revision=revision+1 WHERE id=%s AND color IS NULL',
                                (entry['color'], target_id))
            else:
                cur.execute("""INSERT INTO catalog_entries(catalog_id,name,normalized,color,active)
                    VALUES(%s,%s,%s,%s,%s)""",
                    (catalog_id, entry['name'], entry['normalized'], entry['color'], entry['active']))
                target_id = cur.lastrowid
                added += 1
            id_map[entry['id']] = target_id
        counts[kind] = added
    return id_map, catalog_ids, counts


def seed_fleet(cur, fleet, id_map):
    for table, columns in FLEET.items():
        values = []
        for item in fleet[table]:
            values_by_column = dict(item)
            for key in {'entry_id', 'vehicle_id', 'driver_id'}.intersection(columns):
                values_by_column[key] = id_map.get(item[key])
            if values_by_column[columns[0]] is not None:
                values.append(tuple(values_by_column[column] for column in columns))
        if values:
            slots = ','.join(['%s'] * len(columns))
            cur.executemany(f"""INSERT INTO {table} ({','.join(columns)}) VALUES ({slots})
                ON DUPLICATE KEY UPDATE {columns[0]}={columns[0]}""", values)


def seed_trip_links(cur, table_id, data, id_map):
    live = {r['row_num']: r for r in rows(cur, """SELECT id,row_num,
        JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.O')) O, JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.P')) P
        FROM records WHERE table_id=%s""", (table_id,))}
    links, documents = [], []
    for item in data['origins']:
        row = live.get(item['row_num'])
        field = item['field_key']
        entry_id = id_map.get(item['entry_id'])
        if row and field in ('O', 'P') and entry_id and item['cell_value'] and row[field] == item['cell_value']:
            links.append((row['id'], field, entry_id))
    for item in data['documents']:
        row = live.get(item['row_num'])
        if row and item['driver'] and row['P'] == item['driver'] and row['O'] == item['vehicle']:
            documents.append((row['id'], item['document_text']))
    if links:
        cur.executemany("""INSERT INTO fleet_trip_origins(record_id,field_key,entry_id) VALUES(%s,%s,%s)
            ON DUPLICATE KEY UPDATE entry_id=entry_id""", links)
    if documents:
        cur.executemany("""INSERT INTO fleet_trip_documents(record_id,document_text) VALUES(%s,%s)
            ON DUPLICATE KEY UPDATE document_text=document_text""", documents)
    return {'matchingTripLinks': len(links), 'matchingTripDocuments': len(documents)}


def import_data(package, schema, apply):
    data = json.loads(package.read_bytes())
    if set(data['catalogs']) != set(BINDINGS):
        raise RuntimeError('Unexpected catalog transfer package.')
    with closing(connect(SERVER, 'dltk_v3_test')) as db, db.cursor() as cur:
        verify(cur, 'dltk_v3_test')
        if schema:
            prepare_schema(cur, db)
        one(cur, "SELECT value FROM workspace_meta m WHERE m.key='marker' FOR UPDATE")
        installed = one(cur, 'SELECT value FROM workspace_meta m WHERE m.key=%s', (RECEIPT,))
        if installed:
            print(encode({'mode': 'already applied', 'counts': json.loads(installed['value'])}))
            return
        table = one(cur, "SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE")['id']
        id_map, catalogs, counts = seed_catalogs(cur, table, data['catalogs'])
        for prefix in ENTRY_META:
            for item in data['metadata'][prefix]:
                if item['id'] in id_map:
                    cur.execute('INSERT IGNORE INTO workspace_meta VALUES(%s,%s)',
                                (f"{prefix}{id_map[item['id']]}", item['value']))
        put_metadata(cur, 'catalog_logists', catalogs['carriers'], id_map, data['carrier_logists'])
        seed_fleet(cur, data['fleet'], id_map)
        counts.update(seed_trip_links(cur, table, data, id_map))
        if apply:
            cur.execute('INSERT INTO workspace_meta VALUES(%s,%s)', (RECEIPT, encode(counts)))
            cur.execute("""INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
                VALUES('system',%s,'Автовывоз',%s,'import','catalog',%s)""", (table, encode(counts), RECEIPT))
            db.commit()
        else:
            db.rollback()
    print(encode({'mode': 'applied' if apply else 'preview', 'counts': counts}))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest='mode', required=True)
    source = subparsers.add_parser('export')
    source.add_argument('profile', type=Path)
    source.add_argument('package', type=Path)
    target = subparsers.add_parser('import')
    target.add_argument('package', type=Path)
    target.add_argument('--prepare-schema', action='store_true')
    target.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if args.mode == 'export':
        export_data(args.profile, args.package)
    else:
        import_data(args.package, args.prepare_schema, args.apply)


if __name__ == '__main__':
    main()
