import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

spec=importlib.util.spec_from_file_location('vault_client',Path(__file__).parents[1]/'clients/vault.py')
client=importlib.util.module_from_spec(spec);spec.loader.exec_module(client)


class ClientTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.file=Path(self.tmp.name)/'client.json'
        self.config={'url':'https://vault.invalid','token':'a'*64}
        self.save()
    def tearDown(self):self.tmp.cleanup()
    def save(self):self.file.write_text(json.dumps(self.config));self.file.chmod(0o600)
    def test_rejects_untrusted_config_permissions_symlinks_and_urls(self):
        self.file.chmod(0o644)
        with self.assertRaises(ValueError):client.VaultClient(self.file)
        self.save();link=self.file.parent/'link';link.symlink_to(self.file)
        with self.assertRaises(OSError):client.VaultClient(link)
        for url in ('http://vault.invalid','https://user@vault.invalid','https://vault.invalid/path','https://vault.invalid?secret=x'):
            self.config['url']=url;self.save()
            with self.assertRaises(ValueError):client.VaultClient(self.file)
    def test_network_errors_do_not_echo_credential(self):
        instance=client.VaultClient(self.file);instance.opener=Mock()
        instance.opener.open.side_effect=OSError('secret='+self.config['token'])
        self.assertEqual({'error':'vault_request_failed'},instance.call('/api/v1/catalog'))
    def test_redirects_are_not_followed(self):
        self.assertIsNone(client.NoRedirect().redirect_request(None,None,302,'',{},'https://evil.invalid'))


class ReleaseCommandTests(unittest.TestCase):
    def test_release_posts_to_the_operation_and_prints_only_the_answer(self):
        import contextlib,io,sys,uuid
        from unittest.mock import patch
        tmp=tempfile.TemporaryDirectory();self.addCleanup(tmp.cleanup)
        config=Path(tmp.name)/'client.json';config.write_text(json.dumps({'url':'https://vault.invalid','token':'b'*64}));config.chmod(0o600)
        oid=str(uuid.uuid4());calls=[]
        def call(self,path,payload=None):
            calls.append((path,payload));return {'id':oid,'state':'succeeded','ok':True,'ready':False,'lease_expires_at':None}
        out=io.StringIO()
        with patch.object(client.VaultClient,'call',call),patch.object(sys,'argv',['vault.py','--config',str(config),'release',oid]),contextlib.redirect_stdout(out):
            code=client.main()
        self.assertEqual(0,code);self.assertEqual([('/api/v1/operations/'+oid+'/release',{})],calls)
        self.assertEqual('succeeded',json.loads(out.getvalue())['state']);self.assertNotIn('b'*64,out.getvalue())
        with patch.object(sys,'argv',['vault.py','--config',str(config),'release','../x']),contextlib.redirect_stdout(io.StringIO()),contextlib.redirect_stderr(io.StringIO()):
            self.assertNotEqual(0,client.main())
