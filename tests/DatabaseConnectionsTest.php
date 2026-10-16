<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function cpanelMultipleDatabaseProfile(string $runtime = 'static'): CpanelTestProfile
{
    return new CpanelTestProfile([
        'domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => $runtime, 'install_dependencies' => false],
        'databases' => [
            'main' => ['name' => 'app', 'user' => 'app', 'password' => 'main-secret'],
            'reports' => ['name' => 'reports', 'user' => 'reports', 'type' => 'postgresql', 'password' => 'reports-secret'],
        ],
    ]);
}

test('all database connections reach the generated application environment', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
        'database_environment_prefixes' => ['app' => 'DB', 'reports' => 'REPORTS_DB'],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), cpanelMultipleDatabaseProfile()))->toBeTrue();
    expect($client->uploadedFiles['.env'])->toContain(
        'DB_CONNECTION="mysql"', 'DB_DATABASE="shipper_app"', 'DB_USERNAME="shipper_app"', 'DB_PASSWORD="main-secret"',
        'REPORTS_DB_CONNECTION="pgsql"', 'REPORTS_DB_HOST="127.0.0.1"',
        'REPORTS_DB_DATABASE="shipper_reports"', 'REPORTS_DB_USERNAME="shipper_reports"', 'REPORTS_DB_PASSWORD="reports-secret"',
    );
    expect(cpanelProviderCalls($client, 'uapi', 'Mysql', 'create_user')[0]['parameters']['password'])->toBe('main-secret')
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'create_user')[0]['parameters']['password'])->toBe('reports-secret');
});

test('environment file delivery is read back before apply continues', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
        'database_environment_prefixes' => ['app' => 'DB', 'reports' => 'REPORTS_DB'],
    ]);
    $client->readBackUploadedFiles = false;
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => 'old manifest']),
        $client->success(['content' => 'DB_PASSWORD="main-secret"']),
    ];

    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), cpanelMultipleDatabaseProfile()))->toBeFalse()
        ->and($provider->getLastError())->toContain('environment file readback did not match')
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'ensure_deps'))->toBe([]);
});

test('all database connections reach Passenger environment variables', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
        'database_environment_prefixes' => ['app' => 'DB', 'reports' => 'REPORTS_DB'],
    ]);
    expect($provider->apply(new CpanelTestProject(cpanelProviderFixture()), cpanelMultipleDatabaseProfile('nodejs')))->toBeTrue();
    $parameters = cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'register_application')[0]['parameters'];
    $environment = array_combine($parameters['envvar_name'], $parameters['envvar_value']);
    expect($environment['DB_PASSWORD'])->toBe('main-secret')
        ->and($environment['REPORTS_DB_PASSWORD'])->toBe('reports-secret')
        ->and($environment['REPORTS_DB_DATABASE'])->toBe('shipper_reports');
});

test('project database declarations also receive every configured connection', function (): void {
    $databases = [];
    foreach (['app', 'reports'] as $name) {
        $databases[] = new class($name) {
            public function __construct(private readonly string $value) {}

            public function name(): string
            {
                return $this->value;
            }

            public function user(): string
            {
                return $this->value;
            }

            public function type(): string
            {
                return 'mysql';
            }
        };
    }
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman', 'database_password' => 'configured-secret',
        'database_environment_prefixes' => ['app' => 'DB', 'reports' => 'REPORTS_DB'],
    ]);
    $project = new CpanelTestProject(cpanelProviderFixture(), databases: $databases);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']);
    expect($provider->apply($project, $profile))->toBeTrue()
        ->and($client->uploadedFiles['.env'])->toContain('DB_DATABASE="shipper_app"', 'REPORTS_DB_DATABASE="shipper_reports"', 'REPORTS_DB_PASSWORD="configured-secret"');
});

test('ambiguous database mappings fail validation plan and direct apply without API calls', function (mixed $mapping): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['database_environment_prefixes' => $mapping]);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = cpanelMultipleDatabaseProfile();
    expect($provider->validate($project, $profile))->not->toBeEmpty();
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class);
    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($client->calls)->toBe([]);
})->with([
    'missing' => [[]],
    'partial' => [['app' => 'DB']],
    'duplicate' => [['app' => 'DB', 'reports' => 'DB']],
    'invalid' => [['app' => 'DB', 'reports' => 'reports-db']],
    'wrong type' => ['DB'],
]);
