<?php

declare(strict_types=1);

namespace CorreiosSeller;

use CorreiosSeller\Admin\AdminSettings;
use CorreiosSeller\Admin\UsageReportPage;
use CorreiosSeller\Frontend\ProductShippingPackageFactory;
use CorreiosSeller\Frontend\ProductShippingSimulator;
use CorreiosSeller\Labels\LabelRepository;
use CorreiosSeller\MelhorEnvio\MelhorEnvioShipmentService;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Rest\VendorSettingsController;
use CorreiosSeller\MelhorEnvio\MelhorEnvioOAuthController;
use CorreiosSeller\MelhorEnvio\MelhorEnvioOAuthService;
use CorreiosSeller\Repository\QuoteUsageRepository;
use CorreiosSeller\Shipping\PackageSplitter;
use CorreiosSeller\Shipping\ShippingMethodRegistrar;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use CorreiosSeller\Support\ProductVendorResolver;
use CorreiosSeller\WCFM\OrderLabelActions;
use CorreiosSeller\WCFM\VendorStoreShippingRatesBridge;
use CorreiosSeller\WCFM\VendorSettingsPage;

final class Plugin
{
    private const MELHOR_ENVIO_REQUIRED_SCOPES = [
        'shipping-calculate',
        'shipping-companies',
        'cart-read',
        'cart-write',
        'shipping-checkout',
        'shipping-generate',
        'shipping-print',
        'shipping-tracking',
        'orders-read',
    ];

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
            'melhor_envio_account_mode' => 'admin',
            'product_simulator_enabled' => 'yes',
            'product_simulator_cache_ttl' => 300,
            'cache_ttl' => 900,
            'fallback_enabled' => 'yes',
            'melhor_envio_environment' => 'production',
            'melhor_envio_redirect_uri' => '',
            'melhor_envio_enabled_services' => Options::defaultMelhorEnvioServiceIds(),
        ]);
        add_option('correios_seller_melhor_envio_account_mode', 'admin');
        add_option('correios_seller_product_simulator_enabled', 'yes');
        add_option('correios_seller_product_simulator_cache_ttl', 300);
        add_option('correios_seller_cache_ttl', 900);
        add_option('correios_seller_fallback_enabled', 'yes');
        add_option('correios_seller_melhor_envio_environment', 'production');
        add_option('correios_seller_melhor_envio_redirect_uri', '');
        add_option('correios_seller_melhor_envio_enabled_services_csv', Options::defaultMelhorEnvioServicesCsv());
        add_option('correios_seller_melhor_envio_scopes', self::defaultMelhorEnvioScopes());
        add_option('correios_seller_melhor_envio_user_agent_email', (string) get_option('admin_email'));

        QuoteUsageRepository::install();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('correios_seller_tracking_sync');
    }

    public function boot(): void
    {
        load_plugin_textdomain('correios-seller', false, dirname(plugin_basename(FRETE_MARKETPLACE_FILE)) . '/languages');

        $this->migrateMelhorEnvioOnlyOptions();

        $logger = new Logger();
        $vendorRepository = new VendorSettingsRepository();
        $productVendorResolver = new ProductVendorResolver();
        $productShippingPackageFactory = new ProductShippingPackageFactory($productVendorResolver);
        $quoteUsageRepository = new QuoteUsageRepository();
        $labelRepository = new LabelRepository();
        $melhorEnvioShipmentService = new MelhorEnvioShipmentService($vendorRepository, $labelRepository, $logger);

        if (get_option('correios_seller_quote_schema_version') !== QuoteUsageRepository::SCHEMA_VERSION) {
            QuoteUsageRepository::install();
        }

        (new ShippingMethodRegistrar())->register();
        (new PackageSplitter($vendorRepository, $productVendorResolver))->register();
        (new VendorStoreShippingRatesBridge($logger))->register();
        (new AdminSettings())->register();
        (new UsageReportPage($quoteUsageRepository))->register();
        (new ProductShippingSimulator($productShippingPackageFactory, $logger))->register();
        (new VendorSettingsPage($vendorRepository))->register();
        (new MelhorEnvioOAuthController(new MelhorEnvioOAuthService($logger), $vendorRepository, $logger))->register();
        (new OrderLabelActions($melhorEnvioShipmentService, $labelRepository))->register();
        (new VendorSettingsController($vendorRepository))->register();
    }

    private function migrateMelhorEnvioOnlyOptions(): void
    {
        $mode = get_option('correios_seller_melhor_envio_account_mode', null);
        if (! in_array($mode, ['admin', 'seller'], true)) {
            $legacyMode = (string) get_option('correios_seller_credential_mode', 'admin');
            $legacyResponsibility = (string) get_option('correios_seller_logistics_responsibility', 'marketplace');
            $mode = ($legacyMode === 'vendor' && $legacyResponsibility !== 'marketplace') ? 'seller' : 'admin';
            update_option('correios_seller_melhor_envio_account_mode', $mode, false);
        }

        $this->ensureMelhorEnvioScopes();
        $this->ensureMelhorEnvioDefaultServices();
        add_option('correios_seller_melhor_envio_redirect_uri', '');

        $settings = get_option('correios_seller_settings', []);
        if (is_array($settings)) {
            foreach ([
                'credential_mode',
                'logistics_responsibility',
                'api_environment',
                'labels_enabled',
                'label_layout',
                'enabled_services',
                'shipping_gateway',
                'fallback_gateway',
            ] as $key) {
                unset($settings[$key]);
            }

            $settings['melhor_envio_account_mode'] = $mode;
            $settings['melhor_envio_environment'] = $settings['melhor_envio_environment'] ?? 'production';
            $settings['melhor_envio_redirect_uri'] = $settings['melhor_envio_redirect_uri'] ?? '';
            if (empty($settings['melhor_envio_enabled_services'])) {
                $settings['melhor_envio_enabled_services'] = Options::defaultMelhorEnvioServiceIds();
            }
            $settings['product_simulator_enabled'] = $settings['product_simulator_enabled'] ?? 'yes';
            $settings['product_simulator_cache_ttl'] = $settings['product_simulator_cache_ttl'] ?? 300;
            $settings['cache_ttl'] = $settings['cache_ttl'] ?? 900;
            $settings['fallback_enabled'] = $settings['fallback_enabled'] ?? 'yes';
            update_option('correios_seller_settings', $settings, false);
        }

        foreach ([
            'correios_seller_credential_mode',
            'correios_seller_logistics_responsibility',
            'correios_seller_api_environment',
            'correios_seller_admin_username',
            'correios_seller_admin_password',
            'correios_seller_admin_posting_card',
            'correios_seller_admin_code',
            'correios_seller_enabled_services_csv',
            'correios_seller_shipping_gateway',
            'correios_seller_fallback_gateway',
            'correios_seller_labels_enabled',
            'correios_seller_label_layout',
        ] as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook('correios_seller_tracking_sync');
    }

    private function ensureMelhorEnvioDefaultServices(): void
    {
        $current = get_option('correios_seller_melhor_envio_enabled_services_csv', null);
        if ($current === null || trim((string) $current) === '') {
            update_option('correios_seller_melhor_envio_enabled_services_csv', Options::defaultMelhorEnvioServicesCsv(), false);
        }
    }

    private function ensureMelhorEnvioScopes(): void
    {
        $current = (string) get_option('correios_seller_melhor_envio_scopes', '');
        $scopes = array_values(array_filter(preg_split('/\s+/', trim($current)) ?: []));
        $merged = array_values(array_unique(array_merge($scopes, self::MELHOR_ENVIO_REQUIRED_SCOPES)));
        $value = implode(' ', $merged);

        if ($current !== $value) {
            update_option('correios_seller_melhor_envio_scopes', $value, false);
        }
    }

    private static function defaultMelhorEnvioScopes(): string
    {
        return implode(' ', self::MELHOR_ENVIO_REQUIRED_SCOPES);
    }
}
