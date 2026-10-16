<?php

declare(strict_types=1);

$source = dirname(__DIR__, 2);
$package = $argv[1] ?? throw new RuntimeException('Package directory is required');
require $source.'/vendor/autoload.php';
require $package.'/src/CpanelProvider.php';

use ShipperCli\ProviderCpanel\CpanelProvider;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

$client = new FakeCpanelApiClient;
$client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '17']]);
$client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => "0\n"]);
$provider = new CpanelProvider(['home_directory' => '/home/fixture'], $client);
$file = (new ReflectionClass($provider))->getFileName();
if ($file !== realpath($package.'/src/CpanelProvider.php')) {
    throw new RuntimeException('Provider did not load from the extracted package');
}
$restore = new ReflectionMethod($provider, 'restoreStagedRelease');
$restore->invoke($provider, new CpanelTestProject('.'),
    new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app']),
    ['id' => '20260727010101-deadbeef', 'filename' => '20260727010101-deadbeef.tar.gz',
        'path' => '/home/fixture/releases/20260727010101-deadbeef.tar.gz'], '/app');
$helper = file_get_contents($package.'/resources/validate-release.py');
if (! is_string($helper) || count($client->uploadedFiles) !== 1 || array_values($client->uploadedFiles)[0] !== $helper) {
    throw new RuntimeException('Packaged helper was not uploaded intact');
}
$tasks = array_values(array_filter($client->calls,
    static fn (array $call): bool => $call['module'] === 'Cron' && $call['function'] === 'add_line'));
$command = $tasks[0]['parameters']['command'] ?? '';
if (count($tasks) !== 1 || ! str_contains($command, hash('sha256', $helper)) || ! str_contains($command, '--restore')) {
    throw new RuntimeException('Packaged provider did not schedule its verified restore helper');
}
echo "Extracted provider schedules its packaged restore helper\n";
