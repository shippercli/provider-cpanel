<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel;

use RuntimeException;
use ShipperCli\ProviderCpanel\Api\CpanelApiClientInterface;

final class CpanelAccountResourceManager
{
    public function __construct(
        private readonly CpanelApiClientInterface $api,
    ) {}

    /**
     * @param array{
     *     dns_records: array<int, array<string, mixed>>,
     *     email_accounts: array<int, array<string, mixed>>,
     *     email_forwarders: array<int, array<string, mixed>>,
     *     ftp_accounts: array<int, array<string, mixed>>
     * } $configured
     * @param array<string, mixed> $previous
     *
     * @return array{
     *     dns_records: array<int, array<string, mixed>>,
     *     email_accounts: array<int, array<string, mixed>>,
     *     email_forwarders: array<int, array<string, mixed>>,
     *     ftp_accounts: array<int, array<string, mixed>>
     * }
     */
    public function reconcile(array $configured, array $previous): array
    {
        return [
            'dns_records' => $this->reconcileDnsRecords(
                $configured['dns_records'],
                $this->manifestResources($previous, 'dns_records'),
            ),
            'email_accounts' => $this->reconcileEmailAccounts(
                $configured['email_accounts'],
                $this->manifestResources($previous, 'email_accounts'),
            ),
            'email_forwarders' => $this->reconcileEmailForwarders(
                $configured['email_forwarders'],
                $this->manifestResources($previous, 'email_forwarders'),
            ),
            'ftp_accounts' => $this->reconcileFtpAccounts(
                $configured['ftp_accounts'],
                $this->manifestResources($previous, 'ftp_accounts'),
            ),
        ];
    }

