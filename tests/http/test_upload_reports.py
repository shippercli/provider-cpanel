"""Verify real multipart requests and acceptance reports using synthetic data."""
import base64
from email import policy
from email.parser import BytesParser
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class UploadReportsTest(unittest.TestCase):
    def test_multipart_acceptance(self):
        received = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers['Content-Length']))
                message = BytesParser(policy=policy.default).parsebytes(
                    ('Content-Type: ' + self.headers['Content-Type'] + '\r\n\r\n').encode() + body)
                parts = [(p.get_param('name', header='content-disposition'), p.get_filename(),
                          p.get_payload(decode=True)) for p in message.iter_parts()]
                received.append((self.path, self.headers.get('Authorization'), parts))
                case = self.headers['X-Fixture-Case']
                records = [{'file': 'first.txt', 'status': 1, 'size': 22},
                           {'file': 'empty.txt', 'status': 1, 'size': 0}]
                data = {'failed': 0, 'succeeded': 2, 'uploads': records}
                echo = self.headers.get('Authorization', '') + body.decode(errors='replace')
                if case == 'reverse': records.reverse()
                elif case == 'strings':
                    data.update(failed='0', succeeded='2')
                    for record in records:
                        record.update(status='1', size=str(record['size']))
                elif case == 'missing': records.pop()
                elif case == 'duplicate': records[1] = records[0].copy()
                elif case == 'unexpected': records[1]['file'] = 'other.txt'
                elif case == 'size': records[0]['size'] = 21
                elif case == 'count': data['succeeded'] = 1
                elif case == 'failed': data['failed'] = 1
                elif case == 'status': records[0]['status'] = 0
                elif case == 'malformed-size': records[0]['size'] = '22junk'
                elif case == 'report-error': data['error'] = echo
                elif case == 'report-errors': data['errors'] = [echo]
                elif case == 'file-error': records[0]['error'] = echo
                elif case == 'file-errors': records[0]['errors'] = [echo]
                elif case == 'empty-errors':
                    data.update(error=None, errors=[])
                    records[0].update(error='', errors=[])
                encoded = json.dumps({'status': 1, 'data': data}).encode()
                self.send_response(200)
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 2082), Handler)
        try:
            threading.Thread(target=server.serve_forever, daemon=True).start()
            result = subprocess.run(['php', str(Path(__file__).with_name('verify-upload-reports.php'))],
                                    capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual(len(received), 32)
            self.assertEqual({auth for _, auth, _ in received}, {
                'cpanel fixture:synthetic-token',
                'Basic ' + base64.b64encode(b'fixture:synthetic-password').decode()})
            for path, _, parts in received:
                self.assertEqual(path, '/execute/Fileman/upload_files')
                self.assertEqual(parts, [('dir', None, b'/fixture'), ('overwrite', None, b'1'),
                    ('file-1', 'first.txt', b'synthetic-private-file'), ('file-2', 'empty.txt', b'')])
        finally:
            server.shutdown()
            server.server_close()


if __name__ == '__main__':
    unittest.main()
