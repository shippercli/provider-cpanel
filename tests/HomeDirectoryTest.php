<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('unresolved account homes stop file operations', function (mixed $data, bool $success): void {
    foreach (['apply', 'destroy'] as $operation) {
        $client = new FakeCpanelApiClient;
        $client->responses['uapi:Variables:get_user_information'] = ['success' => $success, 'data' => $data, 'message' => 'fixture', 'raw' => []];
        $provider = cpanelProviderWithExistingDomain($client);
        expect($provider->{$operation}(new CpanelTestProject('.'), new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])))->toBeFalse();
        expect($provider->getLastError())->toContain('home directory');
        expect(array_map(static fn (array $call): string => $call['module'].':'.$call['function'], $client->calls))->toBe(['Variables:get_user_information']);
        expect($client->uploadedFiles)->toBeEmpty();
    }
})->with([
    [null, true], [[], true], [['metadata' => ['home' => '/home/other']], true],
    [['home' => '/home/shipper', 'homedir' => '/home/other'], true],
    [['home' => '/home/shipper'], false], [['home' => '/'], true],
    [['home' => '/home/../other'], true], [['home' => " /home/shipper"], true],
    [['home' => '/home/./shipper'], true], [['home' => '/home//shipper'], true],
    [['home' => "/home/shipper\ninjected"], true], [['home' => null], true],
    [['home' => '/home/comma,name'], true], [['home' => '/home/percent%2fname'], true],
]);

test('valid account home fields and explicit overrides resolve and cache', function (array $data, array $config, string $expected, int $calls): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Variables:get_user_information'] = $client->success($data);
    $provider = new CpanelProvider($config, $client);
    $resolve = new ReflectionMethod($provider, 'resolveHomeDirectory');
    expect($resolve->invoke($provider))->toBe($expected)->and($resolve->invoke($provider))->toBe($expected);
    expect($client->calls)->toHaveCount($calls);
})->with([
    [['home' => '/home2/account'], [], '/home2/account', 1],
    [['homedir' => '/custom/account/'], [], '/custom/account', 1],
    [['home' => '/home/account', 'homedir' => '/home/account/'], [], '/home/account', 1],
    [[], ['home_directory' => '/custom/account'], '/custom/account', 0],
]);

test('unsafe explicit home directory overrides are rejected without API calls', function (mixed $home): void {
    $client = new FakeCpanelApiClient;
    $provider = new CpanelProvider(['home_directory' => $home], $client);
    $resolve = new ReflectionMethod($provider, 'resolveHomeDirectory');
    expect(fn () => $resolve->invoke($provider))->toThrow(RuntimeException::class);
    expect($client->calls)->toBeEmpty();
})->with([['/'], ['relative'], ['/home/../other'], ['/home/./other'], ["/home/a\0b"], ['/home/a\\b'], [12], [[]], [false], [null]]);
