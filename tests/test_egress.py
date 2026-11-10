import asyncio
import importlib.util
import ipaddress
from pathlib import Path
import unittest
from unittest.mock import AsyncMock, patch

spec=importlib.util.spec_from_file_location('egress',Path(__file__).parents[1]/'broker/egress.py')
egress=importlib.util.module_from_spec(spec);spec.loader.exec_module(egress)

class Writer:
    def __init__(self):self.closed=False;self.data=b''
    def write(self,data):self.data+=data
    async def drain(self):pass
    def close(self):self.closed=True
    def write_eof(self):pass

class EgressTests(unittest.IsolatedAsyncioTestCase):
    async def attempt(self,target,ips,allowed):
        reader=asyncio.StreamReader();reader.feed_data(f'CONNECT {target} HTTP/1.1\r\n\r\n'.encode());reader.feed_eof()
        writer=Writer();upstream=asyncio.StreamReader();upstream.feed_eof();remote=Writer()
        loop=asyncio.get_running_loop()
        records=[(2,1,6,'',(ip,443))for ip in ips]
        with patch.object(egress,'destinations',return_value=allowed),patch.object(loop,'getaddrinfo',AsyncMock(return_value=records)),patch.object(egress.asyncio,'open_connection',AsyncMock(return_value=(upstream,remote))) as connect:
            await egress.tunnel(reader,writer)
            return connect,writer
    async def test_private_and_mixed_dns_answers_are_blocked(self):
        for ips in (['127.0.0.1'],['169.254.169.254'],['10.0.0.1'],['8.8.8.8','127.0.0.1']):
            connect,writer=await self.attempt('allowed.invalid:443',ips,{('allowed.invalid',443):[]})
            connect.assert_not_awaited();self.assertEqual(b'',writer.data)
    async def test_address_is_pinned_and_unknown_destinations_refused(self):
        connect,writer=await self.attempt('allowed.invalid:443',['8.8.8.8'],{('allowed.invalid',443):[]})
        connect.assert_awaited_once_with('8.8.8.8',443);self.assertIn(b'200',writer.data)
        connect,_=await self.attempt('other.invalid:443',['8.8.8.8'],{('allowed.invalid',443):[]});connect.assert_not_awaited()
    async def test_internal_destination_needs_explicit_cidr(self):
        connect,_=await self.attempt('fixture:443',['10.1.2.3'],{('fixture',443):[ipaddress.ip_network('10.1.2.3/32')]})
        connect.assert_awaited_once_with('10.1.2.3',443)
