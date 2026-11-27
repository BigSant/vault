"""Isolated action executor. Public results contain states only, never upstream bytes."""
import asyncio
from datetime import datetime, timedelta, timezone
import hmac
import html
import json
import os
import sys
import time
import uuid
import pymysql
from contextlib import asynccontextmanager, suppress
from pathlib import Path
import handoff
import leak
from browser_policy import ignore_config_valid, ignored, origin, registered, request_allowed, static_allowed, static_config, static_request
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
        allowed = data.get('allowed') is True and all(data.get(k) == op[k] for k in ('entry_id', 'version', 'action'))
        for name in ('secret_fields', 'text_fields'):
            fields = data.get(name)
            if allowed and isinstance(fields, list) and all(isinstance(f, str) for f in fields):
                # The entry type's field names by kind (names only): what the leak checks look for (entry_values).
                op[name] = fields
        return allowed


async def notify(op, body):
    """Tells the application about a handoff file (written, removed). True only for 200 with ok=true; for `removed`
    also for 404 (the application does not know the operation: nothing to record)."""
    try:
        async with httpx.AsyncClient(trust_env=False, timeout=5, follow_redirects=False) as client:
            response = await client.post(os.environ['APP_URL'] + '/api/internal/operations/' + op['id'] + '/handoff',
                                         json=body, headers={'Authorization': 'Bearer ' + key()})
            if response.status_code == 404 and body.get('event') == 'removed':
                return True
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


# Response headers a static subresource never delivers: it cannot set a cookie or move the page.
DROPPED_RESPONSE_HEADERS = frozenset({'set-cookie', 'set-cookie2', 'location', 'refresh', 'content-disposition'})
# The only request headers a static file is fetched with: set here, never copied from the page (no Referer with a
# back-office URL and its token, no Cookie, no Authorization, no Origin).
STATIC_ACCEPT = {'stylesheet': 'text/css,*/*;q=0.1', 'image': 'image/avif,image/webp,image/png,image/*,*/*;q=0.8',
                 'font': '*/*', 'script': '*/*', 'media': '*/*', 'texttrack': '*/*', 'manifest': '*/*'}
# A request URL longer than this is denied: the check below must be able to read all of it.
MAX_URL = 8192
# The fields an action types into the page. The username is shown by the site itself, so it is never checked.
TYPED_FIELDS = ('password',)


def entry_values(value, secret_fields=None, text_fields=None):
    """The values a request URL or the handoff file must never carry. With the application's list of the entry type's
    *Text* fields: every field but the username and those (so a field no longer in the type, whose value is still
    stored, is checked too). Else with its list of secret fields: the fields typed into the page (password) and
    those. Else (an older application): every field but the username. Text fields (a URL, a shop name) are not
    checked: they would refuse ordinary pages. Empty values carry nothing."""
    if text_fields is not None:
        names = set(value) - {'username'} - (set(text_fields) - set(TYPED_FIELDS))
    elif secret_fields is not None:
        names = ({*TYPED_FIELDS, *secret_fields} & set(value)) - {'username'}
    else:
        names = set(value) - {'username'}
    return [value[k] for k in sorted(names) if isinstance(value[k], str) and value[k]]


class Failure(ValueError):
    """Ends an operation as failed with a coarse reason (FAILURES): what the operator sees in the audit, never a value,
    a URL or a selector."""

    def __init__(self, code):
        super().__init__()
        self.code = code if code in FAILURES else 'error'


# The reasons a failed operation can have (docs/integrations.md lists them for the operator).
FAILURES = frozenset({
    'policy',        # a request, frame, popup, download or WebSocket the connector does not allow
    'leak',          # a request URL or the session state carried an entry value (or could not be checked completely)
    'unauthorized',  # the application withdrew the operation during the login
    'login',         # the page did not behave as configured: a selector, a page timeout, the success text
    'state',         # the session state was refused for its shape: a partitioned or duplicate cookie, empty, too large
    'connector',     # the connector is not valid for its kind
    'handoff',       # the handoff file could not be written, recorded or removed
    'restart',       # the broker restarted while the operation ran
    'error',         # anything else
})
# Requests already sent when the login is confirmed are waited for at most this long before the state is read.
DRAIN_SECONDS = 15


