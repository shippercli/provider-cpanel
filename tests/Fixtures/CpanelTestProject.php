<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel\Tests\Fixtures;

final class CpanelTestProject
{
    /** @param array<string, mixed> $repository */
    public function __construct(
        private readonly string $path,
        private readonly array $repository = [],
        private readonly string $webDirectory = '/',
    ) {}

    public function name(): string
    {
        return 'sample';
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function repository(): array
    {
        return $this->repository;
    }

    public function webDirectory(): string
    {
        return $this->webDirectory;
    }

    public function phpVersion(): string
    {
        return '';
    }

    /** @return array<int, mixed> */
    public function databases(): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    public function cron(): array
    {
        return [];
    }

    /** @return array<int, mixed> */
    public function redirects(): array
    {
        return [];
    }
}
