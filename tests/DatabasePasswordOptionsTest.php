<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('invalid password rotation flags fail validation plan and direct apply before API calls', function (mixed $flag, string $type): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => 'static'],
        'databases' => ['main' => [
            'name' => 'app', 'user' => 'app', 'type' => $type,
            'password' => 'configured-secret', 'update_password' => $flag,
        ]],
    ]);
    $error = 'Database update_password must be a boolean';
    expect($provider->validate($project, $profile))->toContain($error);
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class, $error);
    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toBe($error)
        ->and($client->calls)->toBeEmpty();
})->with([['false'], ['true'], [0], [1], [null], [[]]])->with(['mysql', 'postgresql']);

test('boolean and omitted password rotation options keep their intended behavior', function (mixed $flag, string $type): void {
    $module = $type === 'mysql' ? 'Mysql' : 'Postgresql';
    $client = new FakeCpanelApiClient;
    $client->responses["uapi:{$module}:list_databases"] = $client->success([['database' => 'shipper_app']]);
    $client->responses["uapi:{$module}:list_users"] = $client->success([['user' => 'shipper_app']]);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $database = ['name' => 'app', 'user' => 'app', 'type' => $type, 'password' => 'configured-secret'];
    if ($flag !== 'omitted') {
        $database['update_password'] = $flag;
    }
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => 'static'], 'databases' => ['main' => $database],
        'cpanel' => ['archive_extraction' => 'direct'],
    ]);
    expect($provider->validate($project, $profile))->not->toContain('Database update_password must be a boolean')
        ->and($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', $module, 'set_password'))->toHaveCount($flag === true ? 1 : 0);
})->with([true, false, 'omitted'])->with(['mysql', 'postgresql']);
