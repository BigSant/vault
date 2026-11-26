"""Isolated action executor. Public results contain states only, never upstream bytes."""
import asyncio
import base64
import binascii
from datetime import datetime, timedelta, timezone
import hmac
import html
import json
import os
import time
import uuid
import pymysql
from contextlib import asynccontextmanager, suppress
from pathlib import Path
import handoff
from browser_policy import origin, registered, request_allowed, static_allowed, static_request
from urllib.parse import quote, quote_plus, urljoin, urlsplit

import httpx
from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel, ConfigDict, Field
from playwright.async_api import async_playwright

operations = {}
proxy = os.environ.get('EGRESS_PROXY', 'http://egress:8080')


def key():
    return Path('/run/vault/broker.key').read_text().strip()


def definitions():
    return json.loads(Path('/config/connectors.json').read_text()).get('actions', {})


async def authorized(op):
    async with httpx.AsyncClient(trust_env=False, timeout=5, follow_redirects=False) as client:
        response = await client.get(os.environ['APP_URL'] + '/internal/operations/' + op['id'], headers={'Authorization': 'Bearer ' + key()})
        data = response.json() if response.status_code == 200 else {}
        return data.get('allowed') is True and all(data.get(k) == op[k] for k in ('entry_id', 'version', 'action'))


async def notify(op, body):
    """Tells the application about a handoff file (written, removed). Only 200 with ok=true counts."""
    try:
        async with httpx.AsyncClient(trust_env=False, timeout=5, follow_redirects=False) as client:
            response = await client.post(os.environ['APP_URL'] + '/api/internal/operations/' + op['id'] + '/handoff',
                                         json=body, headers={'Authorization': 'Bearer ' + key()})
            return response.status_code == 200 and response.json().get('ok') is True
    except Exception:
        return False


async def secret(op):
    credentials = json.loads(Path('/run/vault/bao.json').read_text())
    async with httpx.AsyncClient(base_url=os.environ['BAO_ADDR'] + '/v1/', trust_env=False, timeout=5) as client:
        login = await client.post('auth/approle/login', json=credentials)
        login.raise_for_status()
        token = login.json()['auth']['client_token']
        response = await client.get('secret/data/entries/' + op['entry_id'], params={'version': op['version']}, headers={'X-Vault-Token': token})
        response.raise_for_status()
        return response.json()['data']['data']


async def http_action(action, value):
    async with httpx.AsyncClient(proxy=proxy, trust_env=False, follow_redirects=False, timeout=10) as client:
        # Output is an independently generated boolean, not an upstream body/header/error.
        async with client.stream('GET', action['url'], headers={'Authorization': 'Bearer ' + value['token']}) as response:
            return response.status_code == 200


