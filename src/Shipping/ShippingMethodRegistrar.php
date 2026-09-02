<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

final class ShippingMethodRegistrar
{
    public function register(): void
    {
        add_action('woocommerce_shipping_init', [$this, 'loadShippingMethod']);
        add_filter('woocommerce_shipping_methods', [$this, 'addShippingMethod']);
    }

    public function loadShippingMethod(): void
    {
        require_once FRETE_MARKETPLACE_PATH . 'src/Shipping/WCFMMarketplaceShippingMethod.php';
    }

    public function addShippingMethod(array $methods): array
    {
        $methods[WCFMMarketplaceShippingMethod::METHOD_ID] = WCFMMarketplaceShippingMethod::class;

        return $methods;
    }
}
