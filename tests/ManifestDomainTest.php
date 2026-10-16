<?php

use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('missing or malformed manifest domains cannot authorize a target', function (string $operation): void {
    foreach (['missing', null, 'app.example.com', [], ['domain' => null], ['domain' => 12], ['domain' => '']] as $domain) {
        $client = new FakeCpanelApiClient;
        $manifest = ['project' => 'sample', 'profile' => 'production'];
        if ($domain !== 'missing') $manifest['domain'] = $domain;
        $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
        $provider = new CpanelProvider(['host' => 'cpanel.example.com', 'username' => 'shipper', 'password' => 'secret', 'adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']], $client);
        $project = new CpanelTestProject('.');
        $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']);
        if ($operation === 'plan') {
            expect($provider->plan($project, $profile)['blocked'])->toBeTrue();
        } else {
            expect($provider->{$operation}($project, $profile))->toBeFalse()
                ->and($provider->getLastError())->toContain('valid target domain');
        }
        expect(array_map(static fn (array $call): string => $call['module'].':'.$call['function'], $client->calls))
            ->toBe(['Variables:get_user_information', 'Fileman:get_file_content'])
            ->and($client->uploadedFiles)->toBe([]);
    }
})->with(['plan', 'apply', 'destroy']);
