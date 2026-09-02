<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Contracts\ShippingGateway;

final class GatewayRegistry
{
    /** @var array<string,ShippingGateway> */
    private array $gateways = [];

    /**
     * @param array<int,ShippingGateway> $gateways
     */
    public function __construct(array $gateways)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->id()] = $gateway;
        }
    }

    public function get(string $gatewayId): ?ShippingGateway
    {
        return $this->gateways[$gatewayId] ?? null;
    }

    /**
     * @return array<int,string>
     */
    public function ids(): array
    {
        return array_keys($this->gateways);
    }
}
