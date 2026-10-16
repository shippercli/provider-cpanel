"""Exercise API failure payloads and success controls over real local HTTP."""
import base64
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class JsonFailuresTest(unittest.TestCase):
    def test_safe_api_failure_shapes(self):
        received = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                auth = self.headers.get('Authorization')
                received.append((self.path, auth, body))
                case = self.headers['X-Fixture-Case']
                echo = str(auth) + self.path + body.decode() + 'synthetic-error-secret'
                surface = 'whm' if self.server.server_port == 2086 else 'api2' if self.path.startswith('/json-api/') else 'upload' if 'upload_files' in self.path else 'uapi'
                data = {'content': 'synthetic-success-value', 'failed': 0, 'succeeded': 1,
                    'uploads': [{'file': 'fixture.txt', 'status': 1, 'size': len(b'synthetic-private-file-content')}]}
                result = {'event': {'result': 1}, 'data': [{'content': 'synthetic-success-value'}]}
                payload = {'cpanelresult': result} if surface == 'api2' else {'metadata': {'result': 1}, 'data': data} if surface == 'whm' else {'status': 1, 'data': data}
                flags = result['event'] if surface == 'api2' else payload['metadata'] if surface == 'whm' else payload
                key = 'result' if surface in ['api2', 'whm'] else 'status'
                if case.endswith('root'):
                    payload = {'nullroot': None, 'arrayroot': [], 'stringroot': echo, 'numberroot': 42}[case]
                elif case == 'errors':
                    payload['errors'] = [echo]
                elif case == 'nestederror':
                    if surface == 'api2': result['data'] = [{'result': 0, 'reason': echo}]
                    elif surface == 'whm': payload['metadata']['error'] = echo
                    elif surface == 'upload': data.update(failed=1, uploads=[{'file': 'fixture.txt', 'status': 0, 'reason': echo}])
                    else: payload['result'] = {'status': 1, 'errors': [echo], 'data': {'content': echo}}
                elif case == 'missing':
                    del flags[key]
                else:
                    flags[key] = {'zero': 0, 'junk': '1junk', 'padded': '01', 'fraction': 1.5,
                        'nullflag': None, 'good-int': 1, 'good-string': '1', 'good-bool': True}[case]
                encoded = json.dumps(payload).encode()
                self.send_response(200)
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)

            do_GET = do_POST

        servers, started = [], []
        try:
            for port in [2082, 2086]:
                servers.append(http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler))
            for server in servers:
                threading.Thread(target=server.serve_forever, daemon=True).start()
                started.append(server)
            result = subprocess.run(['php', str(Path(__file__).with_name('verify-json-failures.php'))], capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(len(received), 150)
            self.assertEqual({auth for _, auth, _ in received}, {
                'cpanel fixture:synthetic-cpanel-token', 'whm fixture:synthetic-whm-token',
                'Basic ' + base64.b64encode(b'fixture:synthetic-basic-password').decode(),
            })
            self.assertEqual(sum(b'synthetic-private-file-content' in body for _, _, body in received), 30)
            self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 30)
            self.assertEqual(sum('synthetic-query-secret' in url for url, _, _ in received), 90)
        finally:
            for server in servers:
                if server in started: server.shutdown()
                server.server_close()


if __name__ == '__main__':
    unittest.main()
