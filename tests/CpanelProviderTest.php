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

test('fileman can extract one archive through a monitored cpanel task', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '83']]);
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => "0\n"]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/archive-app',
            'runtime' => ['type' => 'static'],
            'cpanel' => ['archive_extraction' => 'cron'],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line'))->toHaveCount(1)
        ->and($client->uploadedArchiveEntries)->toContain('index.html');

    $extraction = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0];
    expect($extraction['parameters']['command'])
        ->toContain('/usr/bin/unzip')
        ->toContain('/home/shipper/archive-app')
        ->not->toContain('%');
});

test('public web directory archives keep the application and publish its web root', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '84']]);
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => "0\n"]);
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
        ])
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line'))->toHaveCount(1);

    $composerTask = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0];
    expect($composerTask['parameters']['command'])
        ->toContain('/opt/cpanel/ea-php84/root/usr/bin/php')
        ->toContain('/usr/local/bin/composer')
        ->toContain('/home/shipper/laravel/app');
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
                'install_dependencies' => true,
            ],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'register_application'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'ensure_deps'))->toHaveCount(1);

    $registration = cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'register_application')[0];
    $dependencies = cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'ensure_deps')[0];
    expect($registration['parameters']['path'])->toBe('/node-app')
        ->and($registration['parameters']['domain'])->toBe('app.example.com')
        ->and($dependencies['parameters']['app_path'])->toBe('/node-app')
        ->and($dependencies['parameters']['type'])->toBe('npm')
        ->and($client->uploadedPaths)->toHaveKey('node-app/tmp/restart.txt');
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

    $repositoryRoot = '/home/shipper/.shipper/repositories/sample/production';
    $create = cpanelProviderCalls($client, 'uapi', 'VersionControl', 'create')[0];
    $update = cpanelProviderCalls($client, 'uapi', 'VersionControl', 'update')[0];
    $deploy = cpanelProviderCalls($client, 'uapi', 'VersionControlDeployment', 'create')[0];
    expect($create['parameters']['repository_root'])->toBe($repositoryRoot)
        ->and($update['parameters']['repository_root'])->toBe($repositoryRoot)
        ->and($deploy['parameters']['repository_root'])->toBe($repositoryRoot);
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

test('domain creation reconciles cpanel state after a timeout response', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responseQueues['uapi:DomainInfo:domains_data'] = [
        $client->success([
            'main_domain' => 'example.com',
            'addon_domains' => [],
        ]),
        $client->success([
            'main_domain' => 'example.com',
            'addon_domains' => [],
        ]),
        $client->success([
            'main_domain' => 'example.com',
            'addon_domains' => ['app.shippercli.com'],
        ]),
    ];
    $client->responses['api2:AddonDomain:addaddondomain'] = [
        'success' => false,
        'message' => 'Operation timed out after 60002 milliseconds',
        'data' => null,
        'raw' => [],
    ];
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        'deployment_method' => 'fileman',
    ], $client);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.shippercli.com',
            'deploy_path' => '/app',
            'runtime' => ['type' => 'static'],
            'cpanel' => [
                'domain_type' => 'addon',
                'archive_extraction' => 'direct',
                'domain_reconciliation_timeout' => 1,
                'domain_reconciliation_interval_ms' => 0,
            ],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'AddonDomain', 'addaddondomain'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'DomainInfo', 'domains_data'))->toHaveCount(3);

    $manifest = \json_decode($client->uploadedFiles['.shipper-manifest.json'], true);
    expect($manifest['domain']['created'])->toBeTrue();
});

