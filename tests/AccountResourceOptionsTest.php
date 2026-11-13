<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('account resource consent flags require booleans before API calls', function (mixed $value, string $resource, string $option): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $entry = $resource === 'email_accounts'
        ? ['address' => 'mail@example.com', 'password' => 'synthetic-secret']
        : ['user' => 'ftp', 'domain' => 'example.com', 'password' => 'synthetic-secret'];
    $entry[$option] = $value;
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'],
        'cpanel' => [$resource => ['fixture' => $entry]],
    ]);
    $error = "cPanel {$resource}.{$option} must be a boolean";
    expect($provider->validate($project, $profile))->toContain($error);
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class, $error);
    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toBe($error)
        ->and($client->calls)->toBeEmpty();
})->with([['false'], ['true'], [0], [1], [null], [[]]])->with([
    ['email_accounts', 'create'], ['email_accounts', 'manage_existing'], ['email_accounts', 'update_password'],
    ['email_accounts', 'delete_data'], ['email_accounts', 'send_welcome_email'],
    ['ftp_accounts', 'create'], ['ftp_accounts', 'manage_existing'], ['ftp_accounts', 'update_password'],
    ['ftp_accounts', 'delete_home'],
]);

test('genuine account booleans preserve management and password update choices', function (bool $enabled): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Email:list_pops'] = $client->success([['email' => 'mail@example.com']]);
    $client->responses['uapi:Ftp:list_ftp'] = $client->success([['user' => 'ftp']]);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => 'static'],
        'cpanel' => [
            'archive_extraction' => 'direct',
            'email_accounts' => ['fixture' => [
                'address' => 'mail@example.com', 'password' => 'synthetic-secret',
                'create' => $enabled, 'manage_existing' => $enabled, 'update_password' => $enabled,
                'delete_data' => $enabled, 'send_welcome_email' => $enabled,
            ]],
            'ftp_accounts' => ['fixture' => [
                'user' => 'ftp', 'domain' => 'example.com', 'password' => 'synthetic-secret',
                'create' => $enabled, 'manage_existing' => $enabled, 'update_password' => $enabled,
                'delete_home' => $enabled,
            ]],
        ],
    ]);
    expect($provider->validate($project, $profile))->toBeEmpty()
        ->and($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'Email', 'passwd_pop'))->toHaveCount($enabled ? 1 : 0)
        ->and(cpanelProviderCalls($client, 'uapi', 'Ftp', 'passwd'))->toHaveCount($enabled ? 1 : 0)
        ->and(cpanelProviderCalls($client, 'uapi', 'Email', 'add_pop'))->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'uapi', 'Ftp', 'add_ftp'))->toBeEmpty();
})->with([true, false]);
