#!/usr/bin/env python3
"""Real HTTPS/CSRF/MFA/OpenBao/MySQL/runner flow, ONLY against the isolated test stack."""
import base64
import hashlib
import hmac
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import struct
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT/'app/.generated/test/data'
BASE = 'https://vault.test.local'


def otp(seed):
    raw = base64.b32decode(seed + '=' * ((8-len(seed)%8)%8))
    digest = hmac.new(raw, struct.pack('>Q', int(time.time())//30), hashlib.sha1).digest()
    offset = digest[-1] & 15
    return str((struct.unpack('>I', digest[offset:offset+4])[0] & 0x7fffffff) % 1000000).zfill(6)


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = None
        self.last = ''
        self.url = ''

    def request(self, path, data=None, token=None, expected=200, csrf=True):
        headers = {}
        if token:
            headers['Authorization'] = 'Bearer '+token
            headers['Content-Type'] = 'application/json'
            headers['Accept'] = 'application/json'
            body = json.dumps(data).encode() if data is not None else None
        else:
            if data is not None:
                data = dict(data)
                if csrf:
                    data['_token'] = self.csrf
                body = urllib.parse.urlencode(data).encode()
            else:
                body = None
        request = urllib.request.Request(BASE+path, data=body, headers=headers)
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        self.last = response.read().decode()
        self.url = response.geturl()
        self.status = response.status
        assert expected is None or response.status == expected, f'{path}: expected {expected}, got {response.status}'
        assert 'no-store' in response.headers.get('Cache-Control', ''), path
        found = re.search(r'name="_token" value="([^"]+)"', self.last)
        if found:
            self.csrf = html.unescape(found[1])
        return json.loads(self.last) if token else self.last


SECRETS = ['synthetic-password', 'c3ludGhldGljLXBhc3N3b3Jk', 'synthetic%2Dpassword', 'synthetic-token']
SESSIONS = ['synthetic-session-shop', 'synthetic-session-admin']


def storage_state(human, workspace, agent_machine, agent_token, entry):
    """storage_state end to end: executor only, the file and its lifecycle, PrestaShop-like FO/BO logins."""
    import grp
    import stat
    directory = DATA/'handoff'
    seen = []
    api = Client()

    def call(path, data=None, token=None, expected=200):
        answer = api.request(path, data, token=token, expected=expected)
        seen.append(api.last)
        return answer

    # An LLM agent identity cannot be granted it, and cannot run it.
    human.request('/workspaces/'+workspace+'/grants', {'machine_id': agent_machine, 'entry_id': entry, 'action': 'fixture.shop-session', 'days': '1'})
    assert 'Only an identity made as an executor' in human.last
    call('/api/v1/execute', {'entry_id': entry, 'action': 'fixture.shop-session', 'request_id': secrets.token_hex(16)}, agent_token, 404)
    human.request('/workspaces/'+workspace+'/machines', {'name': 'nightly runner', 'days': '1', 'executor': '1'})
    executor = re.search(r'id="agent-token">([^<]+)</pre>', human.last)[1]
    human.request('/workspaces/'+workspace+'/grants')
    runner = re.search(r'<option value="([a-f0-9-]+)">nightly runner</option>', re.search(r'<select name="machine_id"[^>]*>(.*?)</select>', human.last, re.S)[1])[1]
    handoff_actions = ['fixture.shop-session', 'fixture.shop-ttl', 'fixture.shop-leak', 'fixture.shop-fetch', 'fixture.admin-session',
                       'fixture.shop-leak-cookie', 'fixture.shop-leak-path', 'fixture.shop-login-echo']
    for action in handoff_actions:
        human.request('/workspaces/'+workspace+'/grants', {'machine_id': runner, 'entry_id': entry, 'action': action, 'days': '1'})
    grant = lambda action: re.search(r'/revoke/grants/([a-f0-9-]+)"(?:(?!</form>).)*?Revoke grant: '+re.escape(action)+' for nightly runner', human.last, re.S)
    tools = call('/api/mcp', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}, executor)
    assert 'vault_release' in json.dumps(tools)

    def start(action):
        op = call('/api/v1/execute', {'entry_id': entry, 'action': action, 'request_id': secrets.token_hex(16)}, executor)
        deadline = time.monotonic()+60
        while time.monotonic() < deadline and op['state'] == 'running' and not op.get('ready'):
            time.sleep(1)
            op = call('/api/v1/operations/'+op['id'], token=executor)
        return op

    def files():
        return [p for p in directory.iterdir() if p.name != '.vault-handoff']

    def fixture_counts():
        return json.loads(subprocess.check_output(['docker', 'exec', 'vault-test-fixture', 'python3', '-c', "import urllib.request;print(urllib.request.urlopen('http://127.0.0.1:8000/counts').read().decode())"]))

    # The directory: search only for the group (2710) or private (0700), never listable by anyone else.
    assert os.stat(directory).st_mode & 0o067 == 0 and (directory/'.vault-handoff').is_file(), 'handoff directory mode/marker'

    def gone(oid, seconds=5):
        deadline = time.monotonic()+seconds
        while (directory/(oid+'.json')).exists() and time.monotonic() < deadline:
            time.sleep(0.25)
        return not (directory/(oid+'.json')).exists()

    for action, cookie in [('fixture.shop-session', 'synthetic-session-shop'), ('fixture.admin-session', 'synthetic-session-admin')]:
        op = start(action)
        assert op['state'] == 'running' and op['ready'] and op['lease_expires_at'], f'{action}: {op}'
        assert set(op) == {'id', 'state', 'ok', 'expires_at', 'ready', 'lease_expires_at'}
        path = directory/(op['id']+'.json')
        info = os.lstat(path)
        assert stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o640 and info.st_gid == os.stat(directory).st_gid, 'handoff file mode/group'
        content = path.read_text()
        state = json.loads(content)
        assert any(c['value'] == cookie for c in state['cookies']) and all(c['domain'] == 'fixture' for c in state['cookies'])
        assert all(secret not in content for secret in SECRETS), 'entry value in storageState'
        assert all(not c['domain'].startswith('.') for c in state['cookies']) and not any(c['name'] == 'static-set' for c in state['cookies'])
        if action == 'fixture.admin-session':
            # Only the connector's storage_keys: "nav" and "late", not "draft". "late" proves that the page's
            # request after the confirmed login was attempted before the state was read (and was dropped: the
            # counts below).
            assert [o['origin'] for o in state['origins']] == ['http://fixture:8000'], state['origins']
            assert sorted((i['name'], i['value']) for i in state['origins'][0]['localStorage']) == [('late', 'attempted'), ('nav', 'collapsed')], state['origins']
        else:
            assert state['origins'] == []
        # One live handoff per entry.
        call('/api/v1/execute', {'entry_id': entry, 'action': 'fixture.shop-ttl', 'request_id': secrets.token_hex(16)}, executor, 429)
        released = call('/api/v1/operations/'+op['id']+'/release', {}, executor)
        assert released['state'] == 'succeeded' and not released['ready']
        try:
            open(path)
            raise AssertionError('file survived release')
        except FileNotFoundError:
            pass
        print('PASS:', action, 'storageState handed off (0640, directory group, no entry value), released, ENOENT')
    counts = fixture_counts()
    assert counts.get('/ps/themes/theme.css', 0) >= 2 and counts.get('/ps/themes/font.woff2', 0) >= 1 and counts.get('/ps/admin/index.php POST', 0) >= 1
    assert counts.get('/ps/img/moved.png', 0) >= 1 and counts.get('/ps/img/redirected.png', 0) == 0, 'static redirect followed'
    assert counts.get('deleteemployee', 0) == 0, 'state-changing GET as an image left the broker'
    assert counts.get('static-credentials', 0) == 0, 'static request carried the session'
    assert counts.get('static-referer', 0) == 0, 'static request carried the page URL (Referer/Origin)'
    assert counts.get('wishlist-left', 0) == 0 and counts.get('dashboard-xhr-left', 0) == 0, 'an ignored or late XHR left the broker'
    assert counts.get('/ps/js/slow.js', 0) >= 1, 'the file on its way at the confirmation was not waited for'
    print('PASS: XHR/fetch on document-ready (wishlist, notifications with _token, a widget) ignored; a request after the confirmed login attempted and dropped while a sent one was awaited; no Referer to statics')
    print('PASS: static files under static_paths without cookies, 3xx not followed, a back-office GET as an image dropped, XHR login with ?rand=, dashboard with &token=')

    # The CLI's release answer is checked for values like every other response.
    op = start('fixture.shop-session')
    assert op['ready']
    config = Path(tempfile.mkdtemp())/'client.json'
    config.write_text(json.dumps({'url': BASE, 'token': executor}))
    config.chmod(0o600)
    released = subprocess.run([sys.executable, str(ROOT/'clients/vault.py'), '--config', str(config), 'release', op['id']], capture_output=True, text=True)
    config.unlink()
    config.parent.rmdir()
    seen.append(released.stdout + released.stderr)
    assert released.returncode == 0 and json.loads(released.stdout)['state'] == 'succeeded' and gone(op['id']), released.stdout
    print('PASS: clients/vault.py release removes the file')

    # Two executors at the same moment: one live handoff per entry also under MySQL REPEATABLE READ. A third session
    # holds the entry's row lock while both requests take their snapshots, so both wait for it with a snapshot
    # taken before the other committed.
    human.request('/workspaces/'+workspace+'/machines', {'name': 'nightly runner two', 'days': '1', 'executor': '1'})
    second = re.search(r'id="agent-token">([^<]+)</pre>', human.last)[1]
    human.request('/workspaces/'+workspace+'/grants')
    runner2 = re.search(r'<option value="([a-f0-9-]+)">nightly runner two</option>', re.search(r'<select name="machine_id"[^>]*>(.*?)</select>', human.last, re.S)[1])[1]
    human.request('/workspaces/'+workspace+'/grants', {'machine_id': runner2, 'entry_id': entry, 'action': 'fixture.shop-session', 'days': '1'})
    holder = subprocess.Popen(['docker', 'exec', '-i', 'vault-test-mysql-8.4', 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -u root "$MYSQL_DATABASE"'],
                              stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    holder.stdin.write(("BEGIN; SELECT id FROM entries WHERE id='"+entry+"' FOR UPDATE; SELECT SLEEP(4); COMMIT;\n").encode())
    holder.stdin.close()
    time.sleep(1)
    answers = {}

    def race(name, token):
        client = Client()
        try:
            answers[name] = (client.request('/api/v1/execute', {'entry_id': entry, 'action': 'fixture.shop-session', 'request_id': secrets.token_hex(16)}, token=token, expected=None), client.status)
        except Exception as error:
            answers[name] = (None, repr(error))
        seen.append(client.last)
    threads = [threading.Thread(target=race, args=(name, token)) for name, token in (('first', executor), ('second', second))]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join(60)
    holder.wait(30)
    statuses = sorted(status for _, status in answers.values())
    assert statuses == [200, 429], answers
    winner = next(answer for answer, status in answers.values() if status == 200)
    token = executor if answers['first'][1] == 200 else second
    deadline = time.monotonic()+60
    while time.monotonic() < deadline and not winner.get('ready') and winner['state'] == 'running':
        time.sleep(1)
        winner = call('/api/v1/operations/'+winner['id'], token=token)
    call('/api/v1/operations/'+winner['id']+'/release', {}, token)
    assert gone(winner['id'])
    print('PASS: two executors at once under a held entry lock: one handoff, the other 429')

    op = start('fixture.shop-ttl')
    assert op['ready']
    time.sleep(5)
    assert gone(op['id']) and call('/api/v1/operations/'+op['id'], token=executor)['state'] == 'expired'
    print('PASS: TTL end removes the file, operation expired')

    op = start('fixture.shop-session')
    assert op['ready']
    human.request('/workspaces/'+workspace+'/grants')
    human.request('/workspaces/'+workspace+'/revoke/grants/'+grant('fixture.shop-session')[1], {})
    assert gone(op['id'], 8), 'revoked grant left the file'
    assert call('/api/v1/operations/'+op['id'], token=executor)['state'] == 'revoked'
    human.request('/workspaces/'+workspace+'/grants', {'machine_id': runner, 'entry_id': entry, 'action': 'fixture.shop-session', 'days': '1'})
    print('PASS: revoking the grant removes the file within the reaper interval')

    op = start('fixture.shop-session')
    assert op['ready']
    stray = directory/(str(__import__('uuid').uuid4())+'.json')
    stray.write_text('{}')
    foreign = directory/'keep.txt'
    foreign.write_text('not a handoff file')
    subprocess.run(['docker', 'restart', 'vault-test-broker'], check=True, capture_output=True)
    assert gone(op['id'], 20), 'broker stop/start left the operation file'
    deadline = time.monotonic()+20
    while stray.exists() and time.monotonic() < deadline:
        time.sleep(0.25)
    assert not stray.exists(), 'broker start left a stray file'
    assert foreign.exists(), 'broker start removed a file that is not an operation file'
    foreign.unlink()
    for _ in range(30):
        try:
            state = call('/api/v1/operations/'+op['id'], token=executor)['state']
            break
        except AssertionError:
            time.sleep(1)
    assert state == 'failed', state
    print('PASS: broker restart removes every handoff file; the operation failed')

    for action in ['fixture.shop-leak', 'fixture.shop-fetch', 'fixture.shop-leak-path', 'fixture.shop-login-echo']:
        op = start(action)
        assert op['state'] == 'failed' and not files(), f'{action}: {op}'
    counts = fixture_counts()
    assert counts.get('/ps/data', 0) == 0 and counts.get('static-leak', 0) == 0
    print('PASS: localStorage copy, an unlisted fetch, the password in a static path, and a server cookie with the password write nothing')
    op = start('fixture.shop-leak-cookie')
    assert op['ready'], op
    state = json.loads((directory/(op['id']+'.json')).read_text())
    assert [c['name'] for c in state['cookies']] == ['PrestaShop-synthetic'], [c['name'] for c in state['cookies']]
    call('/api/v1/operations/'+op['id']+'/release', {}, executor)
    assert gone(op['id'])
    print('PASS: a cookie a page script wrote (the password) is never exported; only the HttpOnly session')

    human.request('/workspaces/'+workspace+'/audit?group=request')
    assert 'handoff.written' in human.last and 'handoff.removed' in human.last
    seen.append(human.last)
    logs = b''.join(subprocess.run(['docker', 'logs', name], capture_output=True).stdout + subprocess.run(['docker', 'logs', name], capture_output=True).stderr
                    for name in ['vault-test-broker', 'vault-test-egress', 'vault-test-php-fpm', 'vault-test-webserver-apache-2.4.59', 'vault-test-fixture']).decode(errors='replace')
    assert '/ps/en/my-account' in logs, 'target log is off'
    for text in seen + [logs]:
        assert all(value not in text for value in SECRETS + SESSIONS + [str(directory)]), 'value or path echoed'
        assert not re.search(r'[0-9a-f-]{36}\.json', text), 'handoff file name echoed'
    print('PASS: no entry value, session or path in any response, the audit, docker logs or broker stderr')


def run():
    assert DATA.is_dir()
    config=json.loads((ROOT/'tests/fixtures/connectors.json').read_text())
    inspected=json.loads(subprocess.check_output(['docker','inspect','vault-test-fixture']))[0]
    ips=[n['IPAddress']+'/32' for n in inspected['NetworkSettings']['Networks'].values()]
    for action in config['actions'].values():action['allow_cidrs']=ips
    (DATA/'connectors/connectors.json').write_text(json.dumps(config))
    human = Client()
    statefile = DATA/'integration-state.json'
    human.request('/login')
    if statefile.exists():
        state = json.loads(statefile.read_text())
        human.request('/login', {'email': state['email'], 'password': state['password']})
    else:
        state = {'email': 'integration@example.test', 'password': secrets.token_urlsafe(24)}
        human.request('/setup', {'name': 'Integration Owner', **state, 'bootstrap_code': (DATA/'security/app/bootstrap.code').read_text()})
        assert 'class="totp-qr" src="data:image/svg+xml;base64,' in human.last, 'MFA enrollment QR missing'
        state['seed'] = re.search(r'<pre data-secret[^>]*>([^<]+)</pre>', human.last)[1]
        statefile.write_text(json.dumps(state)); statefile.chmod(0o600)
    human.request('/mfa', {'code': otp(state['seed'])})
    print('PASS: real HTTPS, CSRF cookie session, password and TOTP login')
    human.request('/workspaces', {'name': 'No CSRF'}, expected=419, csrf=False)
    human.request('/')
    human.request('/workspaces', {'name': 'Integration '+secrets.token_hex(4)})
    workspace = urllib.parse.parse_qs(urllib.parse.urlsplit(human.url).query)['workspace'][0]
    human.request('/workspaces/'+workspace+'/projects', {'name': 'Target'})
    human.request('/entries/new?workspace='+workspace)
    project = re.search(r'<option value="([a-f0-9-]+)"[^>]*>Target</option>', human.last)[1]
    environment = re.search(r'<option value="([a-f0-9-]+)"[^>]*>stage</option>', human.last)[1]
    ids = {}
    for typ, payload in [('api_key', {'token': 'synthetic-token'}), ('login', {'username': 'synthetic-user-sensitive', 'password': 'synthetic-password'}), ('dynamic_database', {'role':'vault-probe'})]:
        human.request('/entries', {'project_id': project, 'environment_id': environment, 'name': 'Private '+typ, 'alias': 'fixture-'+typ, 'type': typ, 'secret': json.dumps(payload)})
        ids[typ] = human.url.rsplit('/', 1)[-1]
        assert all(value not in human.last for value in payload.values()), 'plaintext in initial entry page'
        human.request('/entries/'+ids[typ]+'/reveal', {})
        assert all(value in human.last for value in payload.values()), 'owner reveal failed'
    listing = '/?' + urllib.parse.urlencode({'workspace': workspace, 'q': 'Private api_key', 'page': 1})
    human.request(listing)
    detail = html.unescape(re.search(r'href="(/entries/' + ids['api_key'] + r'\?from=[^"]+)"', human.last)[1])
    human.request(detail)
    assert html.unescape(re.search(r'<a class="back" href="([^"]+)"', human.last)[1]) == listing
    print('PASS: encrypted backend write, metadata-only detail, audited owner reveal')
    human.request('/workspaces/'+workspace+'/machines', {'name': 'ai-os integration', 'days': '1'})
    token = re.search(r'id="agent-token">([^<]+)</pre>', human.last)[1]
    human.request('/workspaces/'+workspace+'/grants')
    grant_agents = re.search(r'<select name="machine_id"[^>]*>(.*?)</select>', human.last, re.S)[1]
    machine = re.search(r'<option value="([a-f0-9-]+)">ai-os integration</option>', grant_agents)[1]
    handoffs=[name for name,action in config['actions'].items() if action['kind']=='storage_state']
    for action in [name for name in config['actions'] if name not in handoffs]:
        human.request('/workspaces/'+workspace+'/grants', {'machine_id': machine, 'entry_id': ids['login' if 'browser' in action else ('dynamic_database' if action.endswith('database') else 'api_key')], 'action': action, 'days': '1'})
    agent = Client()
    catalog = agent.request('/api/v1/catalog', token=token)
    assert len(catalog['entries']) == len(config['actions'])-len(handoffs)
    assert 'Private' not in agent.last and 'synthetic-' not in agent.last
    for action in [name for name in config['actions'] if name not in handoffs]:
        request = {'entry_id': ids['login' if 'browser' in action else ('dynamic_database' if action.endswith('database') else 'api_key')], 'action': action, 'request_id': secrets.token_hex(16)}
        op = agent.request('/api/v1/execute', request, token=token)
        deadline = time.monotonic()+50
        mfa_sent = False
        while time.monotonic()<deadline:
            op = agent.request('/api/v1/operations/'+op['id'], token=token)
            assert all(s not in agent.last for s in ['synthetic-token', 'synthetic-password', 'synthetic-cookie', 'synthetic-session', 'c3ludGhldGljLXBhc3N3b3Jk'])
            if op['state'] == 'requires_user_action' and not mfa_sent:
                human.request('/workspaces/'+workspace+'/requests')
                human.request('/workspaces/'+workspace+'/operations/'+op['id']+'/mfa', {'code': '123456'})
                mfa_sent = True
            if op['state'] in ['succeeded', 'failed', 'expired', 'revoked']:
                break
            time.sleep(2.5)
        assert op['state'] == ('expired' if action=='fixture.browser-timeout' else ('failed' if action in ['fixture.redirect','fixture.browser-echo','fixture.browser-escape','fixture.browser-form','fixture.browser-redirect','fixture.browser-redirect-chain','fixture.browser-frames','fixture.browser-download','fixture.browser-blob','fixture.browser-srcdoc','fixture.browser-popup-blank'] else 'succeeded')), f'{action}: {op["state"]}'
        if action in ['fixture.browser','fixture.browser-echo','fixture.browser-redirect-allowed']:
            assert mfa_sent
        print('PASS:', action, 'safe state, no upstream payload')
    counts=json.loads(subprocess.check_output(['docker','exec','vault-test-fixture','python3','-c',"import urllib.request;print(urllib.request.urlopen('http://127.0.0.1:8000/counts').read().decode())"]))
    assert all(counts.get(path,0)==0 for path in ['/capture','/unlisted','/blocked-frame','/blocked-popup','/download','cookie-leak'])
    print('PASS: same-origin form/redirect, iframe, popup and export requests never reach target')
    op=agent.request('/api/v1/execute',{'entry_id':ids['login'],'action':'fixture.browser','request_id':secrets.token_hex(16)},token=token)
    for _ in range(20):
        op=agent.request('/api/v1/operations/'+op['id'],token=token)
        if op['state']=='requires_user_action':break
        time.sleep(2.5)
    assert op['state']=='requires_user_action'
    cancelled=agent.request('/api/v1/operations/'+op['id']+'/cancel',{},token=token)
    assert cancelled['state']=='revoked'
    human.request('/workspaces/'+workspace+'/operations/'+op['id']+'/mfa',{'code':'123456'},expected=409)
    print('PASS: browser cancellation during MFA denies continuation')
    storage_state(human, workspace, machine, token, ids['login'])
    agent.request('/api/mcp', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}, token=token)
    assert 'vault_execute' in agent.last and 'get_secret' not in agent.last
    human.request('/workspaces/'+workspace+'/audit')
    assert 'Page 1 of ' in human.last
    human.request('/workspaces/'+workspace+'/audit?outcome=ok')
    assert 'Outcome: OK' in human.last and re.search(r'Showing \d+ of \d+ events', human.last)
    assert 'synthetic-token' not in human.last and 'synthetic-password' not in human.last
    if os.environ.get('AIOS_VAULT_CHECKOUT'):
        checkout=Path(os.environ['AIOS_VAULT_CHECKOUT'])
        reply=subprocess.run(['php',str(checkout/'tests/application/external_vault_live.php')],input=json.dumps({'token':token,'entry_id':ids['api_key']}).encode(),capture_output=True)
        assert reply.returncode==0,'Real ai-os backend integration failed'
        print('PASS: real ai-os backend HTTPS/MCP action integration')
    human.request('/workspaces/'+workspace+'/revoke/machines/'+machine, {})
    agent.request('/api/v1/catalog', token=token, expected=401)
    pending=Client();pending.request('/login');pending.request('/login',{'email':state['email'],'password':state['password']})
    recovery=subprocess.run([str(ROOT/'bin/vault'),'recover-user',state['email']],env={**os.environ,'VAULT_ENV':'test'},capture_output=True)
    assert recovery.returncode==0,'Synthetic owner recovery failed'
    recovered_file=Path.home()/'.config/vault/test/recovered-user.json'
    recovered=json.loads(recovered_file.read_text());recovered_file.unlink()
    state['password']=recovered['password'];statefile.write_text(json.dumps(state))
    pending.request('/mfa',expected=401)
    pending.request('/mfa',{'code':otp(state['seed'])},expected=401)
    human.request('/')
    assert human.url.endswith('/login'),'Old authenticated session survived recovery'
    human.request('/login',{'email':state['email'],'password':state['password']})
    assert 'class="totp-qr" src="data:image/svg+xml;base64,' in human.last, 'MFA enrollment QR missing'
    state['seed']=re.search(r'<pre data-secret[^>]*>([^<]+)</pre>',human.last)[1]
    statefile.write_text(json.dumps(state))
    human.request('/mfa',{'code':otp(state['seed'])})
    print('PASS: real owner recovery revokes pending MFA and authenticated sessions; new credentials enroll MFA')
    print('PASS: MCP action-only interface, secret-free audit, immediate token revocation')


if __name__ == '__main__':
    run()
