<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

test('authenticated API redirects fail without a second request', function (string $location, int $status, string $surface): void {
    $history = [];
    $client = cpanelTestClient([
        new Response($status, ['Location' => $location], '{"status":1,"data":{"unexpected":true}}'),
        new Response(200, [], '{"status":1,"metadata":{"result":1},"cpanelresult":{"event":{"result":1},"data":[]},"data":[]}'),
    ], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $parameters = ['password' => 'synthetic-password'];
    $result = match ($surface) {
        'uapi' => $api->uapi('Custom', 'operation', $parameters),
        'post' => $api->uapi('Custom', 'operation', $parameters, 'POST'),
        'api2' => $api->api2('Custom', 'operation', $parameters),
        'whm' => $api->whm('custom', $parameters),
    };
    expect($history)->toHaveCount(1)
        ->and($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('cPanel API redirects are not allowed')
        ->and($result['raw'])->toBeEmpty();
})->with(['https://other.example.com/collect', 'http://cpanel.example.com/collect', '/redirected-endpoint'])
    ->with([302, 307])->with(['uapi', 'post', 'api2', 'whm']);

test('multipart uploads cannot be redirected with application files', function (int $status): void {
    $history = [];
    $client = cpanelTestClient([
        new Response($status, ['Location' => 'https://other.example.com/collect'], '{"status":1}'),
        new Response(200, [], '{"status":1,"data":{"uploads":[{"status":1}]}}'),
    ], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'password' => 'synthetic-password'], $client);
    $file = tempnam(sys_get_temp_dir(), 'shipper-redirect-upload-');
    file_put_contents($file, 'private application content');
    try {
        $result = $api->uploadFile('/app', $file, 'fixture.txt');
        expect($history)->toHaveCount(1)->and($result['success'])->toBeFalse()
            ->and($result['message'])->toBe('cPanel API redirects are not allowed');
    } finally {
        unlink($file);
    }
})->with([307, 308]);