async def cli_action(action, value):
    proc = await asyncio.create_subprocess_exec('python3', '/opt/vault/http_probe.py', stdin=asyncio.subprocess.PIPE,
                                              stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL,
                                              env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE': '1'})
    try:
        await asyncio.wait_for(proc.communicate(json.dumps({'action': action, 'secret': value, 'proxy': proxy}).encode()), 15)
        return proc.returncode == 0
    finally:
        if proc.returncode is None:
            proc.kill()
            await proc.wait()


async def database_action(action, value):
    role = action['role']
    if value.get('role') != role or not role.startswith('vault-') or not all(c.isalnum() or c == '-' for c in role):
        raise ValueError()
    credentials = json.loads(Path('/run/vault/bao.json').read_text())
    async with httpx.AsyncClient(base_url=os.environ['BAO_ADDR']+'/v1/', trust_env=False, timeout=5) as client:
        login = await client.post('auth/approle/login', json=credentials)
        login.raise_for_status()
        token = login.json()['auth']['client_token']
        headers = {'X-Vault-Token': token}
        try:
            reply = await client.get('database/creds/'+role, headers=headers)
            reply.raise_for_status()
            data = reply.json()['data']

            def check():
                connection = pymysql.connect(host=action['host'], port=int(action.get('port',3306)),
                    user=data['username'], password=data['password'], connect_timeout=3, read_timeout=3, write_timeout=3)
                try:
                    with connection.cursor() as cursor:
                        cursor.execute('SELECT 1')
                        return cursor.fetchone() == (1,)
                finally:
                    connection.close()
            return await asyncio.to_thread(check)
        finally:
            # Revoke this operation's token and its leases. A failed revoke makes the operation fail;
            # the server-side 60s lease remains the independent expiry bound.
            revoked = await client.post('auth/token/revoke-self', headers=headers)
            revoked.raise_for_status()


async def browser_action(op, action, value, capture=None):
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True, chromium_sandbox=True, proxy={'server': proxy},
                                           args=['--proxy-bypass-list=<-loopback>', '--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'])
        context = await browser.new_context(accept_downloads=False, service_workers='block')
        try:
            def policy_violation(*_):
                first = not op.get('policy_denied', False)
                op['policy_denied'] = True
                if first:
                    op['task'].cancel()

            async def deny(request_route):
                policy_violation()
                with suppress(Exception):
                    await request_route.abort()

            async def route(request_route):
                response=None
                try:
                    request=request_route.request
                    navigation=request.is_navigation_request()
                    static=static_allowed(action,request.url,request.method,request.resource_type,navigation)
                    if request.frame==page.main_frame and not static and not request_allowed(action,request.url,request.method) \
                            and static_request(action,request.method,request.resource_type,navigation):
                        # A static GET outside the registered origins (analytics, a font CDN) is dropped before it
                        # leaves; it is not a reason to fail the login.
                        with suppress(Exception):await request_route.abort()
                        return
                    allowed=request.frame==page.main_frame and (static or request_allowed(action,request.url,request.method)) and await authorized(op)
                    if not allowed:
                        await deny(request_route);return
                    # continue_ may follow redirects without another interception. Fetch one
                    # response, validate Location, then let Chromium request the next URL.
                    response=await request_route.fetch(max_redirects=0,timeout=12000)
                    if 'attachment' in response.headers.get('content-disposition','').lower():
                        await deny(request_route);return
                    if response.status in (301,302,303,307,308) and static:
                        # A static subresource is never followed to another place.
                        with suppress(Exception):await request_route.abort()
                        return
                    if response.status in (301,302,303,307,308):
                        location=response.headers.get('location','')
                        method='GET' if response.status==303 or response.status in (301,302) and request.method=='POST' else request.method
                        destination=urljoin(request.url,location)
                        if not location or method!='GET' or not request.is_navigation_request() or not request_allowed(action,destination,method):
                            await deny(request_route);return
                        # Do not deliver 3xx to Chromium: its redirect chain can bypass routing.
                        # A fresh document navigation is intercepted again, including every hop.
                        await request_route.fulfill(status=200,headers={'Content-Type':'text/html','Cache-Control':'no-store','Content-Security-Policy':"default-src 'none'; base-uri 'none'"},
                            body='<meta http-equiv="refresh" content="0;url='+html.escape(destination,quote=True)+'">')
                        return
                    await request_route.fulfill(response=response)
                except Exception:
                    # Playwright errors can include request headers: never let event handlers log them.
                    with suppress(Exception):await request_route.abort()
                finally:
                    if response:
                        with suppress(Exception):await response.dispose()
            await context.route('**/*', route)
            async def deny_socket(socket):
                policy_violation()
                with suppress(Exception):await socket.close()
            await context.route_web_socket('**/*', deny_socket)
            page = await context.new_page()
            # Non-network documents/downloads must fail too; context teardown closes them.
            context.on('page', policy_violation)
            page.on('frameattached', policy_violation)
            page.on('download', policy_violation)
            page.set_default_timeout(12000)
            await page.goto(action['url'], wait_until='domcontentloaded')
            await page.locator(action['username_selector']).fill(value['username'])
            await page.locator(action['password_selector']).fill(value['password'])
            # A selector/form mismatch is a failure, never a reason to ask the model for credentials.
            if not await authorized(op):
                return False
            await page.locator(action['submit_selector']).click()
            if action.get('mfa_selector'):
                await page.locator(action['mfa_selector']).wait_for()
                op['state'] = 'requires_user_action'
                code = await asyncio.wait_for(op['mfa'].get(), 120)
                if not await authorized(op):
                    return False
                await page.locator(action['mfa_selector']).fill(code)
                await page.locator(action['mfa_submit_selector']).click()
                op['state'] = 'running'
            await page.locator(action['success_selector']).wait_for()
            ok = await page.locator(action['success_selector']).inner_text() == action['success_text']
            if ok and capture is not None and not op.get('policy_denied'):
                capture.update(await context.storage_state())
            return ok
        finally:
            await context.close()
            await browser.close()


