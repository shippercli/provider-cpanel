<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\CpanelPlugin;
use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

/** @return array<int, array<string, mixed>> */
function cpanelProviderCalls(
    FakeCpanelApiClient $client,
    string $surface,
    string $module,
    string $function,
): array {
    return \array_values(\array_filter(
        $client->calls,
        static fn (array $call): bool => ($call['surface'] ?? null) === $surface
            && ($call['module'] ?? null) === $module
            && ($call['function'] ?? null) === $function,
    ));
}

/** @param array<string, string> $files */
function cpanelProviderFixture(array $files = ['index.html' => '<h1>Shipper</h1>']): string
{
    $directory = \sys_get_temp_dir().'/shipper-provider-cpanel-'.\bin2hex(\random_bytes(8));
    \mkdir($directory, 0700, true);

    foreach ($files as $relativePath => $contents) {
        $path = $directory.'/'.$relativePath;
        $parent = \dirname($path);
        if (! \is_dir($parent)) {
            \mkdir($parent, 0700, true);
        }
        \file_put_contents($path, $contents);
    }

    return $directory;
}

function cpanelProviderWithExistingDomain(
    FakeCpanelApiClient $client,
    array $config = [],
    string $domain = 'app.example.com',
): CpanelProvider {
    $client->responses['uapi:DomainInfo:domains_data'] = $client->success([
        'main_domain' => 'example.com',
        'sub_domains' => [$domain],
    ]);

    return new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        ...$config,
    ], $client);
}

test('plugin exposes cpanel provider mapping', function (): void {
    expect((new CpanelPlugin)->providers())->toBe([
        'cpanel' => CpanelProvider::class,
    ]);
});

test('plan describes first-class cpanel lifecycle operations', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        'deployment_method' => 'fileman',
    ], $client);
    $project = new CpanelTestProject('.');
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com',
        'deploy_path' => '/app',
        'runtime' => ['type' => 'php', 'version' => '8.4'],
        'databases' => [
            'main' => [
                'name' => 'app',
                'user' => 'app',
                'password' => 'database-secret',
            ],
        ],
        'cron' => [
            'scheduler' => [
                'command' => 'php artisan schedule:run',
                'frequency' => '* * * * *',
            ],
        ],
        'ssl' => [
            'enabled' => true,
            'force_https' => true,
        ],
    ]);

    $plan = $provider->plan($project, $profile);

    expect($plan['provider'])->toBe('cpanel')
        ->and($plan['runtime']['version'])->toBe('8.4')
        ->and($plan['database_count'])->toBe(1)
        ->and($plan['cron_count'])->toBe(1)
        ->and($plan['actions'])->toContain('Upload archive through authenticated Fileman UAPI')
        ->and($plan['actions'])->toContain('Configure SSL and HTTPS redirect');
});

test('validation rejects missing database password', function (): void {
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], new FakeCpanelApiClient);

    $errors = $provider->validate(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'databases' => [
                'main' => [
                    'name' => 'app',
                    'user' => 'app',
                ],
            ],
        ]),
    );

    expect($errors)->toContain('Database password is required for cPanel database user: app');
});

test('fileman apply creates directories and uploads mapped files', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = new CpanelTestProfile([
        'domain' => 'app.example.com',
        'deploy_path' => '/app',
        'runtime' => ['type' => 'static'],
    ]);

    expect($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'upload', 'Fileman', 'upload_files'))->toHaveCount(2)
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toHaveCount(0)
        ->and($client->uploadedPaths)->toHaveKey('app/index.html')
        ->and($client->uploadedFiles)->toHaveKey('.shipper-manifest.json');
});

test('public web directory archives keep the application and publish its web root', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $project = new CpanelTestProject(
        cpanelProviderFixture([
            'composer.json' => '{}',
            'bootstrap/app.php' => '<?php',
            'public/index.php' => "<?php require __DIR__.'/../vendor/autoload.php';",
            'public/app.css' => 'body {}',
        ]),
        webDirectory: '/public',
    );

    expect($provider->apply($project, new CpanelTestProfile([
        'domain' => 'app.example.com',
        'deploy_path' => '/laravel',
        'runtime' => ['type' => 'php', 'version' => '8.4'],
    ])))->toBeTrue()
        ->and($client->uploadedPaths)->toHaveKeys([
            'laravel/app/composer.json',
            'laravel/app/bootstrap/app.php',
            'laravel/app/public/index.php',
            'laravel/index.php',
            'laravel/app.css',
        ]);
});

test('php mysql cron redirect ssl and alias lifecycle is applied through cpanel APIs', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $client->responses['uapi:Mysql:list_databases'] = $client->success([]);
    $client->responses['uapi:Mysql:list_users'] = $client->success([]);
    $client->responses['api2:Cron:listcron'] = $client->success([]);

    $result = $provider->apply(
        new CpanelTestProject(cpanelProviderFixture(['index.php' => '<?php echo "ok";'])),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/php-app',
            'aliases' => ['www-app.example.com'],
            'runtime' => [
                'type' => 'php',
                'version' => '8.4',
                'php_ini' => ['memory_limit' => '256M'],
            ],
            'databases' => [
                'main' => [
                    'name' => 'app',
                    'user' => 'app',
                    'password' => 'database-secret',
                ],
            ],
            'environment' => [
                'variables' => ['APP_ENV' => 'production'],
            ],
            'cron' => [
                'scheduler' => [
                    'command' => 'php artisan schedule:run',
                    'frequency' => '* * * * *',
                ],
            ],
            'redirects' => [
                [
                    'from' => '/legacy',
                    'to' => 'https://app.example.com/',
                    'type' => 301,
                ],
            ],
            'ssl' => [
                'enabled' => true,
                'type' => 'autossl',
                'force_https' => true,
            ],
        ]),
    );

    expect($result)->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'LangPHP', 'php_set_vhost_versions'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'LangPHP', 'php_ini_set_user_basic_directives'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'create_database'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'create_user'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'set_privileges_on_database'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mime', 'add_redirect'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'SSL', 'start_autossl_check'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'SSL', 'toggle_ssl_redirect_for_domains'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Park', 'park'))->toHaveCount(1);

    $databaseCall = cpanelProviderCalls($client, 'uapi', 'Mysql', 'create_database')[0];
    expect($databaseCall['parameters']['name'])->toBe('shipper_app')
        ->and($client->uploadedFiles['.env'])->toContain(
            'APP_ENV="production"',
            'DB_CONNECTION="mysql"',
            'DB_DATABASE="shipper_app"',
        );
});

