<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

final class QuoteRequest
{
    public function __construct(
        public int $vendorId,
        public string $originPostcode,
        public string $destinationPostcode,
        public ShipmentPackage $package,
        public int $handlingDays
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function cachePayload(): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'origin_postcode' => $this->originPostcode,
            'destination_postcode' => $this->destinationPostcode,
            'weight' => $this->package->weightKg,
            'length' => $this->package->lengthCm,
            'width' => $this->package->widthCm,
            'height' => $this->package->heightCm,
            'declared_value' => $this->package->declaredValue,
            'handling_days' => $this->handlingDays,
        ];
    }
}