HANDOFF_KINDS = ('storage_state',)
LOGIN_SECONDS = 180


def lease_seconds(action):
    """The connector's ttl_seconds (default 900): a whole number from 1 to 1800, anything else is refused."""
    value = action.get('ttl_seconds', 900)
    if type(value) is not int or not 1 <= value <= 1800:
        raise ValueError()
    return value


def encodings(secret):
    """The forms a page could store a value in: plain, JSON-escaped, URL-encoded, hex and base64 at every alignment."""
    raw = secret.encode()
    forms = {raw, json.dumps(secret)[1:-1].encode(), quote(secret, safe='').encode(), quote_plus(secret).encode(),
             quote(secret, safe="-_.!~*'()").encode(), binascii.hexlify(raw), ''.join('%%%02X' % byte for byte in raw).encode()}
    for offset in range(3):
        encoded = base64.b64encode(b'\0' * offset + raw)
        first, last = (offset + 2) // 3, (offset + len(raw)) // 3
        core = encoded[first * 4:last * 4]
        if len(core) >= 8:
            forms |= {core, core.translate(bytes.maketrans(b'+/', b'-_'))}
    return [form for form in forms if len(form) >= 4]


def storage_content(action, state, secrets):
    """The executor's file: Playwright storageState limited to the registered origins. Refused when it holds an entry
    value (a page can copy the password into a cookie or localStorage), when it is too large, or when it is empty."""
    allowed = registered(action)
    hosts = {host for _, host, _ in allowed}
    cookies = [cookie for cookie in state.get('cookies', []) if isinstance(cookie, dict)
               and any(host == cookie.get('domain', '').lstrip('.') or host.endswith('.' + cookie.get('domain', '').lstrip('.')) for host in hosts if cookie.get('domain', '').lstrip('.'))]
    origins = [entry for entry in state.get('origins', []) if isinstance(entry, dict) and origin(entry.get('origin', '')) in allowed]
    if not cookies and not origins:
        raise ValueError()
    content = json.dumps({'cookies': cookies, 'origins': origins}, ensure_ascii=False, separators=(',', ':')).encode()
    if len(content) > handoff.MAX_BYTES:
        raise ValueError()
    folded = content.lower()
    for value in secrets:
        if isinstance(value, str) and len(value) >= 4:
            if any(form in content or form.lower() in folded for form in encodings(value)):
                raise ValueError()
    return content


