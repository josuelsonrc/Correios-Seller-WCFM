<?php

declare(strict_types=1);

namespace CorreiosSeller\Orders;

use CorreiosSeller\Support\Logger;

final class TrackingSync
{
    public function __construct(private Logger $logger)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'schedule']);
        add_action('correios_seller_tracking_sync', [$this, 'sync']);
        add_action('woocommerce_order_status_changed', [$this, 'captureShippingMeta'], 20, 4);
    }

    public function schedule(): void
    {
        if (! wp_next_scheduled('correios_seller_tracking_sync')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'correios_seller_tracking_sync');
        }
    }

    public function captureShippingMeta(int $orderId, string $oldStatus, string $newStatus, \WC_Order $order): void
    {
        foreach ($order->get_shipping_methods() as $item) {
            if (! str_starts_with((string) $item->get_method_id(), 'correios_seller')) {
                continue;
            }

            $item->add_meta_data('_correios_seller_vendor_id', $item->get_meta('vendor_id'), true);
            $item->add_meta_data('_correios_seller_service', $item->get_meta('correios_service'), true);
            $item->save();
        }
    }

    public function sync(): void
    {
        $this->logger->info('Tracking sync executado. Integre aqui a API oficial de rastreamento conforme contrato Correios.');
    }
}
