<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('unreadable account inventories cannot authorize account creation', function (mixed $inventory, string $resource, string $module, string $listing, string $create): void {
    $client = new FakeCpanelApiClient;
    $client->responses["uapi:{$module}:{$listing}"] = $client->success($inventory);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $entries = [
        'email_accounts' => ['address' => 'mail@example.com', 'password' => 'synthetic-secret'],
        'email_forwarders' => ['address' => 'forward@example.com', 'forward_to' => 'mail@example.com'],
        'ftp_accounts' => ['user' => 'ftp', 'domain' => 'example.com', 'password' => 'synthetic-secret'],
    ];
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'],
        'cpanel' => ['archive_extraction' => 'direct', $resource => ['fixture' => $entries[$resource]]],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('inventory')
        ->and(cpanelProviderCalls($client, 'uapi', $module, $create))->toBeEmpty();
})->with([[null], ['invalid'], [['unexpected' => []]], [[null]], [[[]]]])->with([
    ['email_accounts', 'Email', 'list_pops', 'add_pop'],
    ['email_forwarders', 'Email', 'list_forwarders', 'add_forwarder'],
    ['ftp_accounts', 'Ftp', 'list_ftp', 'add_ftp'],
]);

test('invalid inventories cannot authorize removal of previously managed resources', function (string $resource, string $module, string $listing, string $delete): void {
    $client = new FakeCpanelApiClient;
    $client->responses["uapi:{$module}:{$listing}"] = $client->success(null);
    $manager = new ShipperCli\ProviderCpanel\CpanelAccountResourceManager($client);
    $configured = ['dns_records' => [], 'email_accounts' => [], 'email_forwarders' => [], 'ftp_accounts' => []];
    $previous = [$resource => [[
        'address' => 'mail@example.com', 'forward_to' => 'other@example.com',
        'user' => 'ftp', 'domain' => 'example.com', 'created' => true,
    ]]];
    expect(fn () => $manager->reconcile($configured, $previous))->toThrow(RuntimeException::class, 'inventory');
    expect(cpanelProviderCalls($client, 'uapi', $module, $delete))->toBeEmpty();
})->with([
    ['email_accounts', 'Email', 'list_pops', 'delete_pop'],
    ['email_forwarders', 'Email', 'list_forwarders', 'delete_forwarder'],
    ['ftp_accounts', 'Ftp', 'list_ftp', 'delete_ftp'],
]);

test('account inventories preserve existing resources and reject duplicate identities', function (bool $duplicate, string $resource, string $module, string $listing, string $create): void {
    $client = new FakeCpanelApiClient;
    $entries = [
        'email_accounts' => ['address' => 'mail@example.com', 'password' => 'synthetic-secret'],
        'email_forwarders' => ['address' => 'forward@example.com', 'forward_to' => 'mail@example.com'],
        'ftp_accounts' => ['user' => 'ftp', 'domain' => 'example.com', 'password' => 'synthetic-secret'],
    ];
    $rows = [
        'email_accounts' => ['email' => 'mail@example.com', 'login' => 'mail@example.com'],
        'email_forwarders' => ['dest' => 'forward@example.com', 'forward' => 'mail@example.com'],
        'ftp_accounts' => ['user' => 'ftp'],
    ];
    $row = $rows[$resource];
    $client->responses["uapi:{$module}:{$listing}"] = $client->success($duplicate ? [$row, $row] : [$row]);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'],
        'cpanel' => ['archive_extraction' => 'direct', $resource => ['fixture' => $entries[$resource]]],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBe(! $duplicate)
        ->and(cpanelProviderCalls($client, 'uapi', $module, $create))->toBeEmpty();
})->with([true, false])->with([
    ['email_accounts', 'Email', 'list_pops', 'add_pop'],
    ['email_forwarders', 'Email', 'list_forwarders', 'add_forwarder'],
    ['ftp_accounts', 'Ftp', 'list_ftp', 'add_ftp'],
]);
