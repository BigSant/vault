"""Synthetic, isolated target. Never enabled in local/stage/prod Compose."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs


class Target(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def respond(self, status, body, cookie=None):
        self.send_response(status)
        self.send_header('Content-Type', 'text/html')
        if cookie:
            self.send_header('Set-Cookie', cookie)
        self.end_headers()
        self.wfile.write(body.encode())

    def do_GET(self):
        if self.path == '/login':
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
        data = parse_qs(self.rfile.read(min(int(self.headers.get('Content-Length', '0')), 4096)).decode())
        if self.path == '/login' and data.get('username') == ['synthetic-user-sensitive'] and data.get('password') == ['synthetic-password']:
            self.respond(200, '<form method="post" action="/mfa"><input name="code"><button id="mfa">Verify</button></form>', 'pending=synthetic-cookie; HttpOnly; SameSite=Strict')
        elif self.path == '/mfa' and data.get('code') == ['123456'] and 'pending=synthetic-cookie' in self.headers.get('Cookie', ''):
            self.respond(200, '<div id="account-state">connected</div>', 'session=synthetic-session; HttpOnly; SameSite=Strict')
        else:
            self.respond(401, 'denied')


ThreadingHTTPServer(('0.0.0.0', 8000), Target).serve_forever()
