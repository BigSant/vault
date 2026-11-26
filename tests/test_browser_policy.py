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


class StaticSubresourceTests(unittest.TestCase):
    action={'url':'https://shop.invalid/en/login?back=my-account','origins':['https://cdn.shop.invalid'],'static_subresources':True,
            'allowed_requests':[{'method':'POST','url':'https://shop.invalid/en/login?back=my-account'}]}
    def test_static_get_from_registered_origins_only(self):
        for kind in ('stylesheet','script','image','font','media'):
            self.assertTrue(policy.static_allowed(self.action,'https://shop.invalid/themes/x.css?v=8.1',  'GET',kind,False),kind)
            self.assertTrue(policy.static_allowed(self.action,'https://cdn.shop.invalid/img/logo.png','GET',kind,False),kind)
        for url in ('https://fonts.googleapis.invalid/css','http://shop.invalid/x.css','https://shop.invalid:8443/x.css','https://user@shop.invalid/x.css'):
            self.assertFalse(policy.static_allowed(self.action,url,'GET','stylesheet',False),url)
    def test_never_xhr_fetch_documents_frames_sockets_or_other_methods(self):
        url='https://shop.invalid/module/x/ajax'
        for kind in ('xhr','fetch','eventsource','websocket','document'):
            self.assertFalse(policy.static_allowed(self.action,url,'GET',kind,False),kind)
            self.assertFalse(policy.static_request(self.action,'GET',kind,False),kind)
        for method in ('POST','PUT','DELETE','HEAD','OPTIONS'):
            self.assertFalse(policy.static_allowed(self.action,url,method,'image',False),method)
        self.assertFalse(policy.static_allowed(self.action,url,'GET','image',True))
        self.assertFalse(policy.static_allowed(self.action,url,'GET','document',True))
    def test_off_by_default(self):
        action=dict(self.action);del action['static_subresources']
        self.assertFalse(policy.static_allowed(action,'https://shop.invalid/x.css','GET','stylesheet',False))
        self.assertFalse(policy.static_request(action,'GET','stylesheet',False))
        action['static_subresources']='yes'
        self.assertFalse(policy.static_request(action,'GET','stylesheet',False))
    def test_posts_stay_exact(self):
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/en/login?back=my-account','POST'))
        for url in ('https://shop.invalid/en/login','https://shop.invalid/en/login?back=evil','https://cdn.shop.invalid/en/login?back=my-account'):
            self.assertFalse(policy.request_allowed(self.action,url,'POST'),url)


class VariableQueryTests(unittest.TestCase):
    action={'url':'https://shop.invalid/admin1/index.php?controller=AdminLogin',
            'allowed_requests':[{'method':'POST','url':'https://shop.invalid/admin1/index.php','variable_query':['rand']},
                                {'method':'GET','url':'https://shop.invalid/admin1/index.php?controller=AdminDashboard','variable_query':['token']}]}
    def test_named_nonce_and_token_may_vary(self):
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?rand=1764201600123','POST'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php','POST'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?controller=AdminDashboard&token=a1b2c3d4e5','GET'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?token=a1b2&controller=AdminDashboard','GET'))
    def test_everything_else_stays_exact(self):
        for url,method in [('https://shop.invalid/admin1/index.php?rand=1&rand=2','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=1&x=1','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=a%2Fb','POST'),
                           ('https://shop.invalid/admin1/index.php?rand='+'1'*65,'POST'),
                           ('https://shop.invalid/admin1/index.php?rand=','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=1','GET'),
                           ('https://shop.invalid/admin1/other.php?rand=1','POST'),
                           ('https://evil.invalid/admin1/index.php?rand=1','POST'),
                           ('https://shop.invalid/admin1/index.php?token=a1b2','GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminDashboard&token=a1b2&controller=AdminOrders','GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminOrders&token=a1b2','GET')]:
            self.assertFalse(policy.request_allowed(self.action,url,method),(url,method))
    def test_malformed_rule_matches_nothing(self):
        for variable in ('rand',[''],[1],{'rand':1}):
            action={'url':'https://shop.invalid/a','allowed_requests':[{'method':'POST','url':'https://shop.invalid/b','variable_query':variable}]}
            self.assertFalse(policy.request_allowed(action,'https://shop.invalid/b?rand=1','POST'),variable)
