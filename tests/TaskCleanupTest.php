<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function rollbackTaskFixture(array $config = []): array
{
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => json_encode($manifest)]), $client->success(['content' => "0\n"]),
    ];
    $client->responseQueues['uapi:Fileman:list_files'] = [
        $client->success([['file' => '20260727010101-deadbeef.tar.gz', 'type' => 'file']]),
        $client->success([]),
    ];
    $client->responses['uapi:Fileman:get_file_content'] = $client->success(['content' => "0\n"]);
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);
    return [$client, cpanelProviderWithExistingDomain($client, $config), new CpanelTestProject('.'),
        new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])];
}

function taskMarkerFileOperations(FakeCpanelApiClient $client): array
{
    return array_values(array_filter(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'),
        static fn (array $call): bool => str_starts_with($call['parameters']['sourcefiles'] ?? '', '.shipper-task-')));
}

test('failed task unscheduling fails rollback and preserves completion markers', function (): void {
    [$client, $provider, $project, $profile] = rollbackTaskFixture();
    $client->responses['api2:Cron:remove_line'] = ['success' => false, 'message' => 'Cron removal refused', 'data' => null, 'raw' => []];
    expect($provider->rollback($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Remove cPanel task schedule')
        ->and(taskMarkerFileOperations($client))->toBeEmpty();
});

test('failed marker deletion cannot be reported as completely successful rollback', function (): void {
    [$client, $provider, $project, $profile] = rollbackTaskFixture();
    $client->responses['api2:Fileman:fileop'] = ['success' => false, 'message' => 'Unlink refused', 'data' => null, 'raw' => []];
    expect($provider->rollback($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Remove completed cPanel task file');
});

test('acknowledged task removal precedes marker deletion', function (): void {
    [$client, $provider, $project, $profile] = rollbackTaskFixture();
    expect($provider->rollback($project, $profile))->toBeTrue();
    $remove = null;
    $unlinks = [];
    foreach ($client->calls as $index => $call) {
        if ($call['module'] === 'Cron' && $call['function'] === 'remove_line') {
            $remove = $index;
        }
        if ($call['module'] === 'Fileman' && $call['function'] === 'fileop' && $call['parameters']['op'] === 'unlink'
            && str_starts_with($call['parameters']['sourcefiles'] ?? '', '.shipper-task-')) {
            expect($remove)->not->toBeNull()->and($index)->toBeGreaterThan($remove);
            $unlinks[] = $index;
        }
    }
    $tasks = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line');
    expect($tasks)->not->toBeEmpty()
        ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line'))->toHaveCount(count($tasks))
        ->and($unlinks)->toHaveCount(2 * count($tasks));
});

test('the generated task wrapper does not rerun while its completion marker is retained', function (): void {
    $home = sys_get_temp_dir().'/shipper-task-guard-'.bin2hex(random_bytes(6));
    mkdir($home, 0700);
    $counter = $home.'/counter';
    $tar = $home.'/fixture-tar';
    file_put_contents($tar, "#!/bin/sh\nprintf x >> ".escapeshellarg($counter)."\n");
    chmod($tar, 0700);
    try {
        [$client, $provider, $project] = rollbackTaskFixture(['home_directory' => $home]);
        $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app', 'cpanel' => ['tar_path' => $tar]]);
        $client->responses['api2:Cron:remove_line'] = ['success' => false, 'message' => 'Cron removal refused', 'data' => null, 'raw' => []];
        $client->responseQueues['uapi:Fileman:get_file_content'] = [$client->success(['content' => "0\n"])];
        $run = new ReflectionMethod($provider, 'runOneTimeCommand');
        expect(fn () => $run->invoke($provider, 'fixture command', escapeshellarg($tar), $profile))
            ->toThrow(RuntimeException::class, 'Remove cPanel task schedule');
        $command = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0]['parameters']['command'];
        $output = [];
        exec($command, $output, $firstStatus);
        exec($command, $output, $secondStatus);
        expect($firstStatus)->toBe(0)->and($secondStatus)->toBe(0)
            ->and(file_get_contents($counter))->toBe('x')
            ->and(taskMarkerFileOperations($client))->toBeEmpty();
    } finally {
        foreach (array_merge(glob($home.'/*'), glob($home.'/.*')) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($home);
    }
});

test('a cancellation marker prevents a delayed cron task from starting', function (): void {
    $home = sys_get_temp_dir().'/shipper-task-cancel-'.bin2hex(random_bytes(6));
    mkdir($home, 0700);
    $counter = $home.'/counter';
    $statusFile = $home.'/.shipper-task-timeout.status';
    $logFile = $home.'/.shipper-task-timeout.log';
    $lockDirectory = $home.'/.shipper-task-timeout.lock';
    $command = "printf x >> ".escapeshellarg($counter);

    try {
        [$client, $provider] = rollbackTaskFixture(['home_directory' => $home]);
        $cancel = new ReflectionMethod($provider, 'markOneTimeCommandCancelled');
        $cancel->invoke($provider, $statusFile);
        $marker = $client->uploadedFiles[basename($statusFile)] ?? null;
        expect($marker)->toBe("125\n");
        file_put_contents($statusFile, $marker);

        $build = new ReflectionMethod($provider, 'oneTimeCommandScript');
        $script = $build->invoke($provider, $command, $statusFile, $logFile, $lockDirectory);
        exec($script, $output, $firstStatus);
        exec($script, $output, $secondStatus);

        expect($firstStatus)->toBe(0)->and($secondStatus)->toBe(0)
            ->and(file_exists($counter))->toBeFalse();
    } finally {
        foreach (array_merge(glob($home.'/*'), glob($home.'/.*')) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($home);
    }
});

test('a missing add-line key writes a cancellation marker and reconciles the scheduled task', function (): void {
    $home = sys_get_temp_dir().'/shipper-task-untracked-'.bin2hex(random_bytes(6));
    mkdir($home, 0700);
    $statusFile = $home.'/.shipper-task-random.status';

    try {
        [$client, $provider] = rollbackTaskFixture(['home_directory' => $home]);
        $client->responses['api2:Cron:listcron'] = $client->success([[
            'command' => '/bin/sh -c test -f '.escapeshellarg($statusFile),
            'linekey' => 113,
        ]]);
        $cancel = new ReflectionMethod($provider, 'cancelUntrackedOneTimeCommand');
        $cancel->invoke($provider, $statusFile, 'fixture command');

        expect($client->uploadedFiles[basename($statusFile)] ?? null)->toBe("125\n")
            ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line'))->toHaveCount(1)
            ->and(cpanelProviderCalls($client, 'api2', 'Cron', 'remove_line')[0]['parameters']['linekey'])->toBe(113);
    } finally {
        foreach (array_merge(glob($home.'/*'), glob($home.'/.*')) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($home);
    }
});

test('a missing add-line key stops polling and leaves a cancellation marker', function (): void {
    $home = sys_get_temp_dir().'/shipper-task-no-key-'.bin2hex(random_bytes(6));
    mkdir($home, 0700);

    try {
        [$client, $provider, , $profile] = rollbackTaskFixture(['home_directory' => $home]);
        $client->responses['api2:Cron:add_line'] = $client->success([]);
        $run = new ReflectionMethod($provider, 'runOneTimeCommand');
        expect(fn () => $run->invoke($provider, 'fixture command', 'true', $profile))
            ->toThrow(RuntimeException::class, 'did not return a line key');
        expect(cpanelProviderCalls($client, 'api2', 'Cron', 'listcron'))->toHaveCount(1)
            ->and(array_values($client->uploadedFiles))->toContain("125\n");
    } finally {
        foreach (array_merge(glob($home.'/*'), glob($home.'/.*')) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($home);
    }
});
