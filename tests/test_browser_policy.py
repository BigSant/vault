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
    action={'url':'https://shop.invalid/en/login?back=my-account','origins':['https://cdn.shop.invalid'],
            'static_paths':['/themes/','/img/'],'static_query':{'v':'version'},
            'allowed_requests':[{'method':'POST','url':'https://shop.invalid/en/login?back=my-account'}]}
    def test_static_get_under_static_paths_of_registered_origins_only(self):
        for kind in ('stylesheet','script','image','font','media'):
            self.assertTrue(policy.static_allowed(self.action,'https://shop.invalid/themes/x.css?v=8.1','GET',kind,False),kind)
            self.assertTrue(policy.static_allowed(self.action,'https://cdn.shop.invalid/img/logo.png','GET',kind,False),kind)
        for url in ('https://fonts.googleapis.invalid/themes/x.css','http://shop.invalid/themes/x.css','https://shop.invalid:8443/themes/x.css','https://user@shop.invalid/themes/x.css'):
            self.assertFalse(policy.static_allowed(self.action,url,'GET','stylesheet',False),url)
    def test_state_changing_or_data_carrying_gets_are_not_static(self):
        # Verified by review on the earlier rule: all three were allowed.
        for url,kind in [('https://shop.invalid/admin/index.php?controller=AdminEmployees&deleteemployee=1&token=abc','image'),
                         ('https://cdn.shop.invalid/img/x.gif?leak=synthetic-password','image'),
                         ('https://shop.invalid/api/orders?callback=f','script'),
                         ('https://shop.invalid/themes/x.php','script'),
                         ('https://shop.invalid/themes/x.css?v=8.1&x=1','stylesheet'),
                         ('https://shop.invalid/themes/x.css?v=abc','stylesheet'),
                         ('https://shop.invalid/themes/x.css?v=8.1&v=8.2','stylesheet'),
                         ('https://shop.invalid/themes/x.css?v=8%2E1','stylesheet'),
                         ('https://shop.invalid/themes/%2e%2e/admin/x.png','image'),
                         ('https://shop.invalid/themesx/x.png','image'),
                         ('https://shop.invalid/img//x.png','image')]:
            self.assertFalse(policy.static_allowed(self.action,url,'GET',kind,False),url)
    def test_other_type_is_never_static(self):
        self.assertFalse(policy.static_allowed(self.action,'https://shop.invalid/img/x.png','GET','other',False))
        self.assertFalse(policy.static_request(self.action,'GET','other',False))
    def test_never_xhr_fetch_documents_frames_sockets_or_other_methods(self):
        url='https://shop.invalid/img/x.png'
        for kind in ('xhr','fetch','eventsource','websocket','document','ping','other'):
            self.assertFalse(policy.static_allowed(self.action,url,'GET',kind,False),kind)
            self.assertFalse(policy.static_request(self.action,'GET',kind,False),kind)
        for method in ('POST','PUT','DELETE','HEAD','OPTIONS'):
            self.assertFalse(policy.static_allowed(self.action,url,method,'image',False),method)
        self.assertFalse(policy.static_allowed(self.action,url,'GET','image',True))
    def test_off_without_valid_static_paths(self):
        for change in ({'static_paths':None},{'static_paths':[]},{'static_paths':'/themes/'},{'static_paths':['themes/']},{'static_paths':['/themes']},
                       {'static_paths':['/a/../b/']},{'static_paths':['/a%2f/']},{'static_extensions':['.php']},{'static_extensions':[]},
                       {'static_query':{'v':'.*'}},{'static_query':['v']},{'static_subresources':True,'static_paths':None}):
            action={k:v for k,v in (self.action|change).items() if v is not None}
            self.assertFalse(policy.static_allowed(action,'https://shop.invalid/themes/x.css','GET','stylesheet',False),change)
            self.assertFalse(policy.static_request(action,'GET','stylesheet',False),change)
    def test_extensions_narrow_and_query_off_by_default(self):
        action=dict(self.action,static_extensions=['.css']);del action['static_query']
        self.assertTrue(policy.static_allowed(action,'https://shop.invalid/themes/x.css','GET','stylesheet',False))
        self.assertFalse(policy.static_allowed(action,'https://shop.invalid/themes/x.css?v=1','GET','stylesheet',False))
        self.assertFalse(policy.static_allowed(action,'https://shop.invalid/themes/x.js','GET','script',False))
    def test_posts_stay_exact(self):
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/en/login?back=my-account','POST'))
        for url in ('https://shop.invalid/en/login','https://shop.invalid/en/login?back=evil','https://cdn.shop.invalid/en/login?back=my-account'):
            self.assertFalse(policy.request_allowed(self.action,url,'POST'),url)


