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
    'entry_types':['prestashop_customer'],'ttl_seconds':900,'storage_keys':['nav']}


def state(extra_local=None,cookie_value=SESSION,extra_cookies=()):
    return {'cookies':[{'name':'PrestaShop-1','value':cookie_value,'domain':'shop.invalid','path':'/','httpOnly':True},
                       {'name':'tracker','value':'x','domain':'.elsewhere.invalid','path':'/','httpOnly':True},*extra_cookies],
            'origins':[{'origin':'https://shop.invalid','localStorage':[{'name':'nav','value':extra_local or 'collapsed'},{'name':'other','value':'v'}]},
                       {'origin':'https://elsewhere.invalid','localStorage':[{'name':'nav','value':'v'}]}]}


class StorageStateBase(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        import os,tempfile
        server.operations.clear();server.unfinished.clear();server.cleanup.update(done=True,reported=False)
        self.tmp=tempfile.TemporaryDirectory();self.dir=Path(self.tmp.name)/'handoff';self.dir.mkdir(mode=0o700);os.chmod(self.dir,0o2710)
        (self.dir/'.vault-handoff').write_bytes(b'')
        self.env=patch.dict(os.environ,{'VAULT_HANDOFF_DIR':str(self.dir),'VAULT_HANDOFF_GID':''});self.env.start()
        self.allowed=True;self.events=[];self.notify_ok=True;self.page=state();self.action=dict(STORAGE);self.modes_at_notify=[]
        async def authorize(op):
            if isinstance(self.allowed,Exception):raise self.allowed
            return self.allowed
        async def secret(op):return {'username':'synthetic-user','password':PASSWORD}
        async def login(op,action,value,capture=None):
            capture.update(self.page);return True
        async def notify(op,body):
            import stat
            if body['event']=='written':
                self.modes_at_notify.append(stat.S_IMODE(os.lstat(self.file(op['id'])).st_mode))
                self.status_at_notify=await server.status(uuid.UUID(op['id']))
            self.events.append(body);return self.notify_ok
        self.patches=[patch.object(server,'authorized',authorize),patch.object(server,'secret',secret),
            patch.object(server,'browser_action',login),patch.object(server,'notify',notify),
            patch.object(server,'definitions',lambda:{'shop.session':self.action,'fixture.api':{'kind':'http','url':'https://api.invalid/'}})]
        for p in self.patches:p.start()
    async def asyncTearDown(self):
        tasks=[op['task']for op in server.operations.values()]
        for task in tasks:task.cancel()
        await asyncio.gather(*tasks,return_exceptions=True);server.operations.clear();server.unfinished.clear()
        for p in self.patches:p.stop()
        self.env.stop();self.tmp.cleanup()
    def file(self,oid):return self.dir/(oid+'.json')
    def files(self):return [p.name for p in self.dir.iterdir() if p.name!='.vault-handoff']
    async def start(self,oid=None,action='shop.session'):
        oid=oid or str(uuid.uuid4())
        await server.start(server.Start(id=uuid.UUID(oid),entry_id=uuid.uuid4(),version=1,action=action))
        return oid
    async def ready(self,oid):
        for _ in range(200):
            if server.operations[oid].get('ready') or server.operations[oid]['task'].done():return
            await asyncio.sleep(0.005)
    async def finish(self,oid,seconds=2):
        await asyncio.wait_for(asyncio.gather(server.operations[oid]['task'],return_exceptions=True),seconds)
    def assert_no_secret(self,*values):
        import base64,json
        text=json.dumps(values)
        for secret in (PASSWORD,SESSION,base64.b64encode(PASSWORD.encode()).decode()):
            self.assertNotIn(secret,text)


class StorageStateTests(StorageStateBase):
    async def test_release_removes_the_file_and_succeeds(self):
        import json,os,re,stat
        oid=await self.start();await self.ready(oid)
        status=await server.status(uuid.UUID(oid))
        self.assertEqual('running',status['state']);self.assertTrue(status['ready'])
        self.assertRegex(status['lease_expires_at'],r'^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$')
        info=os.lstat(self.file(oid));self.assertEqual(0o640,stat.S_IMODE(info.st_mode));self.assertEqual(os.stat(self.dir).st_gid,info.st_gid)
        content=json.loads(self.file(oid).read_text())
        self.assertEqual(['shop.invalid'],[c['domain'] for c in content['cookies']])
        self.assertEqual([{'origin':'https://shop.invalid','localStorage':[{'name':'nav','value':'collapsed'}]}],content['origins'])
        released=await server.release(uuid.UUID(oid))
        self.assertEqual({'id':oid,'state':'succeeded','removed':True},released);self.assertFalse(self.file(oid).exists())
        self.assertEqual(['written','removed'],[e['event'] for e in self.events]);self.assertEqual('release',self.events[1]['reason'])
        self.assert_no_secret(status,released,self.events)

    async def test_file_is_private_and_not_ready_until_the_application_recorded_it(self):
        oid=await self.start();await self.ready(oid)
        self.assertEqual([0o600],self.modes_at_notify)
        self.assertNotIn('ready',self.status_at_notify);self.assertNotIn('lease_expires_at',self.status_at_notify)
        self.assertTrue((await server.status(uuid.UUID(oid)))['ready'])

    async def test_lease_counts_to_one_absolute_deadline(self):
        from datetime import datetime,timezone
        self.action['ttl_seconds']=2
        async def slow_notify(op,body):
            self.events.append(body)
            if body['event']=='written':await asyncio.sleep(1.2)
            return True
        with patch.object(server,'notify',slow_notify):
            oid=await self.start();await self.ready(oid)
            expires=datetime.strptime(server.operations[oid]['lease_expires_at'],'%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=timezone.utc)
            started=asyncio.get_running_loop().time()
            await self.finish(oid,3)
            # The slow record used part of the TTL: the lease ends at lease_expires_at, not TTL after the reply.
            self.assertLess(asyncio.get_running_loop().time()-started,1.6)
        self.assertEqual('expired',server.operations[oid]['state']);self.assertFalse(self.file(oid).exists())
        self.assertLessEqual(abs((expires-datetime.now(timezone.utc)).total_seconds()),1.5)

    async def test_ttl_end_removes_the_file_and_expires(self):
        self.action['ttl_seconds']=1
        oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
        await self.finish(oid,3)
        self.assertEqual('expired',server.operations[oid]['state']);self.assertFalse(self.file(oid).exists())
        self.assertEqual('ttl',self.events[-1]['reason'])

    async def test_periodic_reaper_removes_the_file_of_a_revoked_lease(self):
        janitor=asyncio.create_task(server.reap())
        try:
            oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
            self.allowed=False
            await self.finish(oid,4)
        finally:
            janitor.cancel();await asyncio.gather(janitor,return_exceptions=True)
        self.assertEqual('revoked',server.operations[oid]['state']);self.assertFalse(self.file(oid).exists())
        self.assertEqual({'event':'removed','reason':'revoke'},self.events[-1])

    async def test_reaper_fails_closed_when_the_application_does_not_answer(self):
        janitor=asyncio.create_task(server.reap())
        try:
            oid=await self.start();await self.ready(oid)
            self.allowed=ConnectionError()
            await self.finish(oid,4)
        finally:
            janitor.cancel();await asyncio.gather(janitor,return_exceptions=True)
        self.assertEqual('revoked',server.operations[oid]['state']);self.assertEqual([],self.files())

    async def test_reaper_checks_leases_at_the_same_time(self):
        calls=[]
        async def slow(op):
            calls.append(asyncio.get_running_loop().time());await asyncio.sleep(0.5);return True
        for _ in range(3):
            oid=await self.start();await self.ready(oid)
        with patch.object(server,'authorized',slow):
            janitor=asyncio.create_task(server.reap())
            await asyncio.sleep(1.3)
            janitor.cancel();await asyncio.gather(janitor,return_exceptions=True)
        self.assertGreaterEqual(len(calls),3);self.assertLess(max(calls[:3])-min(calls[:3]),0.1)

    async def test_revoked_grant_right_before_the_write_creates_nothing(self):
        calls=0;created=[]
        async def authorize(op):
            nonlocal calls;calls+=1
            # perform() checks twice (before the secret, after the login); the third is lease()'s, before create.
            return calls<3
        real=server.handoff.create
        with patch.object(server,'authorized',authorize),patch.object(server.handoff,'create',lambda *a:(created.append(1),real(*a))[1]):
            oid=await self.start();await self.finish(oid)
        self.assertEqual(3,calls);self.assertEqual([],created)
        self.assertEqual('revoked',server.operations[oid]['state']);self.assertEqual([],self.files());self.assertEqual([],self.events)

    async def test_unrecorded_write_is_removed_at_once_and_still_reported_removed(self):
        # The application may have recorded `written` although its answer was lost: `removed` always follows.
        self.notify_ok=False
        oid=await self.start();await self.finish(oid)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual([],self.files())
        self.assertEqual([0o600],self.modes_at_notify)
        self.assertEqual(['written','removed'],[e['event'] for e in self.events])

    async def test_failed_removal_is_kept_retried_and_reported_only_when_done(self):
        oid=await self.start();await self.ready(oid)
        with patch.object(server.handoff,'remove',side_effect=server.handoff.HandoffError()):
            released=await server.release(uuid.UUID(oid))
            self.assertEqual({'id':oid,'state':'failed','removed':False},released)
            self.assertTrue(self.file(oid).exists());self.assertIn(oid,server.unfinished)
            self.assertNotIn('removed',[e['event'] for e in self.events])
            self.assertFalse((await server.cancel(uuid.UUID(oid)))['removed'])
            self.assertNotIn('ready',await server.status(uuid.UUID(oid)))
        janitor=asyncio.create_task(server.reap())
        for _ in range(40):
            if oid not in server.unfinished:break
            await asyncio.sleep(0.05)
        janitor.cancel();await asyncio.gather(janitor,return_exceptions=True)
        # The reaper never waits on the application: the deliverer sends the final event.
        self.assertFalse(self.file(oid).exists());self.assertIn(oid,server.undelivered)
        await server.removed(server.undelivered[oid])
        self.assertEqual('removed',self.events[-1]['event']);self.assertNotIn(oid,server.undelivered)
        self.assertTrue((await server.cancel(uuid.UUID(oid)))['removed'])

    async def test_cancel_answers_the_real_state(self):
        oid=await self.start(action='fixture.api')
        with patch.object(server,'http_action',AsyncMock(return_value=True)):
            await self.finish(oid)
        self.assertEqual({'id':oid,'state':'succeeded','removed':True},await server.cancel(uuid.UUID(oid)))
        unknown=uuid.uuid4()
        self.assertEqual({'id':str(unknown),'state':'failed','removed':True},await server.cancel(unknown))
        server.cleanup['done']=False
        self.assertFalse((await server.cancel(uuid.uuid4()))['removed'])

    async def test_pre_existing_file_fails_and_is_left_alone(self):
        oid=str(uuid.uuid4());self.file(oid).write_text('planted')
        await self.start(oid);await self.finish(oid)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual('planted',self.file(oid).read_text());self.assertEqual([],self.events)

    async def test_symlinked_directory_writes_nothing(self):
        import os
        link=Path(self.tmp.name)/'link';link.symlink_to(self.dir)
        with patch.dict(os.environ,{'VAULT_HANDOFF_DIR':str(link)}):
            oid=await self.start();await self.finish(oid)
        self.assertEqual('failed',server.operations[oid]['state']);self.assertEqual([],self.files())

    async def test_repeated_start_writes_one_file(self):
        oid=await self.start();await self.ready(oid)
        await self.start(oid);await self.start(oid)
        self.assertEqual([oid+'.json'],self.files());self.assertEqual(1,sum(e['event']=='written' for e in self.events))

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

    async def test_ready_leases_do_not_hold_the_login_slots_and_have_their_own_cap(self):
        for _ in range(server.MAX_ACTIVE+2):
            oid=await self.start();await self.ready(oid);self.assertTrue(server.operations[oid]['ready'])
        # Six ready leases; another operation still starts.
        with patch.object(server,'http_action',AsyncMock(return_value=True)):
            await self.start(action='fixture.api')
        with patch.object(server,'MAX_LEASES',server.MAX_ACTIVE+2):
            with self.assertRaises(server.HTTPException) as refused:await self.start()
            self.assertEqual(429,refused.exception.status_code)

    async def test_no_handoff_starts_until_the_startup_cleanup_succeeded(self):
        server.cleanup['done']=False
        with self.assertRaises(server.HTTPException) as refused:await self.start()
        self.assertEqual(503,refused.exception.status_code)
        self.assertEqual([],self.files())

    def assert_refused(self,page,msg=None):
        with self.assertRaises(ValueError,msg=msg):server.storage_content(self.action,page,[PASSWORD])

    async def test_leaks_into_cookies_or_storage_write_nothing(self):
        import base64,json
        from urllib.parse import quote,quote_plus
        cases=[PASSWORD,'x'+base64.b64encode(PASSWORD.encode()).decode(),base64.b64encode(b'ab'+PASSWORD.encode()).decode(),
               base64.urlsafe_b64encode(b'a'+PASSWORD.encode()).decode(),quote(PASSWORD,safe=''),PASSWORD.encode().hex(),PASSWORD.encode().hex().upper(),
               '%'+PASSWORD.encode().hex('%').upper(),PASSWORD[::-1],quote(quote(PASSWORD,safe=''),safe=''),json.dumps(json.dumps({'p':PASSWORD})),
               base64.b64encode(quote(PASSWORD).encode()).decode()]
        for leaked in cases:
            self.page=state(leaked)
            oid=await self.start();await self.finish(oid)
            self.assertEqual('failed',server.operations[oid]['state'],leaked);self.assertEqual([],self.files())
            # The same in an HttpOnly cookie the server set.
            self.assert_refused(state(cookie_value=leaked),leaked)

    def test_review_cases_are_refused(self):
        import base64,json
        from urllib.parse import quote_plus
        for password,value in [('Pä"ss!wörd',json.dumps({'p':'Pä"ss!wörd'})),('a"b\\cdef',json.dumps({'p':'a"b\\cdef'})),
                               ('pass~word',quote_plus('pass~word').replace('~','%7E')),('abcde',base64.b64encode(b'abcde').decode()),
                               ('abc','abc')]:
            with self.assertRaises(ValueError,msg=password):
                server.storage_content(self.action,state(value),[password])
        # Split between a cookie and localStorage, and between two items.
        split=[{'name':'part','value':PASSWORD[:9],'domain':'shop.invalid','path':'/','httpOnly':True}]
        self.assert_refused(state(PASSWORD[9:],extra_cookies=split))
        self.action['storage_keys']=['nav','other']
        page=state(PASSWORD[:9]);page['origins'][0]['localStorage'][1]['value']=PASSWORD[9:]
        self.assert_refused(page)

    def test_source_is_narrowed_before_the_check(self):
        import json
        page=state(extra_cookies=[{'name':'script-set','value':PASSWORD,'domain':'shop.invalid','path':'/','httpOnly':False},
                                  {'name':'shared','value':'s','domain':'.shop.invalid','path':'/','httpOnly':True},
                                  {'name':'sibling','value':'s','domain':'other.shop.invalid','path':'/','httpOnly':True}])
        content=json.loads(server.storage_content(self.action,page,[PASSWORD]))
        # A script-readable cookie is not exported (a page can write it); a parent-domain cookie only host-only for
        # the login host (not the static origin); a sibling's not at all. localStorage: only the connector's storage_keys.
        self.assertEqual([('PrestaShop-1','shop.invalid'),('shared','shop.invalid')],
                         sorted((c['name'],c['domain']) for c in content['cookies']))
        self.assertEqual([['nav']],[[i['name'] for i in o['localStorage']] for o in content['origins']])
        del self.action['storage_keys']
        self.assertEqual([],json.loads(server.storage_content(self.action,state(),[PASSWORD]))['origins'])
        self.action['cookie_names']=['script-set']
        self.assert_refused(page)
        with self.assertRaises(ValueError):server.storage_content(STORAGE,{'cookies':[],'origins':[]},[PASSWORD])

    async def test_unattended_kind_refuses_mfa_bad_ttl_and_old_options(self):
        for change in ({'mfa_selector':'#code'},{'ttl_seconds':1801},{'ttl_seconds':0},{'ttl_seconds':'900'},{'entry_types':None},
                       {'static_subresources':True},{'static_paths':['nope']},{'storage_keys':'nav'},{'cookie_names':[1]}):
            self.action=dict(STORAGE)|change
            oid=await self.start();await self.finish(oid)
            self.assertEqual('failed',server.operations[oid]['state'],change);self.assertEqual([],self.files())

    async def test_broker_start_cleans_the_directory_and_reports_the_operations(self):
        left=str(uuid.uuid4());self.file(left).write_text('{}');(self.dir/'stray').write_text('x')
        server.cleanup['done']=False
        async with server.lifespan(server.app):
            for _ in range(100):
                if self.events:break
                await asyncio.sleep(0.01)
            self.assertTrue(server.cleanup['done'])
        self.assertEqual(['stray'],self.files())
        self.assertEqual([{'event':'removed','reason':'restart'}],self.events)

    async def test_failed_startup_cleanup_refuses_handoffs_and_says_so_without_a_path(self):
        import contextlib,io
        (self.dir/'.vault-handoff').unlink()
        server.cleanup.update(done=False,reported=False)
        err=io.StringIO()
        with contextlib.redirect_stderr(err):
            async with server.lifespan(server.app):
                with self.assertRaises(server.HTTPException) as refused:await self.start()
                self.assertEqual(503,refused.exception.status_code)
                (self.dir/'.vault-handoff').write_bytes(b'')
                self.assertEqual([],server.startup_clean())
                self.assertTrue(server.cleanup['done'])
        self.assertIn('cleanup failed',err.getvalue());self.assertNotIn(str(self.dir),err.getvalue())

    async def test_broker_stop_removes_live_files(self):
        async with server.lifespan(server.app):
            oid=await self.start();await self.ready(oid);self.assertTrue(self.file(oid).exists())
        self.assertFalse(self.file(oid).exists());self.assertEqual('restart',self.events[-1]['reason'])


class LeakTests(unittest.TestCase):
    def test_urls_with_an_entry_value_are_recognised(self):
        import base64
        from urllib.parse import quote
        for url in ['https://cdn.invalid/img/'+PASSWORD+'.png','https://cdn.invalid/x.gif?leak='+quote(PASSWORD),
                    'https://cdn.invalid/img/'+base64.urlsafe_b64encode(PASSWORD.encode()).decode().rstrip('=')+'.png',
                    'https://cdn.invalid/img/'+PASSWORD.encode().hex()+'.png']:
            self.assertTrue(server.leak.carries([url],[PASSWORD]),url)
        self.assertFalse(server.leak.carries(['https://shop.invalid/admin/index.php?controller=AdminDashboard&token=0123456789abcdef0123456789abcdef'],[PASSWORD]))


class LeakBudgetTests(unittest.TestCase):
    """Review round 3: the check must never silently stop, and one input must not silence another."""
    b64=__import__('base64').b64encode(PASSWORD.encode()).decode()

    def test_a_large_first_value_does_not_hide_a_later_one(self):
        noisy=':'.join('tok%04dAbCd'%i for i in range(800))
        for texts in ([noisy,self.b64],['A'*4000]*3+[self.b64],[__import__('json').dumps({'k%d'%i:'v%d'%i for i in range(3000)}),self.b64]):
            self.assertTrue(server.leak.carries(texts,[PASSWORD]))
        self.assertFalse(server.leak.carries([noisy],[PASSWORD]))

    def test_an_exhausted_budget_refuses(self):
        with patch.object(server.leak,'MIN_CANDIDATES',8),patch.object(server.leak,'CANDIDATES_PER_CHAR',0):
            self.assertTrue(server.leak.carries([':'.join('tok%04d'%i for i in range(50))],[PASSWORD]))
        with patch.object(server.leak,'MIN_BYTES',10),patch.object(server.leak,'BYTES_PER_CHAR',0):
            self.assertTrue(server.leak.carries(['x'*100],[PASSWORD]))

    def test_base64_with_a_dangling_character(self):
        import base64
        # 24 bytes: base64 without padding, so one more character makes the length 1 mod 4.
        value=PASSWORD[:24];encoded=base64.b64encode(value.encode()).decode();self.assertEqual(0,len(encoded)%4)
        for text in (encoded+'X','https://shop.invalid/img/'+encoded+'X.png','https://shop.invalid/img/'+base64.urlsafe_b64encode(value.encode()).decode()+'Z.png'):
            self.assertTrue(server.leak.carries([text],[value]),text)

    def test_short_values(self):
        # Three characters or more: found inside a longer text. One or two: only as a whole candidate.
        self.assertTrue(server.leak.carries(['session-k9q-end'],['k9q']))
        self.assertTrue(server.leak.carries(['v='+__import__('base64').b64encode(b'k9q').decode()],['k9q']))
        self.assertTrue(server.leak.carries(['k9'],['k9']))
        self.assertFalse(server.leak.carries(['session-k9-end'],['k9']))

    def test_split_needs_the_parts_at_the_edges(self):
        self.assertTrue(server.leak.carries(['a='+PASSWORD[:9],PASSWORD[9:]+';b'],[PASSWORD]))
        self.assertTrue(server.leak.carries(['x:'+PASSWORD[:5]+'&'+PASSWORD[5:]],[PASSWORD]))


REAL_STATE={'cookies':[
    {'name':'PHPSESSID','value':'0f3c9b1a2d4e5f60718293a4b5c6d7e8','domain':'shop.invalid','path':'/','expires':-1,'httpOnly':True,'secure':True,'sameSite':'Lax'},
    {'name':'PrestaShop-a8de9f477145ac121a047acff0941e35','value':'def50200'+'9c1e7a2b4d6f8031'*58,'domain':'.shop.invalid','path':'/','expires':1764202600,
     'httpOnly':True,'secure':True,'sameSite':'Lax'}],'origins':[]}


class EntryValueTests(unittest.TestCase):
    def test_only_typed_and_secret_fields_are_checked(self):
        value={'username':'u','password':'shop2026','url':'https://shop.invalid/admin123','shop':'shop.invalid','pin':'7788'}
        self.assertEqual(['shop2026'],server.entry_values(value,[]))
        self.assertEqual(['shop2026','7788'],server.entry_values(value,['pin']))
        # An older application that names nothing: every field but the username.
        self.assertEqual(5-1,len(server.entry_values(value)))
        self.assertEqual([],server.entry_values({'username':'u','password':''},[]))

    def test_a_clean_prestashop_state_with_an_ordinary_password_is_written(self):
        action=dict(STORAGE,origins=['https://static.shop.invalid'])
        content=__import__('json').loads(server.storage_content(action,REAL_STATE,server.entry_values({'username':'u','password':'shop2026','url':'https://shop.invalid/admin123'},[])))
        self.assertEqual(['PHPSESSID','PrestaShop-a8de9f477145ac121a047acff0941e35'],[c['name'] for c in content['cookies']])
        # Host-only for the login host, a session cookie, not for the static origin.
        self.assertEqual({('shop.invalid',-1)},{(c['domain'],c['expires']) for c in content['cookies']})

    def test_a_host_only_and_a_parent_cookie_of_the_same_name_and_path_are_refused_not_lost(self):
        state={'cookies':[{'name':'s','value':'a','domain':'shop.invalid','path':'/','httpOnly':True},
                          {'name':'s','value':'b','domain':'.shop.invalid','path':'/','httpOnly':True}],'origins':[]}
        with self.assertRaises(ValueError):server.storage_content(STORAGE,state,[PASSWORD])
        state['cookies'][1]['path']='/admin/'
        self.assertEqual(2,len(__import__('json').loads(server.storage_content(STORAGE,state,[PASSWORD]))['cookies']))

    def test_cookie_hosts_name_the_other_session_recipients(self):
        import json
        state={'cookies':[{'name':'s','value':'a','domain':'.shop.invalid','path':'/','httpOnly':True}],'origins':[]}
        action=dict(STORAGE,cookie_hosts=['static.shop.invalid'])
        self.assertEqual(['shop.invalid','static.shop.invalid'],sorted(c['domain'] for c in json.loads(server.storage_content(action,state,[PASSWORD]))['cookies']))
        with self.assertRaises(ValueError):server.storage_content(dict(STORAGE,cookie_hosts=['elsewhere.invalid']),state,[PASSWORD])

    def test_the_cookie_path_is_checked_too(self):
        state={'cookies':[{'name':'s','value':'a','domain':'shop.invalid','path':'/'+PASSWORD+'/','httpOnly':True}],'origins':[]}
        with self.assertRaises(ValueError):server.storage_content(STORAGE,state,[PASSWORD])


class FakeResponse:
    def __init__(self,status=200,headers=None,body=b'x'):self.status,self.headers,self._body=status,headers or {},body
    async def body(self):return self._body
    async def dispose(self):pass


class FakeRoute:
    def __init__(self,request,response=None):self.request,self.response,self.outcome,self.fulfilled=request,response or FakeResponse(),None,None
    async def abort(self):self.outcome='abort'
    async def fetch(self,**_):self.outcome='fetched';return self.response
    async def fulfill(self,**kwargs):self.outcome='fulfill';self.fulfilled=kwargs


class FakeRequest:
    def __init__(self,url,method='GET',kind='document',navigation=False,frame='main',headers=None):
        self.url,self.method,self.resource_type,self._navigation,self.frame=url,method,kind,navigation,frame
        self.headers=headers or {'referer':'https://shop.invalid/admin123/index.php?controller=AdminDashboard&token=0123456789abcdef0123456789abcdef',
                                 'cookie':'PrestaShop-1=session','authorization':'Basic eA==','origin':'https://shop.invalid'}
    def is_navigation_request(self):return self._navigation


class RouterTests(unittest.IsolatedAsyncioTestCase):
    action={'kind':'storage_state','url':'https://shop.invalid/login','origins':['https://static.shop.invalid'],
            'static_paths':['/themes/','/img/'],'allowed_requests':[{'method':'POST','url':'https://shop.invalid/login'}],
            'ignore_requests':[{'method':'POST','url':'https://shop.invalid/admin123/index.php/common/notifications'}]}

    async def asyncSetUp(self):
        self.allowed=True;self.calls=[];self.fetched=[];self.op={'id':str(uuid.uuid4())}
        router_self=self
        class Context:
            async def fetch(self,url,**kwargs):router_self.fetched.append((url,kwargs));return router_self.static_response
            async def dispose(self):pass
        async def new_context():return Context()
        self.static_response=FakeResponse(200,{'content-type':'text/css','set-cookie':'a=1','location':'/x','content-disposition':'inline','refresh':'0'})
        self.router=server.Router(self.op,self.action,[PASSWORD],new_context,lambda:'main')
        async def authorize(op):self.calls.append('authorized');return self.allowed
        self.patch=patch.object(server,'authorized',authorize);self.patch.start()
    async def asyncTearDown(self):self.patch.stop()

    async def go(self,request,response=None):
        route=FakeRoute(request,response);await self.router.route(route);return route

    async def test_a_url_with_an_entry_value_is_denied_before_anything_else(self):
        import base64
        route=await self.go(FakeRequest('https://shop.invalid/themes/'+base64.urlsafe_b64encode(PASSWORD.encode()).decode()+'.css',kind='stylesheet'))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op['policy_denied']);self.assertEqual([],self.calls+self.fetched)

    async def test_a_static_file_is_authorized_first_and_fetched_with_its_own_headers_only(self):
        route=await self.go(FakeRequest('https://static.shop.invalid/themes/a.css',kind='stylesheet'))
        self.assertEqual(['authorized'],self.calls);self.assertEqual('fulfill',route.outcome)
        (url,kwargs),=self.fetched
        self.assertEqual({'Accept'},set(kwargs['headers']));self.assertEqual(0,kwargs['max_redirects'])
        self.assertEqual({'content-type'},set(route.fulfilled['headers']))
        self.allowed=False;self.fetched.clear()
        route=await self.go(FakeRequest('https://static.shop.invalid/themes/a.css',kind='stylesheet'))
        self.assertEqual('abort',route.outcome);self.assertEqual([],self.fetched);self.assertTrue(self.op['policy_denied'])

    async def test_a_redirecting_or_attachment_static_file_is_dropped(self):
        for response in (FakeResponse(302,{'location':'/elsewhere'}),FakeResponse(200,{'content-disposition':'ATTACHMENT; filename=x'})):
            self.static_response=response
            route=await self.go(FakeRequest('https://shop.invalid/img/a.png',kind='image'))
            self.assertEqual('abort',route.outcome)
        self.assertNotIn('policy_denied',self.op)

    async def test_a_frame_never_gets_a_static_file_or_a_listed_request(self):
        route=await self.go(FakeRequest('https://shop.invalid/img/a.png',kind='image',frame='child'))
        self.assertEqual('abort',route.outcome);self.assertEqual([],self.fetched)
        route=await self.go(FakeRequest('https://shop.invalid/login','POST',kind='document',navigation=True,frame='child'))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op['policy_denied'])

    async def test_a_listed_request_with_an_attachment_or_an_unlisted_redirect_fails(self):
        route=await self.go(FakeRequest('https://shop.invalid/login','POST',navigation=True),FakeResponse(200,{'content-disposition':'attachment'}))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op.pop('policy_denied'))
        route=await self.go(FakeRequest('https://shop.invalid/login','POST',navigation=True),FakeResponse(302,{'location':'https://shop.invalid/unlisted'}))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op.pop('policy_denied'))
        route=await self.go(FakeRequest('https://shop.invalid/login','POST',navigation=True),FakeResponse(302,{'location':'/login?p='+PASSWORD}))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op.pop('policy_denied'))
        route=await self.go(FakeRequest('https://shop.invalid/login','POST',navigation=True),FakeResponse(303,{'location':'/login'}))
        self.assertEqual('fulfill',route.outcome);self.assertIn('refresh',route.fulfilled['body'])

    async def test_ignored_and_unlisted_requests(self):
        route=await self.go(FakeRequest('https://shop.invalid/admin123/index.php/common/notifications?_token=abc&rand=1','POST',kind='xhr'))
        self.assertEqual('abort',route.outcome);self.assertNotIn('policy_denied',self.op)
        route=await self.go(FakeRequest('https://shop.invalid/admin123/index.php/other','POST',kind='xhr'))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op['policy_denied'])

    async def test_after_the_login_is_confirmed_nothing_leaves_and_nothing_fails(self):
        self.op['done']=True
        for request in (FakeRequest('https://shop.invalid/unlisted','POST',kind='xhr'),FakeRequest('https://shop.invalid/img/a.png',kind='image')):
            route=await self.go(request);self.assertEqual('abort',route.outcome)
        self.assertNotIn('policy_denied',self.op);self.assertEqual([],self.calls+self.fetched)
        self.router.violation();self.assertNotIn('policy_denied',self.op)

    async def test_an_overlong_url_is_denied(self):
        route=await self.go(FakeRequest('https://shop.invalid/login?'+'a'*server.MAX_URL,'POST'))
        self.assertEqual('abort',route.outcome);self.assertTrue(self.op['policy_denied'])


