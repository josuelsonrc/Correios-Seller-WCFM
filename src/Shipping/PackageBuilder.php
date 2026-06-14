<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

final class PackageBuilder
{
    public function build(array $package, array $vendorSettings): ShipmentPackage
    {
        $weight = 0.0;
        $declaredValue = 0.0;
        $maxLength = (float) ($vendorSettings['default_length'] ?? 16);
        $maxWidth = (float) ($vendorSettings['default_width'] ?? 11);
        $stackedHeight = 0.0;

        foreach (($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            $qty = max(1, (int) ($item['quantity'] ?? 1));

            if (! $product || ! is_a($product, 'WC_Product')) {
                continue;
            }

            $itemWeight = (float) wc_get_weight((float) ($product->get_weight() ?: ($vendorSettings['default_weight'] ?? 0.3)), 'kg');
            $length = (float) wc_get_dimension((float) ($product->get_length() ?: ($vendorSettings['default_length'] ?? 16)), 'cm');
            $width = (float) wc_get_dimension((float) ($product->get_width() ?: ($vendorSettings['default_width'] ?? 11)), 'cm');
            $height = (float) wc_get_dimension((float) ($product->get_height() ?: ($vendorSettings['default_height'] ?? 2)), 'cm');

            $weight += $itemWeight * $qty;
            $declaredValue += (float) $product->get_price() * $qty;
            $maxLength = max($maxLength, $length);
            $maxWidth = max($maxWidth, $width);
            $stackedHeight += $height * $qty;
        }

        return new ShipmentPackage(
            max($weight, (float) ($vendorSettings['default_weight'] ?? 0.3)),
            max($maxLength, 16),
            max($maxWidth, 11),
            max($stackedHeight, (float) ($vendorSettings['default_height'] ?? 2), 2),
            $declaredValue
        );
    }
}