class Router:
    """The request policy of one login, kept apart from Playwright so that it can be tested with fakes.

    In order: once the login is confirmed (`op['done']`) nothing new is sent and nothing fails; a URL too long to
    check or carrying an entry value is denied (the operation fails); a request the connector ignores is dropped; a
    static file is fetched anonymously after the application confirms the operation; any other static-typed GET is
    dropped; everything else must be an exact rule from the main frame and is fetched once, its redirects and
    attachments checked here.

    Sending is admitted only through admit(), which looks at `done` and counts the send with no await in between,
    and only after the last await of the decision (the application's confirmation). close() sets `done` and then
    waits for the sends already admitted: after it returns nothing is on its way and nothing new leaves."""

    def __init__(self, op, action, secrets, new_request_context, main_frame):
        self.op, self.action, self.secrets = op, action, secrets
        self.new_request_context, self.main_frame = new_request_context, main_frame
        self.sending = 0
        self.idle = asyncio.Event()
        self.idle.set()

    def violation(self, *_, code='policy'):
        if self.op.get('done'):
            return
        first = not self.op.get('policy_denied', False)
        self.op['policy_denied'] = True
        if first:
            self.op.setdefault('failure', code)
            if self.op.get('task'):
                self.op['task'].cancel()

    async def deny(self, request_route, code='policy'):
        self.violation(code=code)
        await self.drop(request_route)

    @staticmethod
    async def drop(request_route):
        with suppress(Exception):
            await request_route.abort()

    def admit(self):
        """True when a send may still start, and then counted until settle(). Never awaits: between this check of
        `done` and the send there is no point at which the login can be confirmed unseen."""
        if self.op.get('done'):
            return False
        self.sending += 1
        self.idle.clear()
        return True

    def settle(self):
        self.sending -= 1
        if not self.sending:
            self.idle.set()

    async def close(self, seconds=None):
        """The login is confirmed: from now on nothing new is sent (a request still waiting for the application, or
        arriving later, is dropped), and the sends already admitted are waited for, so that the state read next is
        the one the site's last answer left. Raises TimeoutError when they do not finish in time."""
        self.op['done'] = True
        await asyncio.wait_for(self.idle.wait(), DRAIN_SECONDS if seconds is None else seconds)

    async def static_fetch(self, request_route, request):
        # A fresh request context per file: no cookie jar (not even one a static response tried to set), no
        # credentials, and only the headers set here.
        anonymous = await self.new_request_context()
        response = None
        try:
            response = await anonymous.fetch(request.url, method='GET', headers={'Accept': STATIC_ACCEPT.get(request.resource_type, '*/*')},
                                             max_redirects=0, timeout=12000, fail_on_status_code=False)
            if 300 <= response.status < 400 or 'attachment' in response.headers.get('content-disposition', '').lower():
                # A static subresource is never followed to another place.
                await self.drop(request_route)
                return
            await request_route.fulfill(status=response.status, body=await response.body(),
                headers={k: v for k, v in response.headers.items() if k.lower() not in DROPPED_RESPONSE_HEADERS})
        finally:
            if response:
                with suppress(Exception):
                    await response.dispose()
            with suppress(Exception):
                await anonymous.dispose()

    async def route(self, request_route):
        response = None
        admitted = False
        action, op = self.action, self.op
        try:
            request = request_route.request
            if op.get('done'):
                # The login is confirmed: whatever the page still sends is dropped, not a failure.
                await self.drop(request_route)
                return
            if len(request.url) > MAX_URL or leak.carries([request.url], self.secrets):
                # An entry value in a URL (path or query, plain or encoded) never leaves, whatever the rule, and also
                # fails an ignored or static request.
                await self.deny(request_route, 'leak')
                return
            navigation = request.is_navigation_request()
            main = request.frame == self.main_frame()
            listed = request_allowed(action, request.url, request.method)
            if not listed and main and not navigation and ignored(action, request.url, request.method):
                # Decided before anything is fetched: an ignored path under static_paths is not sent as a static file.
                # An exact allowed_requests rule wins over an ignore rule.
                await self.drop(request_route)
                return
            if main and static_allowed(action, request.url, request.method, request.resource_type, navigation):
                if not await authorized(op):
                    await self.deny(request_route, 'unauthorized')
                    return
                if not self.admit():
                    # Confirmed while the application answered: nothing more leaves.
                    await self.drop(request_route)
                    return
                admitted = True
                await self.static_fetch(request_route, request)
                return
            if not listed and static_request(action, request.method, request.resource_type, navigation):
                # A static GET outside the static paths or registered origins (analytics, a font CDN, a
                # state-changing URL dressed as an image) is dropped before it leaves; the login goes on.
                await self.drop(request_route)
                return
            if not (main and listed):
                await self.deny(request_route)
                return
            if not await authorized(op):
                await self.deny(request_route, 'unauthorized')
                return
            if not self.admit():
                await self.drop(request_route)
                return
            admitted = True
            # continue_ may follow redirects without another interception. Fetch one
            # response, validate Location, then let Chromium request the next URL.
            response = await request_route.fetch(max_redirects=0, timeout=12000)
            if 'attachment' in response.headers.get('content-disposition', '').lower():
                await self.deny(request_route)
                return
            if response.status in (301, 302, 303, 307, 308):
                location = response.headers.get('location', '')
                method = 'GET' if response.status == 303 or response.status in (301, 302) and request.method == 'POST' else request.method
                destination = urljoin(request.url, location)
                if len(destination) > MAX_URL or leak.carries([destination], self.secrets):
                    await self.deny(request_route, 'leak')
                    return
                if not location or method != 'GET' or not navigation or not request_allowed(action, destination, method):
                    await self.deny(request_route)
                    return
                # Do not deliver 3xx to Chromium: its redirect chain can bypass routing.
                # A fresh document navigation is intercepted again, including every hop.
                await request_route.fulfill(status=200, headers={'Content-Type': 'text/html', 'Cache-Control': 'no-store', 'Content-Security-Policy': "default-src 'none'; base-uri 'none'"},
                    body='<meta http-equiv="refresh" content="0;url=' + html.escape(destination, quote=True) + '">')
                return
            await request_route.fulfill(response=response)
        except Exception:
            # Playwright errors can include request headers: never let event handlers log them.
            await self.drop(request_route)
        finally:
            if response:
                with suppress(Exception):
                    await response.dispose()
            if admitted:
                self.settle()