test('alias creation reconciles cpanel state after a timeout response', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['api2:Park:park'] = [
        'success' => false,
        'message' => 'cURL error 28: Operation timed out after 60002 milliseconds',
        'data' => null,
        'raw' => [],
    ];
    $client->responseQueues['api2:Park:listparkeddomains'] = [
        $client->success([]),
        $client->success([]),
    ];
    $client->responseQueues['api2:AddonDomain:listaddondomains'] = [
        $client->success([]),
        $client->success([
            ['domain' => 'alias.app.example.com'],
        ]),
    ];
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'aliases' => ['alias.app.example.com'],
            'deploy_path' => '/app',
            'runtime' => ['type' => 'static'],
            'cpanel' => [
                'archive_extraction' => 'direct',
                'alias_reconciliation_timeout' => 1,
                'alias_reconciliation_interval_ms' => 0,
            ],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Park', 'park'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Park', 'listparkeddomains'))->toHaveCount(2)
        ->and(cpanelProviderCalls($client, 'api2', 'AddonDomain', 'listaddondomains'))->toHaveCount(2);

    $manifest = \json_decode($client->uploadedFiles['.shipper-manifest.json'], true);
    expect($manifest['aliases'][0]['created'])->toBeTrue();
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
    $client->responses['uapi:Fileman:list_files'] = $client->success([
        ['file' => '20260727010101-deadbeef.tar.gz'],
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
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toHaveCount(2);

    $manifestRead = cpanelProviderCalls($client, 'uapi', 'Fileman', 'get_file_content')[0];
    expect($manifestRead['parameters']['dir'])->toBe('/home/shipper/preview');

    $fileDeletes = cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop');
    expect($fileDeletes[0]['parameters']['sourcefiles'])->toBe('.shipper/releases/sample/production')
        ->and($fileDeletes[1]['parameters']['sourcefiles'])->toBe('preview');

    $domainDelete = cpanelProviderCalls($client, 'api2', 'SubDomain', 'delsubdomain')[0];
    expect($domainDelete['parameters']['domain'])->toBe('preview.example.com');
});

test('destroy sends the internal subdomain when deleting an addon domain', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => \json_encode([
            'project' => 'sample',
            'profile' => 'production',
            'domain' => [
                'domain' => 'app.shippercli.com',
                'type' => 'addon',
                'created' => true,
                'primary_domain' => 'example.com',
            ],
            'deploy_path' => '/app',
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
            'domain' => 'app.shippercli.com',
            'deploy_path' => '/app',
        ]),
    ))->toBeTrue();

    $domainDelete = cpanelProviderCalls($client, 'api2', 'AddonDomain', 'deladdondomain')[0];
    expect($domainDelete['parameters'])->toMatchArray([
        'domain' => 'app.shippercli.com',
        'subdomain' => 'app-shippercli-com_example.com',
    ]);
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

test('repeated apply preserves ownership needed for safe destroy', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responseQueues['uapi:DomainInfo:domains_data'] = [
        $client->success([
            'main_domain' => 'example.com',
            'addon_domains' => [],
        ]),
        $client->success([
            'main_domain' => 'example.com',
            'addon_domains' => ['app.shippercli.com'],
        ]),
    ];
    $client->responseQueues['uapi:Mysql:list_databases'] = [
        $client->success([]),
        $client->success([['database' => 'shipper_app']]),
    ];
    $client->responseQueues['uapi:Mysql:list_users'] = [
        $client->success([]),
        $client->success([['user' => 'shipper_app']]),
    ];
    $client->responseQueues['uapi:VersionControl:retrieve'] = [
        $client->success([]),
        $client->success([['repository_root' => '/home/shipper/app']]),
    ];
    $client->responseQueues['uapi:PassengerApps:list_applications'] = [
        $client->success([]),
        $client->success([['name' => 'shipper-sample-production']]),
    ];
    $client->responseQueues['api2:Park:park'] = [
        $client->success(),
        [
            'success' => false,
            'message' => 'The domain already exists',
            'data' => null,
            'raw' => [],
        ],
    ];
    $provider = new CpanelProvider([
        'host' => 'cpanel.example.com',
        'username' => 'shipper',
        'password' => 'secret',
        'deployment_method' => 'git',
    ], $client);
    $project = new CpanelTestProject('.', [
        'url' => 'https://github.com/shippercli/sample.git',
    ]);
    $profile = new CpanelTestProfile([
        'domain' => 'app.shippercli.com',
        'deploy_path' => '/app',
        'aliases' => ['www.app.shippercli.com'],
        'runtime' => [
            'type' => 'nodejs',
            'application_root' => 'app',
        ],
        'databases' => [
            'main' => [
                'type' => 'mysql',
                'name' => 'app',
                'user' => 'app',
                'password' => 'database-secret',
            ],
        ],
        'cpanel' => ['domain_type' => 'addon'],
    ]);

    expect($provider->apply($project, $profile))->toBeTrue();
    $firstManifest = $client->uploadedFiles['.shipper-manifest.json'];
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => $firstManifest,
    ]);

    expect($provider->apply($project, $profile))->toBeTrue();
    $secondManifestJson = $client->uploadedFiles['.shipper-manifest.json'];
    $secondManifest = \json_decode($secondManifestJson, true);

    expect($secondManifest['domain']['created'])->toBeTrue()
        ->and($secondManifest['aliases'][0]['created'])->toBeTrue()
        ->and($secondManifest['databases'][0]['database_created'])->toBeTrue()
        ->and($secondManifest['databases'][0]['user_created'])->toBeTrue()
        ->and($secondManifest['passenger']['created'])->toBeTrue()
        ->and($secondManifest['deployment']['repository_created'])->toBeTrue();

    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => $secondManifestJson,
    ]);

    expect($provider->destroy($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'unregister_application'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'delete_user'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Mysql', 'delete_database'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'VersionControl', 'delete'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'Park', 'unpark'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'api2', 'AddonDomain', 'deladdondomain'))->toHaveCount(1);
});

