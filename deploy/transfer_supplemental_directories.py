"""Transfer the remaining approved local catalog data without replacing server records."""
import argparse
from contextlib import closing
import json
import os
from pathlib import Path

from transfer_directory_data import SERVER, connect, encode, one, rows, verify

KINDS=('carriers','transport','drivers-detailed','drivers-legacy','terminals-detailed',
       'application-contacts','application-checks','etrn-carriers')
RECEIPT='directory_supplement_20261123'


def export_data(profile, target):
    with closing(connect(profile,'dltk_v3_workspace')) as db, db.cursor() as cur:
        verify(cur,'dltk_v3_workspace')
        out=[]
        for kind in (*KINDS,'terminals-main','status'):
            if kind=='status':
                catalog=one(cur,"SELECT c.* FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id "
                            "JOIN table_definitions t ON t.id=b.table_id WHERE c.kind='status' AND t.slug='vyvoz' AND b.field_key='A'")
            else:
                catalog=one(cur,'SELECT * FROM catalogs WHERE kind=%s',(kind,))
            if not catalog:
                raise RuntimeError('Missing source directory: '+kind)
            catalog={key:catalog[key] for key in ('id','title','kind')}
            local_id=catalog['id']
            catalog['entries']=rows(cur,"SELECT id,name,normalized,color,active FROM catalog_entries e WHERE catalog_id=%s "
                                    "AND NOT EXISTS(SELECT 1 FROM workspace_meta m WHERE m.key=CONCAT('catalog_deleted_client_',e.id)) ORDER BY id",(local_id,))
            metadata={}
            for tag in ('catalog_logists','catalog_details','catalog_client_aliases'):
                result=one(cur,'SELECT value FROM workspace_meta m WHERE m.key=%s',(f'{tag}_{local_id}',))
                if result:
                    metadata[tag]=json.loads(result['value'])
            catalog['metadata']=metadata
            out.append(catalog)
    fd=os.open(target,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    with os.fdopen(fd,'w',encoding='utf-8') as file:
        file.write(encode(out))
    print(encode({catalog['kind']:len(catalog['entries']) for catalog in out}))


def put_metadata(cur, tag, target, remap, value):
    key=f'{tag}_{target}'
    before=one(cur,'SELECT value FROM workspace_meta m WHERE m.key=%s',(key,))
    if tag=='catalog_client_aliases':
        value={alias:remap[int(owner)] for alias,owner in value.items() if int(owner) in remap}
    elif tag=='catalog_details':
        value={**value,'entries':{str(remap[int(source)]):entry for source,entry in value.get('entries',{}).items() if int(source) in remap}}
    else:
        value={str(remap[int(source)]):data for source,data in value.items() if int(source) in remap}
    if before:
        prior=json.loads(before['value'])
        if tag=='catalog_details':
            value={**value,**prior,'entries':{**value.get('entries',{}),**prior.get('entries',{})}}
        else:
            value={**value,**prior}
    cur.execute('INSERT INTO workspace_meta VALUES(%s,%s) ON DUPLICATE KEY UPDATE value=VALUES(value)',(key,encode(value)))


def bound_carriers(cur, table, target):
    links=rows(cur,"SELECT b.catalog_id,c.kind FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id "
              "WHERE b.table_id=%s AND b.field_key='S'",(table,))
    for link in links:
        if link['catalog_id']==target:
            continue
        if link['kind']!='text':
            raise RuntimeError('Another production directory owns column S.')
        # The inherited demo binding belongs to the old generic list. Keep the catalog itself.
        cur.execute("DELETE FROM catalog_bindings WHERE catalog_id=%s AND table_id=%s AND field_key='S'",
                    (link['catalog_id'],table))
    cur.execute("INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(%s,%s,'S')",(target,table))


def import_data(path, apply):
    data=json.loads(path.read_bytes())
    if len(data)!=len(KINDS)+2 or {c['kind'] for c in data}!=set(KINDS)|{'status','terminals-main'}:
        raise RuntimeError('Unexpected transfer payload.')
    with closing(connect(SERVER,'dltk_v3_test')) as db, db.cursor() as cur:
        verify(cur,'dltk_v3_test')
        one(cur,"SELECT value FROM workspace_meta m WHERE m.key='marker' FOR UPDATE")
        if one(cur,'SELECT value FROM workspace_meta m WHERE m.key=%s',(RECEIPT,)):
            print('Supplement already applied.')
            return
        table=one(cur,"SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE")['id']
        report={}
        for catalog in data:
            kind=catalog['kind']
            if kind=='status':
                existing=one(cur,"SELECT c.id FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id "
                             "WHERE c.kind='status' AND b.table_id=%s AND b.field_key='A'",(table,))
            else:
                existing=one(cur,'SELECT id FROM catalogs WHERE kind=%s ORDER BY id LIMIT 1',(kind,))
            if not existing:
                cur.execute("INSERT INTO catalogs(title,scope,kind) VALUES(%s,'local',%s)",(catalog['title'],kind))
                target=cur.lastrowid
            else:
                target=existing['id']
            if kind=='carriers':
                bound_carriers(cur,table,target)
            elif kind in KINDS:
                cur.execute("INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key) VALUES(%s,%s,'')",(target,table))
            known={row['normalized']:row for row in rows(cur,'SELECT id,normalized,color,revision FROM catalog_entries WHERE catalog_id=%s',(target,))}
            remap, added, palette_changes={},0,0
            for entry in catalog['entries']:
                current=known.get(entry['normalized'])
                if current:
                    remap[entry['id']]=current['id']
                    if kind=='terminals-main' and current['revision']==1 and entry['color']!=current['color']:
                        cur.execute('UPDATE catalog_entries SET color=%s,revision=revision+1 WHERE id=%s',(entry['color'],current['id']))
                        palette_changes+=1
                else:
                    cur.execute('INSERT INTO catalog_entries(catalog_id,name,normalized,color,active) VALUES(%s,%s,%s,%s,%s)',
                                (target,entry['name'],entry['normalized'],entry['color'],entry['active']))
                    remap[entry['id']]=cur.lastrowid
                    added+=1
            if kind in KINDS:
                for tag,value in catalog['metadata'].items():
                    put_metadata(cur,tag,target,remap,value)
            report[kind]={'added':added,'paletteChanges':palette_changes}
        if not apply:
            db.rollback()
            print(encode({'mode':'preview','catalogs':report}))
            return
        cur.execute('INSERT INTO workspace_meta VALUES(%s,%s)',(RECEIPT,encode(report)))
        cur.execute("INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id) "
                    "VALUES('system',%s,'Справочники',%s,'import','catalog',%s)",(table,encode(report),RECEIPT))
        db.commit()
        print(encode({'mode':'applied','catalogs':report}))


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    subs=parser.add_subparsers(dest='mode',required=True)
    export=subs.add_parser('export')
    export.add_argument('profile',type=Path)
    export.add_argument('package',type=Path)
    target=subs.add_parser('import')
    target.add_argument('package',type=Path)
    target.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    if args.mode=='export':
        export_data(args.profile,args.package)
    else:
        import_data(args.package,args.apply)


if __name__=='__main__':
    main()
