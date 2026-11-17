"""Opt-in deployed-server QA. Writes only one verified demo cell, restores it in finally.

No execution at import/CI collection. Credentials and row contents never enter reports.
"""
from __future__ import annotations
import argparse
import asyncio
import json
from html.parser import HTMLParser
from pathlib import Path
import ssl
import sys
import time
import uuid
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from benchmark import Session, password_from_file, validate_base, meta_value, BenchmarkFailure, is_loopback, origin

TABLE = 'table-2'


def require(condition, message):
    if not condition:
        raise BenchmarkFailure(message)


def api(session, path, body=None):
    raw, _ = session.request(path, 'server QA API', body)
    try:
        return json.loads(raw)
    except (ValueError, TypeError):
        raise BenchmarkFailure('Invalid server QA API response.') from None


class Cells(HTMLParser):
    def __init__(self):
        super().__init__(); self.cells=[]; self.current=None
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag == 'td' and 'data-edit-cell' in attrs:
            self.current={'attrs':attrs,'value':''}
    def handle_data(self, data):
        if self.current is not None:self.current['value']+=data
    def handle_endtag(self, tag):
        if tag == 'td' and self.current is not None:
            self.cells.append(self.current);self.current=None


def find_cell(session, row=None, column=None):
    if row is None:
        raw,_=session.request('/vitrine?table='+TABLE,'demo grid')
        page=raw.decode('utf-8'); session.csrf=meta_value(page,'csrf-token')
        parser=Cells();parser.feed(page)
    else:
        data=api(session,'/api/vitrine/rows?table='+TABLE+'&ids='+str(row))
        parser=Cells();parser.feed(data.get('html',''))
    for item in parser.cells:
        a=item['attrs']
        if (a.get('data-kind')=='text' and int(a.get('data-catalog-id','0'))==0
                and int(a.get('data-reference-id','0'))==0 and json.loads(a.get('data-choices','[]'))==[]
                and int(a.get('data-row','0'))>=1
                and (column is None or a.get('data-column')==column)):
            return {'row':int(a['data-row']),'column':a['data-column'],'value':item['value'],
                    'revision':int(a['data-revision']),'baseHash':a['data-base'],'reset':False}
    raise BenchmarkFailure('No safe unbound demo text cell found.')


async def receive(ws, predicate, seconds=10):
    deadline=asyncio.get_running_loop().time()+seconds
    while True:
        remaining=deadline-asyncio.get_running_loop().time()
        if remaining<=0:raise BenchmarkFailure('Expected WebSocket event timed out.')
        event=json.loads(await asyncio.wait_for(ws.recv(),remaining))
        if predicate(event):return event


async def open_ws(session, no_proxy, context):
    from websockets.asyncio.client import connect
    ticket=session.ticket()
    options={'origin':origin(session.base),'open_timeout':15,'max_size':2*1024*1024}
    if no_proxy or is_loopback(session.base):options['proxy']=None
    if ticket['url'].startswith('wss://'):options['ssl']=context
    ws=await connect(ticket['url'],**options)
    try:
        await ws.send(json.dumps({'type':'auth','ticket':ticket['ticket'],'cursor':ticket['cursor']}))
        await receive(ws,lambda e:e.get('type')=='ready')
        return ws
    except BaseException:
        await ws.close();raise


