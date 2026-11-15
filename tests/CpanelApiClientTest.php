<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
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
        ->and($failure['message'])->toBe('cPanel API feature unavailable')
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

test('rejects API 2 file operations that report errors in successful output', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], \json_encode([
            'cpanelresult' => [
                'event' => ['result' => 1],
                'data' => [[
                    'result' => 1,
                    'output' => "Archive: release.zip\nerror: cannot create index.html\nPermission denied\n",
                ]],
            ],
        ], JSON_THROW_ON_ERROR)),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);

    $result = $api->api2('Fileman', 'fileop', [
        'op' => 'extract',
        'sourcefiles' => 'app/release.zip',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('cPanel API access denied');
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

test('uploads files through authenticated multipart UAPI without double-closing the stream', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], '{"status":1,"data":{"uploads":[{"file":"release.zip"}]}}'),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);
    $path = \tempnam(\sys_get_temp_dir(), 'shipper-upload-');
    expect($path)->toBeString();
    \file_put_contents($path, 'deployment archive');

    try {
        $result = $api->uploadFile('/app', $path, 'release.zip');
    } finally {
        @\unlink($path);
    }

    expect($result['success'])->toBeTrue()
        ->and((string) $history[0]['request']->getUri())
        ->toBe('https://cpanel.example.com:2083/execute/Fileman/upload_files')
        ->and($history[0]['request']->getHeaderLine('Content-Type'))->toContain('multipart/form-data');
});

test('uploads multiple files in one authenticated multipart request', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], '{"status":1,"data":{"failed":0,"uploads":[{"file":"a.txt","status":1},{"file":"b.txt","status":1}]}}'),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);
    $first = \tempnam(\sys_get_temp_dir(), 'shipper-upload-');
    $second = \tempnam(\sys_get_temp_dir(), 'shipper-upload-');
    expect($first)->toBeString()
        ->and($second)->toBeString();
    \file_put_contents($first, 'first');
    \file_put_contents($second, 'second');

    try {
        $result = $api->uploadFiles('/app', [
            'a.txt' => $first,
            'b.txt' => $second,
        ]);
    } finally {
        @\unlink($first);
        @\unlink($second);
    }

    expect($result['success'])->toBeTrue()
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Content-Type'))->toContain('multipart/form-data');
});

test('uses cpanel API token authorization when configured', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], '{"status":1,"data":null}'),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'api_token' => 'cpanel-token',
    ], $client);

    $api->uapi('Features', 'list_features');

    expect($history[0]['request']->getHeaderLine('Authorization'))
        ->toBe('cpanel shipper:cpanel-token');
});

test('uses HTTP Basic authorization for cpanel passwords', function (): void {
    $history = [];
    $client = cpanelTestClient([
        new Response(200, [], '{"status":1,"data":null}'),
    ], $history);
    $api = new CpanelApiClient([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);

    $api->uapi('Features', 'list_features');

    expect($history[0]['request']->getHeaderLine('Authorization'))
        ->toBe('Basic '.\base64_encode('shipper:secret'));
});

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
    ['Git Version Control is not enabled for this account', 'cPanel API feature unavailable'],
    ['Access denied: Git Version Control is not enabled for this account', 'cPanel API feature unavailable'],
    ['Unknown app VersionControl', 'cPanel API feature unavailable'],
    ['cURL error 28', 'cPanel API operation timed out'],
]);

test('an error outside the UAPI result retains its retry classification', function (): void {
    $history = [];
    $payload = ['result' => ['status' => 1, 'data' => []], 'errors' => ['Operation timed out: synthetic-secret']];
    $client = cpanelTestClient([new Response(200, [], json_encode($payload))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);

    expect($api->uapi('Custom', 'operation'))->toBe([
        'success' => false,
        'message' => 'cPanel API operation timed out',
        'data' => null,
        'raw' => [],
    ]);
});

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

test('non-object JSON roots return safe failures instead of escaping decode exceptions', function (string $body): void {
    $history = [];
    $client = cpanelTestClient([new Response(200, [], $body)], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'fixture', 'api_token' => 'synthetic-token'], $client);
    expect($api->uapi('Fixture', 'read'))->toBe([
        'success' => false, 'message' => 'cPanel API returned invalid JSON', 'data' => null, 'raw' => [],
    ]);
})->with(['null', '42', '"synthetic-secret"', '[]']);

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

test('cURL timeouts retain a safe timeout diagnostic for reconciliation', function (): void {
    $history = [];
    $request = new Request('POST', 'https://cpanel.example.com/?token=synthetic-token');
    $client = cpanelTestClient([new ConnectException('Timed out with synthetic-token', $request, null, ['errno' => 28])], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);

    $result = $api->uapi('SubDomain', 'addsubdomain');

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('cPanel API request timed out')
        ->and(json_encode($result))->not->toContain('synthetic-token', 'https://');
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
