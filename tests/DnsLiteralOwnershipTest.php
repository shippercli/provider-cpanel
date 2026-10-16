<?php

declare(strict_types=1);

use ShipperCli\ProviderCpanel\CpanelAccountResourceManager;
use ShipperCli\ProviderCpanel\Tests\Fixtures\FakeCpanelApiClient;

function literalDnsZone(string $text): array
{
    return [
        ['type' => 'record', 'line_index' => 0, 'record_type' => 'SOA', 'dname_b64' => base64_encode('example.com.'), 'data_b64' => array_map('base64_encode', ['ns.example.com.', 'hostmaster.example.com.', '123']), 'ttl' => 300],
        ['type' => 'record', 'line_index' => 1, 'record_type' => 'TXT', 'dname_b64' => base64_encode('_shipper.example.com.'), 'data_b64' => [base64_encode($text)], 'ttl' => 300],
    ];
}

function literalDnsConfiguration(string $text): array
{
    return ['dns_records' => [['zone' => 'example.com', 'name' => '_shipper.example.com', 'type' => 'TXT', 'data' => [$text], 'ttl' => 300]],
        'email_accounts' => [], 'email_forwarders' => [], 'ftp_accounts' => []];
}

test('TXT whitespace is part of record identity', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DNS:parse_zone'] = $client->success(literalDnsZone('verification'));
    $state = (new CpanelAccountResourceManager($client))->reconcile(literalDnsConfiguration(' verification '), []);
    expect(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toHaveCount(1)
        ->and($state['dns_records'][0]['created'])->toBeTrue();
});

test('legacy owned DNS hashes require review before mutation', function (string $operation): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DNS:parse_zone'] = $client->success(literalDnsZone('verification'));
    $previous = ['dns_records' => [['zone' => 'example.com', 'name' => '_shipper.example.com', 'type' => 'TXT',
        'data_hash' => hash('sha256', json_encode(['verification'])), 'created' => true]]];
    $manager = new CpanelAccountResourceManager($client);
    expect(fn () => $operation === 'destroy' ? $manager->destroy($previous) : $manager->reconcile(literalDnsConfiguration(' verification '), $previous))
        ->toThrow(RuntimeException::class, 'DNS ownership');
    expect($client->calls)->toBeEmpty();
})->with(['apply', 'destroy']);

test('versioned ownership deletes the exact TXT value and preserves whitespace variants', function (): void {
    $client = new FakeCpanelApiClient;
    $client->responses['uapi:DNS:parse_zone'] = $client->success(literalDnsZone('verification'));
    $manager = new CpanelAccountResourceManager($client);
    $state = $manager->reconcile(literalDnsConfiguration(' verification '), []);
    expect($state['dns_records'][0]['data_hash_version'])->toBe(2);
    $zone = literalDnsZone('verification');
    $exact = literalDnsZone(' verification ')[1];
    $exact['line_index'] = 2;
    $zone[] = $exact;
    $client->responses['uapi:DNS:parse_zone'] = $client->success($zone);
    $client->calls = [];
    $manager->destroy($state);
    $edits = cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone');
    expect($edits)->toHaveCount(1)->and($edits[0]['parameters']['remove'])->toBe([2]);
});

test('duplicate exact DNS records require ownership review', function (): void {
    $client = new FakeCpanelApiClient;
    $zone = literalDnsZone('verification');
    $copy = $zone[1];
    $copy['line_index'] = 2;
    $zone[] = $copy;
    $client->responses['uapi:DNS:parse_zone'] = $client->success($zone);
    $manager = new CpanelAccountResourceManager($client);
    expect(fn () => $manager->reconcile(literalDnsConfiguration('verification'), []))->toThrow(RuntimeException::class, 'Ambiguous');
    expect(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
});

test('unrelated binary TXT data remains readable without losing bytes', function (): void {
    $client = new FakeCpanelApiClient;
    $zone = literalDnsZone('verification');
    $zone[] = ['type' => 'record', 'line_index' => 2, 'record_type' => 'TXT', 'dname_b64' => base64_encode('_other.example.com.'), 'data_b64' => [base64_encode("\xff\x00")], 'ttl' => 300];
    $client->responses['uapi:DNS:parse_zone'] = $client->success($zone);
    $state = (new CpanelAccountResourceManager($client))->reconcile(literalDnsConfiguration('verification'), []);
    expect($state['dns_records'][0]['created'])->toBeFalse()
        ->and(cpanelProviderCalls($client, 'uapi', 'DNS', 'mass_edit_zone'))->toBeEmpty();
});
