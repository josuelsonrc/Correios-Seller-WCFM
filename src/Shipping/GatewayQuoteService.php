<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Contracts\ShippingGateway;
use CorreiosSeller\Repository\QuoteUsageRepository;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use Throwable;

final class GatewayQuoteService
{
    public function __construct(
        private GatewayRegistry $registry,
        private Cache $cache,
        private QuoteUsageRepository $usage,
        private Logger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $vendorSettings
     * @return array<int,ShippingQuote>
     */
    public function quote(QuoteRequest $request, array $vendorSettings): array
    {
        $gatewayId = Options::shippingGateway();
        $gateway = $this->registry->get($gatewayId);
        if (! $gateway) {
            $this->logger->error('Gateway de frete nao registrado.', ['gateway' => $gatewayId]);
            return [];
        }

        try {
            return $this->quoteGateway($gateway, $request, $vendorSettings);
        } catch (Throwable $exception) {
            $this->usage->recordError($request, $gatewayId, $exception->getMessage());
            $this->logger->error('Falha ao cotar frete no Melhor Envio.', [
                'vendor_id' => $request->vendorId,
                'gateway' => $gatewayId,
                'error' => $exception->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $vendorSettings
     * @return array<int,ShippingQuote>
     */
    private function quoteGateway(ShippingGateway $gateway, QuoteRequest $request, array $vendorSettings): array
    {
        if (! $gateway->isConfigured($vendorSettings, $request->vendorId)) {
            throw new \RuntimeException(sprintf('Gateway %s sem credenciais configuradas.', $gateway->id()));
        }

        $cacheKey = 'gateway_quote_' . md5(wp_json_encode([
            'gateway' => $gateway->id(),
            'credentials' => $gateway->cacheFingerprint($vendorSettings, $request->vendorId),
            'request' => $request->cachePayload(),
        ]));
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            $quotes = $this->hydrate($cached);
            foreach ($quotes as $quote) {
                $this->usage->recordQuote($request, $quote, 'cache_hit');
            }

            return $quotes;
        }

        try {
            $quotes = $gateway->quote($request, $vendorSettings);
            $serialized = array_map(static fn (ShippingQuote $quote): array => $quote->toArray(), $quotes);
            $this->cache->put($cacheKey, $serialized, (int) Options::get('cache_ttl', 900));

            foreach ($quotes as $quote) {
                $this->usage->recordQuote($request, $quote, $quote->fallback ? 'fallback' : 'success');
            }

            return $quotes;
        } catch (Throwable $exception) {
            if (Options::get('fallback_enabled', 'yes') === 'yes') {
                $lastGood = $this->cache->lastGood($cacheKey);
                if (is_array($lastGood)) {
                    $quotes = array_map(static fn (ShippingQuote $quote): ShippingQuote => $quote->markAsFallback(), $this->hydrate($lastGood));
                    foreach ($quotes as $quote) {
                        $this->usage->recordQuote($request, $quote, 'fallback');
                    }

                    return $quotes;
                }
            }

            throw $exception;
        }
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,ShippingQuote>
     */
    private function hydrate(array $items): array
    {
        $quotes = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $quotes[] = ShippingQuote::fromArray($item);
            }
        }

        return $quotes;
    }
}
