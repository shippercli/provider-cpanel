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

        $file = $parameters['file'] ?? null;
        if ($module === 'Fileman' && $function === 'get_file_content'
            && \is_string($file) && \str_starts_with($file, '.shipper-domain-')) {
            $contents = $this->uploadedFiles[$file] ?? null;

            return $this->success($contents === null ? null : ['content' => $contents]);
        }

        return $this->response("uapi:{$module}:{$function}");
    }

    public function api2(string $module, string $function, array $parameters = []): array
    {
        $this->calls[] = compact('module', 'function', 'parameters') + ['surface' => 'api2'];

        $result = $this->response("api2:{$module}:{$function}");
        $source = $parameters['sourcefiles'] ?? null;
        if ($result['success'] && $module === 'Fileman' && $function === 'fileop'
            && ($parameters['op'] ?? null) === 'trash'
            && \is_string($source) && \str_starts_with($source, '.shipper-domain-')) {
            unset($this->uploadedFiles[$source]);
        }

        return $result;
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
        $this->calls[] = [
            'surface' => 'upload',
            'module' => 'Fileman',
            'function' => 'upload_files',
            'parameters' => compact('directory', 'files', 'overwrite'),
        ];
        $result = $this->response('upload:Fileman:upload_files');
        if (! $result['success']) {
            return $result;
        }

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

        return $result;
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
