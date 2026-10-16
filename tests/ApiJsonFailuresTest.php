<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

test('failed JSON responses do not expose remote diagnostics or partial data', function (string $surface): void {
    $history = [];
    $secret = 'synthetic-private-value';
    $payload = match ($surface) {
        'uapi' => ['status' => 0, 'errors' => [$secret], 'data' => ['password' => $secret]],
        'api2' => ['cpanelresult' => ['event' => ['result' => 0], 'error' => $secret, 'data' => [['output' => $secret]]]],
        'whm' => ['metadata' => ['result' => 0, 'reason' => $secret], 'data' => ['password' => $secret]],
    };
    $client = cpanelTestClient([new Response(200, [], json_encode($payload))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = match ($surface) {
        'uapi' => $api->uapi('Custom', 'operation'),
        'api2' => $api->api2('Custom', 'operation'),
        'whm' => $api->whm('custom'),
    };
    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('cPanel API reported failure')
        ->and($result['data'])->toBeNull()
        ->and($result['raw'])->toBeEmpty()
        ->and(json_encode($result))->not->toContain($secret);
})->with(['uapi', 'api2', 'whm']);

test('malformed success flags cannot authorize success', function (mixed $flag, string $surface): void {
    $history = [];
    $payload = match ($surface) {
        'uapi' => ['status' => $flag, 'data' => []],
        'api2' => ['cpanelresult' => ['event' => ['result' => $flag], 'data' => []]],
        'whm' => ['metadata' => ['result' => $flag], 'data' => []],
    };
    $client = cpanelTestClient([new Response(200, [], json_encode($payload))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = match ($surface) {
        'uapi' => $api->uapi('Custom', 'operation'),
        'api2' => $api->api2('Custom', 'operation'),
        'whm' => $api->whm('custom'),
    };
    expect($result['success'])->toBeFalse();
})->with([['1junk'], ['01'], [1.5]])->with(['uapi', 'api2', 'whm']);

test('explicit error fields override successful status flags', function (string $surface): void {
    $history = [];
    $payload = match ($surface) {
        'uapi' => ['status' => 1, 'errors' => ['synthetic-secret'], 'data' => []],
        'api2' => ['cpanelresult' => ['event' => ['result' => 1], 'error' => 'synthetic-secret', 'data' => []]],
        'whm' => ['metadata' => ['result' => 1, 'error' => 'synthetic-secret'], 'data' => []],
    };
    $client = cpanelTestClient([new Response(200, [], json_encode($payload))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = match ($surface) {
        'uapi' => $api->uapi('Custom', 'operation'), 'api2' => $api->api2('Custom', 'operation'), 'whm' => $api->whm('custom'),
    };
    expect($result['success'])->toBeFalse()->and(json_encode($result))->not->toContain('synthetic-secret');
})->with(['uapi', 'api2', 'whm']);

test('safe API error categories retain required provider classifications', function (string $remote, string $safe): void {
    $history = [];
    $client = cpanelTestClient([new Response(200, [], json_encode(['status' => 0, 'errors' => [$remote.' synthetic-secret']]))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = $api->uapi('Custom', 'operation');
    expect($result['message'])->toBe($safe)->and(json_encode($result))->not->toContain('synthetic-secret');
})->with([
    ['No such file or directory', 'cPanel API resource not found'],
    ['Domain already exists', 'cPanel API resource already exists'],
    ['Permission denied', 'cPanel API access denied'],
    ['Operation timed out', 'cPanel API operation timed out'],
    ['Feature disabled', 'cPanel API feature unavailable'],
]);

test('valid canonical statuses preserve successful payload data', function (mixed $flag): void {
    $history = [];
    $payload = ['status' => $flag, 'data' => ['content' => 'private application value']];
    $client = cpanelTestClient([new Response(200, [], json_encode($payload))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $result = $api->uapi('Fileman', 'get_file_content');
    expect($result['success'])->toBeTrue()->and($result['data'])->toBe($payload['data'])->and($result['raw'])->toBe($payload);
})->with([1, '1', true]);

test('failed upload JSON strips remote reasons and partial results', function (array $data): void {
    $history = [];
    $client = cpanelTestClient([new Response(200, [], json_encode(['status' => 1, 'data' => $data]))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $file = tempnam(sys_get_temp_dir(), 'shipper-json-upload-');
    file_put_contents($file, 'fixture');
    try {
        $result = $api->uploadFile('/app', $file, 'fixture.txt');
        expect($result['success'])->toBeFalse()->and($result['data'])->toBeNull()
            ->and($result['raw'])->toBeEmpty()->and(json_encode($result))->not->toContain('synthetic-secret');
    } finally {
        unlink($file);
    }
})->with([
    [['failed' => 1, 'uploads' => [['status' => 0, 'reason' => 'synthetic-secret']]]],
    [['uploads' => [['status' => '1junk', 'reason' => 'synthetic-secret']]]],
    [['uploads' => [['status' => 1, 'error' => 'synthetic-secret']]]],
]);
