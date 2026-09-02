<?php
/**
 * Plugin Name: Frete Marketplace para WCFM
 * Plugin URI: https://example.com/frete-marketplace-wcfm
 * Description: Frete por vendedor para WooCommerce/WCFM com Melhor Envio, contas centralizadas ou individuais e carrinho multivendedor.
 * Version: 1.2.2
 * Author: Frete Marketplace
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

define('FRETE_MARKETPLACE_VERSION', '1.2.2');
define('FRETE_MARKETPLACE_FILE', __FILE__);
define('FRETE_MARKETPLACE_PATH', plugin_dir_path(__FILE__));
define('FRETE_MARKETPLACE_URL', plugin_dir_url(__FILE__));

// Legacy constants keep existing themes and shipping zones from breaking.
define('CORREIOS_SELLER_VERSION', FRETE_MARKETPLACE_VERSION);
define('CORREIOS_SELLER_FILE', FRETE_MARKETPLACE_FILE);
define('CORREIOS_SELLER_PATH', FRETE_MARKETPLACE_PATH);
define('CORREIOS_SELLER_URL', FRETE_MARKETPLACE_URL);

$composerAutoload = FRETE_MARKETPLACE_PATH . 'vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CorreiosSeller\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = FRETE_MARKETPLACE_PATH . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            FRETE_MARKETPLACE_FILE,
            true
        );
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Frete Marketplace para WCFM requer WooCommerce ativo.', 'correios-seller');
            echo '</p></div>';
        });

        return;
    }

    \CorreiosSeller\Plugin::instance()->boot();
});

register_activation_hook(__FILE__, [\CorreiosSeller\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\CorreiosSeller\Plugin::class, 'deactivate']);