async def browser_action(op, action, value, capture=None):
    secrets_ = entry_values(value, op.get('secret_fields'), op.get('text_fields'))
    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True, chromium_sandbox=True, proxy={'server': proxy},
                                           args=['--proxy-bypass-list=<-loopback>', '--disable-quic', '--force-webrtc-ip-handling-policy=disable_non_proxied_udp'])
        context = await browser.new_context(accept_downloads=False, service_workers='block')
        router = None
        try:
            page = None
            router = Router(op, action, secrets_, lambda: pw.request.new_context(proxy={'server': proxy}), lambda: page.main_frame)
            await context.route('**/*', router.route)

            async def deny_socket(socket):
                router.violation()
                with suppress(Exception):
                    await socket.close()
            await context.route_web_socket('**/*', deny_socket)
            page = await context.new_page()
            # Non-network documents/downloads must fail too; context teardown closes them.
            context.on('page', router.violation)
            page.on('frameattached', router.violation)
            page.on('download', router.violation)
            page.set_default_timeout(12000)
            try:
                await page.goto(action['url'], wait_until='domcontentloaded')
                await page.locator(action['username_selector']).fill(value['username'])
                await page.locator(action['password_selector']).fill(value['password'])
            except Exception:
                raise Failure('login') from None
            # A selector/form mismatch is a failure, never a reason to ask the model for credentials.
            if not await authorized(op):
                return False
            try:
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
            except TimeoutError:
                # No MFA code in time: the operation expires, as before.
                raise
            except Exception:
                raise Failure('login') from None
            if not ok:
                op.setdefault('failure', 'login')
            if ok and not op.get('policy_denied'):
                # From here on nothing new leaves and nothing the page still does (an XHR on document-ready, a timer)
                # fails the operation: the login is confirmed. What was already sent is waited for first, so that the
                # state is read after the site's last answer, never while a request is still on its way.
                try:
                    await router.close()
                except TimeoutError:
                    raise Failure('login') from None
                if capture is not None:
                    capture.update(await context.storage_state())
            return ok
        finally:
            op['done'] = True
            await context.close()
            await browser.close()


