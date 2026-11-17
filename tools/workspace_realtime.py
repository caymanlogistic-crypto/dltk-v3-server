"""Local authenticated durable-outbox WebSocket adapter. Never contacts Google."""
import asyncio
import contextlib
import configparser
import hashlib
import json
import os
import re
import time
import uuid
from pathlib import Path
import pymysql
from websockets.asyncio.server import serve

def runtime_config():
    path = Path(os.environ.get('DLTK_CONFIG_FILE', Path(__file__).resolve().parents[1] / 'config/runtime.ini'))
    parser = configparser.ConfigParser(interpolation=None)
    if not parser.read(path, encoding='utf-8') or not parser.has_section('workspace'):
        raise RuntimeError('Workspace runtime configuration is not available')
    cfg = {key:(json.loads(value) if value.startswith(chr(34)) and value.endswith(chr(34)) else value[1:-1] if value.startswith(chr(39)) and value.endswith(chr(39)) else value) for key,value in parser['workspace'].items()}
    required = ['app_url','db_name','db_user','db_password']
    socket_path = cfg.get('db_socket', '')
    if socket_path:
        if not socket_path.startswith('/') or any(ord(c)<32 or c==';' for c in socket_path):
            raise RuntimeError('Invalid database socket')
    else:
        required += ['db_host','db_port']
    if any(not cfg.get(key) for key in required):
        raise RuntimeError('Workspace runtime configuration is incomplete')
    return cfg


def configured_origins(cfg):
    from urllib.parse import urlsplit
    parsed = urlsplit(cfg['app_url'])
    if parsed.scheme not in ('http','https') or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise RuntimeError('Invalid workspace origin')
    return [parsed.scheme + '://' + parsed.netloc]


def connection():
    cfg = runtime_config()
    transport = {'unix_socket':cfg['db_socket']} if cfg.get('db_socket') else {'host':cfg['db_host'],'port':int(cfg['db_port'])}
    return pymysql.connect(**transport,database=cfg['db_name'],user=cfg['db_user'],password=cfg['db_password'],charset='utf8mb4',autocommit=True,cursorclass=pymysql.cursors.DictCursor,connect_timeout=3,read_timeout=5,write_timeout=5)


def authenticate(ticket):
    if not isinstance(ticket, str) or not 32 <= len(ticket) <= 256:
        return None
    db = connection()
    try:
        db.begin()
        with db.cursor() as cur:
            cur.execute('SELECT t.user_id,u.auth_version,u.name FROM ws_tickets t JOIN users u ON u.id=t.user_id WHERE t.token_hash=%s AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP(6) AND u.active=1 FOR UPDATE', (hashlib.sha256(ticket.encode()).hexdigest(),))
            user = cur.fetchone()
            if user:
                cur.execute('UPDATE ws_tickets SET consumed_at=UTC_TIMESTAMP(6) WHERE token_hash=%s', (hashlib.sha256(ticket.encode()).hexdigest(),))
        db.commit()
        return user
    finally:
        db.close()


def allowed(event, user_id):
    data = event['data']
    return event['event'] != 'preferences.changed' or int(data.get('userId', 0)) == int(user_id)


def envelope(row):
    return {'type': 'event', 'id': int(row['id']), 'event': row['event_type'], 'data': json.loads(row['payload']) if isinstance(row['payload'], str) else row['payload']}


def valid_cell_shape(cell):
    return (isinstance(cell, dict) and set(cell) == {'tableSlug', 'row', 'column'}
            and isinstance(cell['tableSlug'], str) and bool(re.fullmatch(r'[a-z0-9-]{1,80}', cell['tableSlug']))
            and type(cell['row']) is int and 0 < cell['row'] <= 2147483647
            and isinstance(cell['column'], str) and bool(re.fullmatch(r'[A-Z]{1,3}', cell['column'])))


def validate_target(cell):
    if not valid_cell_shape(cell): return False
    db = connection()
    try:
        with db.cursor() as cur:
            cur.execute('SELECT t.columns_json FROM table_definitions t JOIN records r ON r.table_id=t.id WHERE t.slug=%s AND r.row_num=%s AND r.archived=0 AND NOT EXISTS(SELECT 1 FROM access_rules)', (cell['tableSlug'], cell['row']))
            row = cur.fetchone()
            return bool(row and any(c.get('letter') == cell['column'] for c in json.loads(row['columns_json'])))
    finally:
        db.close()


