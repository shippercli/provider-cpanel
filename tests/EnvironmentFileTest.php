<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('generated dotenv preserves literal environment and database credentials', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $password = 'secret${REFERENCE}\\path"quoted';
    $values = [
        'REFERENCE' => 'expanded',
        'LITERAL' => 'keep${REFERENCE}$REFERENCE',
        'UNICODE' => 'blåbær',
        'CONTROLS' => "line one\nline two\r\ttab",
        'SLASHES' => '\\n\\r\\t\\${REFERENCE}',
        'EMPTY' => '',
        'QUOTES' => "single' double\" # equals=",
    ];
    $result = $provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
            'environment' => ['variables' => $values],
            'databases' => ['main' => ['name' => 'app', 'user' => 'app', 'password' => $password]],
        ]),
    );

    expect($result)->toBeTrue();
    $parsed = Dotenv::parse($client->uploadedFiles['.env']);
    foreach ($values as $name => $value) {
        expect($parsed[$name])->toBe($value);
    }
    expect($parsed['DB_PASSWORD'])->toBe($password);
});
