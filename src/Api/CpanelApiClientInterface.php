<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel\Api;

interface CpanelApiClientInterface
{
    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    public function uapi(string $module, string $function, array $parameters = [], string $method = 'GET'): array;

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    public function api2(string $module, string $function, array $parameters = []): array;

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    public function whm(string $function, array $parameters = [], string $method = 'GET'): array;

    /**
     * @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>}
     */
    public function uploadFile(string $directory, string $localPath, string $remoteFilename, bool $overwrite = true): array;
}
