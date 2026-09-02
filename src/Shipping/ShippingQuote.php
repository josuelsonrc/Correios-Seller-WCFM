<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

final class ShippingQuote
{
    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public string $gateway,
        public string $serviceId,
        public string $serviceName,
        public string $carrier,
        public float $amount,
        public int $deliveryDays,
        public array $raw = [],
        public bool $fallback = false
    ) {
    }

    public function markAsFallback(): self
    {
        $copy = clone $this;
        $copy->fallback = true;

        return $copy;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'service_id' => $this->serviceId,
            'service_name' => $this->serviceName,
            'carrier' => $this->carrier,
            'amount' => $this->amount,
            'delivery_days' => $this->deliveryDays,
            'raw' => $this->raw,
            'fallback' => $this->fallback,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['gateway'] ?? ''),
            (string) ($data['service_id'] ?? ''),
            (string) ($data['service_name'] ?? ''),
            (string) ($data['carrier'] ?? ''),
            (float) ($data['amount'] ?? 0),
            (int) ($data['delivery_days'] ?? 0),
            is_array($data['raw'] ?? null) ? $data['raw'] : [],
            ! empty($data['fallback'])
        );
    }
}
