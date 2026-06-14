<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Correios\CorreiosClient;
use CorreiosSeller\Correios\Credentials;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Options;

final class QuoteService
{
    public function __construct(
        private CorreiosClient $client,
        private Cache $cache
    ) {
    }

    public function quote(Credentials $credentials, string $serviceCode, string $originPostcode, string $destinationPostcode, ShipmentPackage $package, int $handlingDays): array
    {
        $cachePayload = [
            $credentials->cacheKey(),
            Options::get('api_environment', 'production'),
            $serviceCode,
            $originPostcode,
            $destinationPostcode,
            $package,
            $handlingDays,
        ];
        $cacheKey = 'quote_' . md5(wp_json_encode($cachePayload));
        $ttl = (int) Options::get('cache_ttl', 900);

        try {
            return (array) $this->cache->remember($cacheKey, $ttl, function () use ($credentials, $serviceCode, $originPostcode, $destinationPostcode, $package, $handlingDays): array {
                $payload = $this->payload($serviceCode, $originPostcode, $destinationPostcode, $package);
                $response = $this->client->quote($credentials, $payload);

                return $this->normalizeResponse($serviceCode, $response, $handlingDays);
            });
        } catch (\Throwable $exception) {
            if (Options::get('fallback_enabled', 'yes') === 'yes') {
                $fallback = $this->cache->lastGood($cacheKey);
                if (is_array($fallback)) {
                    $fallback['fallback'] = true;

                    return $fallback;
                }
            }

            throw $exception;
        }
    }

    private function payload(string $serviceCode, string $originPostcode, string $destinationPostcode, ShipmentPackage $package): array
    {
        $common = [
            'coProduto' => $serviceCode,
            'cepOrigem' => $originPostcode,
            'cepDestino' => $destinationPostcode,
        ];

        return [
            'price' => [
                'idLote' => uniqid('wc_', true),
                'parametrosProduto' => [array_merge($common, [
                    'nuRequisicao' => uniqid('', false),
                    'nuPeso' => (string) $package->billableWeightKg(),
                    'tpObjeto' => '2',
                    'comprimento' => (string) $package->lengthCm,
                    'largura' => (string) $package->widthCm,
                    'altura' => (string) $package->heightCm,
                    'vlDeclarado' => (string) $package->declaredValue,
                ])],
            ],
            'deadline' => [
                'idLote' => uniqid('wc_', true),
                'parametrosPrazo' => [array_merge($common, [
                    'nuRequisicao' => uniqid('', false),
                ])],
            ],
        ];
    }

    private function normalizeResponse(string $serviceCode, array $response, int $handlingDays): array
    {
        $priceItem = $response['price'][0] ?? $response['price']['itens'][0] ?? $response['price'];
        $deadlineItem = $response['deadline'][0] ?? $response['deadline']['itens'][0] ?? $response['deadline'];

        $amount = $priceItem['pcFinal'] ?? $priceItem['precoFinal'] ?? $priceItem['valor'] ?? 0;
        $days = (int) ($deadlineItem['prazoEntrega'] ?? $deadlineItem['prazo'] ?? 0) + $handlingDays;

        return [
            'service_code' => $serviceCode,
            'amount' => (float) str_replace(',', '.', (string) $amount),
            'delivery_days' => $days,
            'raw' => $response,
            'fallback' => false,
        ];
    }
}
