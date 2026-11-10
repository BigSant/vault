"""Isolated action executor. Public results contain states only, never upstream bytes."""
import asyncio
import hmac
import html
import json
import os
import time
import uuid
import pymysql
from contextlib import asynccontextmanager, suppress
from pathlib import Path
from browser_policy import origin, request_allowed
from urllib.parse import urljoin

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


async def browser_action(op, action, value):
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True, chromium_sandbox=True, proxy={'server': proxy},
                                           args=['--proxy-bypass-list=<-loopback>', '--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'])
        context = await browser.new_context(accept_downloads=False, service_workers='block')
        try:
            async def deny(request_route):
                first=not op.get('policy_denied',False)
                op['policy_denied']=True
                with suppress(Exception):await request_route.abort()
                if first:op['task'].cancel()

            async def route(request_route):
                response=None
                try:
                    request=request_route.request
                    allowed=request.frame==page.main_frame and request_allowed(action,request.url,request.method) and await authorized(op)
                    if not allowed:
                        await deny(request_route);return
                    # continue_ may follow redirects without another interception. Fetch one
                    # response, validate Location, then let Chromium request the next URL.
                    response=await request_route.fetch(max_redirects=0,timeout=12000)
                    if 'attachment' in response.headers.get('content-disposition','').lower():
                        await deny(request_route);return
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
                first=not op.get('policy_denied',False)
                op['policy_denied']=True
                with suppress(Exception):await socket.close()
                if first:op['task'].cancel()
            await context.route_web_socket('**/*', deny_socket)
            page = await context.new_page()
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
            return await page.locator(action['success_selector']).inner_text() == action['success_text']
        finally:
            await context.close()
            await browser.close()


async def run(op):
    try:
        action = definitions()[op['action']]
        async with asyncio.timeout(min(180,max(1,int(action.get('timeout_seconds',180))))):
            if not await authorized(op):
                op['state'] = 'revoked'
                return
            if action['kind'] != 'database' and origin(action['url']) is None:
                raise ValueError()
            value = await secret(op)
            if action['kind'] == 'http':
                ok = await http_action(action, value)
            elif action['kind'] == 'cli':
                ok = await cli_action(action, value)
            elif action['kind'] == 'browser':
                ok = await browser_action(op, action, value)
            elif action['kind'] == 'database':
                ok = await database_action(action, value)
            else:
                raise ValueError()
            value.clear()
            op['state'] = ('succeeded' if ok else 'failed') if await authorized(op) else 'revoked'
    except TimeoutError:
        op['state'] = 'expired'
    except asyncio.CancelledError:
        op['state'] = 'failed' if op.get('policy_denied') else 'revoked'
        raise
    except Exception:
        op['state'] = 'failed'
    finally:
        op['finished_at'] = time.monotonic()


async def reap():
    while True:
        await asyncio.sleep(1)
        for op in list(operations.values()):
            if op['state'] in ('running', 'requires_user_action'):
                try:
                    if not await authorized(op):
                        op['task'].cancel()
                except Exception:
                    op['task'].cancel()
            elif time.monotonic() - op.get('finished_at', time.monotonic()) > 600:
                operations.pop(op['id'], None)


@asynccontextmanager
async def lifespan(app):
    janitor = asyncio.create_task(reap())
    yield
    janitor.cancel()
    for op in operations.values():
        op['task'].cancel()
    await asyncio.gather(janitor, *(op['task'] for op in operations.values()), return_exceptions=True)


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
    op = body.model_dump(mode='json') | {'state': 'running', 'mfa': asyncio.Queue(maxsize=1)}
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
    return {'id': str(oid), 'state': operations.get(str(oid), {}).get('state', 'failed')}


@app.post('/operations/{oid}/cancel')
async def cancel(oid: uuid.UUID):
    op = operations.get(str(oid))
    if op and not op['task'].done():
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
