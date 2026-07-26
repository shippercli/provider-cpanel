<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCpanel;

use ShipperCli\Contracts\ShipperPluginInterface;

final class CpanelPlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return [
            'cpanel' => CpanelProvider::class,
        ];
    }
}
