<?php

use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('unreadable domain inventories cannot authorize creation or adoption', function (string $operation): void {
    foreach ([null, [], 'unexpected', ['unknown' => []], ['main_domain' => null], ['main_domain' => ''], ['sub_domains' => 'invalid']] as $inventory) {
        $client = new FakeCpanelApiClient;
        $client->responses['uapi:DomainInfo:domains_data'] = $client->success($inventory);
        $provider = new CpanelProvider(['host' => 'cpanel.example.com', 'username' => 'shipper', 'password' => 'secret', 'adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']], $client);
        $project = new CpanelTestProject('.');
        $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']);
        if ($operation === 'plan') {
            expect($provider->plan($project, $profile)['blocked'])->toBeTrue();
        } else {
            expect($provider->apply($project, $profile))->toBeFalse()->and($provider->getLastError())->toContain('domain inventory');
        }
        expect(array_map(static fn (array $call): string => $call['module'].':'.$call['function'], $client->calls))
            ->toBe(['Variables:get_user_information', 'Fileman:get_file_content', 'DomainInfo:domains_data'])
            ->and($client->uploadedFiles)->toBe([]);
    }
})->with(['plan', 'apply']);
