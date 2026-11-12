"""Real Guzzle error/JSON handling with synthetic credentials and loopback HTTP."""
import base64
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class DiagnosticsTest(unittest.TestCase):
    def test_credentials_do_not_escape_failure_responses(self):
        received = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                auth = self.headers.get('Authorization')
                received.append((self.path, auth, body))
                status = int(self.headers['X-Fixture-Status'])
                format_ = self.headers['X-Fixture-Format']
                echo = str(auth) + self.path + body.decode() + 'synthetic-error-secret'
                if format_ == 'success':
                    payload = json.dumps({'status': 1, 'metadata': {'result': 1},
                        'cpanelresult': {'event': {'result': 1}, 'data': []},
                        'data': {'failed': 0, 'succeeded': 1, 'uploads': [{'file': 'fixture.txt', 'status': 1, 'size': len(b'synthetic-private-file-content')}]}}).encode()
                else:
                    payload = json.dumps({'message': echo}).encode() if format_ == 'json' else echo.encode()
                self.send_response(status)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = do_POST

        servers, started = [], []
        try:
            # Bind both documented non-TLS ports before PHP can contact a fixture.
            for port in [2082, 2086]:
                servers.append(http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler))
            for server in servers:
                threading.Thread(target=server.serve_forever, daemon=True).start()
                started.append(server)
            result = subprocess.run(['php', str(Path(__file__).with_name('verify-diagnostics.php'))], capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(len(received), 240)
            self.assertEqual({auth for _, auth, _ in received}, {
                'cpanel fixture:synthetic-cpanel-token', 'whm fixture:synthetic-whm-token',
                'Basic ' + base64.b64encode(b'fixture:synthetic-basic-password').decode(),
            })
            self.assertEqual(sum(b'synthetic-private-file-content' in body for _, _, body in received), 48)
            self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 48)
            self.assertEqual(sum('synthetic-query-secret' in url for url, _, _ in received), 144)
        finally:
            for server in servers:
                if server in started:
                    server.shutdown()
                server.server_close()


if __name__ == '__main__':
    unittest.main()