HANDOFF_KINDS = ('storage_state',)
LOGIN_SECONDS = 180
# Logins (and other operations) in progress at a time. A lease whose file is ready holds no browser and does not
# count; leases have their own limit.
MAX_ACTIVE = 4
MAX_LEASES = 16
# The application's answer to a lease check; a check that takes longer fails closed (the lease ends as revoked).
CHECK_SECONDS = 5
# How often undelivered final events (`removed`) are sent again, how many at a time, and for how long at most.
DELIVERY_SECONDS = 5
DELIVERY_BATCH = 8
DELIVERY_GIVE_UP = 86400
# Startup cleanup of the handoff directory. Until it has succeeded no handoff is started (503).
cleanup = {'done': False, 'reported': False}
# Finished operations whose file could not be removed: retried by the reaper; never reported as removed before.
unfinished = {}
# Finished operations whose final event (`removed`) the application has not confirmed yet: sent again until it does.
# Kept in the handoff directory too (operation IDs and reasons only, handoff.save_journal), so that a broker restart
# does not lose them.
undelivered = {}


def lease_seconds(action):
    """The connector's ttl_seconds (default 900): a whole number from 1 to 1800, anything else is refused."""
    value = action.get('ttl_seconds', 900)
    if type(value) is not int or not 1 <= value <= 1800:
        raise ValueError()
    return value


def names(action, key):
    """An optional list of names in the connector (storage_keys, cookie_names, cookie_hosts); anything else is refused."""
    value = action.get(key, [])
    if not isinstance(value, list) or not all(isinstance(v, str) and 0 < len(v) <= 128 for v in value):
        raise ValueError()
    return set(value)


def login_host(action):
    return origin(action['url'])[1]


def cookie_hosts(action):
    """The hosts whose cookies the executor gets: the login host, plus the connector's `cookie_hosts` (each one a
    registered host). A static-file or other network origin gets no session unless it is named here."""
    extra = names(action, 'cookie_hosts')
    hosts = {host for _, host, _ in registered(action)}
    if not extra <= hosts:
        raise ValueError()
    return sorted({login_host(action)} | extra)


SAME_SITE = {'Strict', 'Lax', 'None'}


def storage_content(action, state, secrets):
    """The executor's file: Playwright storageState narrowed at the source, then checked (best effort).

    Cookies: only HttpOnly ones (a page script cannot write those) plus the connector's `cookie_names`, only those that
    apply to the login host or a host in `cookie_hosts`, each exported host-only for that exact host (a parent-domain
    cookie is narrowed, so the executor's browser does not send it to sibling subdomains) and as a session cookie
    (the site's expiry is not copied). A host-only and a parent-domain cookie with the same name and path for the same
    host cannot both be exported host-only: such a state is refused rather than one of them silently lost. A
    partitioned cookie (CHIPS: `Partitioned`, Playwright's `partitionKey`) is refused too: exported without its
    partition it would become an ordinary cookie in the executor's browser.
    localStorage: none, except the connector's `storage_keys` on the registered origins. Refused when a cookie's name,
    value or path or an item's name or value (repeatedly decoded) carries an entry value, when the result is too
    large, or when it is empty. What is checked is only what the site chose; the file's own JSON is not. Every refusal
    is a Failure: 'leak' for a value, 'state' for the shape."""
    allowed = registered(action)
    hosts = cookie_hosts(action)
    readable, keys = names(action, 'cookie_names'), names(action, 'storage_keys')
    cookies, seen, exported = [], set(), set()
    for cookie in state.get('cookies', []):
        if not isinstance(cookie, dict) or not all(isinstance(cookie.get(k), str) for k in ('name', 'value', 'domain')):
            continue
        if cookie.get('httpOnly') is not True and cookie['name'] not in readable:
            continue
        path = cookie.get('path', '/')
        if not isinstance(path, str) or not path.startswith('/') or len(path) > 256:
            continue
        raw = cookie['domain'].lower()
        domain = raw.lstrip('.')
        for host in hosts:
            # A host-only cookie applies to its host only; a domain cookie (".shop.example") to it and subdomains.
            if not domain or not (host == domain or raw.startswith('.') and host.endswith('.' + domain)):
                continue
            if (cookie['name'], raw, host, path) in seen:
                continue
            if (cookie['name'], host, path) in exported:
                raise Failure('state')
            if cookie.get('partitionKey') or cookie.get('_crHasCrossSiteAncestor'):
                # Its meaning (only in this top-level site) cannot be kept in the file: refused, never widened.
                raise Failure('state')
            seen.add((cookie['name'], raw, host, path))
            exported.add((cookie['name'], host, path))
            cookies.append({'name': cookie['name'], 'value': cookie['value'], 'domain': host, 'path': path, 'expires': -1,
                            'httpOnly': cookie.get('httpOnly') is True, 'secure': cookie.get('secure') is True,
                            'sameSite': cookie.get('sameSite') if cookie.get('sameSite') in SAME_SITE else 'Lax'})
    origins = []
    for entry in state.get('origins', []):
        if isinstance(entry, dict) and origin(entry.get('origin', '')) in allowed:
            items = [{'name': item['name'], 'value': item['value']} for item in entry.get('localStorage', [])
                     if isinstance(item, dict) and item.get('name') in keys and isinstance(item.get('value'), str)]
            if items:
                origins.append({'origin': entry['origin'], 'localStorage': items})
    if not cookies and not origins:
        raise Failure('state')
    content = json.dumps({'cookies': cookies, 'origins': origins}, ensure_ascii=False, separators=(',', ':')).encode()
    if len(content) > handoff.MAX_BYTES:
        raise Failure('state')
    texts = [c[k] for c in cookies for k in ('name', 'value', 'path')]
    texts += [i[k] for o in origins for i in o['localStorage'] for k in ('name', 'value')]
    if leak.carries(texts, secrets):
        raise Failure('leak')
    return content


