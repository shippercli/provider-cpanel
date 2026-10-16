<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

$file = tempnam(sys_get_temp_dir(), 'cpanel-http-upload-');
file_put_contents($file, 'synthetic-private-file-content');
$count = 0;
try {
    foreach (['token', 'basic'] as $auth) {
        foreach ([301, 302, 303, 307, 308] as $status) {
            foreach (['same', 'cross'] as $target) {
                $config = ['host' => 'http://127.0.0.1:2082', 'username' => 'fixture'];
                if ($auth === 'token') {
                    $config['api_token'] = 'synthetic-cpanel-token';
                    $config['whm_api_token'] = 'synthetic-whm-token';
                } else {
                    $config['password'] = 'synthetic-basic-password';
                }
                $client = new Client(['headers' => ['X-Fixture-Status' => (string) $status, 'X-Fixture-Target' => $target]]);
                $api = new CpanelApiClient($config, $client);
                foreach (['get', 'post', 'api2', 'whm', 'upload'] as $surface) {
                    $result = match ($surface) {
                        'get' => $api->uapi('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                        'post' => $api->uapi('Fixture', 'write', ['secret' => 'synthetic-body-secret'], 'POST'),
                        'api2' => $api->api2('Fixture', 'read', ['secret' => 'synthetic-query-secret']),
                        'whm' => $api->whm('fixture', ['secret' => 'synthetic-query-secret']),
                        'upload' => $api->uploadFile('/fixture', $file, 'fixture.txt'),
                    };
                    if ($result['success'] !== false || $result['message'] !== 'cPanel API redirects are not allowed' || $result['raw'] !== []) {
                        throw new RuntimeException("Redirect rejection failed: {$auth}/{$status}/{$target}/{$surface}");
                    }
                    $count++;
                }
            }
        }
    }
} finally {
    unlink($file);
}
echo "{$count} real HTTP redirect rejection checks passed\n";