async def perform(op, action):
    """Logs in or calls the target. Returns a final state, or for a handoff kind the bytes of the executor's file."""
    if not await authorized(op):
        return 'revoked'
    kind = action['kind']
    if kind != 'database' and origin(action['url']) is None:
        raise ValueError()
    if kind in HANDOFF_KINDS:
        # Runs are unattended: a login that would wait for a person is not a storage_state login.
        if action.get('mfa_selector') or not isinstance(action.get('entry_types'), list):
            raise ValueError()
        lease_seconds(action)
    value = await secret(op)
    content = None
    try:
        if kind == 'http':
            ok = await http_action(action, value)
        elif kind == 'cli':
            ok = await cli_action(action, value)
        elif kind == 'browser':
            ok = await browser_action(op, action, value)
        elif kind == 'database':
            ok = await database_action(action, value)
        elif kind == 'storage_state':
            captured = {}
            ok = await browser_action(op, action, value, captured)
            if ok and not op.get('policy_denied'):
                # The username is shown by the site itself; every other field must never reach the file.
                content = storage_content(action, captured, [v for k, v in value.items() if k != 'username'])
            captured.clear()
        else:
            raise ValueError()
    finally:
        value.clear()
    if not await authorized(op):
        return 'revoked'
    if not ok or op.get('policy_denied'):
        return 'failed'
    return content if kind in HANDOFF_KINDS else 'succeeded'


async def lease(op, action, content):
    """Writes the file, tells the application, then holds it until release (succeeded) or the TTL (expired). A
    revocation cancels this task; run() removes the file in every case."""
    seconds = lease_seconds(action)
    if not await authorized(op):
        op['state'] = 'revoked'
        return
    handoff.write(op['id'], content)
    op['written'] = True
    until = datetime.now(timezone.utc) + timedelta(seconds=seconds)
    op['lease_expires_at'] = until.strftime('%Y-%m-%dT%H:%M:%SZ')
    # An unrecorded handoff never stays: without the application's ok the file goes at once.
    if not await notify(op, {'event': 'written', 'lease_expires_at': op['lease_expires_at']}):
        raise ValueError()
    op['ready'] = True
    try:
        await asyncio.wait_for(op['release'].wait(), seconds)
        op['reason'], op['state'] = 'release', 'succeeded'
    except TimeoutError:
        op['reason'], op['state'] = 'ttl', 'expired'


def discard(op):
    """Removes the operation's file if this broker wrote it. Never raises: a failure is a failed operation."""
    if not op.get('written'):
        return True
    try:
        handoff.remove(op['id'])
        op['written'] = False
        op['ready'] = False
        return True
    except Exception:
        return False


async def run(op):
    try:
        action = definitions()[op['action']]
        try:
            async with asyncio.timeout(min(LOGIN_SECONDS,max(1,int(action.get('timeout_seconds',LOGIN_SECONDS))))):
                result = await perform(op, action)
        except TimeoutError:
            op['state'] = 'expired'
            return
        if isinstance(result, str):
            op['state'] = result
            return
        await lease(op, action, result)
    except asyncio.CancelledError:
        op['state'] = 'failed' if op.get('policy_denied') else 'revoked'
        op.setdefault('reason', 'revoke')
        raise
    except Exception:
        op['state'] = 'failed'
    finally:
        had_file = op.get('written', False)
        if not discard(op):
            op['state'] = 'failed'
        elif had_file:
            reason = op.get('reason') or ('restart' if op['state'] == 'failed' else 'revoke')
            # Best effort: the application also records the removal when it learns the final state.
            with suppress(BaseException):
                await asyncio.shield(notify(op, {'event': 'removed', 'reason': reason}))
        op['finished_at'] = time.monotonic()


async def reap():
    while True:
        await asyncio.sleep(1)
        for op in list(operations.values()):
            if op['state'] in ('running', 'requires_user_action'):
                try:
                    if not await authorized(op):
                        op.setdefault('reason', 'revoke')
                        op['task'].cancel()
                except Exception:
                    op.setdefault('reason', 'revoke')
                    op['task'].cancel()
            elif time.monotonic() - op.get('finished_at', time.monotonic()) > 600:
                operations.pop(op['id'], None)


