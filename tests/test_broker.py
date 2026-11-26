import asyncio
import sys
from pathlib import Path
import unittest
from unittest.mock import AsyncMock,patch
import uuid
sys.path.insert(0,str(Path(__file__).parents[1]/'broker'))
import server

class BrokerConcurrencyTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):server.operations.clear()
    async def asyncTearDown(self):
        tasks=[op['task']for op in server.operations.values()]
        for task in tasks:task.cancel()
        await asyncio.gather(*tasks,return_exceptions=True);server.operations.clear()
    def body(self):return server.Start(id=uuid.uuid4(),entry_id=uuid.uuid4(),version=1,action='fixture.api')
    async def test_duplicate_start_after_authorization_wait_executes_once(self):
        barrier=asyncio.Event()
        async def authorize(op):await barrier.wait();return True
        body=self.body()
        with patch.object(server,'authorized',authorize),patch.object(server,'run',AsyncMock())as execute:
            calls=[asyncio.create_task(server.start(body))for _ in range(2)]
            await asyncio.sleep(0);barrier.set();await asyncio.gather(*calls);await asyncio.sleep(0)
            self.assertEqual(1,len(server.operations));self.assertEqual(1,execute.await_count)
    async def test_capacity_is_reserved_after_authorization_wait(self):
        barrier=asyncio.Event()
        async def authorize(op):await barrier.wait();return True
        with patch.object(server,'authorized',authorize),patch.object(server,'run',AsyncMock()):
            calls=[asyncio.create_task(server.start(self.body()))for _ in range(10)]
            await asyncio.sleep(0);barrier.set();results=await asyncio.gather(*calls,return_exceptions=True)
            self.assertEqual(4,len(server.operations))
            self.assertEqual(6,sum(isinstance(value,server.HTTPException)and value.status_code==429 for value in results))


PASSWORD='synthetic-password-handoff'
SESSION='synthetic-session-handoff'
STORAGE={'kind':'storage_state','url':'https://shop.invalid/login','origins':['https://static.shop.invalid'],
    'username_selector':'#u','password_selector':'#p','submit_selector':'#s','success_selector':'#ok','success_text':'ok',
    'entry_types':['prestashop_customer'],'ttl_seconds':900}


def state(extra_local=None):
    return {'cookies':[{'name':'PrestaShop-1','value':SESSION,'domain':'shop.invalid','path':'/'},
                       {'name':'tracker','value':'x','domain':'.elsewhere.invalid','path':'/'}],
            'origins':[{'origin':'https://shop.invalid','localStorage':[{'name':'k','value':extra_local or 'v'}]},
                       {'origin':'https://elsewhere.invalid','localStorage':[{'name':'k','value':'v'}]}]}


class StorageStateTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        import os,tempfile
        server.operations.clear()
        self.tmp=tempfile.TemporaryDirectory();self.dir=Path(self.tmp.name)/'handoff';self.dir.mkdir(mode=0o750)
        self.env=patch.dict(os.environ,{'VAULT_HANDOFF_DIR':str(self.dir),'VAULT_HANDOFF_GID':''});self.env.start()
        self.allowed=True;self.events=[];self.notify_ok=True;self.page=state();self.action=dict(STORAGE)
        async def authorize(op):return self.allowed
        async def secret(op):return {'username':'synthetic-user','password':PASSWORD}
        async def login(op,action,value,capture=None):
            capture.update(self.page);return True
        async def notify(op,body):
            self.events.append(body);return self.notify_ok
        self.patches=[patch.object(server,'authorized',authorize),patch.object(server,'secret',secret),
            patch.object(server,'browser_action',login),patch.object(server,'notify',notify),
            patch.object(server,'definitions',lambda:{'shop.session':self.action})]
        for p in self.patches:p.start()
    async def asyncTearDown(self):
        tasks=[op['task']for op in server.operations.values()]
        for task in tasks:task.cancel()
        await asyncio.gather(*tasks,return_exceptions=True);server.operations.clear()
        for p in self.patches:p.stop()
        self.env.stop();self.tmp.cleanup()
    def file(self,oid):return self.dir/(oid+'.json')
    async def start(self,oid=None):
        oid=oid or str(uuid.uuid4())
        await server.start(server.Start(id=uuid.UUID(oid),entry_id=uuid.uuid4(),version=1,action='shop.session'))
        return oid
    async def ready(self,oid):
        for _ in range(200):
            if server.operations[oid].get('ready') or server.operations[oid]['task'].done():return
            await asyncio.sleep(0.005)
    def assert_no_secret(self,*values):
        import base64,json
        text=json.dumps(values)
        for secret in (PASSWORD,SESSION,base64.b64encode(PASSWORD.encode()).decode()):
            self.assertNotIn(secret,text)

    async def test_release_removes_the_file_and_succeeds(self):
        import json,os,stat
        oid=await self.start();await self.ready(oid)
        status=await server.status(uuid.UUID(oid))
        self.assertEqual('running',status['state']);self.assertIn('lease_expires_at',status)
        info=os.lstat(self.file(oid));self.assertEqual(0o640,stat.S_IMODE(info.st_mode));self.assertEqual(os.stat(self.dir).st_gid,info.st_gid)
        content=json.loads(self.file(oid).read_text())
        self.assertEqual(['shop.invalid'],[c['domain'] for c in content['cookies']])
        self.assertEqual(['https://shop.invalid'],[o['origin'] for o in content['origins']])
        self.assertNotIn(PASSWORD,self.file(oid).read_text())
        released=await server.release(uuid.UUID(oid))
        self.assertEqual('succeeded',released['state']);self.assertFalse(self.file(oid).exists())
        self.assertEqual(['written','removed'],[e['event'] for e in self.events]);self.assertEqual('release',self.events[1]['reason'])
        self.assert_no_secret(status,released,self.events)

    async def test_ttl_end_removes_the_file_and_expires(self):
        self.action['ttl_seconds']=1
        oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
        await asyncio.wait_for(asyncio.shield(server.operations[oid]['task']),3)
        self.assertEqual('expired',server.operations[oid]['state']);self.assertFalse(self.file(oid).exists())
        self.assertEqual('ttl',self.events[-1]['reason'])

    async def test_revocation_during_the_lease_removes_the_file(self):
        oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
        await server.cancel(uuid.UUID(oid))
        self.assertEqual('revoked',server.operations[oid]['state']);self.assertFalse(self.file(oid).exists())
        self.assertEqual('revoke',self.events[-1]['reason'])

    async def test_revoked_grant_before_the_write_leaves_no_file(self):
        calls=0
        async def authorize(op):
            nonlocal calls;calls+=1;return calls<3
        with patch.object(server,'authorized',authorize):
            oid=await self.start();await asyncio.gather(server.operations[oid]['task'],return_exceptions=True)
        self.assertEqual('revoked',server.operations[oid]['state']);self.assertEqual([],list(self.dir.iterdir()));self.assertEqual([],self.events)

    async def test_unrecorded_write_is_removed_at_once(self):
        self.notify_ok=False
        oid=await self.start();await asyncio.gather(server.operations[oid]['task'],return_exceptions=True)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual([],list(self.dir.iterdir()))

    async def test_pre_existing_file_fails_and_is_left_alone(self):
        oid=str(uuid.uuid4());self.file(oid).write_text('planted')
        await self.start(oid);await asyncio.gather(server.operations[oid]['task'],return_exceptions=True)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual('planted',self.file(oid).read_text());self.assertEqual([],self.events)

    async def test_symlinked_directory_writes_nothing(self):
        import os
        link=Path(self.tmp.name)/'link';link.symlink_to(self.dir)
        with patch.dict(os.environ,{'VAULT_HANDOFF_DIR':str(link)}):
            oid=await self.start();await asyncio.gather(server.operations[oid]['task'],return_exceptions=True)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual([],list(self.dir.iterdir()))

    async def test_repeated_start_writes_one_file(self):
        oid=await self.start();await self.ready(oid)
        await self.start(oid);await self.start(oid)
        self.assertEqual([oid+'.json'],[p.name for p in self.dir.iterdir()]);self.assertEqual(1,sum(e['event']=='written' for e in self.events))

    async def test_release_before_the_file_is_ready_is_refused(self):
        gate=asyncio.Event()
        async def slow(op,action,value,capture=None):
            await gate.wait();capture.update(self.page);return True
        with patch.object(server,'browser_action',slow):
            oid=await self.start();await asyncio.sleep(0.01)
            with self.assertRaises(server.HTTPException) as refused:await server.release(uuid.UUID(oid))
            self.assertEqual(409,refused.exception.status_code)
            with self.assertRaises(server.HTTPException):await server.release(uuid.uuid4())
            gate.set()

    async def test_page_that_copies_the_password_into_storage_writes_nothing(self):
        import base64
        from urllib.parse import quote
        for leaked in (PASSWORD,'x'+base64.b64encode(PASSWORD.encode()).decode(),base64.b64encode(b'ab'+PASSWORD.encode()).decode(),
                       base64.urlsafe_b64encode(b'a'+PASSWORD.encode()).decode(),quote(PASSWORD,safe=''),PASSWORD.encode().hex(),PASSWORD.encode().hex().upper(),'%'+PASSWORD.encode().hex('%').upper()):
            self.page=state(leaked)
            oid=await self.start();await asyncio.wait_for(asyncio.gather(server.operations[oid]['task'],return_exceptions=True),2)
            self.assertEqual('failed',server.operations[oid]['state'],leaked);self.assertEqual([],list(self.dir.iterdir()))

    async def test_unattended_kind_refuses_mfa_and_bad_ttl(self):
        for change in ({'mfa_selector':'#code'},{'ttl_seconds':1801},{'ttl_seconds':0},{'ttl_seconds':'900'},{'entry_types':None}):
            self.action=dict(STORAGE)|change
            oid=await self.start();await asyncio.gather(server.operations[oid]['task'],return_exceptions=True)
            self.assertEqual('failed',server.operations[oid]['state'],change);self.assertEqual([],list(self.dir.iterdir()))

    async def test_broker_start_cleans_the_directory_and_reports_the_operations(self):
        left=str(uuid.uuid4());self.file(left).write_text('{}');(self.dir/'stray').write_text('x')
        async with server.lifespan(server.app):
            for _ in range(100):
                if self.events:break
                await asyncio.sleep(0.01)
        self.assertEqual([],list(self.dir.iterdir()))
        self.assertEqual([{'event':'removed','reason':'restart'}],self.events)

    async def test_broker_stop_removes_live_files(self):
        async with server.lifespan(server.app):
            oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
        self.assertFalse(self.file(oid).exists());self.assertEqual('restart',self.events[-1]['reason'])

    def test_storage_content_keeps_registered_origins_only(self):
        import json
        content=json.loads(server.storage_content(STORAGE,state(),[PASSWORD]))
        self.assertEqual(['shop.invalid'],[c['domain'] for c in content['cookies']])
        with self.assertRaises(ValueError):server.storage_content(STORAGE,{'cookies':[],'origins':[]},[PASSWORD])
