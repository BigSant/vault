"""No request logging; pin resolved destination addresses before tunnelling bytes."""
import asyncio
import ipaddress
import json
import os
import socket
import sys
from urllib.parse import urlsplit


class Conflict(ValueError):
    """Two connectors give one host:port different allow_cidrs: every destination is refused."""


# Said once on stderr while the configuration conflicts (never a host, port or network), again after it was fixed.
conflict = {'reported': False}


def destinations():
    config = json.load(open('/config/connectors.json'))
    result = {}
    for action in config.get('actions', {}).values():
        for url in [action.get('url', ''), *action.get('origins', [])]:
            parsed = urlsplit(url)
            if parsed.scheme in (('http','https') if os.environ.get('VAULT_TEST_FIXTURES')=='1' else ('https',)) and parsed.hostname and not parsed.username:
                key = (parsed.hostname, parsed.port or (443 if parsed.scheme == 'https' else 80))
                cidrs = [ipaddress.ip_network(c) for c in action.get('allow_cidrs', [])]
                # One address, one network permission: two connectors that disagree refuse the whole configuration,
                # so neither inherits the other's private range.
                if key in result and set(result[key]) != set(cidrs):
                    raise Conflict()
                result[key] = cidrs
    return result


async def tunnel(reader, writer):
    remote = None
    try:
        raw = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), 5)
        if len(raw) > 16384:
            raise ValueError()
        lines = raw.decode('latin1').split('\r\n')
        method, target, protocol = lines[0].split(' ')
        if method == 'CONNECT':
            parsed = urlsplit('https://' + target)
        else:
            parsed = urlsplit(target)
            if parsed.scheme != 'http':
                raise ValueError()
        host = parsed.hostname
        port = parsed.port or (443 if method == 'CONNECT' else 80)
        try:
            allowed = destinations()
            conflict['reported'] = False
        except Conflict:
            if not conflict['reported']:
                print('vault-egress: two connectors give one host:port different allow_cidrs; every destination is refused until they agree', file=sys.stderr, flush=True)
                conflict['reported'] = True
            raise
        if parsed.username or (host, port) not in allowed:
            raise ValueError()
        addresses = await asyncio.get_running_loop().getaddrinfo(host, port, type=socket.SOCK_STREAM)
        ips = {info[4][0] for info in addresses}
        for value in ips:
            ip = ipaddress.ip_address(value)
            if not ip.is_global and not any(ip in net for net in allowed[(host, port)]):
                raise ValueError()
        upstream, remote = await asyncio.wait_for(asyncio.open_connection(sorted(ips)[0], port), 5)
        if method == 'CONNECT':
            writer.write(b'HTTP/1.1 200 Connection Established\r\n\r\n')
        else:
            headers = [line for line in lines[1:] if line and not line.lower().startswith(('proxy-', 'host:', 'connection:'))]
            path = parsed.path or '/'
            if parsed.query:
                path += '?' + parsed.query
            remote.write((f'{method} {path} {protocol}\r\nHost: {host}:{port}\r\nConnection: close\r\n' + '\r\n'.join(headers) + '\r\n\r\n').encode('latin1'))
        await writer.drain()

        async def pump(source, dest):
            while chunk := await source.read(65536):
                dest.write(chunk)
                await dest.drain()
            try:
                dest.write_eof()
            except (OSError, NotImplementedError):
                pass

        await asyncio.wait_for(asyncio.gather(pump(reader, remote), pump(upstream, writer)), 180)
    except Exception:
        # Neither exception details nor network payloads belong in logs.
        pass
    finally:
        if remote:
            remote.close()
        writer.close()


async def main():
    server = await asyncio.start_server(tunnel, '0.0.0.0', 8080, limit=20000)
    async with server:
        await server.serve_forever()


if __name__ == '__main__':
    asyncio.run(main())
