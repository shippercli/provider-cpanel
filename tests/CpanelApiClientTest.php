<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

function cpanelTestClient(array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

test('normalizes flat and nested UAPI responses', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], \json_encode([
            'status' => 1,
            'data' => ['ok' => true],
            'errors' => null,
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], \json_encode([
            'result' => [
                'status' => 0,
                'data' => null,
                'errors' => ['Feature disabled'],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'https://cpanel.example.com:2087/',
        'username' => 'shipper',
        'api_token' => 'token',
    ], $client);

    $success = $api->uapi('Features', 'list_features');
    $failure = $api->uapi('Git', 'create');

    expect($success['success'])->toBeTrue()
        ->and($success['data'])->toBe(['ok' => true])
        ->and($failure['success'])->toBeFalse()
        ->and($failure['message'])->toBe('Feature disabled')
        ->and((string) $history[0]['request']->getUri())->toStartWith('https://cpanel.example.com:2083/execute/');
});

test('normalizes API 2 and WHM responses', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], \json_encode([
            'cpanelresult' => [
                'event' => ['result' => 1],
                'data' => [['linekey' => '42', 'result' => 1]],
            ],
        ], JSON_THROW_ON_ERROR)),
        new Response(200, [], \json_encode([
            'metadata' => ['result' => 1, 'reason' => 'OK'],
            'data' => ['acct' => []],
        ], JSON_THROW_ON_ERROR)),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        'whm_username' => 'root',
        'whm_api_token' => 'whm-token',
    ], $client);

    $api2 = $api->api2('Cron', 'add_line', ['command' => 'php artisan schedule:run']);
    $whm = $api->whm('listaccts');

    expect($api2['success'])->toBeTrue()
        ->and($api2['data'][0]['linekey'])->toBe('42')
        ->and($whm['success'])->toBeTrue()
        ->and((string) $history[0]['request']->getUri())->toContain('/json-api/cpanel')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('whm root:whm-token');
});

test('encodes repeated UAPI parameters without bracket syntax', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], '{"status":1,"data":null}'),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);

    $api->uapi('PassengerApps', 'register_application', [
        'envvar_name' => ['APP_ENV', 'APP_DEBUG'],
        'envvar_value' => ['production', 'false'],
    ]);

    $query = $history[0]['request']->getUri()->getQuery();

    expect($query)->toContain('envvar_name=APP_ENV')
        ->and($query)->toContain('envvar_name=APP_DEBUG')
        ->and($query)->not->toContain('envvar_name%5B0%5D');
});