test('apply refuses to overwrite another deployment manifest', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => \json_encode([
            'project' => 'another-project',
            'profile' => 'production',
            'deploy_path' => '/app',
        ], JSON_THROW_ON_ERROR),
    ]);
    $provider = cpanelProviderWithExistingDomain($client);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
    ))->toBeFalse()
        ->and($provider->getLastError())
        ->toBe('Refusing cPanel apply because the Shipper manifest belongs to another deployment')
        ->and(cpanelProviderCalls($client, 'uapi', 'Features', 'list_features'))->toHaveCount(0);
});

test('apply archives the previous managed release before deployment', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $manifest = [
        'project' => 'sample',
        'profile' => 'production',
        'deploy_path' => '/app',
    ];
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => \json_encode($manifest, JSON_THROW_ON_ERROR)]),
        $client->success(['content' => "0\n"]),
    ];
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '91']]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
            'runtime' => ['type' => 'static'],
            'cpanel' => [
                'archive_extraction' => 'direct',
                'backup_before_deploy' => true,
            ],
        ]),
    ))->toBeTrue();

    $backupTask = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0];
    $writtenManifest = \json_decode($client->uploadedFiles['.shipper-manifest.json'], true);
    expect($backupTask['parameters']['command'])
        ->toContain('/usr/bin/tar')
        ->toContain(' -czf ')
        ->toContain('/.shipper/releases/sample/production/')
        ->not->toContain('%')
        ->and($writtenManifest['previous_release']['id'])->toMatch('/^\d{14}-[a-f0-9]{8}$/');
});

test('rollback restores a selected managed release archive', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client);
    $manifest = [
        'project' => 'sample',
        'profile' => 'production',
        'deploy_path' => '/app',
    ];
    $release = '20260727010101-deadbeef.tar.gz';
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => \json_encode($manifest, JSON_THROW_ON_ERROR)]),
        $client->success(['content' => "0\n"]),
    ];
    $client->responses['uapi:Fileman:list_files'] = $client->success([
        ['file' => $release],
    ]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);

    expect($provider->rollback(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
        '20260727010101-deadbeef',
    ))->toBeTrue();

    $restoreTask = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0];
    expect($restoreTask['parameters']['command'])
        ->toContain(' -xzf ')
        ->toContain('/.shipper/releases/sample/production/'.$release);
});