class VariableQueryTests(unittest.TestCase):
    action={'url':'https://shop.invalid/admin1/index.php?controller=AdminLogin',
            'allowed_requests':[{'method':'POST','url':'https://shop.invalid/admin1/index.php','variable_query':{'rand':'digits:13'}},
                                {'method':'GET','url':'https://shop.invalid/admin1/index.php?controller=AdminDashboard','variable_query':{'token':'hex:32'}}]}
    token='0123456789abcdef0123456789abcdef'
    def test_named_nonce_and_token_may_vary_in_their_shape(self):
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?rand=1764201600123','POST'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php','POST'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?controller=AdminDashboard&token='+self.token,'GET'))
        self.assertTrue(policy.request_allowed(self.action,'https://shop.invalid/admin1/index.php?token='+self.token+'&controller=AdminDashboard','GET'))
    def test_a_value_outside_its_shape_never_matches(self):
        import base64
        # Verified by review on the earlier rule: rand=<base64url(password)> passed.
        leaked=base64.urlsafe_b64encode(b'synthetic-password').decode().rstrip('=')
        for url,method in [('https://shop.invalid/admin1/index.php?rand='+leaked,'POST'),
                           ('https://shop.invalid/admin1/index.php?rand=12345678901234','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=1a','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=%31','POST'),
                           ('https://shop.invalid/admin1/index.php?r%61nd=1','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=+1','POST'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminDashboard&token='+self.token[:-1],'GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminDashboard&token='+self.token.upper(),'GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminDashboard&token='+self.token+'00','GET'),
                           ('https://shop.invalid/admin1/index.php?controller=Admin%44ashboard&token='+self.token,'GET')]:
            self.assertFalse(policy.request_allowed(self.action,url,method),(url,method))
    def test_everything_else_stays_exact(self):
        for url,method in [('https://shop.invalid/admin1/index.php?rand=1&rand=2','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=1&x=1','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=','POST'),
                           ('https://shop.invalid/admin1/index.php?rand=1','GET'),
                           ('https://shop.invalid/admin1/other.php?rand=1','POST'),
                           ('https://evil.invalid/admin1/index.php?rand=1','POST'),
                           ('https://shop.invalid/admin1/index.php?token='+self.token,'GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminDashboard&token=a1b2&controller=AdminOrders','GET'),
                           ('https://shop.invalid/admin1/index.php?controller=AdminOrders&token='+self.token,'GET')]:
            self.assertFalse(policy.request_allowed(self.action,url,method),(url,method))
    def test_malformed_rule_or_shape_matches_nothing(self):
        for variable in ('rand',['rand'],[''],[1],{'rand':1},{'rand':'.*'},{'rand':'digits:0'},{'rand':'digits:21'},{'rand':'hex'},{'rand':'hex:4'},
                         {'rand':'version:3'},{'ra-nd':'digits'},{}):
            action={'url':'https://shop.invalid/a','allowed_requests':[{'method':'POST','url':'https://shop.invalid/b','variable_query':variable}]}
            self.assertFalse(policy.request_allowed(action,'https://shop.invalid/b?rand=1','POST'),variable)
