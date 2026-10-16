<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\CpanelAccountResourceManager;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function dnsInventorySoa(): array
{
    return ['type' => 'record', 'line_index' => 0, 'record_type' => 'SOA',
        'dname_b64' => base64_encode('example.com.'), 'ttl' => 86400,
        'data_b64' => array_map('base64_encode', ['ns.example.com.', 'hostmaster.example.com.', '2026072801', '3600', '1800', '1209600', '86400'])];
}

function dnsInventoryConfigured(): array
{
    return ['dns_records' => [['zone' => 'example.com', 'name' => '_shipper.example.com', 'type' => 'TXT', 'data' => ['verification'], 'ttl' => 300]],
        'email_accounts' => [], 'email_forwarders' => [], 'ftp_accounts' => []];
}

test('malformed DNS rows cannot be skipped to authorize record creation', function (array $change): void {
    $client = new FakeCpanelApiClient;
    $row = ['type' => 'record', 'line_index' => 1, 'record_type' => 'TXT', 'dname_b64' => base64_encode('_shipper.example.com.'), 'data_b64' => [base64_encode('verification')], 'ttl' => 300];
    $row = array_replace($row, $change);
    $client->responses['uapi:DNS:parse_zone'] = $client->success([dnsInventorySoa(), $row]);
    $manager = new CpanelAccountResourceManager($client);
    expect(fn () => $manager->reconcile(dnsInventoryConfigured(), []))->toThrow(RuntimeException::class);
    expect(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
})->with([
    [['dname_b64' => '%%%']], [[ 'dname_b64' => base64_encode("bad name") ]], [[ 'dname_b64' => base64_encode("bad\x00name") ]], [[ 'dname_b64' => base64_encode("\xff") ]], [['data_b64' => ['%%%']]], [['data_b64' => null]],
    [['line_index' => null]], [['line_index' => -1]], [['line_index' => '1e2']],
    [['record_type' => null]], [['type' => 'unknown']], [['ttl' => null]], [['line_index' => 0]],
]);

test('documented DNS rows and canonical numeric strings preserve existing records', function (): void {
    $client = new FakeCpanelApiClient;
    $soa = dnsInventorySoa();
    $soa['line_index'] = '0';
    $soa['dname_b64'] = base64_encode('@');
    $client->responses['uapi:DNS:parse_zone'] = $client->success([
        ['type' => 'comment', 'line_index' => 1, 'text_b64' => base64_encode('; fixture')],
        ['type' => 'control', 'line_index' => 2, 'text_b64' => base64_encode('$TTL 300')],
        $soa,
        ['type' => 'record', 'line_index' => '3', 'record_type' => 'TXT', 'dname_b64' => base64_encode('_shipper'), 'data_b64' => [base64_encode('verification')], 'ttl' => '300'],
    ]);
    $state = (new CpanelAccountResourceManager($client))->reconcile(dnsInventoryConfigured(), []);
    expect($state['dns_records'][0]['created'])->toBeFalse()
        ->and(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
});

test('invalid SOA serials and duplicate SOAs stop DNS mutation', function (string $serial, bool $duplicate): void {
    $client = new FakeCpanelApiClient;
    $soa = dnsInventorySoa();
    $soa['data_b64'][2] = base64_encode($serial);
    $rows = [$soa];
    if ($duplicate) {
        $soa['line_index'] = 1;
        $rows[] = $soa;
    }
    $client->responses['uapi:DNS:parse_zone'] = $client->success($rows);
    $manager = new CpanelAccountResourceManager($client);
    expect(fn () => $manager->reconcile(dnsInventoryConfigured(), []))->toThrow(RuntimeException::class);
    expect(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
})->with([['-1', false], ['1e2', false], ['4294967296', false], ['2026072801', true]]);

test('malformed DNS inventories cannot make destroy silently succeed', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DNS:parse_zone'] = $client->success([dnsInventorySoa(), ['type' => 'record', 'record_type' => 'TXT']]);
    $manager = new CpanelAccountResourceManager($client);
    $manifest = ['dns_records' => [[
        'zone' => 'example.com', 'name' => '_shipper.example.com', 'type' => 'TXT',
        'data_hash' => hash('sha256', json_encode(['verification'])), 'created' => true,
    ]]];
    expect(fn () => $manager->destroy($manifest))->toThrow(RuntimeException::class);
    expect(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
});