async def announce_restart(ids):
    """Files left by an earlier broker are gone; tell the application, which fails those operations."""
    for _ in range(12):
        ids = [oid for oid in ids if not await notify({'id': oid}, {'event': 'removed', 'reason': 'restart'})]
        if not ids:
            return
        await asyncio.sleep(5)


@asynccontextmanager
async def lifespan(app):
    # No operation survives a restart, so no handoff file may either. A missing or unsafe directory only disables
    # handoff kinds (each write checks it again).
    left = []
    with suppress(Exception):
        left = handoff.clean()
    announcer = asyncio.create_task(announce_restart(left))
    janitor = asyncio.create_task(reap())
    yield
    janitor.cancel()
    announcer.cancel()
    for op in operations.values():
        op.setdefault('reason', 'restart')
        op['task'].cancel()
    await asyncio.gather(janitor, announcer, *(op['task'] for op in operations.values()), return_exceptions=True)


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)


@app.middleware('http')
async def authenticate(request: Request, call_next):
    from starlette.responses import JSONResponse
    if not hmac.compare_digest(request.headers.get('authorization', ''), 'Bearer ' + key()):
        return JSONResponse({'error': 'unauthorized'}, status_code=401)
    if int(request.headers.get('content-length', '0')) > 4096:
        return JSONResponse({'error': 'too_large'}, status_code=413)
    return await call_next(request)


class Start(BaseModel):
    model_config = ConfigDict(extra='forbid')
    id: uuid.UUID
    entry_id: uuid.UUID
    version: int = Field(ge=1)
    action: str = Field(max_length=100)


@app.post('/operations')
async def start(body: Start):
    oid = str(body.id)
    if oid in operations:
        return {'id': oid, 'state': operations[oid]['state']}
    op = body.model_dump(mode='json') | {'state': 'running', 'mfa': asyncio.Queue(maxsize=1), 'release': asyncio.Event()}
    if not await authorized(op):
        raise HTTPException(403)
    # Authorization awaited IO; another start can have claimed the same ID/capacity.
    if oid in operations:
        return {'id':oid,'state':operations[oid]['state']}
    if sum(o['state'] in ('running','requires_user_action') for o in operations.values())>=4:
        raise HTTPException(429)
    operations[oid] = op
    op['task'] = asyncio.create_task(run(op))
    return {'id': oid, 'state': 'running'}


@app.get('/operations/{oid}')
async def status(oid: uuid.UUID):
    # Unknown after a restart is a failure, never a replay of the operation.
    op = operations.get(str(oid), {})
    answer = {'id': str(oid), 'state': op.get('state', 'failed')}
    if op.get('ready') and op.get('state') == 'running':
        answer['lease_expires_at'] = op['lease_expires_at']
    return answer


@app.post('/operations/{oid}/release')
async def release(oid: uuid.UUID):
    """The executor is done with the file: remove it, the operation succeeded."""
    op = operations.get(str(oid))
    if not op or (not op['task'].done() and not op.get('ready')):
        raise HTTPException(409)
    if not op['task'].done():
        op['release'].set()
        await asyncio.gather(op['task'], return_exceptions=True)
    return {'id': str(oid), 'state': op['state']}


@app.post('/operations/{oid}/cancel')
async def cancel(oid: uuid.UUID):
    op = operations.get(str(oid))
    if op and not op['task'].done():
        op.setdefault('reason', 'revoke')
        op['task'].cancel()
        await asyncio.gather(op['task'], return_exceptions=True)
    return {'state': 'revoked'}


class Mfa(BaseModel):
    model_config = ConfigDict(extra='forbid')
    code: str = Field(pattern=r'^\d{6,8}$')


@app.post('/operations/{oid}/mfa')
async def mfa(oid: uuid.UUID, body: Mfa):
    op = operations.get(str(oid))
    if not op or op['state'] != 'requires_user_action' or op['mfa'].full() or not await authorized(op):
        raise HTTPException(409)
    op['mfa'].put_nowait(body.code)
    return {'state': 'running'}
