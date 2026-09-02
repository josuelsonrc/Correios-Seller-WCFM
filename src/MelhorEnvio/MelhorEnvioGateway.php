<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Contracts\ShippingGateway;
use CorreiosSeller\Shipping\QuoteRequest;
use CorreiosSeller\Shipping\ShippingQuote;
use CorreiosSeller\Support\Options;

final class MelhorEnvioGateway implements ShippingGateway
{
    public function __construct(
        private MelhorEnvioClient $client,
        private MelhorEnvioTokenResolver $tokenResolver
    ) {
    }

    public function id(): string
    {
        return 'melhor_envio';
    }

    public function isConfigured(array $vendorSettings, int $vendorId): bool
    {
        return $this->tokenResolver->resolve($vendorSettings, $vendorId) !== '';
    }

    public function cacheFingerprint(array $vendorSettings, int $vendorId): string
    {
        return hash('sha256', wp_json_encode([
            $this->tokenResolver->resolve($vendorSettings, $vendorId),
            Options::get('melhor_envio_environment', 'production'),
            $this->servicesForVendor($vendorSettings),
        ]));
    }

    public function quote(QuoteRequest $request, array $vendorSettings): array
    {
        $accessToken = $this->tokenResolver->resolve($vendorSettings, $request->vendorId);
        if ($accessToken === '') {
            throw new \RuntimeException('Conta do Melhor Envio nao conectada.');
        }

        $response = $this->client->quote($accessToken, $this->payload($request, $vendorSettings));
        $quotes = [];

        foreach ($response as $item) {
            if (! empty($item['error'])) {
                continue;
            }

            $amount = (float) ($item['custom_price'] ?? $item['price'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $company = is_array($item['company'] ?? null) ? $item['company'] : [];
            $deliveryDays = (int) ($item['custom_delivery_time'] ?? $item['delivery_time'] ?? 0);

            $quotes[] = new ShippingQuote(
                $this->id(),
                (string) ($item['id'] ?? ''),
                sanitize_text_field((string) ($item['name'] ?? 'Melhor Envio')),
                sanitize_text_field((string) ($company['name'] ?? 'Melhor Envio')),
                $amount,
                $deliveryDays + $request->handlingDays,
                $item
            );
        }

        return $quotes;
    }

    /**
     * @param array<string,mixed> $vendorSettings
     * @return array<string,mixed>
     */
    private function payload(QuoteRequest $request, array $vendorSettings): array
    {
        $package = $request->package;
        $payload = [
            'from' => ['postal_code' => $request->originPostcode],
            'to' => ['postal_code' => $request->destinationPostcode],
            'products' => [[
                'id' => 'vendor-' . $request->vendorId,
                'width' => round($package->widthCm, 2),
                'height' => round($package->heightCm, 2),
                'length' => round($package->lengthCm, 2),
                'weight' => round($package->weightKg, 3),
                'insurance_value' => round($package->declaredValue, 2),
                'quantity' => 1,
            ]],
            'options' => [
                'receipt' => false,
                'own_hand' => false,
                'collect' => false,
                'insurance_value' => round($package->declaredValue, 2),
            ],
        ];

        $services = $this->servicesForVendor($vendorSettings);
        if ($services !== []) {
            $payload['services'] = implode(',', $services);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $vendorSettings
     * @return array<int,string>
     */
    private function servicesForVendor(array $vendorSettings): array
    {
        $services = (array) ($vendorSettings['melhor_envio_enabled_services'] ?? []);

        return $services !== [] ? array_values($services) : Options::enabledServices('melhor_envio');
    }
}
