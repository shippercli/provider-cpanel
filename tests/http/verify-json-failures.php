<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

$file = tempnam(sys_get_temp_dir(), 'cpanel-json-upload-');
file_put_contents($file, 'synthetic-private-file-content');
$cases = ['zero', 'junk', 'padded', 'fraction', 'missing', 'nullflag', 'errors', 'nestederror',
    'nullroot', 'arrayroot', 'stringroot', 'numberroot', 'good-int', 'good-string', 'good-bool'];
$count = 0;
try {
    foreach (['token', 'basic'] as $auth) {
        foreach ($cases as $case) {
            $config = ['host' => 'http://127.0.0.1:2082', 'username' => 'fixture'];
            if ($auth === 'token') {
                $config['api_token'] = 'synthetic-cpanel-token'; $config['whm_api_token'] = 'synthetic-whm-token';
            } else $config['password'] = 'synthetic-basic-password';
            $api = new CpanelApiClient($config, new Client(['headers' => ['X-Fixture-Case' => $case]]));
            foreach (['get', 'post', 'api2', 'whm', 'upload'] as $surface) {
                $result = match ($surface) {
                    'get' => $api->uapi('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                    'post' => $api->uapi('Fixture', 'write', ['secret' => 'synthetic-body-secret'], 'POST'),
                    'api2' => $api->api2('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                    'whm' => $api->whm('fixture', ['secret' => 'synthetic-query-secret']),
                    'upload' => $api->uploadFile('/fixture', $file, 'fixture.txt'),
                };
                $encoded = json_encode($result, JSON_THROW_ON_ERROR);
                if (str_starts_with($case, 'good-')) {
                    if ($result['success'] !== true || !str_contains($encoded, 'synthetic-success-value')) {
                        throw new RuntimeException('Valid successful payload changed: '.$surface.'/'.$case);
                    }
                } else {
                    foreach (['synthetic-cpanel-token', 'synthetic-whm-token', 'synthetic-basic-password',
                        base64_encode('fixture:synthetic-basic-password'), 'synthetic-query-secret', 'synthetic-body-secret',
                        'synthetic-private-file-content', 'synthetic-error-secret'] as $secret) {
                        if (str_contains($encoded, $secret)) throw new RuntimeException('JSON failure leaked a synthetic credential or payload');
                    }
                    $message = str_ends_with($case, 'root') ? 'cPanel API returned invalid JSON' : 'cPanel API reported failure';
                    if ($surface === 'upload' && $case === 'nestederror'
                        && $result['message'] === 'cPanel upload response did not confirm every requested file') {
                        $message = $result['message'];
                    }
                    if ($result !== ['success' => false, 'message' => $message, 'data' => null, 'raw' => []]) {
                        throw new RuntimeException('Invalid JSON failure shape: '.$surface.'/'.$case);
                    }
                }
                $count++;
            }
        }
    }
} finally { unlink($file); }
echo "{$count} real HTTP API-JSON cases passed\n";