def check_connector(action):
    """Refuses options this broker no longer reads, and malformed new ones, before anything is fetched."""
    if 'static_subresources' in action:
        # Replaced by static_paths (docs/integrations.md): an old connector fails instead of loading every GET.
        raise ValueError()
    if 'static_paths' in action and static_config(action) is None:
        raise ValueError()
    if not ignore_config_valid(action):
        raise ValueError()
    names(action, 'storage_keys')
    names(action, 'cookie_names')
    if action['kind'] in HANDOFF_KINDS:
        cookie_hosts(action)


async def perform(op, action):
    """Logs in or calls the target. Returns a final state, or for a handoff kind the bytes of the executor's file."""
    if not await authorized(op):
        return 'revoked'
    kind = action['kind']
    if kind != 'database' and origin(action['url']) is None:
        raise Failure('connector')
    try:
        check_connector(action)
        if kind in HANDOFF_KINDS:
            # Runs are unattended: a login that would wait for a person is not a storage_state login.
            if action.get('mfa_selector') or not isinstance(action.get('entry_types'), list):
                raise ValueError()
            lease_seconds(action)
    except ValueError:
        raise Failure('connector') from None
    if kind in HANDOFF_KINDS and not cleanup['done']:
        raise Failure('handoff')
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
                # Off the event loop: a large state takes a moment to check, and lease checks must go on meanwhile.
                content = await asyncio.to_thread(storage_content, action, captured, entry_values(value, op.get('secret_fields'), op.get('text_fields')))
            captured.clear()
        else:
            raise Failure('connector')
    finally:
        value.clear()
    if not await authorized(op):
        return 'revoked'
    if not ok or op.get('policy_denied'):
        return 'failed'
    return content if kind in HANDOFF_KINDS else 'succeeded'


async def lease(op, action, content):
    """Writes the file (0600), tells the application, and only on its ok, and only while the lease has time left,
    makes it group-readable (0640): ready. Then holds it until release (succeeded) or one absolute deadline (expired).
    A revocation cancels this task; run() removes the file in every case."""
    seconds = lease_seconds(action)
    if not await authorized(op):
        op['state'] = 'revoked'
        return
    loop = asyncio.get_running_loop()
    deadline = loop.time() + seconds
    op['lease_expires_at'] = (datetime.now(timezone.utc) + timedelta(seconds=seconds)).strftime('%Y-%m-%dT%H:%M:%SZ')
    try:
        op['pending'] = handoff.create(op['id'], content)
    except handoff.Leftover:
        # Created, then neither completed nor removed: it may still be there (0600). Kept as written, so that run()
        # tries again and, failing that, the reaper keeps the file (and its lease slot) until it is gone.
        op['written'] = True
        raise Failure('handoff') from None
    except handoff.HandoffError:
        raise Failure('handoff') from None
    op['written'] = True
    # From the moment `written` is sent the application may have recorded it, even if its answer never arrives:
    # `removed` is then always sent too (the application ignores it for a file it never recorded).
    op['announced'] = True
    # An unrecorded handoff never becomes readable: without the application's ok the file goes at once.
    if not await notify(op, {'event': 'written', 'lease_expires_at': op['lease_expires_at']}):
        raise Failure('handoff')
    if loop.time() >= deadline:
        # The answer came after the lease ended: the file is removed unread, never shown.
        op['reason'], op['state'] = 'ttl', 'expired'
        return
    try:
        op.pop('pending').publish()
    except handoff.HandoffError:
        raise Failure('handoff') from None
    op['ready'] = True
    try:
        await asyncio.wait_for(op['release'].wait(), max(0, deadline - loop.time()))
        op['reason'], op['state'] = 'release', 'succeeded'
    except TimeoutError:
        op['reason'], op['state'] = 'ttl', 'expired'


