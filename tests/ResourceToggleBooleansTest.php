<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function resourceToggleProfile(string $kind, mixed $value, string $runtimeType = 'static'): CpanelTestProfile
{
    $config = ['domain' => 'app.example.com', 'deploy_path' => '/app', 'runtime' => ['type' => $runtimeType],
        'cpanel' => ['archive_extraction' => 'direct', 'adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com']]];
    switch ($kind) {
        case 'runtime': $config['runtime']['install_dependencies'] = $value; break;
        case 'cpanel-runtime': $config['cpanel']['runtime'] = ['install_dependencies' => $value]; break;
        case 'cron': $config['cron'] = ['job' => ['command' => 'echo fixture', 'enabled' => $value]]; break;
        case 'redirects': $config['redirects'] = [['from' => '/old', 'to' => 'https://example.com/new', 'enabled' => $value]]; break;
        case 'ssl': $config['ssl'] = ['enabled' => $value]; break;
        case 'force-https': $config['ssl'] = ['enabled' => true, 'force_https' => $value]; break;
    }
    return new CpanelTestProfile($config);
}

test('resource activation and dependency flags reject non-booleans before API calls', function (mixed $value, string $kind, string $field): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture());
    $profile = resourceToggleProfile($kind, $value);
    $error = "$field must be a boolean";
    expect($provider->validate($project, $profile))->toContain($error);
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class, $error);
    expect($provider->apply($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error);
    expect($provider->rollback($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error)
        ->and($client->calls)->toBeEmpty();
})->with([['false'], ['true'], [0], [1], [null], [[]]])->with([
    ['runtime', 'runtime.install_dependencies'], ['cpanel-runtime', 'runtime.install_dependencies'],
    ['cron', 'cron.enabled'], ['redirects', 'redirects.enabled'], ['ssl', 'ssl.enabled'], ['force-https', 'ssl.force_https'],
]);

function resourceToggleProject(string $kind, mixed $value): object
{
    return new class(cpanelProviderFixture(), $kind, $value) {
        public function __construct(private string $directory, private string $kind, private mixed $value) {}
        public function path(): string { return $this->directory; }
        public function name(): string { return 'sample'; }
        public function webDirectory(): string { return '/'; }
        public function cron(): array { return $this->kind === 'cron' ? ['job' => $this->entry()] : []; }
        public function redirects(): array { return $this->kind === 'redirects' ? [$this->entry()] : []; }
        public function ssl(): ?object { return str_starts_with($this->kind, 'ssl') ? $this->entry() : null; }
        private function entry(): object {
            return new class($this->kind, $this->value) {
                public function __construct(private string $kind, private mixed $value) {}
                public function enabled(): mixed { return $this->kind === 'ssl-force' ? true : $this->value; }
                public function forceHttps(): mixed { return $this->kind === 'ssl-force' ? $this->value : false; }
                public function command(): string { return 'echo fixture'; }
                public function frequency(): string { return 'daily'; }
                public function from(): string { return '/old'; }
                public function to(): string { return 'https://example.com/new'; }
                public function type(): string { return $this->kind === 'redirects' ? 'permanent' : 'autossl'; }
            };
        }
    };
}

test('project boolean getters cannot activate resources with string results', function (string $kind, string $method): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = resourceToggleProject($kind, 'false');
    $profile = resourceToggleProfile('unused', true);
    $error = "cPanel {$method}() must return a boolean";
    expect($provider->validate($project, $profile))->toContain($error);
    expect(fn () => $provider->plan($project, $profile))->toThrow(RuntimeException::class, $error);
    expect($provider->apply($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error);
    expect($provider->rollback($project, $profile))->toBeFalse()->and($provider->getLastError())->toBe($error)
        ->and($client->calls)->toBeEmpty();
})->with([['cron', 'enabled'], ['redirects', 'enabled'], ['ssl', 'enabled'], ['ssl-force', 'forceHttps']]);

test('genuine project boolean getters retain enabled and disabled behavior', function (bool $enabled, string $kind, string $surface, string $module, string $function): void {
    $client = new FakeCpanelApiClient;
    $client->responses['api2:Cron:listcron'] = $client->success([]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '17']]);
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = resourceToggleProject($kind, $enabled);
    $profile = resourceToggleProfile('unused', true);
    expect($provider->validate($project, $profile))->toBeEmpty()
        ->and($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, $surface, $module, $function))->toHaveCount($enabled ? 1 : 0);
})->with([true, false])->with([
    ['cron', 'api2', 'Cron', 'add_line'], ['redirects', 'uapi', 'Mime', 'add_redirect'],
    ['ssl', 'uapi', 'SSL', 'start_autossl_check'], ['ssl-force', 'uapi', 'SSL', 'toggle_ssl_redirect_for_domains'],
]);

test('a genuine cpanel runtime override replaces a shadowed invalid profile value', function (): void {
    $client = new FakeCpanelApiClient;
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture(['index.html' => 'fixture', 'composer.json' => '{}']));
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app',
        'runtime' => ['type' => 'php', 'install_dependencies' => 'false'],
        'cpanel' => ['adopt_existing' => ['project' => 'sample', 'profile' => 'production', 'domain' => 'app.example.com'], 'archive_extraction' => 'direct', 'runtime' => ['install_dependencies' => false]]]);
    expect($provider->validate($project, $profile))->toBeEmpty()
        ->and($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'add_line'))->toBeEmpty();
});

test('genuine profile toggles control resource activation and dependency tasks', function (bool $enabled, string $kind, string $surface, string $module, string $function): void {
    $client = new FakeCpanelApiClient;
    $client->responses['api2:Cron:listcron'] = $client->success([]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '17']]);
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        ['success' => false, 'message' => 'No such file or directory', 'data' => null, 'raw' => []],
        $client->success(['content' => "0\n"]),
    ];
    $provider = cpanelProviderWithExistingDomain($client, ['deployment_method' => 'fileman']);
    $project = new CpanelTestProject(cpanelProviderFixture(['index.html' => 'fixture', 'composer.json' => '{}']));
    $runtime = str_contains($kind, 'runtime') ? 'php' : 'static';
    $profile = resourceToggleProfile($kind, $enabled, $runtime);
    expect($provider->validate($project, $profile))->toBeEmpty()
        ->and($provider->apply($project, $profile))->toBeTrue()
        ->and(cpanelProviderCalls($client, $surface, $module, $function))->toHaveCount($enabled ? 1 : 0);
})->with([true, false])->with([
    ['runtime', 'api2', 'Cron', 'add_line'], ['cpanel-runtime', 'api2', 'Cron', 'add_line'],
    ['cron', 'api2', 'Cron', 'add_line'], ['redirects', 'uapi', 'Mime', 'add_redirect'],
    ['ssl', 'uapi', 'SSL', 'start_autossl_check'], ['force-https', 'uapi', 'SSL', 'toggle_ssl_redirect_for_domains'],
]);
