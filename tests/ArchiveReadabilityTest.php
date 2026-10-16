<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function archiveReadabilityFixture(int $status = 0, array $config = []): array
{
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => json_encode($manifest)]),
        $client->success(['content' => "$status\n"]),
        $client->success(['content' => $status === 0 ? "0\n" : 'Unreadable archive']),
    ];
    $client->responseQueues['uapi:Fileman:list_files'] = [
        $client->success([['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'file']]),
        $client->success([['file' => 'index.html', 'type' => 'file']]),
    ];
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);
    return [$client, cpanelProviderWithExistingDomain($client, $config), new CpanelTestProject('.'),
        new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])];
}

test('unreadable rollback archives fail before deployment cleanup', function (): void {
    [$client, $provider, $project, $profile] = archiveReadabilityFixture(2);
    expect($provider->rollback($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Inspect cPanel release')
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty();
    $tasks = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line');
    expect($tasks)->toHaveCount(1)->and($tasks[0]['parameters']['command'])->toContain(' -tzf ');
});

test('archive inspection completes before cleanup and restore scheduling', function (): void {
    [$client, $provider, $project, $profile] = archiveReadabilityFixture();
    expect($provider->rollback($project, $profile))->toBeTrue();
    $tasks = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line');
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]['parameters']['command'])->toContain(' -tzf ')
        ->and($tasks[1]['parameters']['command'])->toContain(' -xzf ');
    $scheduled = [];
    $removed = [];
    $trash = null;
    foreach ($client->calls as $index => $call) {
        if ($call['module'] === 'Cron' && $call['function'] === 'add_line') $scheduled[] = $index;
        if ($call['module'] === 'Cron' && $call['function'] === 'remove_line') $removed[] = $index;
        if ($call['module'] === 'Fileman' && $call['function'] === 'fileop' && $call['parameters']['op'] === 'trash') $trash = $index;
    }
    expect($trash)->toBeGreaterThan($scheduled[0])->and($trash)->toBeGreaterThan($removed[0])->and($trash)->toBeLessThan($scheduled[1]);
});

test('generated inspection checks local tar archives without extracting content', function (string $kind): void {
    $home = sys_get_temp_dir().'/shipper archive read-'.bin2hex(random_bytes(6));
    mkdir($home.'/.shipper/releases/sample/production', 0700, true);
    mkdir($home.'/source');
    mkdir($home.'/app');
    file_put_contents($home.'/source/index.html', 'old release');
    file_put_contents($home.'/app/index.html', 'current deployment');
    $archive = $home.'/.shipper/releases/sample/production/20260727010101-deadbeef.tar.gz';
    try {
        $output = [];
        exec('/usr/bin/tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($home.'/source').' .', $output, $created);
        expect($created)->toBe(0);
        if ($kind === 'corrupt') file_put_contents($archive, 'not a tar archive');
        if ($kind === 'missing') unlink($archive);
        [$client, $provider, $project, $profile] = archiveReadabilityFixture(0, ['home_directory' => $home]);
        expect($provider->rollback($project, $profile))->toBeTrue();
        $command = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0]['parameters']['command'];
        exec($command, $output, $wrapperStatus);
        $markers = glob($home.'/.shipper-task-*.status');
        expect($markers)->toHaveCount(1);
        $actualStatus = (int) trim(file_get_contents($markers[0]));
        expect($actualStatus === 0)->toBe($kind === 'valid')
            ->and(file_get_contents($home.'/app/index.html'))->toBe('current deployment');
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($home);
    }
})->with(['valid', 'corrupt', 'missing']);
