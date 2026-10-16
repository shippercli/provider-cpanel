<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('invalid release inventories cannot authorize destructive rollback', function (mixed $inventory): void {
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => json_encode($manifest)]);
    $client->responses['uapi:Fileman:list_files'] = $client->success($inventory);
    $provider = cpanelProviderWithExistingDomain($client);
    expect($provider->rollback(new CpanelTestProject('.'), new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])))->toBeFalse()
        ->and($provider->getLastError())->toContain('release inventory')
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toBeEmpty();
})->with([
    [null], [['dirs' => []]],
    [[['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'link']]],
    [[['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'dir']]],
    [[['file' => '../20260727010101-deadbeef.tar.gz', 'type' => 'file']]],
    [[['file' => '20260727010101-deadbeef.tar.gz']]],
    [[['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'file'], ['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'file']]],
]);

test('documented release inventory selects an ordinary archive', function (): void {
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => json_encode($manifest)]), $client->success(['content' => "0\n"]), $client->success(['content' => "0\n"]),
    ];
    $client->responses['uapi:Fileman:list_files'] = $client->success(['dirs' => [], 'files' => [
        ['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'file'],
    ]]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);
    $provider = cpanelProviderWithExistingDomain($client);
    expect($provider->rollback(new CpanelTestProject('.'), new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']), '20260727010101-deadbeef'))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toHaveCount(1);
});

test('status distinguishes unavailable and verified empty release inventories', function (bool $available): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:list_files'] = $client->success($available ? ['dirs' => [], 'files' => []] : null);
    $provider = cpanelProviderWithExistingDomain($client);
    $status = $provider->status(new CpanelTestProject('.'), new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']));
    expect($status['releases'])->toBeEmpty()->and($status['release_inventory']['available'])->toBe($available);
    if (! $available) {
        expect($status['release_inventory']['error'])->toContain('release inventory');
    }
})->with([true, false]);

test('release listing API failure fails rollback before mutations', function (): void {
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => json_encode($manifest)]);
    $client->responses['uapi:Fileman:list_files'] = ['success' => false, 'message' => 'Access denied', 'data' => null, 'raw' => []];
    $provider = cpanelProviderWithExistingDomain($client);
    expect($provider->rollback(new CpanelTestProject('.'), new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])))->toBeFalse()
        ->and($provider->getLastError())->toContain('Read cPanel release inventory failed')
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toBeEmpty();
});
