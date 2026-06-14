<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class ProductVendorResolver
{
    public function resolveFromProduct(\WC_Product $product): int
    {
        $productId = $this->ownerProductId($product);
        if ($productId <= 0) {
            return 0;
        }

        return $this->resolveFromProductId($productId);
    }

    public function resolveFromProductId(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $wcfmAuthor = (int) get_post_meta($productId, '_wcfm_product_author', true);
        if ($wcfmAuthor > 0) {
            return $wcfmAuthor;
        }

        return (int) get_post_field('post_author', $productId);
    }

    public function ownerProductId(\WC_Product $product): int
    {
        if ($product->is_type('variation') && method_exists($product, 'get_parent_id')) {
            return (int) $product->get_parent_id();
        }

        return (int) $product->get_id();
    }
}
