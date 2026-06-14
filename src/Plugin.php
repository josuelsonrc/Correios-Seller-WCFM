<?php

declare(strict_types=1);

namespace CorreiosSeller;

use CorreiosSeller\Admin\AdminSettings;
use CorreiosSeller\Frontend\ProductShippingPackageFactory;
use CorreiosSeller\Frontend\ProductShippingSimulator;
use CorreiosSeller\Orders\TrackingSync;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Rest\VendorSettingsController;
use CorreiosSeller\Labels\LabelRepository;
use CorreiosSeller\Labels\MarketplaceLabelService;
use CorreiosSeller\Shipping\PackageSplitter;
use CorreiosSeller\Shipping\ShippingMethodRegistrar;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\ProductVendorResolver;
use CorreiosSeller\WCFM\OrderLabelActions;
use CorreiosSeller\WCFM\VendorStoreShippingRatesBridge;
use CorreiosSeller\WCFM\VendorSettingsPage;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        add_option('correios_seller_settings', [
            'credential_mode' => 'admin',
            'logistics_responsibility' => 'marketplace',
            'api_environment' => 'production',
            'labels_enabled' => 'yes',
            'label_layout' => 'PADRAO',
            'product_simulator_enabled' => 'yes',
            'product_simulator_cache_ttl' => 300,
            'enabled_services' => ['03220', '03298', '04227'],
            'cache_ttl' => 900,
            'fallback_enabled' => 'yes',
        ]);
        add_option('correios_seller_credential_mode', 'admin');
        add_option('correios_seller_logistics_responsibility', 'marketplace');
        add_option('correios_seller_api_environment', 'production');
        add_option('correios_seller_labels_enabled', 'yes');
        add_option('correios_seller_label_layout', 'PADRAO');
        add_option('correios_seller_product_simulator_enabled', 'yes');
        add_option('correios_seller_product_simulator_cache_ttl', 300);
        add_option('correios_seller_enabled_services_csv', '03220,03298,04227');
        add_option('correios_seller_cache_ttl', 900);
        add_option('correios_seller_fallback_enabled', 'yes');
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('correios_seller_tracking_sync');
    }

    public function boot(): void
    {
        load_plugin_textdomain('correios-seller', false, dirname(plugin_basename(CORREIOS_SELLER_FILE)) . '/languages');

        $logger = new Logger();
        $vendorRepository = new VendorSettingsRepository();
        $productVendorResolver = new ProductVendorResolver();
        $productShippingPackageFactory = new ProductShippingPackageFactory($productVendorResolver);
        $labelRepository = new LabelRepository();
        $labelService = new MarketplaceLabelService($vendorRepository, $labelRepository, $logger);

        (new ShippingMethodRegistrar())->register();
        (new PackageSplitter($vendorRepository, $productVendorResolver))->register();
        (new VendorStoreShippingRatesBridge($logger))->register();
        (new AdminSettings())->register();
        (new ProductShippingSimulator($productShippingPackageFactory, $logger))->register();
        (new VendorSettingsPage($vendorRepository))->register();
        (new OrderLabelActions($labelService, $labelRepository))->register();
        (new VendorSettingsController($vendorRepository))->register();
        (new TrackingSync($logger))->register();
    }
}
