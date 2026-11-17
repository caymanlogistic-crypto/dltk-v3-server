"""Protocol/security units. Run with the project Python runtime."""
import sys
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import workspace_realtime as live


class RealtimeTests(unittest.TestCase):
    def test_private_preference_routing(self):
        event={'event':'preferences.changed','data':{'userId':2}}
        self.assertFalse(live.allowed(event,1))
        self.assertTrue(live.allowed(event,2))

    def test_common_cell_event(self):
        self.assertTrue(live.allowed({'event':'cell.changed','data':{}},1))

    def test_invalid_ticket_never_connects_database(self):
        with patch.object(live,'connection') as connect:
            for ticket in [None,123,'', 'short','x'*257]:
                self.assertIsNone(live.authenticate(ticket))
            connect.assert_not_called()

    def test_envelope(self):
        result=live.envelope({'id':12,'event_type':'cell.changed','payload':'{"value":"новое"}'})
        self.assertEqual(result,{'type':'event','id':12,'event':'cell.changed','data':{'value':'новое'}})

    def test_origin_allowlist(self):
        origins=live.configured_origins({'app_url':'https://89.208.213.14:8443'})
        self.assertEqual(origins,['https://89.208.213.14:8443'])
        self.assertNotIn('*',origins)
        with self.assertRaises(RuntimeError):live.configured_origins({'app_url':'https://user:password@host'})


class PresenceTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.hub=live.Hub()
        class WS:
            def __init__(self):self.sent=[]
            async def send(self,value):self.sent.append(value)
        self.ws=WS()
        self.client={'user':{'user_id':1,'name':'Actual name'},'connection_id':'abc','editing':None,'editing_until':0}
        self.hub.clients[self.ws]=self.client
        self.cell={'tableSlug':'table-2','row':21,'column':'B'}

    async def test_start_heartbeat_stop(self):
        with patch.object(live,'validate_target',return_value=True) as validate:
            await self.hub.editing(self.ws,{'type':'editing','cell':self.cell})
            await self.hub.editing(self.ws,{'type':'editing','cell':self.cell})
            self.assertEqual(validate.call_count,1)
        self.assertEqual(self.hub.presence()[0]['userName'],'Actual name')
        await self.hub.editing(self.ws,{'type':'editing','cell':None})
        self.assertEqual(self.hub.presence(),[])

    async def test_invalid_and_forged_name_rejected(self):
        await self.hub.editing(self.ws,{'type':'editing','cell':self.cell,'userName':'Forged'})
        self.assertIsNone(self.client['editing'])
        with patch.object(live,'validate_target',return_value=False):
            await self.hub.editing(self.ws,{'type':'editing','cell':self.cell})
        self.assertIsNone(self.client['editing'])
        self.assertEqual(len(self.ws.sent),2)

    async def test_expiry_removes_presence(self):
        self.client.update(editing=self.cell,editing_until=10)
        self.assertEqual(len(self.hub.presence(now=9)),1)
        self.assertEqual(self.hub.presence(now=10),[])

    async def test_schema_bounds(self):
        self.assertTrue(live.valid_cell_shape(self.cell))
        self.assertFalse(live.valid_cell_shape({**self.cell,'row':True}))
        self.assertFalse(live.valid_cell_shape({**self.cell,'column':'bad'}))


class RuntimeConfigTests(unittest.TestCase):
    def test_private_ini_quoted_values(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'runtime.ini'
            path.write_text('[workspace]\nAPP_URL="https://89.208.213.14:8443"\nDB_HOST="db"\nDB_PORT=3306\nDB_NAME=dltk_v3_test\nDB_USER=dltk_app\nDB_PASSWORD="not-a-real-secret"\n',encoding='utf-8')
            with patch.dict(os.environ,{'DLTK_CONFIG_FILE':str(path)}):
                cfg=live.runtime_config()
            self.assertEqual(cfg['db_host'],'db')
            self.assertEqual(cfg['db_port'],'3306')
            self.assertEqual(live.configured_origins(cfg),['https://89.208.213.14:8443'])

if __name__=='__main__': unittest.main()