def discard(op):
    """Removes the operation's file if this broker wrote it. Never raises: False means it may still be there."""
    op['ready'] = False
    if not op.get('written'):
        return True
    try:
        pending = op.pop('pending', None)
        if not (pending and pending.discard()):
            handoff.remove(op['id'])
        op['written'] = False
        return True
    except Exception:
        return False


REASON = {'succeeded': 'release', 'expired': 'ttl', 'revoked': 'revoke', 'failed': 'error'}


def removal_event(op):
    """The final event's body: the reason, and for a failure its coarse code (FAILURES)."""
    body = {'event': 'removed', 'reason': op['reason']}
    if op['reason'] == 'error' and op.get('failure') in FAILURES:
        body['failure'] = op['failure']
    return body


def persist():
    """Writes the undelivered final events (IDs, reasons, failure codes, since when) next to the files. Never a value
    or session data. A failure is reported once; the events are still retried from memory."""
    entries = {oid: {'reason': op['reason'], 'since': op['delivery_wall'], **({'failure': op['failure']} if op.get('failure') in FAILURES else {})}
               for oid, op in undelivered.items()}
    try:
        handoff.save_journal(entries)
        cleanup['journal_reported'] = False
    except Exception:
        if not cleanup.get('journal_reported'):
            report('undelivered final handoff events could not be saved; they are retried from memory only until the broker restarts')
            cleanup['journal_reported'] = True


def enqueue(op):
    """Keeps an unconfirmed final event for the deliverer, in memory and in the journal."""
    op.setdefault('reason', REASON.get(op.get('state'), 'error'))
    op.setdefault('delivery_since', time.monotonic())
    op.setdefault('delivery_wall', time.time())
    known = op['id'] in undelivered
    undelivered[op['id']] = op
    if not known:
        persist()


def forget(oid):
    if undelivered.pop(oid, None) is not None:
        persist()


async def removed(op):
    """Tells the application the file is gone: only after a confirmed removal, and only when `written` was sent. The
    reason is fixed at the first attempt; an attempt that is not confirmed is repeated by the deliverer until it is."""
    if not op.get('announced'):
        return True
    op.setdefault('reason', REASON.get(op['state'], 'error'))
    op.setdefault('delivery_since', time.monotonic())
    op.setdefault('delivery_wall', time.time())
    op['attempted_at'] = time.monotonic()
    try:
        delivered = await asyncio.shield(notify(op, removal_event(op)))
    except BaseException:
        delivered = False
    if delivered:
        op['announced'] = False
        forget(op['id'])
    else:
        enqueue(op)
    return delivered


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
            if result == 'failed':
                op.setdefault('failure', 'error')
            return
        await lease(op, action, result)
    except asyncio.CancelledError:
        op['state'] = 'failed' if op.get('policy_denied') else 'revoked'
        op.setdefault('reason', 'error' if op.get('policy_denied') else 'revoke')
        if op.get('reason') == 'restart':
            op.setdefault('failure', 'restart')
        raise
    except Exception as error:
        op['state'] = 'failed'
        op.setdefault('failure', error.code if isinstance(error, Failure) else 'error')
    finally:
        if not discard(op):
            # Kept and retried by the reaper; never reported as removed until it is.
            op['state'] = 'failed'
            op.setdefault('failure', 'handoff')
            unfinished[op['id']] = op
        else:
            await removed(op)
        op['finished_at'] = time.monotonic()


def report(message):
    """One line on stderr, never a path or a value."""
    print('vault-broker: ' + message, file=sys.stderr, flush=True)


