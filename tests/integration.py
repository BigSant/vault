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
        assert response.status == expected, f'{path}: expected {expected}, got {response.status}'
        assert 'no-store' in response.headers.get('Cache-Control', ''), path
        found = re.search(r'name="_token" value="([^"]+)"', self.last)
        if found:
            self.csrf = html.unescape(found[1])
        return json.loads(self.last) if token else self.last


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
        state['seed'] = re.search(r'<pre data-secret>([^<]+)</pre>', human.last)[1]
        statefile.write_text(json.dumps(state)); statefile.chmod(0o600)
    human.request('/mfa', {'code': otp(state['seed'])})
    print('PASS: real HTTPS, CSRF cookie session, password and TOTP login')
    human.request('/workspaces', {'name': 'No CSRF'}, expected=419, csrf=False)
    human.request('/')
    human.request('/workspaces', {'name': 'Integration '+secrets.token_hex(4)})
    workspace = urllib.parse.parse_qs(urllib.parse.urlsplit(human.url).query)['workspace'][0]
    human.request('/workspaces/'+workspace+'/projects', {'name': 'Target'})
    human.request('/entries/new?workspace='+workspace)
    environment = re.search(r'<option value="([a-f0-9-]+)"[^>]*>Target / stage</option>', human.last)[1]
    ids = {}
    for typ, payload in [('api_key', {'token': 'synthetic-token'}), ('login', {'username': 'synthetic-user-sensitive', 'password': 'synthetic-password'}), ('dynamic_database', {'role':'vault-probe'})]:
        human.request('/entries', {'environment_id': environment, 'name': 'Private '+typ, 'alias': 'fixture-'+typ, 'type': typ, 'secret': json.dumps(payload)})
        ids[typ] = human.url.rsplit('/', 1)[-1]
        assert all(value not in human.last for value in payload.values()), 'plaintext in initial entry page'
        human.request('/entries/'+ids[typ]+'/reveal', {})
        assert all(value in human.last for value in payload.values()), 'owner reveal failed'
    print('PASS: encrypted backend write, metadata-only detail, audited owner reveal')
    human.request('/workspaces/'+workspace+'/machines', {'name': 'ai-os integration', 'days': '1'})
    token = re.search(r'id="agent-token">([^<]+)</pre>', human.last)[1]
    human.request('/workspaces/'+workspace+'/settings')
    machine = re.search(r'<option value="([a-f0-9-]+)">ai-os integration</option>', human.last)[1]
    for action in ['fixture.api', 'fixture.cli', 'fixture.browser', 'fixture.redirect', 'fixture.database', 'fixture.browser-echo', 'fixture.browser-escape']:
        human.request('/workspaces/'+workspace+'/grants', {'machine_id': machine, 'entry_id': ids['login' if 'browser' in action else ('dynamic_database' if action.endswith('database') else 'api_key')], 'action': action, 'days': '1'})
    agent = Client()
    catalog = agent.request('/api/v1/catalog', token=token)
    assert len(catalog['entries']) == 7
    assert 'Private' not in agent.last and 'synthetic-' not in agent.last
    for action in ['fixture.api', 'fixture.cli', 'fixture.redirect', 'fixture.database', 'fixture.browser', 'fixture.browser-echo', 'fixture.browser-escape']:
        request = {'entry_id': ids['login' if 'browser' in action else ('dynamic_database' if action.endswith('database') else 'api_key')], 'action': action, 'request_id': secrets.token_hex(16)}
        op = agent.request('/api/v1/execute', request, token=token)
        deadline = time.monotonic()+50
        mfa_sent = False
        while time.monotonic()<deadline:
            op = agent.request('/api/v1/operations/'+op['id'], token=token)
            assert all(s not in agent.last for s in ['synthetic-token', 'synthetic-password', 'synthetic-cookie', 'synthetic-session', 'c3ludGhldGljLXBhc3N3b3Jk'])
            if op['state'] == 'requires_user_action' and not mfa_sent:
                human.request('/workspaces/'+workspace+'/settings')
                human.request('/workspaces/'+workspace+'/operations/'+op['id']+'/mfa', {'code': '123456'})
                mfa_sent = True
            if op['state'] in ['succeeded', 'failed', 'expired', 'revoked']:
                break
            time.sleep(1)
        assert op['state'] == ('failed' if action in ['fixture.redirect','fixture.browser-echo','fixture.browser-escape'] else 'succeeded'), f'{action}: {op["state"]}'
        if 'browser' in action and action!='fixture.browser-escape':
            assert mfa_sent
        print('PASS:', action, 'safe state, no upstream payload')
    op=agent.request('/api/v1/execute',{'entry_id':ids['login'],'action':'fixture.browser','request_id':secrets.token_hex(16)},token=token)
    for _ in range(20):
        op=agent.request('/api/v1/operations/'+op['id'],token=token)
        if op['state']=='requires_user_action':break
        time.sleep(1)
    assert op['state']=='requires_user_action'
    cancelled=agent.request('/api/v1/operations/'+op['id']+'/cancel',{},token=token)
    assert cancelled['state']=='revoked'
    human.request('/workspaces/'+workspace+'/operations/'+op['id']+'/mfa',{'code':'123456'},expected=409)
    print('PASS: browser cancellation during MFA denies continuation')
    agent.request('/api/mcp', {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}, token=token)
    assert 'vault_execute' in agent.last and 'get_secret' not in agent.last
    human.request('/workspaces/'+workspace+'/audit')
    assert 'synthetic-token' not in human.last and 'synthetic-password' not in human.last
    if os.environ.get('AIOS_VAULT_CHECKOUT'):
        checkout=Path(os.environ['AIOS_VAULT_CHECKOUT'])
        reply=subprocess.run(['php',str(checkout/'tests/application/external_vault_live.php')],input=json.dumps({'token':token,'entry_id':ids['api_key']}).encode(),capture_output=True)
        assert reply.returncode==0,'Real ai-os backend integration failed'
        print('PASS: real ai-os backend HTTPS/MCP action integration')
    human.request('/workspaces/'+workspace+'/revoke/machines/'+machine, {})
    agent.request('/api/v1/catalog', token=token, expected=401)
    print('PASS: MCP action-only interface, secret-free audit, immediate token revocation')


if __name__ == '__main__':
    run()
