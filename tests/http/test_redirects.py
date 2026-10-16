"""Exercise the real Guzzle handler against local HTTP redirects, using synthetic data."""
import base64
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class RedirectsTest(unittest.TestCase):
    def test_credentials_and_files_are_never_replayed(self):
        received = []
        collected = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                self.handle_request()

            def do_POST(self):
                self.handle_request()

            def handle_request(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                row = (self.path, self.headers.get('Authorization'), body)
                if self.path.startswith('/collect'):
                    collected.append(row)
                    self.send_response(200)
                else:
                    received.append(row)
                    self.send_response(int(self.headers['X-Fixture-Status']))
                    location = '/collect' if self.headers['X-Fixture-Target'] == 'same' else f'http://127.0.0.1:{sink.server_port}/collect'
                    self.send_header('Location', location)
                payload = json.dumps({'status': 1, 'data': {}}).encode()
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

        servers = []
        started = []
        try:
            # The production client selects HTTP only for documented cPanel/WHM ports.
            # Refuse occupied ports; never contact another local service instead.
            sink = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
            servers.append(sink)
            for port in [2082, 2086]:
                servers.append(http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler))
            for server in servers:
                threading.Thread(target=server.serve_forever, daemon=True).start()
                started.append(server)
            result = subprocess.run(['php', str(Path(__file__).with_name('verify-redirects.php'))], capture_output=True, text=True, timeout=30)
            self.assertEqual(collected, [], 'A redirect destination received a replayed request')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(len(received), 100)
            auths = {row[1] for row in received}
            self.assertEqual(auths, {
                'cpanel fixture:synthetic-cpanel-token',
                'whm fixture:synthetic-whm-token',
                'Basic ' + base64.b64encode(b'fixture:synthetic-basic-password').decode(),
            })
            self.assertEqual(sum(b'synthetic-private-file-content' in row[2] for row in received), 20)
            self.assertEqual(sum(b'synthetic-body-secret' in row[2] for row in received), 20)
            self.assertEqual(sum('synthetic-query-secret' in row[0] for row in received), 60)
        finally:
            for server in servers:
                if server in started:
                    server.shutdown()
                server.server_close()


if __name__ == '__main__':
    unittest.main()
