#!/usr/bin/env python3
"""Trusted-host CLI / stdio MCP bridge. No command returns secret values."""
import argparse
import json
import os
from pathlib import Path
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class VaultClient:
    def __init__(self, filename):
        fd = os.open(filename, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd) as source:
            metadata = os.fstat(source.fileno())
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.getuid() or metadata.st_mode & 0o077:
                raise ValueError('Client configuration must be owned by this user and mode 0600')
            config = json.load(source)
        url = urllib.parse.urlsplit(config['url'])
        if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
            raise ValueError('A fixed HTTPS origin is required')
        token = config['token']
        if not isinstance(token, str) or len(token) != 64 or any(c not in '0123456789abcdef' for c in token):
            raise ValueError('Invalid client configuration')
        self.url = config['url'].rstrip('/')
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}))

    def call(self, path, payload=None):
        data = json.dumps(payload).encode() if payload is not None else None
        request = urllib.request.Request(self.url+path, data=data, headers={'Authorization': 'Bearer '+self.token,
            'Content-Type': 'application/json', 'Accept': 'application/json'})
        try:
            with self.opener.open(request, timeout=15) as response:
                raw = response.read(1048577)
                if len(raw)>1048576:
                    raise ValueError()
                return json.loads(raw)
        except (OSError, ValueError):
            # Never dump the request, authentication header or upstream error body.
            return {'error': 'vault_request_failed'}

    def mcp(self):
        for raw in sys.stdin:
            if len(raw)>65536:
                continue
            try:
                message = json.loads(raw)
                if not isinstance(message, dict) or 'id' not in message:
                    continue
                result = self.call('/api/mcp', message)
                if 'error' in result and 'jsonrpc' not in result:
                    result = {'jsonrpc':'2.0','id':message['id'],'error':{'code':-32000,'message':'Vault unavailable'}}
                print(json.dumps(result), flush=True)
            except (ValueError, TypeError):
                continue


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True, help='Private operator-owned JSON file (url, token), mode 0600')
    sub = parser.add_subparsers(dest='command', required=True)
    listing=sub.add_parser('list'); sub.add_parser('mcp')
    listing.add_argument('--query');listing.add_argument('--project-id');listing.add_argument('--environment-id');listing.add_argument('--page',type=int,default=1)
    execute = sub.add_parser('execute')
    execute.add_argument('entry'); execute.add_argument('action'); execute.add_argument('--request-id', required=True)
    for command in ['status','cancel']:
        sub.add_parser(command).add_argument('id')
    args = parser.parse_args()
    try:
        client = VaultClient(args.config)
        if args.command == 'mcp':
            return client.mcp()
        if args.command == 'list':
            filters={key:getattr(args,key) for key in ('query','project_id','environment_id','page') if getattr(args,key) is not None}
            answer = client.call('/api/v1/catalog?'+urllib.parse.urlencode(filters))
        elif args.command == 'execute':
            answer = client.call('/api/v1/execute', {'entry_id':args.entry,'action':args.action,'request_id':args.request_id})
        else:
            import uuid
            oid = str(uuid.UUID(args.id))
            answer = client.call('/api/v1/operations/'+oid+('/cancel' if args.command=='cancel' else ''), {} if args.command=='cancel' else None)
        print(json.dumps(answer, ensure_ascii=False))
        return 1 if 'error' in answer else 0
    except (OSError, ValueError, KeyError):
        print('Vault client configuration is unavailable or invalid.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