class Hub:
    """One reusable connection and one poller for all clients."""
    def __init__(self):
        self.clients = {}
        self.db = None
        self.next_auth_check = 0

    def snapshot(self, after, check_auth):
        if self.db is None:
            self.db = connection()
        try:
            self.db.ping(reconnect=True)
            with self.db.cursor() as cur:
                users = None
                restricted = False
                if check_auth:
                    cur.execute('SELECT EXISTS(SELECT 1 FROM access_rules) AS restricted')
                    restricted = bool(cur.fetchone()['restricted'])
                    cur.execute('SELECT id,name,active,auth_version FROM users')
                    users = {int(u['id']): u for u in cur.fetchall()}
                cur.execute('SELECT id,event_type,payload FROM outbox_events WHERE id>%s ORDER BY id LIMIT 500', (after,))
                return cur.fetchall(), users, restricted
        except Exception:
            with contextlib.suppress(Exception): self.db.close()
            self.db = None
            raise

    async def send_batch(self, ws, client, rows):
        try:
            for row in rows:
                if int(row['id']) <= client['cursor']: continue
                event = envelope(row)
                if allowed(event, client['user']['user_id']):
                    await asyncio.wait_for(ws.send(json.dumps(event, ensure_ascii=False)), 2)
                client['cursor'] = event['id']
            if rows:
                await asyncio.wait_for(ws.send(json.dumps({'type':'cursor','cursor':client['cursor']})), 2)
        except Exception:
            self.clients.pop(ws, None)
            with contextlib.suppress(Exception): await ws.close(1011, 'Connection unavailable')

    def presence(self, now=None):
        now = time.monotonic() if now is None else now
        editors = []
        for client in self.clients.values():
            if client.get('editing') and client.get('editing_until', 0) <= now:
                client['editing'] = None
            if client.get('editing'):
                editors.append({'connectionId':client['connection_id'], 'userId':int(client['user']['user_id']),
                                'userName':client['user']['name'], **client['editing']})
        return sorted(editors, key=lambda item:item['connectionId'])

    async def publish_presence(self):
        payload = json.dumps({'type':'presence','editors':self.presence()}, ensure_ascii=False)
        for ws, client in list(self.clients.items()):
            if client.get('presence_sent') == payload: continue
            try:
                await asyncio.wait_for(ws.send(payload), 2)
                client['presence_sent'] = payload
            except Exception:
                self.clients.pop(ws, None)
                with contextlib.suppress(Exception): await ws.close()

    async def editing(self, ws, message):
        client = self.clients.get(ws)
        if client is None: return
        if not isinstance(message, dict) or set(message) != {'type','cell'} or message.get('type') != 'editing':
            await ws.send(json.dumps({'type':'presence_error','message':'Некорректный запрос редактирования.'}, ensure_ascii=False))
            return
        cell = message['cell']
        if cell is None:
            client['editing'] = None
            return
        now = time.monotonic()
        if not valid_cell_shape(cell) or (cell != client.get('editing') or client.get('editing_until',0) <= now) and not await asyncio.to_thread(validate_target, cell):
            await ws.send(json.dumps({'type':'presence_error','message':'Ячейка недоступна для редактирования.'}, ensure_ascii=False))
            return
        client['editing'] = dict(cell)
        client['editing_until'] = now + 15

    async def poll(self):
        while True:
            try:
                if not self.clients:
                    await asyncio.sleep(0.1)
                    continue
                now = asyncio.get_running_loop().time()
                check_auth = now >= self.next_auth_check
                batch = list(self.clients.items())
                after = min(c['cursor'] for _, c in batch)
                rows, users, restricted = await asyncio.to_thread(self.snapshot, after, check_auth)
                if check_auth:
                    self.next_auth_check = now + 1
                    for ws, client in list(self.clients.items()):
                        user = client['user']; current = users.get(int(user['user_id']))
                        if restricted or not current or not current['active'] or current['auth_version'] != user['auth_version']:
                            self.clients.pop(ws, None)
                            await ws.close(1008, 'Session or access policy changed')
                        else:
                            user['name'] = current['name']
                await asyncio.gather(*(self.send_batch(ws, client, rows) for ws, client in batch if ws in self.clients))
                await self.publish_presence()
                await asyncio.sleep(0.1 if len(rows) < 500 else 0)
            except Exception:
                for ws in list(self.clients):
                    with contextlib.suppress(Exception): await ws.close(1011, 'Service unavailable')
                self.clients.clear()
                await asyncio.sleep(1)


hub = Hub()


async def handler(ws):
    try:
        raw = await asyncio.wait_for(ws.recv(), 8)
        auth = json.loads(raw)
        if auth.get('type') != 'auth' or type(auth.get('cursor')) is not int or auth['cursor'] < 0:
            await ws.close(1008, 'Authentication required')
            return
        user = await asyncio.to_thread(authenticate, auth.get('ticket'))
        if not user:
            await ws.close(1008, 'Invalid or expired ticket')
            return
        cursor = auth['cursor']
        await ws.send(json.dumps({'type': 'ready', 'cursor': cursor}))
        hub.clients[ws] = {'user':user, 'cursor':cursor, 'connection_id':uuid.uuid4().hex, 'editing':None, 'editing_until':0}
        hub.next_auth_check = 0
        async for raw in ws:
            try:
                message = json.loads(raw)
            except (ValueError, TypeError):
                message = None
            await hub.editing(ws, message)
    except (TimeoutError, ValueError, TypeError, KeyError):
        with contextlib.suppress(Exception): await ws.close(1008, 'Invalid protocol')
    except Exception:
        with contextlib.suppress(Exception): await ws.close(1011, 'Service unavailable')
    finally:
        hub.clients.pop(ws, None)


async def main():
    poller = asyncio.create_task(hub.poll())
    try:
        async with serve(handler, os.environ.get('DLTK_WS_BIND','0.0.0.0'), int(os.environ.get('DLTK_WS_PORT','33386')), origins=configured_origins(runtime_config()), max_size=4096, ping_interval=20, ping_timeout=20, compression=None):
            await asyncio.Future()
    finally:
        poller.cancel()
        with contextlib.suppress(asyncio.CancelledError): await poller
        if hub.db:
            with contextlib.suppress(Exception): hub.db.close()


if __name__ == '__main__':
    asyncio.run(main())
