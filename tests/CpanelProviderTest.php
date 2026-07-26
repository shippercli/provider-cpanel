<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Api\CpanelApiClientInterface;
use ShipperCli\ProviderCpanel\CpanelPlugin;
use ShipperCli\ProviderCpanel\CpanelProvider;

final class FakeCpanelApiClient implements CpanelApiClientInterface
{
    /** @var array<int, array{surface: string, module: string, function: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{success: bool, message: string, data: mixed, raw: array<string, mixed>}> */
    public array $responses = [];

    public function uapi(string $module, string $function, array $parameters = [], string $method = 'GET'): array
    {
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'uapi'];

        return $this->responses["uapi:{$module}:{$function}"] ?? $this->success();
    }

    public function api2(string $module, string $function, array $parameters = []): array
    {
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'api2'];

        return $this->responses["api2:{$module}:{$function}"] ?? $this->success();
    }

    public function whm(string $function, array $parameters = [], string $method = 'GET'): array
    {
        $module = '';
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'whm'];

        return $this->responses["whm:{$function}"] ?? $this->success();
    }

    public function uploadFile(string $directory, string $localPath, string $remoteFilename, bool $overwrite = true): array
    {
        $this->calls[] = [
            'surface' => 'upload',
            'module' => 'Fileman',
            'function' => 'upload_files',
            'parameters' => compact('directory', 'localPath', 'remoteFilename', 'overwrite'),
        ];

        return $this->success();
    }

    /** @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>} */
    private function success(mixed $data = null): array
    {
        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data,
            'raw' => [],
        ];
    }
}

final class CpanelTestProject
{
    /** @param array<string, mixed> $repository */
    public function __construct(
        private readonly string $path,
        private readonly array $repository = [],
        private readonly string $webDirectory = '/',
    ) {}

    public function name(): string
    {
        return 'sample';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function repository(): array
    {
        return $this->repository;
    }

    public function webDirectory(): string
    {
        return $this->webDirectory;
    }

    public function phpVersion(): string
    {
        return '';
    }

    public function databases(): array
    {
        return [];
    }

    public function cron(): array
    {
        return [];
    }

    public function redirects(): array
    {
        return [];
    }
}

final class CpanelTestProfile
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'production';
    }

    public function branch(): string
    {
        return 'main';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function aliases(): array
    {
        return [];
    }
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
