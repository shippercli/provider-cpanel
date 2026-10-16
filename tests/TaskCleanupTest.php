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
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);
    return [$client, cpanelProviderWithExistingDomain($client, $config), new CpanelTestProject('.'),
        new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app'])];
}

test('failed task unscheduling fails rollback and preserves completion markers', function (): void {
    [$client, $provider, $project, $profile] = rollbackTaskFixture();
    $client->responses['api2:Cron:remove_line'] = ['success' => false, 'message' => 'Cron removal refused', 'data' => null, 'raw' => []];
    expect($provider->rollback($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Remove cPanel task schedule')
        ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty();
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
        if ($call['module'] === 'Fileman' && $call['function'] === 'fileop' && $call['parameters']['op'] === 'unlink') {
            $unlinks[] = $index;
        }
    }
    expect($remove)->not->toBeNull()->and($unlinks)->toHaveCount(2);
    foreach ($unlinks as $index) {
        expect($index)->toBeGreaterThan($remove);
    }
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
        expect($provider->rollback($project, $profile))->toBeFalse();
        $command = cpanelProviderCalls($client, 'api2', 'Cron', 'add_line')[0]['parameters']['command'];
        $output = [];
        exec($command, $output, $firstStatus);
        exec($command, $output, $secondStatus);
        expect($firstStatus)->toBe(0)->and($secondStatus)->toBe(0)
            ->and(file_get_contents($counter))->toBe('x')
            ->and(cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop'))->toBeEmpty();
    } finally {
        foreach (array_merge(glob($home.'/*'), glob($home.'/.*')) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($home);
    }
});
