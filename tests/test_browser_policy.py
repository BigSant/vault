import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('policy',Path(__file__).parents[1]/'broker/browser_policy.py')
policy=importlib.util.module_from_spec(spec);spec.loader.exec_module(policy)
class BrowserPolicyTests(unittest.TestCase):
    def test_exact_method_url_and_tls_are_required(self):
        action={'url':'https://site.invalid/login','allowed_requests':[{'method':'POST','url':'https://site.invalid/session'}]}
        self.assertTrue(policy.request_allowed(action,action['url'],'GET'))
        self.assertTrue(policy.request_allowed(action,'https://site.invalid/session','POST'))
        for url,method in [('https://site.invalid/capture','POST'),('https://site.invalid/session','GET'),('https://site.invalid/session?to=evil','POST'),('http://site.invalid/login','GET'),('https://evil.invalid/session','POST')]:
            self.assertFalse(policy.request_allowed(action,url,method))
    def test_insecure_http_requires_test_only_environment(self):
        action={'url':'http://fixture:8000/login'}
        with patch.dict(os.environ,{'VAULT_TEST_FIXTURES':'0'}):self.assertFalse(policy.request_allowed(action,action['url'],'GET'))
        with patch.dict(os.environ,{'VAULT_TEST_FIXTURES':'1'}):self.assertTrue(policy.request_allowed(action,action['url'],'GET'))
