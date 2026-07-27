<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel\Api;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class CpanelApiClient implements CpanelApiClientInterface
{
    private string $hostname;

    private int $cpanelPort;

    private int $whmPort;

    private bool $verifyTls;

    private ?string $originIp;

    private string $username;

    private string $credential;

    private bool $usesApiToken;

    private string $whmUsername;

    private string $whmCredential;

    private bool $usesWhmApiToken;

    private ?ClientInterface $httpClient;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        [$this->hostname, $sourcePort, $secure] = $this->parseHost($config['host'] ?? '');

        $configuredPort = $this->integerValue($config['cpanel_port'] ?? $config['port'] ?? null);
        $this->cpanelPort = $configuredPort ?? match ($sourcePort) {
            2086 => 2082,
            2087 => 2083,
            null => $secure ? 2083 : 2082,
            default => $sourcePort,
        };

        $configuredWhmPort = $this->integerValue($config['whm_port'] ?? null);
        $this->whmPort = $configuredWhmPort ?? match ($sourcePort) {
            2086, 2087 => $sourcePort,
            default => $this->cpanelPort === 2082 ? 2086 : 2087,
        };

        $this->verifyTls = ! isset($config['verify_tls']) || (bool) $config['verify_tls'];
        $this->originIp = $this->nullableString($config['origin_ip'] ?? null);
        if ($this->originIp !== null && \filter_var($this->originIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('cPanel origin_ip must be a valid IPv4 or IPv6 address');
        }

        $this->username = $this->requiredString($config['username'] ?? null, 'cPanel username');
        $apiToken = $this->nullableString($config['api_token'] ?? null);
        $password = $this->nullableString($config['password'] ?? null);
        $this->usesApiToken = $apiToken !== null;
        $this->credential = $apiToken ?? $password ?? '';
        if ($this->credential === '') {
            throw new InvalidArgumentException('cPanel password or API token is required');
        }

        $this->whmUsername = $this->nullableString($config['whm_username'] ?? null) ?? $this->username;
        $whmApiToken = $this->nullableString($config['whm_api_token'] ?? null);
        $whmPassword = $this->nullableString($config['whm_password'] ?? null);
        $this->usesWhmApiToken = $whmApiToken !== null;
        $this->whmCredential = $whmApiToken ?? $whmPassword ?? $this->credential;
        $this->httpClient = $httpClient;
    }

    public function uapi(string $module, string $function, array $parameters = [], string $method = 'GET'): array
    {
        $url = $this->cpanelBaseUrl().'/execute/'.\rawurlencode($module).'/'.\rawurlencode($function);

        return $this->requestJson($url, $method, $parameters, false, 'uapi');
    }

    public function api2(string $module, string $function, array $parameters = []): array
    {
        $query = [
            'cpanel_jsonapi_user' => $this->username,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
            ...$parameters,
        ];

        return $this->requestJson($this->cpanelBaseUrl().'/json-api/cpanel', 'GET', $query, false, 'api2');
    }

    public function whm(string $function, array $parameters = [], string $method = 'GET'): array
    {
        $url = $this->whmBaseUrl().'/json-api/'.\rawurlencode($function);

        return $this->requestJson($url, $method, $parameters, true, 'whm');
    }

    public function uploadFile(
        string $directory,
        string $localPath,
        string $remoteFilename,
        bool $overwrite = true,
    ): array {
        $handle = @\fopen($localPath, 'rb');
        if ($handle === false) {
            return $this->failedResponse("Unable to open file for upload: {$localPath}");
        }

        $options = $this->authenticationOptions(false, $this->cpanelPort);
        $options['multipart'] = [
            [
                'name' => 'dir',
                'contents' => $directory,
            ],
            [
                'name' => 'overwrite',
                'contents' => $overwrite ? '1' : '0',
            ],
            [
                'name' => 'file-1',
                'contents' => $handle,
                'filename' => $remoteFilename,
            ],
        ];

        try {
            $response = $this->client()->request(
                'POST',
                $this->cpanelBaseUrl().'/execute/Fileman/upload_files',
                $options,
            );

            return $this->normalizeResponse($this->decode((string) $response->getBody()), 'uapi');
        } catch (GuzzleException|JsonException $exception) {
            return $this->failedResponse($exception->getMessage());
        } finally {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    private function requestJson(
        string $url,
        string $method,
        array $parameters,
        bool $whm,
        string $surface,
    ): array {
        $port = $whm ? $this->whmPort : $this->cpanelPort;
        $options = $this->authenticationOptions($whm, $port);
        $encoded = $this->encodeParameters($parameters);
        $method = \strtoupper($method);

        if ($method === 'GET') {
            $options['query'] = $encoded;
        } else {
            $options['body'] = $encoded;
            $options['headers'] = [
                ...($options['headers'] ?? []),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ];
        }

        try {
            $response = $this->client()->request($method, $url, $options);

            return $this->normalizeResponse($this->decode((string) $response->getBody()), $surface);
        } catch (GuzzleException|JsonException $exception) {
            return $this->failedResponse($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticationOptions(bool $whm, int $port): array
    {
        $username = $whm ? $this->whmUsername : $this->username;
        $credential = $whm ? $this->whmCredential : $this->credential;
        $usesToken = $whm ? $this->usesWhmApiToken : $this->usesApiToken;
        $options = [
            'http_errors' => true,
            'timeout' => 60,
            'verify' => $this->verifyTls,
        ];

        if ($usesToken) {
            $options['headers'] = [
                'Authorization' => ($whm ? 'whm ' : 'cpanel ').$username.':'.$credential,
            ];
        } else {
            $options['auth'] = [$username, $credential];
        }

        if ($this->originIp !== null && \defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [
                \CURLOPT_RESOLVE => [
                    "{$this->hostname}:{$port}:{$this->originIp}",
                ],
            ];
        }

        return $options;
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client;
    }

    private function cpanelBaseUrl(): string
    {
        $scheme = $this->cpanelPort === 2082 ? 'http' : 'https';

        return "{$scheme}://{$this->hostname}:{$this->cpanelPort}";
    }

    private function whmBaseUrl(): string
    {
        $scheme = $this->whmPort === 2086 ? 'http' : 'https';

        return "{$scheme}://{$this->hostname}:{$this->whmPort}";
    }

    /**
     * @param mixed $host
     *
     * @return array{string, int|null, bool}
     */
    private function parseHost(mixed $host): array
    {
        if (! \is_string($host) || \trim($host) === '') {
            throw new InvalidArgumentException('cPanel host is required');
        }

        $host = \trim($host);
        $url = \str_contains($host, '://') ? $host : 'https://'.$host;
        $hostname = \parse_url($url, PHP_URL_HOST);
        if (! \is_string($hostname) || $hostname === '') {
            throw new InvalidArgumentException('cPanel host is invalid');
        }

        $port = \parse_url($url, PHP_URL_PORT);
        $scheme = \parse_url($url, PHP_URL_SCHEME);

        return [
            $hostname,
            \is_int($port) ? $port : null,
            ! \is_string($scheme) || \strtolower($scheme) !== 'http',
        ];
    }

    /**
     * Encode list values as repeated keys and objects as JSON.
     *
     * @param array<string, mixed> $parameters
     */
    private function encodeParameters(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (\is_array($value) && \array_is_list($value)) {
                foreach ($value as $item) {
                    $pairs[] = $this->encodePair($key, $item);
                }

                continue;
            }

            if (\is_array($value) || \is_object($value)) {
                $value = \json_encode($value, JSON_THROW_ON_ERROR);
            }

            $pairs[] = $this->encodePair($key, $value);
        }

        return \implode('&', $pairs);
    }

    private function encodePair(string $key, mixed $value): string
    {
        if (\is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (! \is_scalar($value)) {
            throw new InvalidArgumentException("Unsupported cPanel API parameter: {$key}");
        }

        return \rawurlencode($key).'='.\rawurlencode((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! \is_array($decoded)) {
            throw new RuntimeException('cPanel returned a non-object JSON response');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    private function normalizeResponse(array $payload, string $surface): array
    {
        if ($surface === 'api2') {
            $result = \is_array($payload['cpanelresult'] ?? null) ? $payload['cpanelresult'] : $payload;
            $event = \is_array($result['event'] ?? null) ? $result['event'] : [];
            $success = (int) ($event['result'] ?? 0) === 1;
            $data = $result['data'] ?? [];

            if (\is_array($data)) {
                foreach ($data as $item) {
                    if (\is_array($item) && isset($item['result']) && (int) $item['result'] !== 1) {
                        $success = false;
                        break;
                    }

                    $output = \is_array($item) ? $this->firstString($item['output'] ?? null) : null;
                    if ($output !== null && \preg_match('/(?:^|\R)\s*(?:error:|.*\bpermission denied\b)/i', $output) === 1) {
                        $success = false;
                        break;
                    }
                }
            }

            return [
                'success' => $success,
                'message' => $this->extractMessage($result),
                'data' => $data,
                'raw' => $payload,
            ];
        }

        if ($surface === 'whm') {
            $metadata = \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

            return [
                'success' => (int) ($metadata['result'] ?? 0) === 1,
                'message' => $this->extractMessage($metadata),
                'data' => $payload['data'] ?? null,
                'raw' => $payload,
            ];
        }

        $result = \is_array($payload['result'] ?? null) && \array_key_exists('status', $payload['result'])
            ? $payload['result']
            : $payload;

        return [
            'success' => (int) ($result['status'] ?? 0) === 1,
            'message' => $this->extractMessage($result),
            'data' => $result['data'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractMessage(array $result): string
    {
        foreach (['errors', 'error', 'messages', 'reason', 'statusmsg'] as $key) {
            $message = $this->firstString($result[$key] ?? null);
            if ($message !== null && $message !== '') {
                return $message;
            }
        }

        $dataMessage = $this->findValueByKeys($result['data'] ?? null, ['reason', 'error', 'statusmsg', 'output']);

        return $dataMessage ?? 'OK';
    }

    private function firstString(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                $found = $this->firstString($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     */
    private function findValueByKeys(mixed $value, array $keys): ?string
    {
        if (! \is_array($value)) {
            return null;
        }

        foreach ($keys as $key) {
            $found = $this->firstString($value[$key] ?? null);
            if ($found !== null) {
                return $found;
            }
        }

        foreach ($value as $item) {
            $found = $this->findValueByKeys($item, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array{success: false, message: string, data: null, raw: array<string, mixed>}
     */
    private function failedResponse(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'raw' => [],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! \is_string($value)) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }

    private function requiredString(mixed $value, string $label): string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            throw new InvalidArgumentException("{$label} is required");
        }

        return $value;
    }

    private function integerValue(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && \ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
