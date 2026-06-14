<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\ProductVendorResolver;

final class PackageSplitter
{
    private ProductVendorResolver $productVendorResolver;

    public function __construct(
        private VendorSettingsRepository $vendorSettings,
        ?ProductVendorResolver $productVendorResolver = null
    ) {
        $this->productVendorResolver = $productVendorResolver ?? new ProductVendorResolver();
    }

    public function register(): void
    {
        add_filter('woocommerce_cart_shipping_packages', [$this, 'splitByVendor'], 40);
    }

    public function splitByVendor(array $packages): array
    {
        $splitPackages = [];

        foreach ($packages as $package) {
            $groups = [];

            foreach (($package['contents'] ?? []) as $cartItemKey => $item) {
                $vendorId = $this->vendorIdFromItem($item);
                if ($vendorId <= 0) {
                    $vendorId = 0;
                }

                $groups[$vendorId][$cartItemKey] = $item;
            }

            if (count($groups) <= 1) {
                $vendorId = array_key_first($groups);
                $package['seller_id'] = (int) $vendorId;
                $package['vendor_id'] = (int) $vendorId;
                $splitPackages[] = $package;
                continue;
            }

            foreach ($groups as $vendorId => $contents) {
                $vendorPackage = $package;
                $vendorPackage['contents'] = $contents;
                $vendorPackage['contents_cost'] = array_sum(array_map(static fn ($item) => (float) ($item['line_total'] ?? 0), $contents));
                $vendorPackage['seller_id'] = (int) $vendorId;
                $vendorPackage['vendor_id'] = (int) $vendorId;
                $splitPackages[] = $vendorPackage;
            }
        }

        return $splitPackages;
    }

    private function vendorIdFromItem(array $item): int
    {
        $product = $item['data'] ?? null;
        if (! $product || ! is_a($product, 'WC_Product')) {
            return 0;
        }

        return $this->productVendorResolver->resolveFromProduct($product);
    }
}