class HandoffLifecycleTests(StorageStateBase):
    """Review round 3: a late `written`, final events and the lease slot of a file not yet removed."""
    async def test_a_written_answer_after_the_deadline_never_publishes(self):
        import stat,os
        self.action['ttl_seconds']=1
        modes=[]
        async def late(op,body):
            self.events.append(body)
            if body['event']=='written':
                await asyncio.sleep(1.2);modes.append(stat.S_IMODE(os.lstat(self.file(op['id'])).st_mode))
            return True
        published=[]
        real=server.handoff.Pending.publish
        with patch.object(server,'notify',late),patch.object(server.handoff.Pending,'publish',lambda self:(published.append(1),real(self))[1]):
            oid=await self.start();await self.finish(oid,4)
        self.assertEqual([0o600],modes);self.assertEqual([],published);self.assertEqual('expired',server.operations[oid]['state'])
        self.assertFalse(server.operations[oid].get('ready'));self.assertEqual([],self.files())
        self.assertEqual({'event':'removed','reason':'ttl'},self.events[-1])

    async def test_an_undelivered_final_event_is_retried_and_the_result_kept(self):
        oid=await self.start();await self.ready(oid)
        self.notify_ok=False
        self.assertTrue((await server.release(uuid.UUID(oid)))['removed'])
        self.assertIn(oid,server.undelivered);self.assertEqual('release',server.operations[oid]['reason'])
        server.operations[oid]['finished_at']-=10000
        with patch.object(server,'DELIVERY_SECONDS',0.05):
            janitor=asyncio.create_task(server.reap());deliverer=asyncio.create_task(server.deliver())
            await asyncio.sleep(1.2)
            # Not forgotten after 600 s while the application has not confirmed it.
            self.assertIn(oid,server.operations);self.assertEqual('succeeded',(await server.status(uuid.UUID(oid)))['state'])
            self.notify_ok=True
            await asyncio.sleep(0.3)
            for task in (janitor,deliverer):task.cancel()
            await asyncio.gather(janitor,deliverer,return_exceptions=True)
        self.assertNotIn(oid,server.undelivered)
        removed=[e for e in self.events if e['event']=='removed']
        self.assertGreaterEqual(len(removed),2);self.assertEqual({'release'},{e['reason'] for e in removed})

    async def test_a_file_not_yet_removed_keeps_its_lease_slot(self):
        oid=await self.start();await self.ready(oid)
        with patch.object(server.handoff,'remove',side_effect=server.handoff.HandoffError()):
            await server.release(uuid.UUID(oid))
        self.assertIn(oid,server.unfinished)
        with patch.object(server,'MAX_LEASES',1):
            with self.assertRaises(server.HTTPException) as refused:await self.start()
            self.assertEqual(429,refused.exception.status_code)

    async def test_status_says_whether_the_broker_knows_the_operation(self):
        self.assertEqual({'id':str(uuid.UUID(int=1)),'state':'failed','known':False},await server.status(uuid.UUID(int=1)))
        oid=await self.start();await self.ready(oid)
        self.assertTrue((await server.status(uuid.UUID(oid)))['known'])

    async def test_a_lease_check_never_waits_longer_than_its_bound(self):
        async def stalled(op):await asyncio.sleep(30);return True
        oid=await self.start();await self.ready(oid)
        with patch.object(server,'authorized',stalled),patch.object(server,'CHECK_SECONDS',0.2):
            started=asyncio.get_running_loop().time()
            await server.check(server.operations[oid]);await self.finish(oid)
        self.assertLess(asyncio.get_running_loop().time()-started,1);self.assertEqual('revoked',server.operations[oid]['state'])
