<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ShipperCli\Contracts\DeploymentLogsProviderInterface;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\DeploymentRollbackProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;
use ShipperCli\ProviderCpanel\Api\CpanelApiClientInterface;
use Throwable;
use ZipArchive;

final class CpanelProvider implements
    DeploymentLogsProviderInterface,
    DeploymentProviderInterface,
    DeploymentRollbackProviderInterface,
    DeploymentStatusProviderInterface
{
    private const MANIFEST_FILENAME = '.shipper-manifest.json';

    private const RELEASE_EXTENSION = '.tar.gz';

    private const RELEASE_FILENAME_PATTERN = '/^\d{14}-[a-f0-9]{8}\.tar\.gz$/';

    private const SUPPORTED_DEPLOYMENT_METHODS = ['auto', 'fileman', 'git'];

    private const SUPPORTED_RUNTIME_TYPES = ['static', 'php', 'nodejs', 'python', 'ruby'];

    /** @var array<string, mixed> */
    private readonly array $config;

    private ?CpanelApiClientInterface $apiClient;

    private string $lastError = '';

    private ?string $homeDirectory = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?CpanelApiClientInterface $apiClient = null)
    {
        $this->config = $config;
        $this->apiClient = $apiClient;
    }

    public function getName(): string
    {
        return 'cpanel';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function api(): CpanelApiClientInterface
    {
        return $this->apiClient ??= new CpanelApiClient($this->config);
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];

        if ($this->stringConfig('host') === '') {
            $errors[] = 'cPanel host is required';
        }

        if ($this->stringConfig('username') === '') {
            $errors[] = 'cPanel username is required';
        }

        if ($this->stringConfig('password') === '' && $this->stringConfig('api_token') === '') {
            $errors[] = 'cPanel password or API token is required';
        }

        if ($this->projectPath($project) === '') {
            $errors[] = 'Project path is required';
        }

        if ($this->domain($profile) === '') {
            $errors[] = 'Domain is required for profile';
        }

        $method = $this->deploymentMethod($project, $profile);
        if (! \in_array($method, self::SUPPORTED_DEPLOYMENT_METHODS, true)) {
            $errors[] = 'cPanel deployment_method must be auto, fileman, or git';
        }

        $archiveExtraction = $this->stringCpanelOption($profile, 'archive_extraction', 'auto');
        if (! \in_array($archiveExtraction, ['auto', 'cron', 'direct'], true)) {
            $errors[] = 'cPanel archive_extraction must be auto, cron, or direct';
        }

        $runtime = $this->runtimeConfig($project, $profile);
        if (! \in_array($runtime['type'], self::SUPPORTED_RUNTIME_TYPES, true)) {
            $errors[] = 'cPanel runtime type must be static, php, nodejs, python, or ruby';
        }

        if ($method === 'git' && $this->repositoryUrl($project) === '') {
            $errors[] = 'Repository URL is required for cPanel Git deployment';
        }

        foreach ($this->databaseConfigs($project, $profile) as $database) {
            if (($database['user'] ?? '') !== '' && ($database['password'] ?? '') === '') {
                $errors[] = "Database password is required for cPanel database user: {$database['user']}";
            }
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $domain = $this->domain($profile);
        $method = $this->deploymentMethod($project, $profile);
        $runtime = $this->runtimeConfig($project, $profile);
        $actions = [
            'Discover enabled cPanel account features',
            "Create or find domain: {$domain}",
        ];

        if ($this->boolCpanelOption($profile, 'backup_before_deploy', false)) {
            $actions[] = 'Archive the current Shipper-managed release before deployment';
        }

        if ($method === 'git') {
            $actions[] = 'Create or update cPanel-managed Git repository';
            $actions[] = 'Trigger cPanel Git deployment task';
        } elseif ($method === 'fileman') {
            $actions[] = 'Build deployment archive locally';
            $actions[] = 'Upload archive through authenticated Fileman UAPI';
            $actions[] = 'Extract large archives through a monitored cPanel cron task';
        } else {
            $actions[] = 'Use cPanel Git when available, otherwise use authenticated Fileman deployment';
        }

        if ($runtime['type'] === 'php' && $runtime['version'] !== '') {
            $actions[] = "Set PHP runtime: {$runtime['version']}";
        }

        if ($runtime['type'] === 'php' && $runtime['install_dependencies']) {
            $actions[] = 'Install Composer dependencies when composer.json is present';
        }

        if (\in_array($runtime['type'], ['nodejs', 'python', 'ruby'], true)) {
            $actions[] = "Register or update {$runtime['type']} Passenger application";
        }

        foreach ($this->databaseConfigs($project, $profile) as $database) {
            $actions[] = "Create or find {$database['type']} database: {$database['name']}";
        }

        $environment = $this->environmentVariables($project, $profile);
        if ($environment !== []) {
            $actions[] = 'Synchronize '.\count($environment).' environment variables';
        }

        $cron = $this->cronConfigs($project, $profile);
        if ($cron !== []) {
            $actions[] = 'Reconcile '.\count($cron).' marker-owned cron jobs';
        }

        if ($this->sslConfig($project, $profile)['enabled']) {
            $actions[] = 'Configure SSL and HTTPS redirect';
        }

        foreach (['before_apply', 'after_apply'] as $phase) {
            $count = \count($this->customOperations($profile, $phase));
            if ($count > 0) {
                $actions[] = "Run {$count} custom cPanel {$phase} operations";
            }
        }

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'branch' => $this->profileBranch($profile),
            'domain' => $domain,
            'domain_type' => $this->domainType($profile),
            'deploy_path' => $this->deployPath($profile),
            'deployment_method' => $method,
            'runtime' => $runtime,
            'database_count' => \count($this->databaseConfigs($project, $profile)),
            'cron_count' => \count($cron),
            'actions' => $actions,
            'note' => 'Operations are authorized and feature-gated by the target cPanel account.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->lastError = '';

        try {
            $context = $this->context($project, $profile);
            $this->runCustomOperations($profile, 'before_apply', $context);
            $features = $this->required($this->api()->uapi('Features', 'list_features'), 'Discover cPanel features');
            $domainState = $this->ensureDomain($profile);
            $runtime = $this->runtimeConfig($project, $profile);

            $this->configurePhp($domainState['domain'], $runtime, $profile);
            $databaseState = $this->ensureDatabases($project, $profile);

            $method = $this->deploymentMethod($project, $profile);
            $previousRelease = $this->createReleaseBackup($project, $profile);
            $deploymentState = $this->deploy($project, $profile, $method);
            $environment = [
                ...$this->environmentVariables($project, $profile),
                ...$databaseState['environment'],
            ];

            $passengerState = $this->configurePassenger(
                $project,
                $profile,
                $runtime,
                $environment,
            );

            if ($passengerState === null || $this->boolCpanelOption($profile, 'environment_file', false)) {
                $this->writeEnvironmentFile($project, $profile, $environment);
            }

            $dependencyState = $this->installPhpDependencies($project, $profile, $runtime);
            $cronState = $this->reconcileCron($project, $profile);
            $redirectState = $this->reconcileRedirects($project, $profile);
            $sslState = $this->configureSsl($project, $profile);
            $aliasState = $this->ensureAliases($profile, $domainState);

            $manifest = [
                'version' => 1,
                'provider' => $this->getName(),
                'project' => $this->projectName($project),
                'profile' => $this->profileName($profile),
                'domain' => $domainState,
                'aliases' => $aliasState,
                'deploy_path' => $this->deployPath($profile),
                'previous_release' => $previousRelease,
                'deployment' => $deploymentState,
                'runtime' => $runtime,
                'passenger' => $passengerState,
                'dependencies' => $dependencyState,
                'databases' => $databaseState['resources'],
                'cron' => $cronState,
                'redirects' => $redirectState,
                'ssl' => $sslState,
                'features' => $features,
                'applied_at' => \gmdate(DATE_ATOM),
            ];

            $this->writeManifest($profile, $manifest);
            $this->runCustomOperations($profile, 'after_apply', [
                ...$context,
                'manifest' => $manifest,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return false;
        }
    }

    public function destroy(object $project, object $profile): bool
    {
        $this->lastError = '';

        try {
            $manifest = $this->readManifest($profile);
            if ($manifest === null) {
                throw new RuntimeException('Refusing cPanel destroy because no matching Shipper manifest exists');
            }

            if (($manifest['project'] ?? null) !== $this->projectName($project)
                || ($manifest['profile'] ?? null) !== $this->profileName($profile)) {
                throw new RuntimeException('Refusing cPanel destroy because the Shipper manifest belongs to another deployment');
            }

            $context = [
                ...$this->context($project, $profile),
                'manifest' => $manifest,
            ];
            $this->runCustomOperations($profile, 'before_destroy', $context);
            $this->destroyCron($manifest);
            $this->destroyPassenger($manifest);
            $this->destroyDatabases($manifest);
            $this->destroyGitRepository($manifest);
            $this->destroyAliases($manifest);
            $this->destroyDomain($manifest);
            $this->destroyFiles($manifest);
            $this->runCustomOperations($profile, 'after_destroy', $context);

            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(object $project, object $profile): array
    {
        $manifest = $this->readManifest($profile);
        $manifestMatches = $manifest !== null && $this->manifestMatches($manifest, $project, $profile);
        $domainResult = $this->api()->uapi('DomainInfo', 'domains_data', ['format' => 'hash']);
        $resourceResult = $this->api()->uapi('ResourceUsage', 'get_usages');
        $deploymentStatus = null;

        if ($manifestMatches) {
            $deployment = $manifest['deployment'] ?? null;
            if (\is_array($deployment)
                && ($deployment['method'] ?? null) === 'git'
                && \is_string($deployment['repository_root'] ?? null)) {
                $deploymentStatus = $this->optionalApiResult(
                    $this->api()->uapi('VersionControlDeployment', 'retrieve', [
                        'repository_root' => $deployment['repository_root'],
                    ]),
                );
            }
        }

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'state' => $manifest === null
                ? 'not_deployed'
                : ($manifestMatches ? 'deployed' : 'manifest_mismatch'),
            'domain' => $this->domain($profile),
            'deploy_path' => $this->deployPath($profile),
            'manifest_matches' => $manifestMatches,
            'applied_at' => $manifestMatches ? ($manifest['applied_at'] ?? null) : null,
            'runtime' => $manifestMatches ? ($manifest['runtime'] ?? null) : null,
            'deployment' => $manifestMatches ? ($manifest['deployment'] ?? null) : null,
            'deployment_status' => $deploymentStatus,
            'domain_status' => $this->optionalApiResult($domainResult),
            'resource_usage' => $this->optionalApiResult($resourceResult),
            'releases' => $this->availableReleases($project, $profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logs(object $project, object $profile, int $lines = 100): array
    {
        $limit = \max(1, \min(5000, $lines));
        $data = $this->required($this->api()->uapi('Stats', 'get_site_errors', [
            'domain' => $this->domain($profile),
            'log' => 'error',
            'maxlines' => $limit,
        ]), 'Read cPanel site error log');
        $entries = $this->logLines($data);

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'domain' => $this->domain($profile),
            'source' => 'apache_error_log',
            'lines' => \array_slice($entries, -$limit),
        ];
    }

    public function rollback(
        object $project,
        object $profile,
        ?string $release = null,
    ): bool {
        $this->lastError = '';

        try {
            $manifest = $this->matchingManifest($project, $profile, 'rollback');
            $selected = $this->selectRelease($project, $profile, $release);

            if ($this->boolCpanelOption($profile, 'backup_before_rollback', false)) {
                $this->createReleaseBackup($project, $profile, true);
            }

            $deployPath = $manifest['deploy_path'] ?? null;
            if (! \is_string($deployPath) || ! $this->isSafeManagedPath($deployPath)) {
                throw new RuntimeException('Refusing cPanel rollback because the manifest deploy path is unsafe');
            }

            $deployDirectory = $this->relativeHomePath($deployPath);
            $this->cleanManagedDirectory($deployDirectory);
            $this->ensureRemoteDirectory($deployDirectory);

            $tar = $this->stringCpanelOption($profile, 'tar_path', '/usr/bin/tar');
            $command = \escapeshellarg($tar).' -xzf '.\escapeshellarg($selected['path'])
                .' -C '.\escapeshellarg($this->absolutePath($deployPath));
            $this->runOneTimeCommand("Restore cPanel release {$selected['id']}", $command, $profile);

            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return false;
        }
    }

    /**
     * @return array{domain: string, type: string, created: bool, primary_domain: string}
     */
    private function ensureDomain(object $profile): array
    {
        $domain = $this->domain($profile);
        $domains = $this->required(
            $this->api()->uapi('DomainInfo', 'domains_data', ['format' => 'hash']),
            'List cPanel domains',
        );
        $existingType = $this->classifyDomain($domains, $domain);

        if ($existingType !== null) {
            return [
                'domain' => $domain,
                'type' => $existingType,
                'created' => false,
                'primary_domain' => $this->primaryDomain($domains),
            ];
        }

        $primaryDomain = $this->primaryDomain($domains);
        $type = $this->domainType($profile);
        if ($type === 'auto') {
            $type = \str_ends_with($domain, '.'.$primaryDomain) ? 'subdomain' : 'addon';
        }

        $deployPath = $this->deployPath($profile);

        if ($type === 'existing') {
            throw new RuntimeException("cPanel domain does not exist: {$domain}");
        }

        if ($type === 'subdomain') {
            if (! \str_ends_with($domain, '.'.$primaryDomain)) {
                throw new RuntimeException("Subdomain {$domain} is not below cPanel primary domain {$primaryDomain}");
            }

            $subdomain = \substr($domain, 0, -\strlen('.'.$primaryDomain));
            $creation = $this->api()->uapi('SubDomain', 'addsubdomain', [
                'domain' => $subdomain,
                'rootdomain' => $primaryDomain,
                'dir' => $deployPath,
                'disallowdot' => 0,
            ]);
            $operation = "Create cPanel subdomain {$domain}";
        } elseif ($type === 'addon') {
            $subdomain = $this->slug($domain);
            $creation = $this->api()->api2('AddonDomain', 'addaddondomain', [
                'newdomain' => $domain,
                'subdomain' => $subdomain,
                'dir' => \ltrim($deployPath, '/'),
                'ftp_is_optional' => 1,
            ]);
            $operation = "Create cPanel addon domain {$domain}";
        } else {
            throw new RuntimeException("Unsupported cPanel domain type: {$type}");
        }

        if (! $creation['success']) {
            $reconciled = $this->api()->uapi('DomainInfo', 'domains_data', ['format' => 'hash']);
            if (! $reconciled['success'] || $this->classifyDomain($reconciled['data'], $domain) === null) {
                $this->required($creation, $operation);
            }
        }

        return [
            'domain' => $domain,
            'type' => $type,
            'created' => true,
            'primary_domain' => $primaryDomain,
        ];
    }

    /**
     * @param array{domain: string, type: string, created: bool, primary_domain: string} $domainState
     *
     * @return array<int, array{domain: string, created: bool}>
     */
    private function ensureAliases(object $profile, array $domainState): array
    {
        $aliases = $this->profileAliases($profile);
        $state = [];

        foreach ($aliases as $alias) {
            $parameters = [
                'domain' => $alias,
                'disallowdot' => 0,
            ];

            if ($domainState['domain'] !== $domainState['primary_domain']) {
                $parameters['topdomain'] = \strtok($domainState['domain'], '.') ?: $domainState['domain'];
            }

            $result = $this->api()->api2('Park', 'park', $parameters);
            $created = $result['success'];
            if (! $created && ! $this->isAlreadyExists($result['message'])) {
                $this->required($result, "Create cPanel domain alias {$alias}");
            }

            $state[] = [
                'domain' => $alias,
                'created' => $created,
            ];
        }

        return $state;
    }

    /**
     * @param array{type: string, version: string, application_root: string, base_uri: string, install_dependencies: bool, php_ini: array<string, scalar>} $runtime
     */
    private function configurePhp(string $domain, array $runtime, object $profile): void
    {
        if ($runtime['type'] !== 'php') {
            return;
        }

        if ($runtime['version'] !== '') {
            $this->required($this->api()->uapi('LangPHP', 'php_set_vhost_versions', [
                'version' => $this->normalizePhpVersion($runtime['version']),
                'vhost-0' => $domain,
            ]), "Set PHP version for {$domain}");
        }

        if ($runtime['php_ini'] !== []) {
            $directives = [];
            foreach ($runtime['php_ini'] as $name => $value) {
                $directives[] = $name.':'.$this->scalarString($value);
            }

            $this->required($this->api()->uapi('LangPHP', 'php_ini_set_user_basic_directives', [
                'type' => 'vhost',
                'vhost' => $domain,
                'directive' => $directives,
            ]), "Set PHP directives for {$domain}");
        }
    }

    /**
     * @param array{type: string, version: string, application_root: string, base_uri: string, install_dependencies: bool, php_ini: array<string, scalar>} $runtime
     *
     * @return array{manager: string, working_directory: string}|null
     */
    private function installPhpDependencies(object $project, object $profile, array $runtime): ?array
    {
        if ($runtime['type'] !== 'php' || ! $runtime['install_dependencies']) {
            return null;
        }

        $source = $this->resolveProjectPath($this->projectPath($project));
        if (! \is_file($source.'/composer.json')) {
            return null;
        }

        $workingDirectory = $this->absolutePath($this->deployPath($profile));
        if (\trim($this->projectWebDirectory($project), '/') !== '') {
            $workingDirectory .= '/app';
        }

        $php = $this->stringCpanelOption($profile, 'php_cli_path');
        if ($php === '') {
            $php = $runtime['version'] === ''
                ? '/usr/local/bin/php'
                : '/opt/cpanel/'.$this->normalizePhpVersion($runtime['version']).'/root/usr/bin/php';
        }
        $composer = $this->stringCpanelOption($profile, 'composer_path', '/usr/local/bin/composer');
        $command = 'cd '.\escapeshellarg($workingDirectory)
            .' && '.\escapeshellarg($php).' '.\escapeshellarg($composer)
            .' install --no-dev --no-interaction --prefer-dist --optimize-autoloader';
        $this->runOneTimeCommand('Install cPanel Composer dependencies', $command, $profile);

        return [
            'manager' => 'composer',
            'working_directory' => $workingDirectory,
        ];
    }

    private function runOneTimeCommand(string $label, string $command, object $profile): void
    {
        $task = '.shipper-task-'.\bin2hex(\random_bytes(8));
        $statusFile = $this->absolutePath('/'.$task.'.status');
        $logFile = $this->absolutePath('/'.$task.'.log');
        $lockDirectory = $this->absolutePath('/'.$task.'.lock');
        $script = 'if [ ! -f '.\escapeshellarg($statusFile).' ]'
            .' && mkdir '.\escapeshellarg($lockDirectory).' 2>/dev/null; then '
            .'if ('.$command.') > '.\escapeshellarg($logFile).' 2>&1; '
            .'then status=0; else status=$?; fi; '
            .'echo "$status" > '.\escapeshellarg($statusFile).'; '
            .'rmdir '.\escapeshellarg($lockDirectory).' 2>/dev/null || true; fi';
        $cron = $this->required($this->api()->api2('Cron', 'add_line', [
            'command' => '/bin/sh -c '.\escapeshellarg($script),
            'day' => '*',
            'hour' => '*',
            'minute' => '*',
            'month' => '*',
            'weekday' => '*',
        ]), "Schedule {$label}");
        $linekey = $this->findValueAtKey($cron, 'linekey');
        if (! \is_scalar($linekey) || (string) $linekey === '') {
            throw new RuntimeException("cPanel did not return a line key for {$label}");
        }

        $status = null;
        $options = $this->cpanelOptions($profile);
        $timeoutValue = $options['task_timeout'] ?? $options['dependency_timeout'] ?? 360;
        $timeout = \is_numeric($timeoutValue) ? \max(60, (int) $timeoutValue) : 360;
        $deadline = \microtime(true) + $timeout;

        try {
            do {
                $result = $this->api()->uapi('Fileman', 'get_file_content', [
                    'dir' => \dirname($statusFile),
                    'file' => \basename($statusFile),
                    'from_charset' => 'utf-8',
                    'to_charset' => 'utf-8',
                ]);
                $content = $result['success'] ? $this->findValueAtKey($result['data'], 'content') : null;
                if (\is_string($content) && \preg_match('/^\s*(\d+)\s*$/', $content, $matches) === 1) {
                    $status = (int) $matches[1];
                    break;
                }

                if (\microtime(true) >= $deadline) {
                    throw new RuntimeException("Timed out waiting for {$label} after {$timeout} seconds");
                }

                \usleep(2_000_000);
            } while (true);

            if ($status !== 0) {
                $logResult = $this->api()->uapi('Fileman', 'get_file_content', [
                    'dir' => \dirname($logFile),
                    'file' => \basename($logFile),
                    'from_charset' => 'utf-8',
                    'to_charset' => 'utf-8',
                ]);
                $log = $logResult['success'] ? $this->findValueAtKey($logResult['data'], 'content') : null;
                $details = \is_string($log) && \trim($log) !== ''
                    ? ': '.\substr(\trim($log), -2000)
                    : '';

                throw new RuntimeException("{$label} failed with exit code {$status}{$details}");
            }
        } finally {
            $this->api()->api2('Cron', 'remove_line', ['linekey' => (string) $linekey]);

            if ($status === 0) {
                foreach ([$statusFile, $logFile] as $file) {
                    $this->api()->api2('Fileman', 'fileop', [
                        'op' => 'unlink',
                        'sourcefiles' => $this->relativeHomePath($file),
                    ]);
                }
            }
        }
    }

    /**
     * @return array{resources: array<int, array<string, mixed>>, environment: array<string, string>}
     */
    private function ensureDatabases(object $project, object $profile): array
    {
        $resources = [];
        $environment = [];

        foreach ($this->databaseConfigs($project, $profile) as $index => $database) {
            $type = $database['type'];
            $name = $this->qualifiedDatabaseName($database['name']);
            $user = $database['user'] === '' ? '' : $this->qualifiedDatabaseName($database['user']);
            $password = $database['password'];

            if ($type === 'mysql') {
                $databaseExisted = $this->mysqlDatabaseExists($name);
                if (! $databaseExisted) {
                    $this->required(
                        $this->api()->uapi('Mysql', 'create_database', ['name' => $name]),
                        "Create MySQL database {$name}",
                    );
                }

                $userExisted = $user === '' ? true : $this->mysqlUserExists($user);
                if ($user !== '' && ! $userExisted) {
                    $this->required($this->api()->uapi('Mysql', 'create_user', [
                        'name' => $user,
                        'password' => $password,
                    ]), "Create MySQL user {$user}");
                } elseif ($user !== '' && $password !== '' && $this->boolDatabaseOption($database, 'update_password', false)) {
                    $this->required($this->api()->uapi('Mysql', 'set_password', [
                        'user' => $user,
                        'password' => $password,
                    ]), "Update MySQL user {$user}");
                }

                if ($user !== '') {
                    $this->required($this->api()->uapi('Mysql', 'set_privileges_on_database', [
                        'user' => $user,
                        'database' => $name,
                        'privileges' => $database['privileges'],
                    ]), "Set MySQL privileges for {$user}");
                }
            } elseif ($type === 'postgresql') {
                $databaseExisted = $this->postgresDatabaseExists($name);
                if (! $databaseExisted) {
                    $this->required(
                        $this->api()->uapi('Postgresql', 'create_database', ['name' => $name]),
                        "Create PostgreSQL database {$name}",
                    );
                }

                $userExisted = $user === '' ? true : $this->postgresUserExists($user);
                if ($user !== '' && ! $userExisted) {
                    $this->required($this->api()->uapi('Postgresql', 'create_user', [
                        'name' => $user,
                        'password' => $password,
                    ]), "Create PostgreSQL user {$user}");
                }

                if ($user !== '') {
                    $this->required($this->api()->uapi('Postgresql', 'grant_all_privileges', [
                        'user' => $user,
                        'database' => $name,
                    ]), "Grant PostgreSQL privileges for {$user}");
                }
            } else {
                throw new RuntimeException("Unsupported cPanel database type: {$type}");
            }

            $resources[] = [
                'type' => $type,
                'name' => $name,
                'user' => $user,
                'database_created' => ! $databaseExisted,
                'user_created' => ! $userExisted,
            ];

            if ($index === 0) {
                $environment = [
                    'DB_CONNECTION' => $type === 'postgresql' ? 'pgsql' : 'mysql',
                    'DB_HOST' => '127.0.0.1',
                    'DB_DATABASE' => $name,
                    'DB_USERNAME' => $user,
                    'DB_PASSWORD' => $password,
                ];
            }
        }

        return [
            'resources' => $resources,
            'environment' => $environment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deploy(object $project, object $profile, string $method): array
    {
        if ($method === 'fileman') {
            return $this->deployWithFileman($project, $profile);
        }

        if ($method === 'git') {
            return $this->deployWithGit($project, $profile);
        }

        if ($this->repositoryUrl($project) !== '') {
            try {
                return $this->deployWithGit($project, $profile);
            } catch (RuntimeException $exception) {
                if (! $this->isUnavailableFeature($exception->getMessage())) {
                    throw $exception;
                }
            }
        }

        return $this->deployWithFileman($project, $profile);
    }

    /**
     * @return array<string, mixed>
     */
    private function deployWithFileman(object $project, object $profile): array
    {
        $deployPath = $this->deployPath($profile);
        $archivePath = $this->buildArchive($project);
        $deployDirectory = $this->relativeHomePath($deployPath);

        try {
            $this->ensureRemoteDirectory($deployDirectory);

            if ($this->boolCpanelOption($profile, 'clean', true)) {
                $this->cleanManagedDirectory($deployDirectory);
            }

            $extraction = $this->archiveExtractionMethod($profile, $archivePath);
            if ($extraction === 'cron') {
                $archiveName = '.shipper-release-'.\bin2hex(\random_bytes(8)).'.zip';
                $this->required(
                    $this->api()->uploadFile($deployDirectory, $archivePath, $archiveName, true),
                    'Upload cPanel deployment archive',
                );

                $absoluteDeployDirectory = $this->absolutePath($deployPath);
                $remoteArchive = $absoluteDeployDirectory.'/'.$archiveName;
                $unzip = $this->stringCpanelOption($profile, 'unzip_path', '/usr/bin/unzip');
                $command = \escapeshellarg($unzip).' -oq '.\escapeshellarg($remoteArchive)
                    .' -d '.\escapeshellarg($absoluteDeployDirectory)
                    .' && /bin/rm -f '.\escapeshellarg($remoteArchive);
                $this->runOneTimeCommand('Extract cPanel deployment archive', $command, $profile);
            } else {
                $this->uploadArchiveContents($archivePath, $deployDirectory);
            }

            return [
                'method' => 'fileman',
                'extraction' => $extraction,
                'repository_created' => false,
                'repository_root' => null,
            ];
        } finally {
            @\unlink($archivePath);
        }
    }

    private function archiveExtractionMethod(object $profile, string $archivePath): string
    {
        $method = \strtolower($this->stringCpanelOption($profile, 'archive_extraction', 'auto'));
        if ($method === 'cron' || $method === 'direct') {
            return $method;
        }
        if ($method !== 'auto') {
            throw new RuntimeException("Unsupported cPanel archive extraction method: {$method}");
        }

        $archive = new ZipArchive;
        if ($archive->open($archivePath) !== true) {
            throw new RuntimeException("Unable to inspect cPanel deployment archive: {$archivePath}");
        }

        try {
            return $archive->numFiles > 40 ? 'cron' : 'direct';
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deployWithGit(object $project, object $profile): array
    {
        $repositoryUrl = $this->repositoryUrl($project);
        if ($repositoryUrl === '') {
            throw new RuntimeException('Repository URL is required for cPanel Git deployment');
        }

        $repositoryRoot = $this->absolutePath($this->deployPath($profile));
        $repositories = $this->required(
            $this->api()->uapi('VersionControl', 'retrieve'),
            'List cPanel Git repositories',
        );
        $exists = $this->containsValueAtKey($repositories, 'repository_root', $repositoryRoot);

        if (! $exists) {
            $this->required($this->api()->uapi('VersionControl', 'create', [
                'type' => 'git',
                'name' => $this->projectName($project),
                'repository_root' => $repositoryRoot,
                'source_repository' => [
                    'remote_name' => 'origin',
                    'url' => $repositoryUrl,
                ],
            ]), 'Create cPanel Git repository');
        }

        $this->required($this->api()->uapi('VersionControl', 'update', [
            'repository_root' => $repositoryRoot,
            'branch' => $this->profileBranch($profile),
        ]), 'Update cPanel Git repository');

        $deployment = $this->required($this->api()->uapi('VersionControlDeployment', 'create', [
            'repository_root' => $repositoryRoot,
        ]), 'Start cPanel Git deployment');

        return [
            'method' => 'git',
            'repository_created' => ! $exists,
            'repository_root' => $repositoryRoot,
            'task' => $deployment,
        ];
    }

    /**
     * @param array{type: string, version: string, application_root: string, base_uri: string, install_dependencies: bool, php_ini: array<string, scalar>} $runtime
     * @param array<string, string> $environment
     *
     * @return array{name: string, created: bool}|null
     */
    private function configurePassenger(
        object $project,
        object $profile,
        array $runtime,
        array $environment,
    ): ?array {
        if (! \in_array($runtime['type'], ['nodejs', 'python', 'ruby'], true)) {
            return null;
        }

        $name = 'shipper-'.$this->slug($this->projectName($project)).'-'.$this->slug($this->profileName($profile));
        $configuredPath = $runtime['application_root'] !== ''
            ? $runtime['application_root']
            : $this->relativeHomePath($this->deployPath($profile));
        $relativePath = $this->relativeHomePath($configuredPath);
        $registrationPath = '/'.$relativePath;
        $absolutePath = $this->absolutePath($registrationPath);
        $applications = $this->required(
            $this->api()->uapi('PassengerApps', 'list_applications'),
            'List cPanel Passenger applications',
        );
        $exists = $this->containsValueAtKey($applications, 'name', $name);
        $parameters = [
            'name' => $name,
            'path' => $exists ? $absolutePath : $registrationPath,
            'domain' => $this->domain($profile),
            'base_uri' => $runtime['base_uri'],
            'envvar_name' => \array_keys($environment),
            'envvar_value' => \array_values($environment),
            'enabled' => 1,
        ];

        $this->required(
            $this->api()->uapi('PassengerApps', $exists ? 'edit_application' : 'register_application', $parameters),
            ($exists ? 'Update' : 'Register').' cPanel Passenger application',
        );

        if ($runtime['install_dependencies']) {
            $dependencyType = match ($runtime['type']) {
                'nodejs' => 'npm',
                'python' => 'pip',
                'ruby' => 'gem',
            };
            $this->required($this->api()->uapi('PassengerApps', 'ensure_deps', [
                'app_path' => $registrationPath,
                'type' => $dependencyType,
            ]), 'Install cPanel Passenger application dependencies');
        }

        $restartDirectory = $relativePath.'/tmp';
        $this->ensureRemoteDirectory($restartDirectory);
        $this->uploadContent(
            $restartDirectory,
            'restart.txt',
            \gmdate(DATE_ATOM)."\n",
            'Restart cPanel Passenger application',
        );

        return [
            'name' => $name,
            'created' => ! $exists,
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    private function writeEnvironmentFile(object $project, object $profile, array $environment): void
    {
        if ($environment === []) {
            return;
        }

        $directory = $this->deployPath($profile);
        if (\trim($this->projectWebDirectory($project), '/') !== '') {
            $directory .= '/app';
        }

        $lines = [];
        foreach ($environment as $name => $value) {
            if (\preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) !== 1) {
                throw new RuntimeException("Invalid environment variable name: {$name}");
            }

            $lines[] = $name.'='.\json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $this->uploadContent(
            $directory,
            $this->stringCpanelOption($profile, 'environment_filename', '.env'),
            \implode("\n", $lines)."\n",
            'Write cPanel environment file',
        );
    }

    /**
     * @return array<int, array{name: string, marker: string, linekey: string|null}>
     */
    private function reconcileCron(object $project, object $profile): array
    {
        $configured = $this->cronConfigs($project, $profile);
        if ($configured === []) {
            return [];
        }

        $existing = $this->required($this->api()->api2('Cron', 'listcron'), 'List cPanel cron jobs');
        $state = [];

        foreach ($configured as $name => $cron) {
            $marker = '# shipper:'.$this->slug($this->projectName($project)).':'.$this->slug($this->profileName($profile)).':'.$this->slug($name);
            foreach ($this->recordsContaining($existing, 'command', $marker) as $record) {
                $linekey = $record['linekey'] ?? null;
                if (\is_string($linekey) || \is_int($linekey)) {
                    $this->required(
                        $this->api()->api2('Cron', 'remove_line', ['linekey' => (string) $linekey]),
                        "Remove previous cPanel cron job {$name}",
                    );
                }
            }

            if (! $cron['enabled']) {
                continue;
            }

            [$minute, $hour, $day, $month, $weekday] = $this->cronExpression($cron['frequency']);
            $result = $this->required($this->api()->api2('Cron', 'add_line', [
                'command' => $cron['command'].' '.$marker,
                'minute' => $minute,
                'hour' => $hour,
                'day' => $day,
                'month' => $month,
                'weekday' => $weekday,
            ]), "Create cPanel cron job {$name}");

            $linekey = $this->findValueAtKey($result, 'linekey');
            $state[] = [
                'name' => $name,
                'marker' => $marker,
                'linekey' => \is_scalar($linekey) ? (string) $linekey : null,
            ];
        }

        return $state;
    }

    /**
     * @return array<int, array{domain: string, source: string}>
     */
    private function reconcileRedirects(object $project, object $profile): array
    {
        $state = [];
        foreach ($this->redirectConfigs($project, $profile) as $redirect) {
            if (! $redirect['enabled']) {
                continue;
            }

            $domain = $this->domain($profile);
            $source = $redirect['from'] === '' ? '/' : $redirect['from'];
            $this->api()->uapi('Mime', 'delete_redirect', [
                'domain' => $domain,
                'src' => $source,
            ]);
            $this->required($this->api()->uapi('Mime', 'add_redirect', [
                'domain' => $domain,
                'src' => $source,
                'redirect' => $redirect['to'],
                'type' => \in_array((string) $redirect['type'], ['302', 'temp'], true) ? 'temp' : 'permanent',
                'redirect_wildcard' => 0,
                'redirect_www' => 0,
            ]), "Create cPanel redirect for {$domain}{$source}");

            $state[] = [
                'domain' => $domain,
                'source' => $source,
            ];
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function configureSsl(object $project, object $profile): array
    {
        $ssl = $this->sslConfig($project, $profile);
        if (! $ssl['enabled']) {
            return [
                'enabled' => false,
            ];
        }

        $domain = $this->domain($profile);
        if ($ssl['type'] === 'custom') {
            if ($ssl['certificate'] === '' || $ssl['private_key'] === '') {
                throw new RuntimeException('Custom cPanel SSL requires certificate and private_key');
            }

            $this->required($this->api()->uapi('SSL', 'install_ssl', [
                'domain' => $domain,
                'cert' => $this->fileOrValue($ssl['certificate']),
                'key' => $this->fileOrValue($ssl['private_key']),
                'ca_bundle' => $this->fileOrValue($ssl['ca_bundle']),
            ]), "Install custom SSL certificate for {$domain}");
        } else {
            $this->required($this->api()->uapi('SSL', 'remove_autossl_excluded_domains', [
                'domains' => $domain,
            ]), "Enable AutoSSL for {$domain}");
            $this->required(
                $this->api()->uapi('SSL', 'start_autossl_check'),
                "Start AutoSSL check for {$domain}",
            );
        }

        if ($ssl['force_https']) {
            $this->required($this->api()->uapi('SSL', 'toggle_ssl_redirect_for_domains', [
                'domains' => $domain,
                'state' => 1,
            ]), "Enable HTTPS redirect for {$domain}");
        }

        return [
            'enabled' => true,
            'type' => $ssl['type'],
            'force_https' => $ssl['force_https'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runCustomOperations(object $profile, string $phase, array $context): void
    {
        foreach ($this->customOperations($profile, $phase) as $index => $operation) {
            $surface = \strtolower($this->arrayString($operation, 'api', 'uapi'));
            $function = $this->arrayString($operation, 'function');
            $module = $this->arrayString($operation, 'module');
            $method = $this->arrayString($operation, 'method', 'GET');
            $parameters = $operation['parameters'] ?? $operation['params'] ?? [];
            if (! \is_array($parameters)) {
                throw new RuntimeException("Custom cPanel operation {$phase}[{$index}] parameters must be an object");
            }

            $parameters = $this->interpolateOperationValue($parameters, $context);
            if (! \is_array($parameters)) {
                throw new RuntimeException("Custom cPanel operation {$phase}[{$index}] parameters are invalid");
            }

            $result = match ($surface) {
                'uapi' => $this->api()->uapi($module, $function, $parameters, $method),
                'api2' => $this->api()->api2($module, $function, $parameters),
                'whm' => $this->api()->whm($function, $parameters, $method),
                default => throw new RuntimeException("Unsupported cPanel API surface: {$surface}"),
            };

            if (! $result['success'] && ! (bool) ($operation['continue_on_error'] ?? false)) {
                $this->required($result, "Run custom cPanel {$surface} operation {$phase}[{$index}]");
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(object $profile, array $manifest): void
    {
        $this->uploadContent(
            $this->deployPath($profile),
            self::MANIFEST_FILENAME,
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'Write cPanel deployment manifest',
        );
    }

    private function uploadContent(
        string $directory,
        string $filename,
        string $contents,
        string $operation,
    ): void {
        $temporaryPath = \tempnam(\sys_get_temp_dir(), 'shipper-cpanel-content-');
        if ($temporaryPath === false || \file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException("Unable to create temporary file for {$filename}");
        }

        try {
            $this->required(
                $this->api()->uploadFile($this->relativeHomePath($directory), $temporaryPath, $filename, true),
                $operation,
            );
        } finally {
            @\unlink($temporaryPath);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(object $profile): ?array
    {
        $result = $this->api()->uapi('Fileman', 'get_file_content', [
            'dir' => $this->absolutePath($this->deployPath($profile)),
            'file' => self::MANIFEST_FILENAME,
            'from_charset' => 'utf-8',
            'to_charset' => 'utf-8',
        ]);
        if (! $result['success']) {
            return null;
        }

        $content = $this->findValueAtKey($result['data'], 'content');
        if (! \is_string($content) || $content === '') {
            return null;
        }

        $manifest = \json_decode($content, true);

        return \is_array($manifest) ? $manifest : null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifestMatches(array $manifest, object $project, object $profile): bool
    {
        return ($manifest['project'] ?? null) === $this->projectName($project)
            && ($manifest['profile'] ?? null) === $this->profileName($profile);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchingManifest(object $project, object $profile, string $operation): array
    {
        $manifest = $this->readManifest($profile);
        if ($manifest === null) {
            throw new RuntimeException("Refusing cPanel {$operation} because no Shipper manifest exists");
        }

        if (! $this->manifestMatches($manifest, $project, $profile)) {
            throw new RuntimeException(
                "Refusing cPanel {$operation} because the Shipper manifest belongs to another deployment",
            );
        }

        return $manifest;
    }

    /**
     * @return array{id: string, filename: string, path: string, created_at: string}|null
     */
    private function createReleaseBackup(
        object $project,
        object $profile,
        bool $force = false,
    ): ?array {
        if (! $force && ! $this->boolCpanelOption($profile, 'backup_before_deploy', false)) {
            return null;
        }

        $manifest = $this->readManifest($profile);
        if ($manifest === null) {
            return null;
        }

        if (! $this->manifestMatches($manifest, $project, $profile)) {
            throw new RuntimeException(
                'Refusing cPanel release backup because the Shipper manifest belongs to another deployment',
            );
        }

        $deployPath = $manifest['deploy_path'] ?? null;
        if (! \is_string($deployPath) || ! $this->isSafeManagedPath($deployPath)) {
            throw new RuntimeException('Refusing cPanel release backup because the manifest deploy path is unsafe');
        }

        $releaseDirectory = $this->releaseDirectory($project, $profile);
        $this->ensureRemoteDirectory($this->relativeHomePath($releaseDirectory));

        $filename = \gmdate('YmdHis').'-'.\bin2hex(\random_bytes(4)).self::RELEASE_EXTENSION;
        $releasePath = $releaseDirectory.'/'.$filename;
        $tar = $this->stringCpanelOption($profile, 'tar_path', '/usr/bin/tar');
        $command = \escapeshellarg($tar).' -czf '.\escapeshellarg($releasePath)
            .' -C '.\escapeshellarg($this->absolutePath($deployPath)).' .';
        $this->runOneTimeCommand('Archive current cPanel release', $command, $profile);
        $this->pruneReleases(
            $project,
            $profile,
            $this->intCpanelOption($profile, 'release_retention', 5),
        );

        return [
            'id' => \substr($filename, 0, -\strlen(self::RELEASE_EXTENSION)),
            'filename' => $filename,
            'path' => $releasePath,
            'created_at' => \gmdate(DATE_ATOM),
        ];
    }

    /**
     * @return array<int, array{id: string, filename: string, path: string}>
     */
    private function availableReleases(object $project, object $profile): array
    {
        $directory = $this->releaseDirectory($project, $profile);
        $result = $this->api()->uapi('Fileman', 'list_files', [
            'dir' => $this->relativeHomePath($directory),
            'show_hidden' => 1,
        ]);
        if (! $result['success']) {
            return [];
        }

        $filenames = [];
        foreach ($this->fileNames($result['data']) as $name) {
            $filename = \basename($name);
            if (\preg_match(self::RELEASE_FILENAME_PATTERN, $filename) === 1) {
                $filenames[$filename] = true;
            }
        }
        $filenames = \array_keys($filenames);
        \rsort($filenames, SORT_STRING);

        return \array_map(
            static fn (string $filename): array => [
                'id' => \substr($filename, 0, -\strlen(self::RELEASE_EXTENSION)),
                'filename' => $filename,
                'path' => $directory.'/'.$filename,
            ],
            $filenames,
        );
    }

    /**
     * @return array{id: string, filename: string, path: string}
     */
    private function selectRelease(
        object $project,
        object $profile,
        ?string $release,
    ): array {
        $releases = $this->availableReleases($project, $profile);
        if ($releases === []) {
            throw new RuntimeException('No cPanel release archives are available for rollback');
        }

        if ($release === null || \trim($release) === '') {
            return $releases[0];
        }

        $requested = \trim($release);
        if (\basename($requested) !== $requested) {
            throw new RuntimeException('cPanel release must be an archive ID, not a path');
        }
        $requested = \str_ends_with($requested, self::RELEASE_EXTENSION)
            ? $requested
            : $requested.self::RELEASE_EXTENSION;

        foreach ($releases as $candidate) {
            if ($candidate['filename'] === $requested) {
                return $candidate;
            }
        }

        throw new RuntimeException("cPanel release archive does not exist: {$release}");
    }

    private function pruneReleases(object $project, object $profile, int $retention): void
    {
        $releases = $this->availableReleases($project, $profile);
        foreach (\array_slice($releases, \max(1, $retention)) as $release) {
            $this->required($this->api()->api2('Fileman', 'fileop', [
                'op' => 'unlink',
                'sourcefiles' => $this->relativeHomePath($release['path']),
            ]), "Prune cPanel release {$release['id']}");
        }
    }

    private function releaseDirectory(object $project, object $profile): string
    {
        return $this->absolutePath(
            '/.shipper/releases/'.$this->slug($this->projectName($project))
            .'/'.$this->slug($this->profileName($profile)),
        );
    }

    /**
     * @param array{success: bool, message: string, data: mixed, raw: array<string, mixed>} $result
     *
     * @return array{available: bool, data?: mixed, error?: string}
     */
    private function optionalApiResult(array $result): array
    {
        if ($result['success']) {
            return [
                'available' => true,
                'data' => $result['data'],
            ];
        }

        return [
            'available' => false,
            'error' => $result['message'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function logLines(mixed $value): array
    {
        if (\is_string($value)) {
            $lines = \preg_split('/\R/', $value) ?: [];

            return \array_values(\array_filter(
                \array_map('\trim', $lines),
                static fn (string $line): bool => $line !== '',
            ));
        }

        if (! \is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $entry) {
            $lines = [...$lines, ...$this->logLines($entry)];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyCron(array $manifest): void
    {
        $cron = $manifest['cron'] ?? [];
        if (! \is_array($cron)) {
            return;
        }

        foreach ($cron as $job) {
            if (! \is_array($job)) {
                continue;
            }

            $linekey = $job['linekey'] ?? null;
            if (\is_string($linekey) && $linekey !== '') {
                $this->required(
                    $this->api()->api2('Cron', 'remove_line', ['linekey' => $linekey]),
                    'Remove Shipper-managed cPanel cron job',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyPassenger(array $manifest): void
    {
        $passenger = $manifest['passenger'] ?? null;
        if (! \is_array($passenger) || ! (bool) ($passenger['created'] ?? false)) {
            return;
        }

        $name = $passenger['name'] ?? null;
        if (\is_string($name) && $name !== '') {
            $this->required(
                $this->api()->uapi('PassengerApps', 'unregister_application', ['name' => $name]),
                "Remove cPanel Passenger application {$name}",
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyDatabases(array $manifest): void
    {
        $databases = $manifest['databases'] ?? [];
        if (! \is_array($databases)) {
            return;
        }

        foreach ($databases as $database) {
            if (! \is_array($database)) {
                continue;
            }

            $type = $database['type'] ?? null;
            $name = $database['name'] ?? null;
            $user = $database['user'] ?? null;

            if ((bool) ($database['user_created'] ?? false) && \is_string($user) && $user !== '') {
                $module = $type === 'postgresql' ? 'Postgresql' : 'Mysql';
                $this->required(
                    $this->api()->uapi($module, 'delete_user', ['name' => $user]),
                    "Delete Shipper-managed cPanel database user {$user}",
                );
            }

            if ((bool) ($database['database_created'] ?? false) && \is_string($name) && $name !== '') {
                $module = $type === 'postgresql' ? 'Postgresql' : 'Mysql';
                $this->required(
                    $this->api()->uapi($module, 'delete_database', ['name' => $name]),
                    "Delete Shipper-managed cPanel database {$name}",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyGitRepository(array $manifest): void
    {
        $deployment = $manifest['deployment'] ?? null;
        if (! \is_array($deployment)
            || ($deployment['method'] ?? null) !== 'git'
            || ! (bool) ($deployment['repository_created'] ?? false)) {
            return;
        }

        $root = $deployment['repository_root'] ?? null;
        if (\is_string($root) && $root !== '') {
            $this->required(
                $this->api()->uapi('VersionControl', 'delete', ['repository_root' => $root]),
                "Delete Shipper-managed cPanel Git repository {$root}",
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyAliases(array $manifest): void
    {
        $aliases = $manifest['aliases'] ?? [];
        if (! \is_array($aliases)) {
            return;
        }

        foreach ($aliases as $alias) {
            if (! \is_array($alias) || ! (bool) ($alias['created'] ?? false)) {
                continue;
            }

            $domain = $alias['domain'] ?? null;
            if (\is_string($domain) && $domain !== '') {
                $this->required(
                    $this->api()->api2('Park', 'unpark', ['domain' => $domain]),
                    "Delete Shipper-managed cPanel alias {$domain}",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyDomain(array $manifest): void
    {
        $domain = $manifest['domain'] ?? null;
        if (! \is_array($domain) || ! (bool) ($domain['created'] ?? false)) {
            return;
        }

        $name = $domain['domain'] ?? null;
        $type = $domain['type'] ?? null;
        if (! \is_string($name) || $name === '') {
            return;
        }

        if ($type === 'subdomain') {
            $this->required(
                $this->api()->api2('SubDomain', 'delsubdomain', ['domain' => $name]),
                "Delete Shipper-managed cPanel subdomain {$name}",
            );
        } elseif ($type === 'addon') {
            $this->required(
                $this->api()->api2('AddonDomain', 'deladdondomain', ['domain' => $name]),
                "Delete Shipper-managed cPanel addon domain {$name}",
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function destroyFiles(array $manifest): void
    {
        $path = $manifest['deploy_path'] ?? null;
        if (! \is_string($path) || ! $this->isSafeManagedPath($path)) {
            return;
        }

        $this->required($this->api()->api2('Fileman', 'fileop', [
            'op' => 'trash',
            'sourcefiles' => $this->relativeHomePath($path),
            'doubledecode' => 1,
        ]), "Remove Shipper-managed cPanel deployment directory {$path}");
    }

    private function cleanManagedDirectory(string $directory): void
    {
        $result = $this->api()->uapi('Fileman', 'list_files', [
            'dir' => $directory,
            'show_hidden' => 1,
        ]);
        if (! $result['success']) {
            return;
        }

        $names = $this->fileNames($result['data']);
        $managed = [];
        foreach ($names as $name) {
            if (\in_array($name, ['.', '..', '.well-known'], true)) {
                continue;
            }
            $managed[] = $this->relativeHomePath($directory.'/'.$name);
        }

        if ($managed === []) {
            return;
        }

        $this->required($this->api()->api2('Fileman', 'fileop', [
            'op' => 'trash',
            'sourcefiles' => \implode(',', $managed),
            'doubledecode' => 1,
        ]), "Clean cPanel deployment directory {$directory}");
    }

    private function ensureRemoteDirectory(string $directory): void
    {
        $relative = \trim($this->relativeHomePath($directory), '/');
        if ($relative === '') {
            return;
        }

        $current = $this->resolveHomeDirectory();
        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }

            $candidate = $current.'/'.$segment;
            if ($this->remoteDirectoryExists($candidate)) {
                $current = $candidate;

                continue;
            }

            $result = $this->api()->api2('Fileman', 'mkdir', [
                'path' => $current,
                'name' => $segment,
                'permissions' => '0755',
            ]);
            if (! $result['success'] && ! $this->remoteDirectoryExists($candidate)) {
                $this->required($result, "Create cPanel deployment directory {$candidate}");
            }

            $current = $candidate;
        }
    }

    private function remoteDirectoryExists(string $absolutePath): bool
    {
        $result = $this->api()->uapi('Fileman', 'get_file_information', [
            'path' => $absolutePath,
        ]);
        if (! $result['success']) {
            return false;
        }

        $type = $this->findValueAtKey($result['data'], 'type');
        $exists = $this->findValueAtKey($result['data'], 'exists');

        return \is_string($type)
            && \strtolower($type) === 'dir'
            && ($exists === null || (int) $exists === 1);
    }

    private function uploadArchiveContents(string $archivePath, string $deployDirectory): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException("Unable to open deployment archive: {$archivePath}");
        }

        try {
            $files = [];
            $directories = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $rawEntry = $zip->getNameIndex($index);
                if (! \is_string($rawEntry)) {
                    continue;
                }

                $directoryEntry = \str_ends_with($rawEntry, '/');
                $entry = $this->normalizeArchiveEntry($rawEntry);
                if ($entry === '') {
                    continue;
                }

                if ($directoryEntry) {
                    $directories[$entry] = true;
                } else {
                    $files[] = $entry;
                }

                $parent = $directoryEntry ? $entry : \dirname($entry);
                while ($parent !== '.' && $parent !== '') {
                    $directories[$parent] = true;
                    $parent = \dirname($parent);
                }
            }

            \uksort($directories, static function (string $left, string $right): int {
                $depth = \substr_count($left, '/') <=> \substr_count($right, '/');

                return $depth !== 0 ? $depth : $left <=> $right;
            });

            foreach (\array_keys($directories) as $directory) {
                $this->ensureRemoteDirectory($deployDirectory.'/'.$directory);
            }

            $filesByDirectory = [];
            foreach ($files as $entry) {
                $parent = \dirname($entry);
                $remoteDirectory = $parent === '.'
                    ? $deployDirectory
                    : $deployDirectory.'/'.$parent;
                $filesByDirectory[$remoteDirectory][] = $entry;
            }

            foreach ($filesByDirectory as $remoteDirectory => $entries) {
                foreach (\array_chunk($entries, 20) as $batch) {
                    $uploads = [];
                    foreach ($batch as $entry) {
                        $stream = $zip->getStream($entry);
                        if ($stream === false) {
                            throw new RuntimeException("Unable to read deployment archive entry: {$entry}");
                        }

                        $temporaryPath = \tempnam(\sys_get_temp_dir(), 'shipper-cpanel-file-');
                        if ($temporaryPath === false) {
                            \fclose($stream);

                            throw new RuntimeException("Unable to create temporary file for archive entry: {$entry}");
                        }

                        $output = @\fopen($temporaryPath, 'wb');
                        if ($output === false) {
                            \fclose($stream);
                            @\unlink($temporaryPath);

                            throw new RuntimeException("Unable to write temporary file for archive entry: {$entry}");
                        }

                        try {
                            if (\stream_copy_to_stream($stream, $output) === false) {
                                throw new RuntimeException("Unable to extract deployment archive entry: {$entry}");
                            }
                        } finally {
                            \fclose($stream);
                            \fclose($output);
                        }

                        $uploads[\basename($entry)] = $temporaryPath;
                    }

                    try {
                        $this->required(
                            $this->api()->uploadFiles($remoteDirectory, $uploads, true),
                            "Upload cPanel deployment files to {$remoteDirectory}",
                        );
                    } finally {
                        foreach ($uploads as $temporaryPath) {
                            @\unlink($temporaryPath);
                        }
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function normalizeArchiveEntry(string $entry): string
    {
        $entry = \str_replace('\\', '/', $entry);
        if (\str_starts_with($entry, '/') || \preg_match('#(^|/)\.\.(/|$)#', $entry) === 1) {
            throw new RuntimeException("Unsafe deployment archive entry: {$entry}");
        }

        return \trim($entry, '/');
    }

    private function buildArchive(object $project): string
    {
        $source = $this->resolveProjectPath($this->projectPath($project));
        if (! \is_dir($source)) {
            throw new RuntimeException("Project path does not exist: {$source}");
        }

        $archive = \sys_get_temp_dir().'/shipper-cpanel-'.\bin2hex(\random_bytes(8)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create deployment archive: {$archive}");
        }

        $webDirectory = \trim($this->projectWebDirectory($project), '/');
        $exclusions = ['.git', '.github', 'node_modules'];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();
                $relative = \str_replace('\\', '/', \substr($path, \strlen(\rtrim($source, '/').'/')));
                if ($relative === '' || $this->isExcludedPath($relative, $exclusions)) {
                    continue;
                }

                $entry = $webDirectory === '' ? $relative : 'app/'.$relative;
                if ($item->isDir()) {
                    $zip->addEmptyDir($entry);
                } else {
                    $zip->addFile($path, $entry);
                }

                if ($webDirectory !== '' && ($relative === $webDirectory || \str_starts_with($relative, $webDirectory.'/'))) {
                    $publicEntry = \ltrim(\substr($relative, \strlen($webDirectory)), '/');
                    if ($publicEntry === '') {
                        continue;
                    }

                    if ($item->isDir()) {
                        $zip->addEmptyDir($publicEntry);
                    } elseif ($publicEntry === 'index.php') {
                        $contents = \file_get_contents($path);
                        if (! \is_string($contents)) {
                            throw new RuntimeException("Unable to read public entrypoint: {$path}");
                        }
                        $contents = \str_replace("__DIR__.'/../vendor/autoload.php'", "__DIR__.'/app/vendor/autoload.php'", $contents);
                        $contents = \str_replace("__DIR__.'/../bootstrap/app.php'", "__DIR__.'/app/bootstrap/app.php'", $contents);
                        $zip->addFromString($publicEntry, $contents);
                    } else {
                        $zip->addFile($path, $publicEntry);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * @return array{type: string, version: string, application_root: string, base_uri: string, install_dependencies: bool, php_ini: array<string, scalar>}
     */
    private function runtimeConfig(object $project, object $profile): array
    {
        $raw = $this->profileValue($profile, 'runtime', []);
        $cpanel = $this->cpanelOptions($profile);
        if (\is_array($cpanel['runtime'] ?? null)) {
            $raw = [...(\is_array($raw) ? $raw : []), ...$cpanel['runtime']];
        }
        if (! \is_array($raw)) {
            $raw = [];
        }

        $type = \strtolower($this->arrayString($raw, 'type'));
        $version = $this->arrayString($raw, 'version');
        if ($type === '') {
            $projectVersion = $this->projectMethodString($project, 'phpVersion');
            $type = $projectVersion !== '' ? 'php' : 'static';
            $version = $version !== '' ? $version : $projectVersion;
        }

        $phpIni = \is_array($raw['php_ini'] ?? null) ? $raw['php_ini'] : [];
        $directives = [];
        foreach ($phpIni as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $directives[$key] = $value;
            }
        }

        return [
            'type' => $type,
            'version' => $version,
            'application_root' => $this->arrayString($raw, 'application_root'),
            'base_uri' => $this->arrayString($raw, 'base_uri', '/'),
            'install_dependencies' => ! isset($raw['install_dependencies']) || (bool) $raw['install_dependencies'],
            'php_ini' => $directives,
        ];
    }

    /**
     * @return array<int, array{name: string, user: string, type: string, password: string, privileges: string, update_password?: bool}>
     */
    private function databaseConfigs(object $project, object $profile): array
    {
        $raw = $this->profileValue($profile, 'databases');
        $result = [];

        if (\is_array($raw)) {
            foreach ($raw as $key => $database) {
                if (! \is_array($database)) {
                    continue;
                }
                $name = $this->arrayString($database, 'name', \is_string($key) ? $key : '');
                $result[] = [
                    'name' => $this->interpolate($name, $project, $profile),
                    'user' => $this->interpolate($this->arrayString($database, 'user', $name), $project, $profile),
                    'type' => \strtolower($this->arrayString($database, 'type', 'mysql')),
                    'password' => $this->arrayString($database, 'password', $this->stringConfig('database_password')),
                    'privileges' => $this->privilegeString($database['privileges'] ?? 'ALL PRIVILEGES'),
                    'update_password' => (bool) ($database['update_password'] ?? false),
                ];
            }

            return $result;
        }

        $databases = $this->projectMethodValue($project, 'databases', []);
        if (! \is_array($databases)) {
            return [];
        }

        foreach ($databases as $database) {
            if (! \is_object($database)) {
                continue;
            }
            $name = $this->objectMethodString($database, 'name');
            $result[] = [
                'name' => $this->interpolate($name, $project, $profile),
                'user' => $this->interpolate($this->objectMethodString($database, 'user'), $project, $profile),
                'type' => \strtolower($this->objectMethodString($database, 'type', 'mysql')),
                'password' => $this->stringConfig('database_password'),
                'privileges' => 'ALL PRIVILEGES',
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{command: string, frequency: string, enabled: bool}>
     */
    private function cronConfigs(object $project, object $profile): array
    {
        $raw = $this->profileValue($profile, 'cron');
        $result = [];

        if (\is_array($raw)) {
            foreach ($raw as $name => $cron) {
                if (! \is_string($name) || ! \is_array($cron)) {
                    continue;
                }
                $result[$name] = [
                    'command' => $this->arrayString($cron, 'command'),
                    'frequency' => $this->arrayString($cron, 'frequency', 'daily'),
                    'enabled' => ! isset($cron['enabled']) || (bool) $cron['enabled'],
                ];
            }

            return $result;
        }

        $cron = $this->projectMethodValue($project, 'cron', []);
        if (! \is_array($cron)) {
            return [];
        }

        foreach ($cron as $name => $entry) {
            if (! \is_string($name) || ! \is_object($entry)) {
                continue;
            }
            $result[$name] = [
                'command' => $this->objectMethodString($entry, 'command'),
                'frequency' => $this->objectMethodString($entry, 'frequency', 'daily'),
                'enabled' => $this->objectMethodBool($entry, 'enabled', true),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{from: string, to: string, type: int|string, enabled: bool}>
     */
    private function redirectConfigs(object $project, object $profile): array
    {
        $raw = $this->profileValue($profile, 'redirects');
        $result = [];

        if (\is_array($raw)) {
            foreach ($raw as $redirect) {
                if (! \is_array($redirect)) {
                    continue;
                }
                $result[] = [
                    'from' => $this->arrayString($redirect, 'from'),
                    'to' => $this->arrayString($redirect, 'to'),
                    'type' => \is_int($redirect['type'] ?? null) || \is_string($redirect['type'] ?? null)
                        ? $redirect['type']
                        : 301,
                    'enabled' => ! isset($redirect['enabled']) || (bool) $redirect['enabled'],
                ];
            }

            return $result;
        }

        $redirects = $this->projectMethodValue($project, 'redirects', []);
        if (! \is_array($redirects)) {
            return [];
        }

        foreach ($redirects as $redirect) {
            if (! \is_object($redirect)) {
                continue;
            }
            $type = $this->projectMethodValue($redirect, 'type', 301);
            $result[] = [
                'from' => $this->objectMethodString($redirect, 'from'),
                'to' => $this->objectMethodString($redirect, 'to'),
                'type' => \is_int($type) || \is_string($type) ? $type : 301,
                'enabled' => $this->objectMethodBool($redirect, 'enabled', true),
            ];
        }

        return $result;
    }

    /**
     * @return array{enabled: bool, type: string, force_https: bool, certificate: string, private_key: string, ca_bundle: string}
     */
    private function sslConfig(object $project, object $profile): array
    {
        $raw = $this->profileValue($profile, 'ssl');
        if (\is_array($raw)) {
            return [
                'enabled' => (bool) ($raw['enabled'] ?? false),
                'type' => \strtolower($this->arrayString($raw, 'type', 'autossl')),
                'force_https' => (bool) ($raw['force_https'] ?? false),
                'certificate' => $this->arrayString($raw, 'certificate'),
                'private_key' => $this->arrayString($raw, 'private_key'),
                'ca_bundle' => $this->arrayString($raw, 'ca_bundle'),
            ];
        }

        $ssl = $this->projectMethodValue($project, 'ssl');
        if (! \is_object($ssl)) {
            return [
                'enabled' => false,
                'type' => 'autossl',
                'force_https' => false,
                'certificate' => '',
                'private_key' => '',
                'ca_bundle' => '',
            ];
        }

        return [
            'enabled' => $this->objectMethodBool($ssl, 'enabled', false),
            'type' => \strtolower($this->objectMethodString($ssl, 'type', 'autossl')),
            'force_https' => $this->objectMethodBool($ssl, 'forceHttps', false),
            'certificate' => '',
            'private_key' => '',
            'ca_bundle' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environmentVariables(object $project, object $profile): array
    {
        $variables = [];
        foreach ([$this->projectMethodValue($project, 'environment'), $this->projectMethodValue($profile, 'environment')] as $environment) {
            if (! \is_object($environment) || ! \method_exists($environment, 'variables')) {
                continue;
            }
            $values = $environment->variables();
            if (\is_array($values)) {
                foreach ($values as $name => $value) {
                    if (\is_string($name) && (\is_string($value) || \is_numeric($value))) {
                        $variables[$name] = (string) $value;
                    }
                }
            }
        }

        $raw = $this->profileValue($profile, 'environment');
        if (\is_array($raw) && \is_array($raw['variables'] ?? null)) {
            foreach ($raw['variables'] as $name => $value) {
                if (\is_string($name) && (\is_string($value) || \is_numeric($value))) {
                    $variables[$name] = (string) $value;
                }
            }
        }

        return $variables;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customOperations(object $profile, string $phase): array
    {
        $operations = $this->cpanelOptions($profile)['operations'] ?? [];
        if (! \is_array($operations) || ! \is_array($operations[$phase] ?? null)) {
            return [];
        }

        return \array_values(\array_filter($operations[$phase], '\is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(object $project, object $profile): array
    {
        return [
            'domain' => $this->domain($profile),
            'deploy_path' => $this->deployPath($profile),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'branch' => $this->profileBranch($profile),
            'username' => $this->stringConfig('username'),
        ];
    }

    private function deploymentMethod(object $project, object $profile): string
    {
        $method = $this->profileValue(
            $profile,
            'deployment_method',
            $this->config['deployment_method'] ?? 'auto',
        );

        return \is_string($method) ? \strtolower($method) : 'auto';
    }

    private function domain(object $profile): string
    {
        $domain = $this->profileValue($profile, 'domain');

        return \is_string($domain) ? \strtolower(\trim($domain)) : '';
    }

    private function domainType(object $profile): string
    {
        return \strtolower($this->stringCpanelOption($profile, 'domain_type', 'auto'));
    }

    private function deployPath(object $profile): string
    {
        $configured = $this->profileValue($profile, 'deploy_path');
        if (\is_string($configured) && \trim($configured) !== '') {
            return $this->normalizeRemotePath($configured);
        }

        $domain = $this->domain($profile);
        $parts = \explode('.', $domain);

        return \count($parts) > 2 ? '/'.$this->slug($parts[0]) : '/public_html';
    }

    private function primaryDomain(mixed $domains): string
    {
        $primary = $this->findValueAtKey($domains, 'main_domain')
            ?? $this->findValueAtKey($domains, 'primary_domain');
        if (\is_string($primary) && $primary !== '') {
            return $primary;
        }

        $result = $this->required(
            $this->api()->uapi('DomainInfo', 'primary_domain'),
            'Read cPanel primary domain',
        );
        $primary = $this->findDomain($result);
        if ($primary === null) {
            throw new RuntimeException('cPanel did not return a primary domain');
        }

        return $primary;
    }

    private function classifyDomain(mixed $data, string $domain, string $key = ''): ?string
    {
        if (! \is_array($data)) {
            return null;
        }

        foreach ($data as $itemKey => $value) {
            $nextKey = \is_string($itemKey) ? $itemKey : $key;
            if (\is_string($value) && \strtolower($value) === $domain) {
                $lower = \strtolower($nextKey);

                return match (true) {
                    \str_contains($lower, 'addon') => 'addon',
                    \str_contains($lower, 'sub') => 'subdomain',
                    \str_contains($lower, 'park'), \str_contains($lower, 'alias') => 'alias',
                    default => 'primary',
                };
            }

            $found = $this->classifyDomain($value, $domain, $nextKey);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function mysqlDatabaseExists(string $name): bool
    {
        $data = $this->required($this->api()->uapi('Mysql', 'list_databases'), 'List MySQL databases');

        return $this->containsValueAtKey($data, 'database', $name)
            || $this->containsValueAtKey($data, 'name', $name);
    }

    private function mysqlUserExists(string $name): bool
    {
        $data = $this->required($this->api()->uapi('Mysql', 'list_users'), 'List MySQL users');

        return $this->containsValueAtKey($data, 'user', $name)
            || $this->containsValueAtKey($data, 'name', $name);
    }

    private function postgresDatabaseExists(string $name): bool
    {
        $data = $this->required($this->api()->uapi('Postgresql', 'list_databases'), 'List PostgreSQL databases');

        return $this->containsValueAtKey($data, 'database', $name)
            || $this->containsValueAtKey($data, 'name', $name);
    }

    private function postgresUserExists(string $name): bool
    {
        $data = $this->required($this->api()->uapi('Postgresql', 'list_users'), 'List PostgreSQL users');

        return $this->containsValueAtKey($data, 'user', $name)
            || $this->containsValueAtKey($data, 'name', $name);
    }

    /**
     * @param array{success: bool, message: string, data: mixed, raw: array<string, mixed>} $result
     */
    private function required(array $result, string $operation): mixed
    {
        if (! $result['success']) {
            throw new RuntimeException($operation.' failed: '.$result['message']);
        }

        return $result['data'];
    }

    private function resolveHomeDirectory(): string
    {
        if ($this->homeDirectory !== null) {
            return $this->homeDirectory;
        }

        $configured = $this->stringConfig('home_directory');
        if ($configured !== '') {
            return $this->homeDirectory = \rtrim($configured, '/');
        }

        $result = $this->api()->uapi('Variables', 'get_user_information');
        if ($result['success']) {
            $home = $this->findValueAtKey($result['data'], 'home')
                ?? $this->findValueAtKey($result['data'], 'homedir');
            if (\is_string($home) && $home !== '') {
                return $this->homeDirectory = \rtrim($home, '/');
            }
        }

        return $this->homeDirectory = '/home/'.$this->stringConfig('username');
    }

    private function absolutePath(string $path): string
    {
        $path = $this->normalizeRemotePath($path);
        $home = $this->resolveHomeDirectory();

        return \str_starts_with($path, $home.'/') || $path === $home
            ? $path
            : $home.$path;
    }

    private function relativeHomePath(string $path): string
    {
        $path = $this->normalizeRemotePath($path);
        $home = $this->resolveHomeDirectory();
        if (\str_starts_with($path, $home.'/')) {
            return \ltrim(\substr($path, \strlen($home)), '/');
        }

        return \ltrim($path, '/');
    }

    private function normalizeRemotePath(string $path): string
    {
        $path = '/'.\ltrim(\str_replace('\\', '/', \trim($path)), '/');
        $segments = \explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '..' || \str_contains($segment, "\0")) {
                throw new RuntimeException('cPanel path traversal is not allowed');
            }
        }

        return \rtrim($path, '/') ?: '/';
    }

    private function isSafeManagedPath(string $path): bool
    {
        $path = $this->normalizeRemotePath($path);

        return ! \in_array($path, ['/', '/public_html', $this->resolveHomeDirectory()], true);
    }

    private function resolveProjectPath(string $path): string
    {
        if (\str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = \getcwd();
        if ($cwd === false) {
            throw new RuntimeException('Unable to resolve current working directory');
        }

        return \rtrim($cwd, '/').'/'.$path;
    }

    /**
     * @param array<int, string> $exclusions
     */
    private function isExcludedPath(string $path, array $exclusions): bool
    {
        $first = \explode('/', $path, 2)[0];

        return \in_array($first, $exclusions, true);
    }

    /**
     * @return array<int, string>
     */
    private function fileNames(mixed $data): array
    {
        if (! \is_array($data)) {
            return [];
        }

        $names = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, ['file', 'name'], true) && \is_string($value)) {
                $names[] = $value;
            } elseif (\is_array($value)) {
                $names = [...$names, ...$this->fileNames($value)];
            }
        }

        return \array_values(\array_unique($names));
    }

    private function containsValueAtKey(mixed $data, string $key, string $expected): bool
    {
        if (! \is_array($data)) {
            return false;
        }

        foreach ($data as $itemKey => $value) {
            if ($itemKey === $key && \is_scalar($value) && (string) $value === $expected) {
                return true;
            }
            if (\is_array($value) && $this->containsValueAtKey($value, $key, $expected)) {
                return true;
            }
        }

        return false;
    }

    private function findValueAtKey(mixed $data, string $key): mixed
    {
        if (! \is_array($data)) {
            return null;
        }

        if (\array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($data as $value) {
            $found = $this->findValueAtKey($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function findDomain(mixed $data): ?string
    {
        if (\is_string($data) && \preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $data) === 1) {
            return \strtolower($data);
        }

        if (\is_array($data)) {
            foreach ($data as $value) {
                $found = $this->findDomain($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recordsContaining(mixed $data, string $key, string $needle): array
    {
        if (! \is_array($data)) {
            return [];
        }

        $records = [];
        if (isset($data[$key]) && \is_string($data[$key]) && \str_contains($data[$key], $needle)) {
            $records[] = $data;
        }

        foreach ($data as $value) {
            if (\is_array($value)) {
                $records = [...$records, ...$this->recordsContaining($value, $key, $needle)];
            }
        }

        return $records;
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function cronExpression(string $frequency): array
    {
        $frequency = \trim(\strtolower($frequency));
        $preset = match ($frequency) {
            'every_minute' => '* * * * *',
            'hourly' => '0 * * * *',
            'daily' => '0 0 * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
            default => $frequency,
        };
        $parts = \preg_split('/\s+/', $preset);
        if (! \is_array($parts) || \count($parts) !== 5) {
            throw new RuntimeException("Invalid cPanel cron frequency: {$frequency}");
        }

        return [$parts[0], $parts[1], $parts[2], $parts[3], $parts[4]];
    }

    private function qualifiedDatabaseName(string $name): string
    {
        $name = \preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        $prefix = $this->stringConfig('database_prefix');
        if ($prefix === '') {
            $prefix = $this->stringConfig('username');
        }
        $prefix = \trim($prefix, '_');

        return \str_starts_with($name, $prefix.'_') ? $name : $prefix.'_'.$name;
    }

    private function privilegeString(mixed $privileges): string
    {
        if (\is_array($privileges)) {
            return \implode(',', \array_values(\array_filter($privileges, '\is_string')));
        }

        return \is_string($privileges) && $privileges !== '' ? $privileges : 'ALL PRIVILEGES';
    }

    private function normalizePhpVersion(string $version): string
    {
        if (\str_starts_with($version, 'ea-php')) {
            return $version;
        }

        return 'ea-php'.\str_replace('.', '', $version);
    }

    private function fileOrValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (\is_file($value)) {
            $contents = \file_get_contents($value);
            if (! \is_string($contents)) {
                throw new RuntimeException("Unable to read configured cPanel SSL file: {$value}");
            }

            return $contents;
        }

        return $value;
    }

    private function isAlreadyExists(string $message): bool
    {
        $message = \strtolower($message);

        return \str_contains($message, 'already exists')
            || \str_contains($message, 'already configured');
    }

    private function isUnavailableFeature(string $message): bool
    {
        $message = \strtolower($message);

        return \str_contains($message, 'feature')
            || \str_contains($message, 'module')
            || \str_contains($message, 'not enabled')
            || \str_contains($message, 'not found')
            || \str_contains($message, 'unknown app');
    }

    private function interpolate(string $value, object $project, object $profile): string
    {
        $value = \str_replace('${PROJECT_NAME}', $this->projectName($project), $value);
        $value = \str_replace('${PROFILE}', $this->profileName($profile), $value);

        return \preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)\}/',
            static function (array $matches): string {
                $environment = \getenv($matches[1]);

                return $environment === false ? $matches[0] : $environment;
            },
            $value,
        ) ?? $value;
    }

    private function interpolateOperationValue(mixed $value, array $context): mixed
    {
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->interpolateOperationValue($item, $context);
            }

            return $value;
        }

        if (! \is_string($value)) {
            return $value;
        }

        foreach ($context as $key => $replacement) {
            if (\is_scalar($replacement)) {
                $value = \str_replace('${'.\strtoupper($key).'}', (string) $replacement, $value);
            }
        }

        return \preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)\}/',
            static function (array $matches): string {
                $environment = \getenv($matches[1]);

                return $environment === false ? $matches[0] : $environment;
            },
            $value,
        ) ?? $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function cpanelOptions(object $profile): array
    {
        $options = $this->profileValue($profile, 'cpanel', []);

        return \is_array($options) ? $options : [];
    }

    private function stringCpanelOption(object $profile, string $key, string $default = ''): string
    {
        return $this->arrayString($this->cpanelOptions($profile), $key, $default);
    }

    private function boolCpanelOption(object $profile, string $key, bool $default): bool
    {
        $options = $this->cpanelOptions($profile);

        return isset($options[$key]) ? (bool) $options[$key] : $default;
    }

    private function intCpanelOption(object $profile, string $key, int $default): int
    {
        $value = $this->cpanelOptions($profile)[$key] ?? null;

        return \is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $database
     */
    private function boolDatabaseOption(array $database, string $key, bool $default): bool
    {
        return isset($database[$key]) ? (bool) $database[$key] : $default;
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;

        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * @param array<string, mixed> $array
     */
    private function arrayString(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    private function scalarString(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    private function projectName(object $project): string
    {
        return $this->projectMethodString($project, 'name', 'unknown');
    }

    private function projectPath(object $project): string
    {
        return $this->projectMethodString($project, 'path');
    }

    private function projectWebDirectory(object $project): string
    {
        return $this->projectMethodString($project, 'webDirectory', '/public');
    }

    private function profileName(object $profile): string
    {
        return $this->objectMethodString($profile, 'name', 'unknown');
    }

    private function profileBranch(object $profile): string
    {
        return $this->objectMethodString($profile, 'branch', 'main');
    }

    private function repositoryUrl(object $project): string
    {
        $repository = $this->projectMethodValue($project, 'repository', []);
        if (! \is_array($repository)) {
            return '';
        }

        return $this->arrayString($repository, 'url');
    }

    /**
     * @return array<int, string>
     */
    private function profileAliases(object $profile): array
    {
        if (\method_exists($profile, 'aliases')) {
            $aliases = $profile->aliases();

            return \is_array($aliases) ? \array_values(\array_filter($aliases, '\is_string')) : [];
        }

        $aliases = $this->profileValue($profile, 'aliases', []);

        return \is_array($aliases) ? \array_values(\array_filter($aliases, '\is_string')) : [];
    }

    private function profileValue(object $profile, string $key, mixed $default = null): mixed
    {
        return \method_exists($profile, 'get') ? $profile->get($key, $default) : $default;
    }

    private function projectMethodString(object $object, string $method, string $default = ''): string
    {
        return $this->objectMethodString($object, $method, $default);
    }

    private function objectMethodString(object $object, string $method, string $default = ''): string
    {
        if (! \method_exists($object, $method)) {
            return $default;
        }

        $value = $object->{$method}();

        return \is_string($value) ? $value : $default;
    }

    private function objectMethodBool(object $object, string $method, bool $default): bool
    {
        if (! \method_exists($object, $method)) {
            return $default;
        }

        return (bool) $object->{$method}();
    }

    private function projectMethodValue(object $object, string $method, mixed $default = null): mixed
    {
        return \method_exists($object, $method) ? $object->{$method}() : $default;
    }

    private function slug(string $value): string
    {
        $slug = \strtolower($value);
        $slug = \preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return \trim($slug, '-') ?: 'unnamed';
    }
}