async def run(args, report):
    password=password_from_file(args.password_file)
    base=validate_base(args.base_url)
    context=ssl.create_default_context(cafile=str(args.ca_file) if args.ca_file else None)
    sessions=[Session(i,base,password,args.no_proxy) for i in (0,1)]
    if args.ca_file:
        import urllib.request
        for session in sessions:session.opener.add_handler(urllib.request.HTTPSHandler(context=context))
    for session in sessions:session.setup()
    actor,observer=sessions
    boots=[api(session,'/api/workspace/bootstrap') for session in sessions]
    require([b['user']['login'] for b in boots]==['manager','logist'],'Expected demo users unavailable.')
    tables=boots[0]['tables']; demos=[t for t in tables if t.get('isDemo') is True]
    real=[t for t in tables if t.get('slug')=='vyvoz']
    require(len(tables)==5 and len(demos)==4 and len(real)==1 and not real[0].get('isDemo'),'Unexpected real/demo table layout.')
    require(any(t.get('slug')==TABLE for t in demos),'QA target is not explicitly marked as demo.')
    report['checks'].append('five tables: four demo and one real, real never written')
    original=find_cell(actor); row=original['row'];column=original['column']
    cell={'tableSlug':TABLE,'row':row,'column':column}
    actor_id=int(boots[0]['user']['id']);actor_name=boots[0]['user']['name']
    sockets=[];attempted=False;qa='SERVER-QA-'+uuid.uuid4().hex
    try:
        a=await open_ws(actor,args.no_proxy,context);sockets.append(a)
        await a.send(json.dumps({'type':'editing','cell':cell}))
        own=await receive(a,lambda e:e.get('type')=='presence' and any(x.get('userId')==actor_id and x.get('tableSlug')==TABLE and x.get('row')==row and x.get('column')==column for x in e.get('editors',[])))
        own_entry=next(x for x in own['editors'] if x.get('userId')==actor_id and x.get('tableSlug')==TABLE and x.get('row')==row and x.get('column')==column)
        connection_id=own_entry['connectionId']
        require(own_entry['userName']==actor_name,'Presence author mismatch.')
        b=await open_ws(observer,args.no_proxy,context);sockets.append(b)
        await receive(b,lambda e:e.get('type')=='presence' and any(x.get('connectionId')==connection_id for x in e.get('editors',[])))
        report['checks'].append('presence starts before save; authenticated name; late subscriber snapshot')
        await a.send(json.dumps({'type':'editing','cell':None}))
        await receive(b,lambda e:e.get('type')=='presence' and not any(x.get('connectionId')==connection_id for x in e.get('editors',[])))
        report['checks'].append('presence stop removes editor')
        # A response failure can occur after commit, so set attempted BEFORE request.
        attempted=True;report['restored']=False;started=time.perf_counter()
        result=api(actor,'/api/vitrine/cell?table='+TABLE,{**original,'value':qa,'requestId':uuid.uuid4().hex,'editAction':'edit'})
        require(result.get('value')==qa,'Demo save confirmation mismatch.')
        event=await receive(b,lambda e:e.get('event')=='cell.changed' and e.get('data',{}).get('tableSlug')==TABLE and e['data'].get('row')==row and e['data'].get('column')==column and e['data'].get('value')==qa)
        require(int(event['data'].get('actorId',0))==actor_id,'Live event author mismatch.')
        report['save_to_observer_ms']=round((time.perf_counter()-started)*1000,2)
        require(find_cell(observer,row,column)['value']==qa,'Second account did not read committed value.')
        history=api(observer,'/api/workspace/history?table='+TABLE+'&row='+str(row)+'&column='+column)
        require(any(e.get('afterValue')==qa and e.get('beforeValue')==original['value'] and int(e.get('actorId') or 0)==actor_id for e in history.get('events',[])),'Expected attributed audit event missing.')
        report['checks'].append('demo save; other WebSocket actor; second account read; exact audit before/after')
        await a.send(json.dumps({'type':'editing','cell':cell}))
        await receive(b,lambda e:e.get('type')=='presence' and any(x.get('connectionId')==connection_id for x in e.get('editors',[])))
        await a.close();sockets.remove(a)
        await receive(b,lambda e:e.get('type')=='presence' and not any(x.get('connectionId')==connection_id for x in e.get('editors',[])))
        report['checks'].append('disconnect removes editor')
    finally:
        # Never overwrite a concurrent colleague update. Retained audit is intentional.
        try:
            if attempted:
                current=find_cell(actor,row,column)
                if current['value']==qa:
                    api(actor,'/api/vitrine/cell?table='+TABLE,{**current,'value':original['value'],'requestId':uuid.uuid4().hex,'editAction':'undo'})
                    require(find_cell(actor,row,column)['value']==original['value'],'Demo restoration verification failed.')
                    report['restored']=True
                elif current['value']==original['value']:report['restored']=True
                else:raise BenchmarkFailure('Demo cell changed concurrently; restoration refused to avoid overwriting another edit.')
        finally:
            for ws in sockets:
                try:await ws.close()
                except Exception:pass
    report['checks'].append('demo value restored; audit retained')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url',required=True)
    parser.add_argument('--password-file',required=True,type=Path)
    parser.add_argument('--ca-file',type=Path,help='Trusted deployment CA/certificate PEM; TLS verification remains enabled.')
    parser.add_argument('--no-proxy',action='store_true')
    parser.add_argument('--output',type=Path)
    args=parser.parse_args()
    report={'status':'failed','checks':[],'restored':None,'real_table_writes':0,'audit_retained':True}
    try:
        asyncio.run(run(args,report));report['status']='ok'
    except BaseException as error:
        # Exception text can contain response bodies or connection secrets; never print it.
        report['errorType']=type(error).__name__
        report['message']=str(error) if isinstance(error,BenchmarkFailure) else 'Server E2E failed; inspect sanitized checks and restoration flag before retrying.'
    if args.output:
        args.output.parent.mkdir(parents=True,exist_ok=True)
        args.output.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps(report,ensure_ascii=False))
    return 0 if report['status']=='ok' else 1


if __name__=='__main__':raise SystemExit(main())
