<?php

declare(strict_types=1);

namespace CorreiosSeller\Repository;

use CorreiosSeller\Shipping\QuoteRequest;
use CorreiosSeller\Shipping\ShippingQuote;

final class QuoteUsageRepository
{
    public const SCHEMA_VERSION = '1.0.0';

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'correios_seller_quote_usage';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            gateway varchar(32) NOT NULL,
            service_id varchar(64) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL,
            amount decimal(18,2) NOT NULL DEFAULT 0,
            delivery_days int(10) unsigned NOT NULL DEFAULT 0,
            origin_postcode varchar(12) NOT NULL DEFAULT '',
            destination_postcode varchar(12) NOT NULL DEFAULT '',
            error_message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY vendor_created (vendor_id, created_at),
            KEY gateway_created (gateway, created_at),
            KEY status_created (status, created_at)
        ) {$charset};");

        update_option('correios_seller_quote_schema_version', self::SCHEMA_VERSION, false);
    }

    public function recordQuote(QuoteRequest $request, ShippingQuote $quote, string $status): void
    {
        $this->insert([
            'vendor_id' => $request->vendorId,
            'gateway' => $quote->gateway,
            'service_id' => $quote->serviceId,
            'status' => $status,
            'amount' => $quote->amount,
            'delivery_days' => $quote->deliveryDays,
            'origin_postcode' => $request->originPostcode,
            'destination_postcode' => $request->destinationPostcode,
            'error_message' => '',
        ]);
    }

    public function recordError(QuoteRequest $request, string $gateway, string $message): void
    {
        $this->insert([
            'vendor_id' => $request->vendorId,
            'gateway' => $gateway,
            'service_id' => '',
            'status' => 'error',
            'amount' => 0,
            'delivery_days' => 0,
            'origin_postcode' => $request->originPostcode,
            'destination_postcode' => $request->destinationPostcode,
            'error_message' => substr($message, 0, 1000),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function summary(int $days = 30): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'correios_seller_quote_usage';
        $since = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        $sql = $wpdb->prepare(
            "SELECT vendor_id, gateway,
                COUNT(*) AS requests,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN status IN ('fallback', 'gateway_fallback') THEN 1 ELSE 0 END) AS fallbacks,
                AVG(CASE WHEN amount > 0 THEN amount ELSE NULL END) AS average_amount,
                MAX(created_at) AS last_request
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY vendor_id, gateway
             ORDER BY requests DESC",
            $since
        );

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insert(array $data): void
    {
        global $wpdb;

        $data['created_at'] = current_time('mysql', true);
        $wpdb->insert(
            $wpdb->prefix . 'correios_seller_quote_usage',
            $data,
            ['%d', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s']
        );
    }
}
