<?php

declare(strict_types=1);

namespace CorreiosSeller\WCFM;

use CorreiosSeller\Support\Logger;
use Throwable;

final class VendorStoreShippingRatesBridge
{
    private const WCFM_ZONE_METHOD_ID = 'wcfmmp_product_shipping_by_zone';

    private bool $resolving = false;

    public function __construct(private Logger $logger)
    {
    }

    public function register(): void
    {
        add_filter('woocommerce_package_rates', [$this, 'mergeApplicableVendorRates'], 30, 2);
    }

    /**
     * WooCommerce resolves only one zone. WCFM vendor zones may be more specific
     * than a marketplace-wide zone, so add the applicable vendor rates separately.
     *
     * @param array<string, \WC_Shipping_Rate> $rates
     * @param array<string, mixed>             $package
     *
     * @return array<string, \WC_Shipping_Rate>
     */
    public function mergeApplicableVendorRates(array $rates, array $package): array
    {
        if (
            $this->resolving
            || $this->containsVendorZoneRate($rates)
            || ! class_exists('\WCFMmp_Shipping_Zone')
            || ! class_exists('\WC_Shipping_Zones')
            || ! class_exists('\WC_Cache_Helper')
        ) {
            return $rates;
        }

        $vendorId = (int) ($package['vendor_id'] ?? $package['seller_id'] ?? 0);
        if ($vendorId <= 0) {
            return $rates;
        }

        $this->resolving = true;

        try {
            $vendorRates = $this->findApplicableVendorRates($package, $vendorId);

            if ($vendorRates !== []) {
                $this->logger->info('WCFM vendor store shipping rates merged into package.', [
                    'vendor_id' => $vendorId,
                    'rate_ids' => array_keys($vendorRates),
                ]);
            }

            return $rates + $vendorRates;
        } catch (Throwable $exception) {
            $this->logger->error('Unable to merge WCFM vendor store shipping rates.', [
                'vendor_id' => $vendorId,
                'message' => $exception->getMessage(),
            ]);

            return $rates;
        } finally {
            $this->resolving = false;
        }
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, \WC_Shipping_Rate>
     */
    private function findApplicableVendorRates(array $package, int $vendorId): array
    {
        $cacheKey = $this->zoneCacheKey($package);
        $originalZoneId = (int) \WC_Shipping_Zones::get_zone_matching_package($package)->get_id();

        try {
            foreach ((array) \WCFMmp_Shipping_Zone::get_zones($vendorId) as $zoneId => $zone) {
                if (empty($zone['shipping_methods'])) {
                    continue;
                }

                $shippingMethod = $this->vendorZoneMethod((int) $zoneId);
                if (! $shippingMethod) {
                    continue;
                }

                wp_cache_set($cacheKey, (int) $zoneId, 'shipping_zones');
                $candidateRates = (array) $shippingMethod->get_rates_for_package($package);

                if ($candidateRates !== []) {
                    return $candidateRates;
                }
            }

            return [];
        } finally {
            wp_cache_set($cacheKey, $originalZoneId, 'shipping_zones');
        }
    }

    private function vendorZoneMethod(int $zoneId): ?\WC_Shipping_Method
    {
        $zone = new \WC_Shipping_Zone($zoneId);

        foreach ($zone->get_shipping_methods(true) as $method) {
            if ($method->id === self::WCFM_ZONE_METHOD_ID) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @param array<string, \WC_Shipping_Rate> $rates
     */
    private function containsVendorZoneRate(array $rates): bool
    {
        foreach ($rates as $rate) {
            if (
                $rate instanceof \WC_Shipping_Rate
                && $rate->get_method_id() === self::WCFM_ZONE_METHOD_ID
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function zoneCacheKey(array $package): string
    {
        $destination = (array) ($package['destination'] ?? []);
        $country = strtoupper(wc_clean((string) ($destination['country'] ?? '')));
        $state = strtoupper(wc_clean((string) ($destination['state'] ?? '')));
        $postcode = wc_normalize_postcode(wc_clean((string) ($destination['postcode'] ?? '')));

        return \WC_Cache_Helper::get_cache_prefix('shipping_zones')
            . 'wc_shipping_zone_'
            . md5(sprintf('%s+%s+%s', $country, $state, $postcode));
    }
}