    /** @param array<string, mixed> $manifest */
    public function destroy(array $manifest): void
    {
        foreach ($this->manifestResources($manifest, 'email_forwarders') as $forwarder) {
            if ((bool) ($forwarder['created'] ?? false)) {
                $this->deleteEmailForwarder($forwarder);
            }
        }

        foreach ($this->manifestResources($manifest, 'email_accounts') as $account) {
            if ((bool) ($account['created'] ?? false)) {
                $this->deleteEmailAccount($account);
            }
        }

        foreach ($this->manifestResources($manifest, 'ftp_accounts') as $account) {
            if ((bool) ($account['created'] ?? false)) {
                $this->deleteFtpAccount($account);
            }
        }

        foreach ($this->manifestResources($manifest, 'dns_records') as $record) {
            if ((bool) ($record['created'] ?? false)) {
                $this->deleteDnsRecord($record);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $configured
     * @param array<int, array<string, mixed>> $previous
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileDnsRecords(array $configured, array $previous): array
    {
        $state = [];

        foreach ($configured as $record) {
            $expected = $this->dnsState($record, false);
            $owned = $this->matchingResource(
                $previous,
                $expected,
                ['zone', 'name', 'type', 'data_hash'],
            );
            $zone = $this->parseDnsZone($expected['zone']);
            $existing = $this->matchingDnsRecord($zone['records'], $expected);
            $created = (bool) ($owned['created'] ?? false);

            if ($existing === null) {
                $this->editDnsZone($zone, [
                    'add' => [\json_encode([
                        'dname' => $expected['name'],
                        'ttl' => $expected['ttl'],
                        'record_type' => $expected['type'],
                        'data' => $record['data'],
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                ], "Create cPanel DNS record {$expected['name']} {$expected['type']}");
                $created = true;
            } elseif ((int) $existing['ttl'] !== $expected['ttl']) {
                if (! $created) {
                    throw new RuntimeException(
                        "Refusing to change the TTL of unowned cPanel DNS record {$expected['name']} {$expected['type']}",
                    );
                }

                $this->editDnsZone($zone, [
                    'edit' => [\json_encode([
                        'line_index' => $existing['line_index'],
                        'dname' => $expected['name'],
                        'ttl' => $expected['ttl'],
                        'record_type' => $expected['type'],
                        'data' => $record['data'],
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                ], "Update cPanel DNS record {$expected['name']} {$expected['type']}");
            }

            $expected['created'] = $created;
            $state[] = $expected;
        }

        foreach ($previous as $record) {
            if (! (bool) ($record['created'] ?? false)
                || $this->matchingResource($state, $record, ['zone', 'name', 'type', 'data_hash']) !== null) {
                continue;
            }

            $this->deleteDnsRecord($record);
        }

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $configured
     * @param array<int, array<string, mixed>> $previous
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileEmailAccounts(array $configured, array $previous): array
    {
        if ($configured === [] && $previous === []) {
            return [];
        }

        $existing = $this->required($this->api->uapi('Email', 'list_pops', [
            'skip_main' => 1,
        ]), 'List cPanel email accounts');
        $state = [];

        foreach ($configured as $account) {
            $address = $account['address'];
            $owned = $this->matchingResource($previous, $account, ['address']);
            $exists = $this->recordExists($existing, [
                'email' => $address,
            ]) || $this->recordExists($existing, [
                'login' => $address,
            ]);
            $created = (bool) ($owned['created'] ?? false);

            if (! $exists) {
                if (! $account['create']) {
                    throw new RuntimeException("Required cPanel email account does not exist: {$address}");
                }

                [$local, $domain] = $this->emailParts($address);
                $parameters = [
                    'email' => $local,
                    'domain' => $domain,
                    'send_welcome_email' => $account['send_welcome_email'] ? 1 : 0,
                ];
                if ($account['password_hash'] !== '') {
                    $parameters['password_hash'] = $account['password_hash'];
                } else {
                    $parameters['password'] = $account['password'];
                }
                if ($account['quota'] !== null) {
                    $parameters['quota'] = $account['quota'];
                }

                $this->required(
                    $this->api->uapi('Email', 'add_pop', $parameters),
                    "Create cPanel email account {$address}",
                );
                $created = true;
            } elseif ($created || $account['manage_existing']) {
                [$local, $domain] = $this->emailParts($address);
                if ($account['update_password'] && $account['password'] !== '') {
                    $this->required($this->api->uapi('Email', 'passwd_pop', [
                        'email' => $local,
                        'domain' => $domain,
                        'password' => $account['password'],
                    ]), "Update cPanel email account password {$address}");
                }
                if ($account['quota'] !== null) {
                    $this->required($this->api->uapi('Email', 'edit_pop_quota', [
                        'email' => $local,
                        'domain' => $domain,
                        'quota' => $account['quota'],
                    ]), "Update cPanel email account quota {$address}");
                }
            }

            $state[] = [
                'address' => $address,
                'created' => $created,
                'delete_data' => $account['delete_data'],
            ];
        }

        foreach ($previous as $account) {
            if (! (bool) ($account['created'] ?? false)
                || $this->matchingResource($state, $account, ['address']) !== null) {
                continue;
            }

            $this->deleteEmailAccount($account);
        }

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $configured
     * @param array<int, array<string, mixed>> $previous
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileEmailForwarders(array $configured, array $previous): array
    {
        if ($configured === [] && $previous === []) {
            return [];
        }

        $existing = $this->required(
            $this->api->uapi('Email', 'list_forwarders'),
            'List cPanel email forwarders',
        );
        $state = [];

        foreach ($configured as $forwarder) {
            $owned = $this->matchingResource($previous, $forwarder, ['address', 'forward_to']);
            $exists = $this->recordExists($existing, [
                'dest' => $forwarder['address'],
                'forward' => $forwarder['forward_to'],
            ]);
            $created = (bool) ($owned['created'] ?? false);

            if (! $exists) {
                [, $domain] = $this->emailParts($forwarder['address']);
                $this->required($this->api->uapi('Email', 'add_forwarder', [
                    'domain' => $domain,
                    'email' => $forwarder['address'],
                    'fwdopt' => 'fwd',
                    'fwdemail' => $forwarder['forward_to'],
                ]), "Create cPanel email forwarder {$forwarder['address']}");
                $created = true;
            }

            $state[] = [
                'address' => $forwarder['address'],
                'forward_to' => $forwarder['forward_to'],
                'created' => $created,
            ];
        }

        foreach ($previous as $forwarder) {
            if (! (bool) ($forwarder['created'] ?? false)
                || $this->matchingResource($state, $forwarder, ['address', 'forward_to']) !== null) {
                continue;
            }

            $this->deleteEmailForwarder($forwarder);
        }

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $configured
     * @param array<int, array<string, mixed>> $previous
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileFtpAccounts(array $configured, array $previous): array
    {
        if ($configured === [] && $previous === []) {
            return [];
        }

        $existing = $this->required($this->api->uapi('Ftp', 'list_ftp'), 'List cPanel FTP accounts');
        $state = [];

        foreach ($configured as $account) {
            $owned = $this->matchingResource($previous, $account, ['user', 'domain']);
            $exists = $this->recordExists($existing, [
                'user' => $account['user'],
            ]) || $this->recordExists($existing, [
                'user' => $account['user'].'@'.$account['domain'],
            ]);
            $created = (bool) ($owned['created'] ?? false);

            if (! $exists) {
                if (! $account['create']) {
                    throw new RuntimeException(
                        "Required cPanel FTP account does not exist: {$account['user']}@{$account['domain']}",
                    );
                }

                $parameters = [
                    'user' => $account['user'],
                    'domain' => $account['domain'],
                    'homedir' => $account['home_directory'],
                    'quota' => $account['quota'] ?? 0,
                ];
                if ($account['password_hash'] !== '') {
                    $parameters['pass_hash'] = $account['password_hash'];
                } else {
                    $parameters['pass'] = $account['password'];
                }
                $this->required(
                    $this->api->uapi('Ftp', 'add_ftp', $parameters),
                    "Create cPanel FTP account {$account['user']}@{$account['domain']}",
                );
                $created = true;
            } elseif ($created || $account['manage_existing']) {
                $identity = [
                    'user' => $account['user'],
                    'domain' => $account['domain'],
                ];
                if ($account['home_directory'] !== '') {
                    $this->required($this->api->uapi('Ftp', 'set_homedir', [
                        ...$identity,
                        'homedir' => $account['home_directory'],
                    ]), "Update cPanel FTP home directory {$account['user']}");
                }
                if ($account['update_password'] && $account['password'] !== '') {
                    $this->required($this->api->uapi('Ftp', 'passwd', [
                        ...$identity,
                        'pass' => $account['password'],
                    ]), "Update cPanel FTP password {$account['user']}");
                }
                if ($account['quota'] !== null) {
                    $this->required($this->api->uapi('Ftp', 'set_quota', [
                        ...$identity,
                        'quota' => $account['quota'],
                        'kill' => $account['quota'] === 0 ? 1 : 0,
                    ]), "Update cPanel FTP quota {$account['user']}");
                }
            }

            $state[] = [
                'user' => $account['user'],
                'domain' => $account['domain'],
                'home_directory' => $account['home_directory'],
                'created' => $created,
                'delete_home' => $account['delete_home'],
            ];
        }

        foreach ($previous as $account) {
            if (! (bool) ($account['created'] ?? false)
                || $this->matchingResource($state, $account, ['user', 'domain']) !== null) {
                continue;
            }

            $this->deleteFtpAccount($account);
        }

        return $state;
    }

    /** @param array<string, mixed> $record */
    private function deleteDnsRecord(array $record): void
    {
        foreach (['zone', 'name', 'type', 'data_hash'] as $key) {
            if (! \is_string($record[$key] ?? null) || $record[$key] === '') {
                return;
            }
        }

        $zone = $this->parseDnsZone($record['zone']);
        $existing = $this->matchingDnsRecord($zone['records'], $record);
        if ($existing === null) {
            return;
        }

        $this->editDnsZone($zone, [
            'remove' => [$existing['line_index']],
        ], "Delete Shipper-managed cPanel DNS record {$record['name']} {$record['type']}");
    }

    /** @param array<string, mixed> $account */
    private function deleteEmailAccount(array $account): void
    {
        $address = $account['address'] ?? null;
        if (! \is_string($address) || $address === '') {
            return;
        }

        $parameters = ['email' => $address];
        if (! (bool) ($account['delete_data'] ?? false)) {
            $parameters['flags'] = 'passwd';
        }
        $this->required(
            $this->api->uapi('Email', 'delete_pop', $parameters),
            "Delete Shipper-managed cPanel email account {$address}",
        );
    }

    /** @param array<string, mixed> $forwarder */
    private function deleteEmailForwarder(array $forwarder): void
    {
        $address = $forwarder['address'] ?? null;
        $destination = $forwarder['forward_to'] ?? null;
        if (! \is_string($address) || $address === ''
            || ! \is_string($destination) || $destination === '') {
            return;
        }

        $this->required($this->api->uapi('Email', 'delete_forwarder', [
            'address' => $address,
            'forwarder' => $destination,
        ]), "Delete Shipper-managed cPanel email forwarder {$address}");
    }

    /** @param array<string, mixed> $account */
    private function deleteFtpAccount(array $account): void
    {
        $user = $account['user'] ?? null;
        $domain = $account['domain'] ?? null;
        if (! \is_string($user) || $user === '' || ! \is_string($domain) || $domain === '') {
            return;
        }

        $this->required($this->api->uapi('Ftp', 'delete_ftp', [
            'user' => $user,
            'domain' => $domain,
            'destroy' => (bool) ($account['delete_home'] ?? false) ? 1 : 0,
        ]), "Delete Shipper-managed cPanel FTP account {$user}@{$domain}");
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{zone: string, name: string, type: string, data_hash: string, ttl: int, created: bool}
     */
    private function dnsState(array $record, bool $created): array
    {
        $zone = $this->normalizeDnsName($record['zone']);
        $name = $this->normalizeDnsName($record['name'], $zone);
        $type = \strtoupper($record['type']);

        return [
            'zone' => $zone,
            'name' => $name,
            'type' => $type,
            'data_hash' => $this->dnsDataHash($type, $record['data']),
            'ttl' => (int) $record['ttl'],
            'created' => $created,
        ];
    }

    /**
     * @return array{
     *     zone: string,
     *     serial: int,
     *     records: array<int, array{line_index: int, name: string, type: string, data_hash: string, ttl: int}>
     * }
     */
    private function parseDnsZone(string $zone): array
    {
        $zone = $this->normalizeDnsName($zone);
        $data = $this->required(
            $this->api->uapi('DNS', 'parse_zone', ['zone' => $zone]),
            "Parse cPanel DNS zone {$zone}",
        );
        $rows = [];
        $this->collectDnsRows($data, $rows);
        $records = [];
        $serial = null;

        foreach ($rows as $row) {
            if (($row['type'] ?? null) !== 'record'
                || ! \is_string($row['record_type'] ?? null)
                || ! \is_numeric($row['line_index'] ?? null)) {
                continue;
            }

            $type = \strtoupper($row['record_type']);
            $decodedData = $this->decodeDnsData($row['data_b64'] ?? []);
            if ($type === 'SOA' && isset($decodedData[2]) && \is_numeric($decodedData[2])) {
                $serial = (int) $decodedData[2];
            }

            $decodedName = $this->decodeBase64($row['dname_b64'] ?? null);
            if ($decodedName === null) {
                continue;
            }

            $records[] = [
                'line_index' => (int) $row['line_index'],
                'name' => $this->normalizeDnsName($decodedName, $zone),
                'type' => $type,
                'data_hash' => $this->dnsDataHash($type, $decodedData),
                'ttl' => \is_numeric($row['ttl'] ?? null) ? (int) $row['ttl'] : 0,
            ];
        }

        if ($serial === null) {
            throw new RuntimeException("cPanel DNS zone {$zone} did not expose an SOA serial");
        }

        return [
            'zone' => $zone,
            'serial' => $serial,
            'records' => $records,
        ];
    }

    /**
     * @param array{zone: string, serial: int, records: array<int, array<string, mixed>>} $zone
     * @param array<string, mixed> $changes
     */
    private function editDnsZone(array $zone, array $changes, string $operation): void
    {
        $this->required($this->api->uapi('DNS', 'mass_edit_zone', [
            'zone' => $zone['zone'],
            'serial' => $zone['serial'],
            ...$changes,
        ]), $operation);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>|null
     */
    private function matchingDnsRecord(array $records, array $expected): ?array
    {
        return $this->matchingResource($records, $expected, ['name', 'type', 'data_hash']);
    }

    /**
     * @param mixed $value
     * @param array<int, array<string, mixed>> $rows
     */
    private function collectDnsRows(mixed $value, array &$rows): void
    {
        if (! \is_array($value)) {
            return;
        }

        if (isset($value['type'], $value['line_index'])) {
            $rows[] = $value;

            return;
        }

        foreach ($value as $item) {
            $this->collectDnsRows($item, $rows);
        }
    }

    /** @return array<int, string> */
    private function decodeDnsData(mixed $data): array
    {
        if (! \is_array($data)) {
            return [];
        }

        $decoded = [];
        foreach ($data as $value) {
            $item = $this->decodeBase64($value);
            if ($item !== null) {
                $decoded[] = $item;
            }
        }

        return $decoded;
    }

    private function decodeBase64(mixed $value): ?string
    {
        if (! \is_string($value)) {
            return null;
        }

        $decoded = \base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }

    /** @param array<int, string> $data */
    private function dnsDataHash(string $type, array $data): string
    {
        $normalized = \array_values(\array_map(
            static fn (string $value): string => \trim($value),
            $data,
        ));
        $domainIndexes = match ($type) {
            'CNAME', 'NS', 'PTR' => [0],
            'MX' => [1],
            'SRV' => [3],
            default => [],
        };
        foreach ($domainIndexes as $index) {
            if (isset($normalized[$index])) {
                $normalized[$index] = \strtolower(\rtrim($normalized[$index], '.'));
            }
        }

        return \hash('sha256', \json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalizeDnsName(string $name, string $zone = ''): string
    {
        $hadTrailingDot = \str_ends_with(\trim($name), '.');
        $name = \strtolower(\rtrim(\trim($name), '.'));
        $zone = \strtolower(\rtrim(\trim($zone), '.'));
        if (! $hadTrailingDot && $zone !== '' && $name !== $zone && ! \str_ends_with($name, '.'.$zone)) {
            $name .= '.'.$zone;
        }

        return $name;
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @param array<string, mixed> $expected
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>|null
     */
    private function matchingResource(array $resources, array $expected, array $keys): ?array
    {
        foreach ($resources as $resource) {
            foreach ($keys as $key) {
                if (($resource[$key] ?? null) !== ($expected[$key] ?? null)) {
                    continue 2;
                }
            }

            return $resource;
        }

        return null;
    }

    /** @param array<string, string> $expected */
    private function recordExists(mixed $data, array $expected): bool
    {
        if (! \is_array($data)) {
            return false;
        }

        $matches = true;
        foreach ($expected as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }

        foreach ($data as $value) {
            if ($this->recordExists($value, $expected)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, string} */
    private function emailParts(string $address): array
    {
        $separator = \strrpos($address, '@');
        if ($separator === false) {
            throw new RuntimeException("Invalid cPanel email address: {$address}");
        }

        return [\substr($address, 0, $separator), \substr($address, $separator + 1)];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<int, array<string, mixed>>
     */
    private function manifestResources(array $manifest, string $key): array
    {
        $resources = $manifest[$key] ?? [];
        if (! \is_array($resources)) {
            return [];
        }

        return \array_values(\array_filter($resources, '\is_array'));
    }

    private function required(array $result, string $operation): mixed
    {
        if (! $result['success']) {
            throw new RuntimeException("{$operation} failed: {$result['message']}");
        }

        return $result['data'];
    }
}
