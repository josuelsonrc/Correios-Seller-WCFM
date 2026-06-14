<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

final class ShipmentPackage
{
    public function __construct(
        public float $weightKg,
        public float $lengthCm,
        public float $widthCm,
        public float $heightCm,
        public float $declaredValue
    ) {
    }

    public function cubicWeightKg(): float
    {
        return round(($this->lengthCm * $this->widthCm * $this->heightCm) / 6000, 3);
    }

    public function billableWeightKg(): float
    {
        return max($this->weightKg, $this->cubicWeightKg());
    }
}