test('node applications are registered with passenger', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $client->responses['uapi:PassengerApps:list_applications'] = $client->success([]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture([
            'app.js' => 'console.log("ready");',
            'package.json' => '{"scripts":{"start":"node app.js"}}',
        ])),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/node-app',
            'runtime' => [
                'type' => 'nodejs',
                'application_root' => 'node-app',
                'install_dependencies' => false,
            ],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'register_application'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'ensure_deps'))->toHaveCount(0);

    $registration = cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'register_application')[0];
    expect($registration['parameters']['path'])->toBe('node-app')
        ->and($registration['parameters']['domain'])->toBe('app.example.com');
});

test('git deployment creates updates and deploys a cpanel repository', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'git',
    ]);
    $client->responses['uapi:VersionControl:retrieve'] = $client->success([]);

    expect($provider->apply(
        new CpanelTestProject('.', [
            'url' => 'https://github.com/shippercli/sample.git',
        ]),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/git-app',
            'runtime' => ['type' => 'static'],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'VersionControl', 'create'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'VersionControl', 'update'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'VersionControlDeployment', 'create'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'upload', 'Fileman', 'upload_files'))->toHaveCount(1)
        ->and($client->uploadedArchiveEntries)->toBe([])
        ->and($client->uploadedFiles)->toHaveKey('.shipper-manifest.json');
});

test('missing subdomains are created with the current uapi surface', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DomainInfo:domains_data'] = $client->success([
        'main_domain' => 'example.com',
        'sub_domains' => [],
    ]);
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        'deployment_method' => 'fileman',
    ], $client);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'preview.example.com',
            'deploy_path' => '/preview',
            'runtime' => ['type' => 'static'],
            'cpanel' => ['domain_type' => 'subdomain'],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'SubDomain', 'addsubdomain'))->toHaveCount(1);
});

test('custom operations expose uapi api2 and whm surfaces', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/custom',
            'runtime' => ['type' => 'static'],
            'cpanel' => [
                'operations' => [
                    'before_apply' => [
                        [
                            'api' => 'uapi',
                            'module' => 'Email',
                            'function' => 'list_pops',
                            'parameters' => ['domain' => '${DOMAIN}'],
                        ],
                        [
                            'api' => 'api2',
                            'module' => 'Cron',
                            'function' => 'listcron',
                        ],
                        [
                            'api' => 'whm',
                            'function' => 'version',
                        ],
                    ],
                ],
            ],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'Email', 'list_pops'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'listcron'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'whm', '', 'version'))->toHaveCount(1);

    $emailCall = cpanelProviderCalls($client, 'uapi', 'Email', 'list_pops')[0];
    expect($emailCall['parameters']['domain'])->toBe('app.example.com');
});

test('destroy removes only resources recorded as created in the shipper manifest', function (): void {
    $client = new FakeCpanelApiClient;
    $manifest = [
        'project' => 'sample',
        'profile' => 'production',
        'domain' => [
            'domain' => 'preview.example.com',
            'type' => 'subdomain',
            'created' => true,
        ],
        'aliases' => [
            ['domain' => 'alias.example.com', 'created' => true],
        ],
        'deploy_path' => '/preview',
        'deployment' => [
            'method' => 'git',
            'repository_created' => true,
            'repository_root' => '/home/shipper/preview',
        ],
        'passenger' => [
            'name' => 'shipper-sample-production',
            'created' => true,
        ],
        'databases' => [
            [
                'type' => 'mysql',
                'name' => 'shipper_preview',
                'user' => 'shipper_preview',
                'database_created' => true,
                'user_created' => true,
            ],
        ],
        'cron' => [
            ['linekey' => '42'],
        ],
    ];
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => \json_encode($manifest, JSON_THROW_ON_ERROR),
    ]);
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);

    expect($provider->destroy(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'preview.example.com',
            'deploy_path' => '/preview',
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'unregister_application'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'delete_user'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'delete_database'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'VersionControl', 'delete'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Park', 'unpark'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'SubDomain', 'delsubdomain'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toHaveCount(1);

    $manifestRead = cpanelProviderCalls($client, 'uapi', 'Fileman', 'get_file_content')[0];
    expect($manifestRead['parameters']['dir'])->toBe('/home/shipper/preview');
});

test('destroy protects the account web root', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => \json_encode([
            'project' => 'sample',
            'profile' => 'production',
            'domain' => ['created' => false],
            'deploy_path' => '/public_html',
        ], JSON_THROW_ON_ERROR),
    ]);
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
    ], $client);

    expect($provider->destroy(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'example.com',
            'deploy_path' => '/public_html',
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toHaveCount(0);
});

test('provider reports cpanel API failures without throwing from apply', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Features:list_features'] = [
        'success' => false,
        'message' => 'Authentication failed',
        'data' => null,
        'raw' => [],
    ];
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'invalid',
    ], $client);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
    ))->toBeFalse()
        ->and($provider->getLastError())->toContain('Authentication failed');
});
