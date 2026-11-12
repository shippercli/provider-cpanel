<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('malformed manifest consent cannot authorize mutations', function (mixed $value, string $section, string $flag, string $operation): void {
    $manifest = ['project' => 'sample', 'profile' => 'production', 'domain' => ['domain' => 'app.example.com', 'created' => false]];
    if ($section === 'domain') {
        $manifest['domain'][$flag] = $value;
    } elseif (in_array($section, ['passenger', 'deployment'], true)) {
        $manifest[$section] = [$flag => $value];
    } else {
        $manifest[$section] = [[$flag => $value]];
    }
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
    $provider = cpanelProviderWithExistingDomain($client);
    $project = new CpanelTestProject('.');
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']);
    if ($operation === 'plan') {
        expect($provider->plan($project, $profile)['blocked'])->toBeTrue();
    } else {
        expect($provider->{$operation}($project, $profile))->toBeFalse()
            ->and($provider->getLastError())->toContain("manifest {$section}.{$flag} must be a boolean");
    }
    expect(array_map(static fn (array $call): string => $call['module'].':'.$call['function'], $client->calls))
        ->toBe(['Variables:get_user_information', 'Fileman:get_file_content'])
        ->and($client->uploadedFiles)->toBe([]);
})->with([['false'], ['true'], [0], [1], [null], [[]]])->with([
    ['domain', 'created'], ['passenger', 'created'], ['deployment', 'repository_created'],
    ['aliases', 'created'], ['databases', 'database_created'], ['databases', 'user_created'],
    ['email_accounts', 'created'], ['email_accounts', 'delete_data'], ['email_forwarders', 'created'],
    ['ftp_accounts', 'created'], ['ftp_accounts', 'delete_home'], ['dns_records', 'created'],
])->with(['plan', 'apply', 'destroy']);
