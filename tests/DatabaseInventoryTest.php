<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('unreadable database inventories cannot authorize creation or credential changes', function (mixed $inventory, string $module, string $function): void {
    $client = new FakeCpanelApiClient;
    $client->responses["uapi:{$module}:list_databases"] = $client->success([]);
    $client->responses["uapi:{$module}:list_users"] = $client->success([]);
    $client->responses["uapi:{$module}:{$function}"] = $client->success($inventory);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => 'static'],
        'databases' => ['main' => [
            'name' => 'app', 'user' => 'app', 'type' => $module === 'Mysql' ? 'mysql' : 'postgresql',
            'password' => 'configured-secret', 'update_password' => true,
        ]],
        'cpanel' => ['archive_extraction' => 'direct'],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('inventory')
        ->and(cpanelProviderCalls($client, 'uapi', $module, 'create_database'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'uapi', $module, 'create_user'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'uapi', $module, 'set_password'))->toBeEmpty()
        ->and($client->uploadedFiles)->toBeEmpty();
})->with([
    [null], ['invalid'], [['unexpected' => []]], [[null]], [[['name' => '']]],
    [[['name' => 'shipper_app'], ['name' => 'shipper_app']]],
])->with([
    ['Mysql', 'list_databases'], ['Mysql', 'list_users'],
    ['Postgresql', 'list_databases'], ['Postgresql', 'list_users'],
]);


test('documented postgresql username strings preserve an existing user', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Postgresql:list_databases'] = $client->success([['database' => 'shipper_app']]);
    $client->responses['uapi:Postgresql:list_users'] = $client->success(['shipper_app', 'shipper_other']);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => 'static'],
        'databases' => ['main' => ['name' => 'app', 'user' => 'app', 'type' => 'postgresql', 'password' => 'existing-secret']],
        'cpanel' => ['archive_extraction' => 'direct'],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'create_user'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'create_database'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'set_password'))->toBeEmpty();
});
