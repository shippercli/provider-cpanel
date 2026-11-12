<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

$file = tempnam(sys_get_temp_dir(), 'cpanel-diagnostic-upload-');
file_put_contents($file, 'synthetic-private-file-content');
$count = 0;
try {
    foreach (['token', 'basic'] as $auth) {
        $cases = [];
        foreach ([400, 401, 403, 404, 405, 409, 422, 429, 500, 502, 503] as $status) {
            foreach (['json', 'text'] as $format) $cases[] = [$status, $format];
        }
        $cases[] = [200, 'invalid'];
        $cases[] = [200, 'success'];
        foreach ($cases as [$status, $format]) {
            $config = ['host' => 'http://127.0.0.1:2082', 'username' => 'fixture'];
            if ($auth === 'token') {
                $config['api_token'] = 'synthetic-cpanel-token';
                $config['whm_api_token'] = 'synthetic-whm-token';
            } else $config['password'] = 'synthetic-basic-password';
            $client = new Client(['headers' => ['X-Fixture-Status' => (string) $status, 'X-Fixture-Format' => $format]]);
            $api = new CpanelApiClient($config, $client);
            foreach (['get', 'post', 'api2', 'whm', 'upload'] as $surface) {
                $result = match ($surface) {
                    'get' => $api->uapi('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                    'post' => $api->uapi('Fixture', 'write', ['secret' => 'synthetic-body-secret'], 'POST'),
                    'api2' => $api->api2('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                    'whm' => $api->whm('fixture', ['secret' => 'synthetic-query-secret']),
                    'upload' => $api->uploadFile('/fixture', $file, 'fixture.txt'),
                };
                if ($format === 'success') {
                    if ($result['success'] !== true) throw new RuntimeException('Valid success control failed: '.$surface);
                } else {
                    $serialized = json_encode($result, JSON_THROW_ON_ERROR);
                    foreach (['synthetic-cpanel-token', 'synthetic-whm-token', 'synthetic-basic-password',
                        base64_encode('fixture:synthetic-basic-password'), 'synthetic-query-secret', 'synthetic-body-secret',
                        'synthetic-private-file-content', 'synthetic-error-secret'] as $secret) {
                        if (str_contains($serialized, $secret)) throw new RuntimeException('Diagnostic leaked a synthetic credential or payload');
                    }
                    $expected = $status === 200 ? 'cPanel API returned invalid JSON' : 'cPanel API request failed with HTTP '.$status;
                    if ($result !== ['success' => false, 'message' => $expected, 'data' => null, 'raw' => []]) {
                        throw new RuntimeException("Failure diagnostic lost its safe shape: {$auth}/{$status}/{$format}/{$surface}");
                    }
                }
                $count++;
            }
        }
    }
} finally { unlink($file); }
echo "{$count} real HTTP diagnostic/control checks passed\n";