test('status reports manifest deployment resource usage and releases', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client);
    $client->responses['uapi:Fileman:get_file_content'] = $client->success([
        'content' => \json_encode([
            'project' => 'sample',
            'profile' => 'production',
            'deploy_path' => '/app',
            'applied_at' => '2026-07-27T01:02:03+00:00',
            'runtime' => ['type' => 'php'],
            'deployment' => [
                'method' => 'git',
                'repository_root' => '/home/shipper/app',
            ],
            'previous_release' => [
                'id' => '20260727010101-deadbeef',
                'filename' => '20260727010101-deadbeef.tar.gz',
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
    $client->responses['uapi:ResourceUsage:get_usages'] = $client->success([
        ['id' => 'diskusage', 'usage' => 42],
    ]);
    $client->responses['uapi:VersionControlDeployment:retrieve'] = $client->success([
        ['state' => 'succeeded'],
    ]);
    $client->responses['uapi:Fileman:list_files'] = $client->success([
        ['file' => '20260727010101-deadbeef.tar.gz'],
    ]);

    $status = $provider->status(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
    );

    expect($status['state'])->toBe('deployed')
        ->and($status['manifest_matches'])->toBeTrue()
        ->and($status['deployment']['method'])->toBe('git')
        ->and($status['previous_release']['id'])->toBe('20260727010101-deadbeef')
        ->and($status['deployment_status']['available'])->toBeTrue()
        ->and($status['resource_usage']['data'][0]['usage'])->toBe(42)
        ->and($status['releases'][0]['id'])->toBe('20260727010101-deadbeef');
});

test('logs normalize cpanel site error entries and enforce the requested limit', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client);
    $client->responses['uapi:Stats:get_site_errors'] = $client->success([
        "first line\nsecond line",
        ['message' => 'third line'],
    ]);

    $logs = $provider->logs(
        new CpanelTestProject('.'),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
        2,
    );

    expect($logs)->toBe(['second line', 'third line']);
    $call = cpanelProviderCalls($client, 'uapi', 'Stats', 'get_site_errors')[0];
    expect($call['parameters']['maxlines'])->toBe(2)
        ->and($call['parameters']['domain'])->toBe('app.example.com');
});

test('python and ruby applications install passenger dependencies', function (
    string $runtime,
    string $manager,
): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);
    $client->responses['uapi:PassengerApps:list_applications'] = $client->success([]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
            'runtime' => [
                'type' => $runtime,
                'application_root' => 'app',
                'install_dependencies' => true,
            ],
            'cpanel' => ['archive_extraction' => 'direct'],
        ]),
    ))->toBeTrue();

    $dependencies = cpanelProviderCalls($client, 'uapi', 'PassengerApps', 'ensure_deps')[0];
    expect($dependencies['parameters']['type'])->toBe($manager);
})->with([
    'Python and pip' => ['python', 'pip'],
    'Ruby and gem' => ['ruby', 'gem'],
]);

test('postgresql databases users and privileges use the cpanel postgresql API', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'fileman',
    ]);

    expect($provider->apply(
        new CpanelTestProject(cpanelProviderFixture()),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
            'runtime' => ['type' => 'static'],
            'databases' => [
                'main' => [
                    'type' => 'postgresql',
                    'name' => 'app',
                    'user' => 'app',
                    'password' => 'database-secret',
                ],
            ],
            'cpanel' => ['archive_extraction' => 'direct'],
        ]),
    ))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'create_database'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'create_user'))->toHaveCount(1)
        ->and(cpanelProviderCalls($client, 'uapi', 'Postgresql', 'grant_all_privileges'))->toHaveCount(1)
        ->and($client->uploadedFiles['.env'])->toContain(
            'DB_CONNECTION="pgsql"',
            'DB_DATABASE="shipper_app"',
        );
});

test('explicit git deployment reports unavailable account features precisely', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, [
        'deployment_method' => 'git',
    ]);
    $client->responses['uapi:VersionControl:retrieve'] = [
        'success' => false,
        'message' => 'Git Version Control is not enabled for this account',
        'data' => null,
        'raw' => [],
    ];

    expect($provider->apply(
        new CpanelTestProject('.', ['url' => 'https://github.com/shippercli/sample.git']),
        new CpanelTestProfile([
            'domain' => 'app.example.com',
            'deploy_path' => '/app',
        ]),
    ))->toBeFalse()
        ->and($provider->getLastError())
        ->toBe('List cPanel Git repositories failed: Git Version Control is not enabled for this account');
});
