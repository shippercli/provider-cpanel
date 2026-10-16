<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProfile;
use ShipperCli\ProviderCpanel\Tests\Fixtures\CpanelTestProject;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

test('rollback retention preserves its selected archive and freshly created safety backup', function (string $selected): void {
    $client = new FakeCpanelApiClient;
    $manifest = ['project' => 'sample', 'profile' => 'production', 'deploy_path' => '/app', 'domain' => ['domain' => 'app.example.com']];
    $client->responseQueues['uapi:Fileman:get_file_content'] = [
        $client->success(['content' => json_encode($manifest)]),
        $client->success(['content' => "0\n"]), $client->success(['content' => "0\n"]), $client->success(['content' => "0\n"]),
    ];
    $client->responses['api2:Cron:add_line'] = $client->success([['linekey' => '92']]);
    $backup = null;
    $discard = '19990101010101-cafebabe.tar.gz';
    $client->uapiResponseFactories['Fileman:list_files'] = function () use ($client, $selected, $discard, &$backup): array {
        $latest = $client->calls[array_key_last($client->calls)];
        if (($latest['parameters']['dir'] ?? null) === 'app') {
            return $client->success(['dirs' => [], 'files' => [['file' => 'index.html', 'type' => 'file']]]);
        }
        foreach (cpanelProviderCalls($client, 'api2', 'Cron', 'add_line') as $task) {
            $command = $task['parameters']['command'];
            if (str_contains($command, ' -czf ') && preg_match('/[0-9]{14}-[a-f0-9]{8}\\.tar\\.gz/', $command, $matches)) {
                $backup = $matches[0];
            }
        }
        $files = [$selected];
        if ($backup !== null) $files = [$selected, $backup, $discard];
        return $client->success(['dirs' => [], 'files' => array_map(static fn (string $name): array => ['file' => $name, 'type' => 'file'], $files)]);
    };
    $provider = cpanelProviderWithExistingDomain($client);
    $profile = new CpanelTestProfile(['domain' => 'app.example.com', 'deploy_path' => '/app',
        'cpanel' => ['backup_before_rollback' => true, 'release_retention' => 1]]);
    expect($provider->rollback(new CpanelTestProject('.'), $profile, $selected))->toBeTrue()
        ->and($backup)->not->toBeNull();
    $pruned = [];
    foreach (cpanelProviderCalls($client, 'api2', 'Fileman', 'fileop') as $operation) {
        $params = $operation['parameters'];
        if ($params['op'] === 'unlink' && str_starts_with($params['sourcefiles'], '.shipper/releases/')) {
            $pruned[] = basename($params['sourcefiles']);
        }
    }
    expect($pruned)->toBe([$discard]);
})->with(['20200101010101-deadbeef.tar.gz', '99990101010101-deadbeef.tar.gz']);
