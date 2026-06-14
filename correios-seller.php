<?php
/**
 * Plugin Name: Correios Seller Shipping for WCFM
 * Plugin URI: https://example.com/correios-seller-shipping
 * Description: Frete Correios por vendedor para marketplaces WooCommerce/WCFM, com suporte a origem individual, conta centralizada e API oficial dos Correios.
 * Version: 0.2.0
 * Author: Correios Seller
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * Text Domain: correios-seller
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('CORREIOS_SELLER_VERSION', '0.2.0');
define('CORREIOS_SELLER_FILE', __FILE__);
define('CORREIOS_SELLER_PATH', plugin_dir_path(__FILE__));
define('CORREIOS_SELLER_URL', plugin_dir_url(__FILE__));

$composerAutoload = CORREIOS_SELLER_PATH . 'vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CorreiosSeller\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = CORREIOS_SELLER_PATH . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            CORREIOS_SELLER_FILE,
            true
        );
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Correios Seller Shipping requer WooCommerce ativo.', 'correios-seller');
            echo '</p></div>';
        });

        return;
    }

    \CorreiosSeller\Plugin::instance()->boot();
});

register_activation_hook(__FILE__, [\CorreiosSeller\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\CorreiosSeller\Plugin::class, 'deactivate']);
