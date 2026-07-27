<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel\Tests\Fixtures;

final class CpanelTestProfile
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'production';
    }

    public function branch(): string
    {
        return 'main';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /** @return array<int, string> */
    public function aliases(): array
    {
        $aliases = $this->config['aliases'] ?? [];

        return \is_array($aliases) ? \array_values(\array_filter($aliases, '\is_string')) : [];
    }
}
