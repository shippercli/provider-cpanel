<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('unreadable or unsafe directory inventories stop deployment cleanup', function (mixed $inventory): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:list_files'] = $client->success($inventory);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'], 'cpanel' => ['archive_extraction' => 'direct']]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('directory inventory')
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty()
        ->and($client->uploadedFiles)->toBeEmpty();
})->with([
    [null], [['dirs' => []]],
    [[['file' => 'nested/unrelated.txt', 'type' => 'file']]],
    [[['file' => 'entry,/home/shipper/other', 'type' => 'file']]],
    [[['file' => '%2e%2e%2fother', 'type' => 'file']]],
    [[['file' => 'index.html', 'type' => 'file'], ['file' => 'index.html', 'type' => 'file']]],
    [[['metadata' => ['file' => 'unrelated.txt', 'type' => 'file']]]],
]);

test('directory cleanup refuses account roots and ambiguous directory paths', function (string $path): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:list_files'] = $client->success([['file' => 'index.html', 'type' => 'file']]);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => $path, 'runtime' => ['type' => 'static'], 'cpanel' => ['archive_extraction' => 'direct']]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeFalse()
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty()
        ->and($client->uploadedFiles)->toBeEmpty();
})->with(['/', '/public_html', '/public_html/.', '/public_html//.', '/home/shipper', '/home/shipper/.', '/app,other', '/app%2fother']);

test('valid directory inventories clean only exact children and preserve well-known', function (bool $mapping): void {
    $client = new FakeCpanelApiClient;
    $rows = [['file' => 'index.html', 'type' => 'file'], ['file' => '.well-known', 'type' => 'dir']];
    $client->responses['uapi:Fileman:list_files'] = $client->success($mapping ? ['dirs' => [$rows[1]], 'files' => [$rows[0]]] : $rows);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'], 'cpanel' => ['archive_extraction' => 'direct']]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeTrue();
    $operations = cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop');
    expect($operations)->toHaveCount(1)->and($operations[0]['parameters']['sourcefiles'])->toBe('app/index.html');
})->with([true, false]);
