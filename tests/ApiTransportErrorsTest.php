<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

test('HTTP failure diagnostics expose status without request URLs or response bodies', function (string $surface, int $status): void {
    $history = [];
    $client = cpanelTestClient([new Response($status, [], 'Echoed credentials: synthetic-password synthetic-token')], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $parameters = ['password' => 'synthetic-password', 'token' => 'synthetic-token'];
    $result = match ($surface) {
        'uapi-get' => $api->uapi('Mysql', 'create_user', $parameters),
        'uapi-post' => $api->uapi('Mysql', 'create_user', $parameters, 'POST'),
        'api2' => $api->api2('Custom', 'operation', $parameters),
        'whm' => $api->whm('custom', $parameters),
    };
    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe("cPanel API request failed with HTTP {$status}")
        ->and(json_encode($result))->not->toContain('synthetic-password', 'synthetic-token', 'Echoed credentials', 'https://')
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri().(string) $history[0]['request']->getBody())->toContain('synthetic-password')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->not->toBeEmpty();
})->with(['uapi-get', 'uapi-post', 'api2', 'whm'])->with([401, 403, 404, 500]);

test('connection failures do not expose exception text', function (): void {
    $history = [];
    $client = cpanelTestClient([new ConnectException('TLS failed for URL containing synthetic-token', new Request('GET', 'https://cpanel.example.com/?token=synthetic-token'))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = $api->uapi('Features', 'list_features');
    expect($result['message'])->toBe('cPanel API transport failed')
        ->and(json_encode($result))->not->toContain('synthetic-token', 'https://');
});

test('upload HTTP failures do not expose remote response text', function (): void {
    $history = [];
    $client = cpanelTestClient([new Response(403, [], 'Rejected synthetic-token')], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $file = tempnam(sys_get_temp_dir(), 'shipper-upload-error-');
    file_put_contents($file, 'fixture');
    try {
        $result = $api->uploadFile('/app', $file, 'fixture.txt');
        expect($result['message'])->toBe('cPanel API request failed with HTTP 403')
            ->and(json_encode($result))->not->toContain('synthetic-token', 'Rejected', 'https://');
    } finally {
        unlink($file);
    }
});

test('invalid JSON produces a fixed safe diagnostic', function (): void {
    $history = [];
    $client = cpanelTestClient([new Response(200, [], 'not JSON synthetic-token')], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = $api->uapi('Features', 'list_features');
    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('cPanel API returned invalid JSON')
        ->and(json_encode($result))->not->toContain('synthetic-token', 'not JSON');
});
