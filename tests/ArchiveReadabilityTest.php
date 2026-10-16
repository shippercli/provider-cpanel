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

test('failed staged restore never invokes API deployment cleanup', function (): void {
    [$client, $provider, $project, $profile] = archiveReadabilityFixture(2);
    expect($provider->rollback($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Staged cPanel restore failed');
    $tasks = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line');
    expect($tasks)->toHaveCount(1)->and($tasks[0]['parameters']['command'])->toContain('--restore');
    foreach (cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop') as $operation) {
        expect($operation['parameters']['op'])->not->toBe('trash');
    }
});

test('rollback schedules one scoped restore and removes its uploaded helper', function (): void {
    [$client, $provider, $project, $profile] = archiveReadabilityFixture();
    expect($provider->rollback($project, $profile))->toBeTrue();
    $tasks = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line');
    expect($tasks)->toHaveCount(1)->and($tasks[0]['parameters']['command'])->toContain('--restore')->not->toContain(' -xzf ');
    $helpers = array_filter(array_keys($client->uploadedFiles), static fn (string $name): bool => str_ends_with($name, '.py'));
    expect($helpers)->toHaveCount(1);
    $helper = array_values($helpers)[0];
    $removed = array_filter(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'),
        static fn (array $call): bool => $call['parameters']['op'] === 'unlink' && $call['parameters']['sourcefiles'] === $helper);
    expect($removed)->toHaveCount(1);
});

test('generated restore command validates and stages real local archives', function (string $kind): void {
    $home = realpath(sys_get_temp_dir()).'/shipper archive read-'.bin2hex(random_bytes(6));
    mkdir($home.'/.shipper/releases/sample/production', 0700, true);
    mkdir($home.'/source');
    mkdir($home.'/app');
    file_put_contents($home.'/source/index.html', 'old release');
    file_put_contents($home.'/app/index.html', 'current deployment');
    $manifest = ['project' => 'sample', 'profile' => 'production', 'domain' => ['domain' => 'app.example.com'], 'deploy_path' => '/app'];
    file_put_contents($home.'/app/.shipper-manifest.json', json_encode($manifest));
    if ($kind === 'foreign') $manifest['project'] = 'other';
    file_put_contents($home.'/source/.shipper-manifest.json', json_encode($manifest));
    if ($kind === 'escaping-link') symlink($home.'/app/index.html', $home.'/source/external');
    $archive = $home.'/.shipper/releases/sample/production/20260727010101-deadbeef.tar.gz';
    try {
        $output = [];
        exec('/usr/bin/tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($home.'/source').' .', $output, $created);
        expect($created)->toBe(0);
        if ($kind === 'corrupt') file_put_contents($archive, 'not a tar archive');
        if ($kind === 'missing') unlink($archive);
        [$client, $provider, $project, $profile] = archiveReadabilityFixture(0, ['home_directory' => $home]);
        $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app',
            'cpanel' => ['rollback_python_path' => $kind === 'missing-python' ? '/nonexistent/shipper-python' : '/usr/bin/python3']]);
        expect($provider->rollback($project, $profile))->toBeTrue();
        foreach ($client->uploadedPaths as $remote => $contents) {
            $path = '/'.ltrim($remote, '/');
            file_put_contents($path, $kind === 'tampered-helper' ? $contents.'# altered' : $contents);
        }
        $command = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0]['parameters']['command'];
        exec($command, $output, $wrapperStatus);
        $markers = glob($home.'/.shipper-task-*.status');
        expect($markers)->toHaveCount(1);
        $actualStatus = (int) trim(file_get_contents($markers[0]));
        expect($actualStatus === 0)->toBe($kind === 'valid')
            ->and(file_get_contents($home.'/app/index.html'))->toBe($kind === 'valid' ? 'old release' : 'current deployment');
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($home);
    }
})->with(['valid', 'corrupt', 'missing', 'escaping-link', 'foreign', 'tampered-helper', 'missing-python']);
