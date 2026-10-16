<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderCpanel\Api\CpanelApiClient;

test('upload success requires complete matching acceptance reports', function (mixed $data): void {
    $history = [];
    $client = cpanelTestClient([new Response(200, [], json_encode(['status' => 1, 'data' => $data]))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $file = tempnam(sys_get_temp_dir(), 'shipper-upload-report-');
    file_put_contents($file, 'fixture');
    try {
        $result = $api->uploadFile('/app', $file, 'fixture.txt');
        expect($result['success'])->toBeFalse()->and($result['raw'])->toBeEmpty();
    } finally {
        unlink($file);
    }
})->with([
    [null], [[]], [['failed' => 0, 'succeeded' => 1, 'uploads' => []]],
    [['failed' => 'invalid', 'succeeded' => 1, 'uploads' => [['file' => 'fixture.txt', 'status' => 1, 'size' => 7]]]],
    [['failed' => 0, 'succeeded' => 0, 'uploads' => [['file' => 'fixture.txt', 'status' => 1, 'size' => 7]]]],
    [['failed' => 0, 'succeeded' => 1, 'uploads' => [['file' => 'wrong.txt', 'status' => 1, 'size' => 7]]]],
    [['failed' => 0, 'succeeded' => 1, 'uploads' => [['file' => 'fixture.txt', 'size' => 7]]]],
    [['failed' => 0, 'succeeded' => 1, 'uploads' => [['file' => 'fixture.txt', 'status' => 1, 'size' => 6]]]],
    [['failed' => 0, 'succeeded' => 1, 'uploads' => [['file' => 'fixture.txt', 'status' => 1, 'size' => null]]]],
    [['failed' => 0, 'succeeded' => 1, 'uploads' => [['file' => 'fixture.txt', 'status' => 1, 'size' => 7], ['file' => 'fixture.txt', 'status' => 1, 'size' => 7]]]],
]);

test('complete upload reports accept stored empty files and canonical strings', function (bool $strings): void {
    $history = [];
    $data = ['failed' => $strings ? '0' : 0, 'succeeded' => $strings ? '1' : 1,
        'uploads' => [['file' => 'empty.txt', 'status' => $strings ? '1' : 1, 'size' => $strings ? '0' : 0]]];
    $client = cpanelTestClient([new Response(200, [], json_encode(['status' => 1, 'data' => $data]))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $file = tempnam(sys_get_temp_dir(), 'shipper-empty-upload-');
    try {
        expect($api->uploadFile('/app', $file, 'empty.txt')['success'])->toBeTrue();
    } finally {
        unlink($file);
    }
})->with([true, false]);

test('multipart acceptance reports cover every filename exactly once regardless of order', function (bool $duplicate): void {
    $history = [];
    $first = ['file' => 'first.txt', 'status' => 1, 'size' => 5];
    $second = ['file' => 'second.txt', 'status' => 1, 'size' => 6];
    $data = ['failed' => 0, 'succeeded' => 2, 'uploads' => $duplicate ? [$first, $first] : [$second, $first]];
    $client = cpanelTestClient([new Response(200, [], json_encode(['status' => 1, 'data' => $data]))], $history);
    $api = new CpanelApiClient(['host' => 'cpanel.example.com', 'username' => 'shipper', 'api_token' => 'synthetic-token'], $client);
    $firstPath = tempnam(sys_get_temp_dir(), 'shipper-first-upload-');
    $secondPath = tempnam(sys_get_temp_dir(), 'shipper-second-upload-');
    file_put_contents($firstPath, 'first');
    file_put_contents($secondPath, 'second');
    try {
        expect($api->uploadFiles('/app', ['first.txt' => $firstPath, 'second.txt' => $secondPath])['success'])->toBe(! $duplicate);
    } finally {
        unlink($firstPath);
        unlink($secondPath);
    }
})->with([true, false]);
