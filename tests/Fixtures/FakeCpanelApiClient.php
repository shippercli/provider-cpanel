<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel\Tests\Fixtures;

use ShipperCli\ProviderCpanel\Api\CpanelApiClientInterface;
use ZipArchive;

final class FakeCpanelApiClient implements CpanelApiClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<string, array{success: bool, message: string, data: mixed, raw: array<string, mixed>}> */
    public array $responses = [];

    /** @var array<string, array<int, array{success: bool, message: string, data: mixed, raw: array<string, mixed>}>> */
    public array $responseQueues = [];

    /** @var array<int, string> */
    public array $uploadedArchiveEntries = [];

    /** @var array<string, string> */
    public array $uploadedFiles = [];

    /** @var array<string, string> */
    public array $uploadedPaths = [];

    public function uapi(string $module, string $function, array $parameters = [], string $method = 'GET'): array
    {
        $this->calls[] = compact('module', 'function', 'parameters', 'method') + ['surface' => 'uapi'];

        return $this->response("uapi:{$module}:{$function}");
    }

    public function api2(string $module, string $function, array $parameters = []): array
    {
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'api2'];

        return $this->response("api2:{$module}:{$function}");
    }

    public function whm(string $function, array $parameters = [], string $method = 'GET'): array
    {
        $module = '';
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'whm'];

        return $this->response("whm:{$function}");
    }

    public function uploadFile(string $directory, string $localPath, string $remoteFilename, bool $overwrite = true): array
    {
        return $this->uploadFiles($directory, [$remoteFilename => $localPath], $overwrite);
    }

    public function uploadFiles(string $directory, array $files, bool $overwrite = true): array
    {
        foreach ($files as $remoteFilename => $localPath) {
            $contents = \file_get_contents($localPath);
            if (\is_string($contents)) {
                $this->uploadedFiles[$remoteFilename] = $contents;
                $this->uploadedPaths[\trim($directory, '/').'/'.$remoteFilename] = $contents;
            }

            if (\str_ends_with($remoteFilename, '.zip')) {
                $archive = new ZipArchive;
                if ($archive->open($localPath) === true) {
                    for ($index = 0; $index < $archive->numFiles; $index++) {
                        $entry = $archive->getNameIndex($index);
                        if (\is_string($entry)) {
                            $this->uploadedArchiveEntries[] = $entry;
                        }
                    }
                    $archive->close();
                }
            }
        }

        $this->calls[] = [
            'surface' => 'upload',
            'module' => 'Fileman',
            'function' => 'upload_files',
            'parameters' => compact('directory', 'files', 'overwrite'),
        ];

        return $this->success();
    }

    /** @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>} */
    public function success(mixed $data = null): array
    {
        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data,
            'raw' => [],
        ];
    }

    /** @return array{success: bool, message: string, data: mixed, raw: array<string, mixed>} */
    private function response(string $key): array
    {
        if (($this->responseQueues[$key] ?? []) !== []) {
            return \array_shift($this->responseQueues[$key]);
        }

        return $this->responses[$key] ?? $this->success();
    }
}
