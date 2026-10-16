<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

$first = tempnam(sys_get_temp_dir(), 'upload-first-');
$empty = tempnam(sys_get_temp_dir(), 'upload-empty-');
file_put_contents($first, 'synthetic-private-file');
$cases = ['valid', 'reverse', 'strings', 'empty-errors', 'missing', 'duplicate', 'unexpected',
    'size', 'count', 'failed', 'status', 'malformed-size', 'report-error', 'report-errors', 'file-error', 'file-errors'];
$count = 0;
try {
    foreach (['token', 'basic'] as $auth) {
        foreach ($cases as $case) {
            $config = ['host' => 'http://127.0.0.1:2082', 'username' => 'fixture'];
            if ($auth === 'token') $config['api_token'] = 'synthetic-token';
            else $config['password'] = 'synthetic-password';
            $api = new CpanelApiClient($config, new Client(['headers' => ['X-Fixture-Case' => $case]]));
            $result = $api->uploadFiles('/fixture', ['first.txt' => $first, 'empty.txt' => $empty]);
            if (in_array($case, ['valid', 'reverse', 'strings', 'empty-errors'], true)) {
                if ($result['success'] !== true || count($result['data']['uploads'] ?? []) !== 2) {
                    throw new RuntimeException('Valid upload report rejected: '.$case);
                }
            } elseif ($result !== ['success' => false,
                'message' => 'cPanel upload response did not confirm every requested file', 'data' => null, 'raw' => []]) {
                throw new RuntimeException('Contradictory upload report accepted or exposed: '.$case);
            }
            $count++;
        }
    }
} finally {
    unlink($first);
    unlink($empty);
}
echo "{$count} multipart upload report cases passed\n";