def startup_clean():
    """Removes every handoff file an earlier broker left. Returns the operation IDs removed."""
    try:
        left, complete = handoff.clean()
    except Exception:
        left, complete = [], False
    if complete:
        if cleanup['reported']:
            report('handoff directory cleanup succeeded; handoff actions are accepted again')
        cleanup.update(done=True, reported=False)
    elif not cleanup['reported']:
        report('handoff directory cleanup failed (missing, unmarked, not owned by the broker, or a file could not be removed); handoff actions are refused until it succeeds')
        cleanup['reported'] = True
    return left


def restore_journal():
    """The final events an earlier broker could not deliver: queued again with their reasons."""
    try:
        entries = handoff.load_journal()
    except Exception:
        return
    for oid, entry in entries.items():
        if oid in undelivered or entry.get("reason") not in handoff.JOURNAL_REASONS:
            continue
        op = {'id': oid, 'announced': True, 'state': 'failed', 'reason': entry['reason'], 'delivery_wall': entry.get('since', time.time()),
              'delivery_since': time.monotonic() - max(0, time.time() - entry.get('since', time.time()))}
        if entry.get('failure') in FAILURES:
            op['failure'] = entry['failure']
        undelivered[oid] = op




async def check(op):
    try:
        allowed = await asyncio.wait_for(authorized(op), CHECK_SECONDS)
    except Exception:
        # The application cannot answer in time (restart, a stalled database): fail closed, the lease ends as revoked.
        allowed = False
    if not allowed and not op['task'].done():
        op.setdefault('reason', 'revoke')
        op['task'].cancel()


async def deliver_once():
    """One round: at most DELIVERY_BATCH events, those tried longest ago first; a day without confirmation gives up."""
    now = time.monotonic()
    for oid, op in list(undelivered.items()):
        if now - op.get('delivery_since', now) > DELIVERY_GIVE_UP:
            forget(oid)
            report('a final handoff event was not confirmed by the application for a day; vault:links and vault:maintenance settle it')
    batch = sorted(undelivered.values(), key=lambda op: op.get('attempted_at', 0))[:DELIVERY_BATCH]
    await asyncio.gather(*(removed(op) for op in batch), return_exceptions=True)


async def deliver():
    """Sends undelivered final events again every DELIVERY_SECONDS, apart from the lease checks."""
    while True:
        await asyncio.sleep(DELIVERY_SECONDS)
        await deliver_once()


def holds_lease(op):
    """A handoff that still counts against MAX_LEASES: running, or finished with its file not confirmed gone."""
    return is_handoff(op['action']) and (not op['task'].done() or op['id'] in unfinished)


async def reap():
    announcers = set()
    tick = 0
    while True:
        await asyncio.sleep(1)
        tick += 1
        # Every live operation is checked at the same time, each check bounded by CHECK_SECONDS, and nothing else
        # waits in this loop: a revocation ends a lease (and removes its file) at most 2 * CHECK_SECONDS + 1 seconds
        # after it (a wave already under way, the pause, the next check), usually within 1-2 s.
        await asyncio.gather(*(check(op) for op in list(operations.values()) if op['state'] in ('running', 'requires_user_action')))
        for oid, op in list(unfinished.items()):
            if discard(op):
                unfinished.pop(oid, None)
                if op.get('announced'):
                    # Delivered by the deliverer, never awaited here.
                    enqueue(op)
        if not cleanup['done'] and tick % 10 == 0:
            left = startup_clean()
            if left:
                task = asyncio.create_task(announce_restart(left))
                announcers.add(task)
                task.add_done_callback(announcers.discard)
        for op in list(operations.values()):
            # A result is kept 600 s after the end, and always until its file is gone and its final event confirmed.
            if op['task'].done() and op['id'] not in unfinished and op['id'] not in undelivered \
                    and time.monotonic() - op.get('finished_at', time.monotonic()) > 600:
                operations.pop(op['id'], None)


async def announce_restart(ids):
    """Files left by an earlier broker are gone; tell the application, which fails those operations. One attempt, in
    batches; what it does not confirm goes to the deliverer (and its journal), which repeats it."""
    ids = [oid for oid in ids if oid not in undelivered]
    left = []
    for i in range(0, len(ids), DELIVERY_BATCH):
        batch = ids[i:i + DELIVERY_BATCH]
        results = await asyncio.gather(*(notify({'id': oid}, {'event': 'removed', 'reason': 'restart'}) for oid in batch), return_exceptions=True)
        left += [oid for oid, ok in zip(batch, results) if ok is not True]
    for oid in left:
        enqueue({'id': oid, 'announced': True, 'state': 'failed', 'reason': 'restart'})
    if left:
        report('%d final handoff events after a restart were not confirmed by the application; retried every %d s' % (len(left), DELIVERY_SECONDS))


