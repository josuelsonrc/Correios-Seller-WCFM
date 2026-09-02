<?php

declare(strict_types=1);

namespace CorreiosSeller\Contracts;

use CorreiosSeller\Shipping\QuoteRequest;

interface ShippingGateway
{
    public function id(): string;

    /**
     * @param array<string,mixed> $vendorSettings
     */
    public function isConfigured(array $vendorSettings, int $vendorId): bool;

    /**
     * @param array<string,mixed> $vendorSettings
     */
    public function cacheFingerprint(array $vendorSettings, int $vendorId): string;

    /**
     * @param array<string,mixed> $vendorSettings
     * @return array<int,\CorreiosSeller\Shipping\ShippingQuote>
     */
    public function quote(QuoteRequest $request, array $vendorSettings): array;
}
