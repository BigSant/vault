"""Synthetic, isolated target. Never enabled in local/stage/prod Compose."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit
import json
import secrets
from collections import Counter
COUNTS=Counter()
SHOP_COOKIE='PrestaShop-synthetic=synthetic-session-shop; Path=/; HttpOnly; SameSite=Lax'
ADMIN_COOKIE='PrestaShop-admin=synthetic-session-admin; Path=/; HttpOnly; SameSite=Lax'
# A theme as PrestaShop serves it: files under /themes/, /js/, /img/ with a ?v= version, one image that redirects,
# an off-site pixel, and a state-changing back-office URL dressed as an image (never sent).
STATIC='<link rel="stylesheet" href="/ps/themes/theme.css?v=8.1"><script src="/ps/js/core.js"></script><img src="/ps/img/logo.png" alt="">'\
    '<img src="/ps/img/moved.png" alt=""><img src="http://offsite.invalid:8000/pixel.gif" alt="">'\
    '<img src="/ps/admin/index.php?controller=AdminEmployees&amp;deleteemployee=1&amp;token=0123456789abcdef0123456789abcdef" alt="">'
# PrestaShop 1.7/8 back office: the form is posted by XHR to index.php?rand=<ms>; the answer names the dashboard with a token.
ADMIN_JS=('document.querySelector("#submit_login").onclick=()=>{const x=new XMLHttpRequest();'
    'x.open("POST","/ps/admin/index.php?rand="+Date.now());x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");'
    'x.onload=()=>{const r=JSON.parse(x.responseText);if(!r.hasErrors)location.assign(r.redirect)};'
    'x.send("ajax=1&controller=AdminLogin&submitLogin=1&email="+encodeURIComponent(document.querySelector("#email").value)'
    '+"&passwd="+encodeURIComponent(document.querySelector("#passwd").value));};')


class Target(BaseHTTPRequestHandler):
    # Request lines go to stderr (docker logs vault-test-fixture): the integration test checks that no entry value
    # ever reached the target in a URL.
    pass

    def respond(self, status, body, cookie=None):
        self.send_response(status)
        self.send_header('Content-Type', 'text/html')
        if cookie:
            self.send_header('Set-Cookie', cookie)
        self.end_headers()
        self.wfile.write(body.encode())

    def static(self, kind, body):
        self.send_response(200);self.send_header('Content-Type',kind);self.end_headers();self.wfile.write(body)

    def shop_get(self):
        path=urlsplit(self.path).path;cookie=self.headers.get('Cookie','')
        if 'deleteemployee' in self.path:COUNTS['deleteemployee']+=1
        if path.startswith(('/ps/themes/','/ps/js/','/ps/img/')):
            # A static file is fetched without the session or any credential.
            if cookie or self.headers.get('Authorization'):COUNTS['static-credentials']+=1
            # Not even the page's URL (a back-office URL carries its token) reaches the static server.
            if self.headers.get('Referer') or self.headers.get('Origin'):COUNTS['static-referer']+=1
            if 'c3ludGhldGlj' in path or 'synthetic' in path:COUNTS['static-leak']+=1
        if path=='/ps/themes/theme.css':return self.static('text/css',b'@font-face{font-family:s;src:url(/ps/themes/font.woff2)}body{font-family:s;background:url(/ps/img/bg.png)}')
        if path=='/ps/js/core.js':
            # Tries to set a cookie from a static response: dropped.
            self.send_response(200);self.send_header('Content-Type','text/javascript');self.send_header('Set-Cookie','static-set=1; Path=/; HttpOnly');self.end_headers()
            self.wfile.write(b'window.prestashop={};');return
        if path=='/ps/img/moved.png':
            self.send_response(302);self.send_header('Location','/ps/img/redirected.png');self.end_headers();return
        if path in ('/ps/img/logo.png','/ps/img/bg.png','/ps/themes/font.woff2','/ps/img/redirected.png'):return self.static('application/octet-stream',b'\x00')
        if path=='/ps/module/blockwishlist/action':COUNTS['wishlist-left']+=1
        if path=='/ps/en/login':
            # PrestaShop 8's wishlist module fetches on every page, before the login too.
            return self.respond(200,'<html><head>'+STATIC+'<script>fetch("/ps/module/blockwishlist/action?action=getAllWishlist")</script></head><body><form method="post" action="/ps/en/login?back=my-account"><input name="email" id="field-email">'
                '<input name="password" id="field-password" type="password"><button id="submit-login">Sign in</button></form></body></html>')
        if path=='/ps/en/my-account':
            return self.respond(200,STATIC+'<div id="account-state">'+('connected' if 'synthetic-session-shop' in cookie else 'guest')+'</div>')
        if path=='/ps/en/leak':
            # A hostile page: copies the password into localStorage (base64) before posting.
            return self.respond(200,'<form method="post" action="/ps/en/login?back=my-account"><input name="email" id="field-email"><input name="password" id="field-password">'
                '<button id="submit-login" onclick="localStorage.setItem(\'p\',btoa(document.querySelector(\'#field-password\').value))">Sign in</button></form>')
        if path=='/ps/en/leak-cookie':
            # A page script copies the password into a (script-readable) cookie: never exported.
            return self.respond(200,'<html><head>'+STATIC+'</head><body><form method="post" action="/ps/en/login?back=my-account"><input name="email" id="field-email"><input name="password" id="field-password">'
                '<button id="submit-login" onclick="document.cookie=\'p=\'+btoa(document.querySelector(\'#field-password\').value)+\'; path=/\'">Sign in</button></form></body></html>')
        if path=='/ps/en/leak-path':
            # A page script puts the password into a static path on the registered origin: denied before it leaves.
            return self.respond(200,'<form method="post" action="/ps/en/login?back=my-account"><input name="email" id="field-email"><input name="password" id="field-password">'
                '<button id="submit-login" onclick="new Image().src=\'/ps/img/\'+btoa(document.querySelector(\'#field-password\').value)+\'.png\'">Sign in</button></form>')
        if path=='/ps/en/login-echo':
            return self.respond(200,'<form method="post" action="/ps/en/login-echo"><input name="email" id="field-email"><input name="password" id="field-password" type="password"><button id="submit-login">Sign in</button></form>')
        if path=='/ps/en/fetch':
            return self.respond(200,STATIC+'<script>fetch("/ps/data")</script><form method="post" action="/ps/en/login?back=my-account"><input name="email" id="field-email">'
                '<input name="password" id="field-password"><button id="submit-login">Sign in</button></form>')
        if path=='/ps/admin/index.php':
            query=parse_qs(urlsplit(self.path).query)
            if query.get('controller')==['AdminLogin']:
                return self.respond(200,'<html><head>'+STATIC+'</head><body><input id="email"><input id="passwd" type="password"><button id="submit_login">Log in</button><script>'+ADMIN_JS+'</script></body></html>')
            if query.get('ajax')==['true']:COUNTS['dashboard-xhr-left']+=1
            if query.get('controller')==['AdminDashboard'] and query.get('token'):
                # Logged in: the theme loads again (now with a session in the jar) and the page keeps UI state.
                # As PrestaShop 8's dashboard: XHRs on document-ready (notifications with a Symfony CSRF token, a
                # widget) and a timer after the login is confirmed. None of them is listed; none may fail the login.
                return self.respond(200,STATIC+'<div id="account-state">'+('connected' if 'synthetic-session-admin' in cookie else 'guest')+'</div><script>localStorage.setItem("nav","collapsed");localStorage.setItem("draft","x");'
                    'document.addEventListener("DOMContentLoaded",()=>{const x=new XMLHttpRequest();x.open("POST","/ps/admin/index.php/common/notifications?_token=BYOThSjgJf8LRsc3hJpVmYwYWBfWm5hEUoWRhBXw56Q&rand="+Date.now());x.send("type=order");'
                    'fetch("/ps/admin/index.php?controller=AdminDashboard&ajax=true&action=refreshDashboard&module=dashactivity")});'
                    'setTimeout(()=>fetch("/ps/admin/index.php?controller=AdminDashboard&ajax=true&action=late",{method:"POST"}),1500)</script>')
        return self.respond(404,'not found')

    def shop_post(self, data):
        path=urlsplit(self.path).path
        if path.startswith('/ps/admin/index.php/common/') or 'ajax=true' in self.path:COUNTS['dashboard-xhr-left']+=1
        credentials=data.get('email')==['synthetic-user-sensitive'] and data.get('password' if path=='/ps/en/login' else 'passwd')==['synthetic-password']
        if path=='/ps/en/login-echo':
            # A hostile site: its own HttpOnly cookie carries the password (base64). The broker refuses the state.
            if not credentials and not (data.get('email')==['synthetic-user-sensitive'] and data.get('password')==['synthetic-password']):return self.respond(401,'denied')
            import base64
            self.send_response(302);self.send_header('Location','/ps/en/my-account');self.send_header('Set-Cookie',SHOP_COOKIE)
            self.send_header('Set-Cookie','echo='+base64.b64encode(data['password'][0].encode()).decode()+'; Path=/; HttpOnly');self.end_headers();return
        if path=='/ps/en/login' and urlsplit(self.path).query=='back=my-account':
            if not credentials:return self.respond(401,'denied')
            self.send_response(302);self.send_header('Location','/ps/en/my-account');self.send_header('Set-Cookie',SHOP_COOKIE);self.end_headers();return
        if path=='/ps/admin/index.php' and data.get('submitLogin')==['1']:
            if not credentials:return self.respond(200,json.dumps({'hasErrors':True}))
            self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Set-Cookie',ADMIN_COOKIE);self.end_headers()
            self.wfile.write(json.dumps({'hasErrors':False,'redirect':'/ps/admin/index.php?controller=AdminDashboard&token='+secrets.token_hex(16)}).encode());return
        return self.respond(401,'denied')

    def do_GET(self):
        COUNTS[urlsplit(self.path).path if self.path.startswith('/ps/') else self.path]+=1
        if self.path.startswith('/ps/'):return self.shop_get()
        if self.path.startswith('/login') and self.headers.get('Cookie'):COUNTS['cookie-leak']+=1
        if self.path=='/counts':
            self.respond(200,json.dumps(COUNTS));return
        if self.path in ['/login-blob', '/login-srcdoc', '/login-popup-blank']:
            effect = {
                '/login-blob': "const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['synthetic-export']));a.download='export.txt';a.click();",
                '/login-srcdoc': "const f=document.createElement('iframe');f.srcdoc='<p>nested</p>';document.body.append(f);",
                '/login-popup-blank': "window.open('about:blank');",
            }[self.path]
            self.respond(200, '<input name="username"><input name="password"><button id="login">Login</button><div id="account-state">connected</div><script>document.querySelector("#login").onclick=()=>{'+effect+'};</script>');return
        if self.path=='/attachment':
            self.send_response(200);self.send_header('Content-Disposition','attachment; filename=secret.txt');self.end_headers();self.wfile.write(b'synthetic-export');return
        if self.path=='/login-download':
            self.respond(200,'<form><input name="username"><input name="password"></form><a id="login" download href="/attachment">Export</a>');return
        if self.path=='/login-form':
            self.respond(200,'<form method="post" action="/capture"><input name="username"><input name="password"><button id="login">Login</button></form>');return
        if self.path in ['/login-redirect-allowed','/login-redirect-chain']:
            self.send_response(302);self.send_header('Location','/login' if self.path.endswith('allowed') else '/redirect-bridge');self.end_headers();return
        if self.path=='/redirect-bridge':
            self.send_response(302);self.send_header('Location','/unlisted');self.end_headers();return
        if self.path=='/login-redirect':
            self.send_response(302);self.send_header('Location','/unlisted');self.end_headers();return
        if self.path=='/login-frames':
            self.respond(200,'<iframe src="/blocked-frame"></iframe><script>window.open("/blocked-popup");fetch("/download");</script><form method="post" action="/login"><input name="username"><input name="password"><button id="login">Login</button></form>');return
        if self.path=='/login-timeout':
            self.respond(200,'<p>Never displays a login form.</p>');return
        if self.path == '/login-escape':
            self.respond(200, '<form method="post" action="http://openbao:8200/v1/sys/health"><input name="username"><input name="password" type="password"><button id="login">Login</button></form>')
        elif self.path == '/login':
            self.respond(200, '<form method="post" action="/login"><input name="username"><input name="password" type="password"><button id="login">Login</button></form>')
        elif self.path == '/api/status':
            ok = self.headers.get('Authorization') == 'Bearer synthetic-token'
            self.respond(200 if ok else 401, 'synthetic-token')  # malicious echo must never reach the model
        elif self.path == '/api/redirect':
            self.send_response(302)
            self.send_header('Location', 'http://openbao:8200/v1/sys/health')
            self.end_headers()
        else:
            self.respond(404, 'not found')

    def do_POST(self):
        COUNTS[self.path]+=1
        data = parse_qs(self.rfile.read(min(int(self.headers.get('Content-Length', '0')), 4096)).decode())
        if self.path.startswith('/ps/'):
            COUNTS[urlsplit(self.path).path+' POST']+=1
            return self.shop_post(data)
        if self.path == '/login' and data.get('username') == ['synthetic-user-sensitive'] and data.get('password') == ['synthetic-password']:
            self.respond(200, '<form method="post" action="/mfa"><input name="code"><button id="mfa">Verify</button></form>', 'pending=synthetic-cookie; HttpOnly; SameSite=Strict')
        elif self.path == '/mfa' and data.get('code') == ['123456'] and 'pending=synthetic-cookie' in self.headers.get('Cookie', ''):
            self.respond(200, '<div id="account-state">connected</div><div id="echo">synthetic-password c3ludGhldGljLXBhc3N3b3Jk</div>', 'session=synthetic-session; HttpOnly; SameSite=Strict')
        else:
            self.respond(401, 'denied')


ThreadingHTTPServer(('0.0.0.0', 8000), Target).serve_forever()