@asynccontextmanager
async def lifespan(app):
    # No operation survives a restart, so no handoff file may either. Until the directory is clean no handoff starts.
    left = startup_clean()
    restore_journal()
    announcer = asyncio.create_task(announce_restart(left))
    janitor = asyncio.create_task(reap())
    deliverer = asyncio.create_task(deliver())
    yield
    janitor.cancel()
    announcer.cancel()
    deliverer.cancel()
    for op in operations.values():
        op.setdefault('reason', 'restart')
        op['task'].cancel()
    await asyncio.gather(janitor, announcer, deliverer, *(op['task'] for op in operations.values()), return_exceptions=True)


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


def is_handoff(name):
    try:
        return definitions().get(name, {}).get('kind') in HANDOFF_KINDS
    except Exception:
        return False


@app.post('/operations')
async def start(body: Start):
    oid = str(body.id)
    if oid in operations:
        return {'id': oid, 'state': operations[oid]['state']}
    handoff_kind = is_handoff(body.action)
    if handoff_kind and not cleanup['done']:
        raise HTTPException(503)
    op = body.model_dump(mode='json') | {'state': 'running', 'mfa': asyncio.Queue(maxsize=1), 'release': asyncio.Event()}
    if not await authorized(op):
        raise HTTPException(403)
    # Authorization awaited IO; another start can have claimed the same ID/capacity.
    if oid in operations:
        return {'id':oid,'state':operations[oid]['state']}
    if sum(o['state'] in ('running','requires_user_action') and not o.get('ready') for o in operations.values())>=MAX_ACTIVE:
        raise HTTPException(429)
    # A lease whose file could not be removed keeps its slot until it is.
    if handoff_kind and sum(holds_lease(o) for o in operations.values())>=MAX_LEASES:
        raise HTTPException(429)
    operations[oid] = op
    op['task'] = asyncio.create_task(run(op))
    return {'id': oid, 'state': 'running'}


def gone(op):
    """Whether this operation's file is confirmed gone (or never was)."""
    return not op.get('written') and op['id'] not in unfinished


@app.get('/operations/{oid}')
async def status(oid: uuid.UUID):
    # Unknown after a restart is a failure, never a replay of the operation.
    op = operations.get(str(oid), {})
    # known: false tells the application this broker has no such operation (a restart, or a start not yet
    # registered): it decides by whether it saw the start confirmed.
    answer = {'id': str(oid), 'state': op.get('state', 'failed'), 'known': bool(op)}
    if answer['state'] == 'failed':
        # A coarse reason for the operator (FAILURES), never a value, URL or selector.
        answer['reason'] = op.get('failure', 'error') if op else 'restart'
    if op.get('ready') and op.get('state') == 'running':
        answer |= {'ready': True, 'lease_expires_at': op['lease_expires_at']}
    return answer


@app.post('/operations/{oid}/release')
async def release(oid: uuid.UUID):
    """The executor is done with the file: remove it, the operation succeeded. Only for a ready file."""
    op = operations.get(str(oid))
    if not op or (not op['task'].done() and not op.get('ready')):
        raise HTTPException(409)
    if not op['task'].done():
        op['release'].set()
        await asyncio.gather(op['task'], return_exceptions=True)
    return {'id': str(oid), 'state': op['state'], 'removed': gone(op)}


@app.post('/operations/{oid}/cancel')
async def cancel(oid: uuid.UUID):
    """Ends the operation. removed: its file is confirmed gone (an unknown operation has none once the startup
    cleanup succeeded)."""
    op = operations.get(str(oid))
    if not op:
        return {'id': str(oid), 'state': 'failed', 'removed': cleanup['done']}
    if not op['task'].done():
        op.setdefault('reason', 'revoke')
        op['task'].cancel()
        await asyncio.gather(op['task'], return_exceptions=True)
    if op['id'] in unfinished and discard(op):
        unfinished.pop(op['id'], None)
        await removed(op)
    elif op['id'] in undelivered:
        # The application asks, so it is listening: the pending final event goes now, not at the next round.
        await removed(op)
    return {'id': str(oid), 'state': op['state'], 'removed': gone(op)}


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
