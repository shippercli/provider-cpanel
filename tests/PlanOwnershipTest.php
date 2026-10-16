<?php

use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('cpanel plans inspect ownership and adoption without writes', function (string $mode): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DomainInfo:domains_data'] = $client->success([
        'main_domain' => 'example.com',
        'sub_domains' => $mode === 'new' ? [] : ['app.example.com'],
    ]);
    if (in_array($mode, ['owned', 'wrong-project', 'wrong-domain'], true)) {
        $manifest = ['project' => $mode === 'wrong-project' ? 'other' : 'sample', 'profile' => 'production', 'domain' => ['domain' => $mode === 'wrong-domain' ? 'another.example.com' : 'app.example.com']];
        $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
    } elseif ($mode === 'corrupt') {
        $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => 'invalid JSON']);
    } elseif ($mode === 'unreadable') {
        $client->responses['uapi:Fileman:get_file_content'] = ['success' => false, 'message' => 'sensitive API detail', 'data' => null, 'raw' => []];
    }
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com', 'username' => 'shipper', 'password' => 'secret',
        'adopt_existing' => $mode === 'adopt'
            ? ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']
            : false,
    ], $client);
    $plan = $provider->plan(new CpanelTestProject('.'), new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'php'],
    ]));
    expect($plan['blocked'])->toBe(! in_array($mode, ['new', 'owned', 'adopt'], true));
    if ($mode === 'adopt') {
        expect($plan['actions'])->toContain('Adopt existing cPanel domain: app.example.com');
    }
    if ($plan['blocked']) {
        expect($plan['actions'])->toHaveCount(1)->and($plan['actions'][0])->toStartWith('BLOCKED:')->not->toContain('sensitive API detail');
    }
    foreach ($client->calls as $call) {
        expect($call['surface'])->toBe('uapi');
        expect($call['module'].':'.$call['function'])->toBeIn(['Variables:get_user_information', 'Fileman:get_file_content', 'DomainInfo:domains_data']);
    }
})->with(['new', 'owned', 'adopt', 'existing', 'wrong-project', 'wrong-domain', 'corrupt', 'unreadable']);

test('adoption consent must match the exact project profile and domain', function (mixed $consent): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DomainInfo:domains_data'] = $client->success([
        'main_domain' => 'example.com',
        'sub_domains' => ['app.example.com'],
    ]);
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com', 'username' => 'shipper', 'password' => 'secret',
        'adopt_existing' => $consent,
    ], $client);
    $plan = $provider->plan(new CpanelTestProject('.'), new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
    ]));

    expect($plan['blocked'])->toBeTrue()
        ->and($plan['actions'])->toHaveCount(1)
        ->and($plan['actions'][0])->toStartWith('BLOCKED:')
        ->and(array_map(static fn (array $call): string => $call['module'].':'.$call['function'], $client->calls))
        ->toBe(['Variables:get_user_information', 'Fileman:get_file_content', 'DomainInfo:domains_data'])
        ->and($client->uploadedFiles)->toBe([]);
})->with([
    [['project' => 'other', 'profile' => 'production', 'domain' => 'app.example.com']],
    [['project' => 'sample', 'profile' => 'staging', 'domain' => 'app.example.com']],
    [['project' => 'sample', 'profile' => 'production', 'domain' => 'other.example.com']],
    [true],
]);
