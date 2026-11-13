<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('deployment flags reject non-booleans before validation plan apply or rollback API calls', function (mixed $value, string $flag): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'], 'cpanel' => [$flag => $value]]);
    $error = "cPanel {$flag} must be a boolean";
    expect($provider->validate($project, $profile))->toContain($error);
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class, $error);
    expect($provider->apply($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error);
    expect($provider->rollback($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error)
        ->and($client->calls)->toBeEmpty();
})->with([['false'], ['true'], [0], [1], [null], [[]]])
    ->with(['clean', 'backup_before_deploy', 'backup_before_rollback', 'environment_file']);

test('quoted false cannot authorize deployment directory trashing', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responseQueues['uapi:Fileman:list_files'] = [
        $client->success([['file' => 'index.html', 'type' => 'file']]),
        $client->success([]),
    ];
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'],
        'cpanel' => ['clean' => 'false', 'archive_extraction' => 'direct']]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeFalse()
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty();
});

test('genuine clean booleans and the omitted default preserve cleanup choices', function (mixed $clean): void {
    $client = new FakeCpanelApiClient;
    $client->responseQueues['uapi:Fileman:list_files'] = [
        $client->success([['file' => 'index.html', 'type' => 'file']]),
        $client->success([]),
    ];
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $options = ['archive_extraction' => 'direct', 'adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']];
    if ($clean !== 'omitted') $options['clean'] = $clean;
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'], 'cpanel' => $options]);
    expect($provider->validate(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeEmpty()
        ->and($provider->apply(new CpanelTestProject(cpanelProviderFixture()), $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toHaveCount($clean === false ? 0 : 1);
})->with([true, false, 'omitted']);

test('genuine backup flags preserve their plan actions', function (mixed $backup): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $options = ['adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']];
    if ($backup !== 'omitted') $options['backup_before_deploy'] = $backup;
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'cpanel' => $options]);
    $plan = $provider->plan(new CpanelTestProject(cpanelProviderFixture()), $profile);
    expect(in_array('Archive the current Shipper-managed release before deployment', $plan['actions'], true))->toBe($backup === true);
})->with([true, false, 'omitted']);
