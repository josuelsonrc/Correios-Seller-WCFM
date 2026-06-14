<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Correios\CorreiosClient;
use CorreiosSeller\Correios\CredentialsResolver;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use CorreiosSeller\Support\ProductVendorResolver;

final class WCFMCorreiosShippingMethod extends \WC_Shipping_Method
{
    private VendorSettingsRepository $vendorSettings;
    private PackageBuilder $packageBuilder;
    private CredentialsResolver $credentialsResolver;
    private QuoteService $quoteService;
    private Logger $logger;
    private ProductVendorResolver $productVendorResolver;

    public function __construct(int $instanceId = 0)
    {
        $this->id = 'correios_seller';
        $this->instance_id = absint($instanceId);
        $this->method_title = __('Correios por Seller', 'correios-seller');
        $this->method_description = __('Calcula PAC, SEDEX, Mini Envios e outros servicos dos Correios pela origem individual do vendedor.', 'correios-seller');
        $this->supports = ['shipping-zones', 'instance-settings'];

        $this->init();

        $cache = new Cache();
        $this->logger = new Logger();
        $this->vendorSettings = new VendorSettingsRepository();
        $this->packageBuilder = new PackageBuilder();
        $this->credentialsResolver = new CredentialsResolver();
        $this->quoteService = new QuoteService(new CorreiosClient($cache, $this->logger), $cache);
        $this->productVendorResolver = new ProductVendorResolver();
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Correios', 'correios-seller'));

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'enabled' => [
                'title' => __('Ativar', 'correios-seller'),
                'type' => 'checkbox',
                'label' => __('Ativar frete Correios por seller nesta zona', 'correios-seller'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Titulo', 'correios-seller'),
                'type' => 'text',
                'default' => __('Correios', 'correios-seller'),
            ],
        ];
    }

    public function calculate_shipping($package = []): void
    {
        if ($this->enabled !== 'yes') {
            return;
        }

        $vendorId = (int) ($package['seller_id'] ?? $package['vendor_id'] ?? $this->detectVendorId($package));
        if ($vendorId <= 0) {
            $this->logger->error('Pacote sem vendedor identificado.', ['package' => array_keys((array) $package)]);
            return;
        }

        $destinationPostcode = preg_replace('/\D+/', '', (string) ($package['destination']['postcode'] ?? ''));
        if ($destinationPostcode === '') {
            return;
        }

        $settings = $this->vendorSettings->get($vendorId);
        if (($settings['enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        $originPostcode = (string) ($settings['origin_postcode'] ?? '');
        if ($originPostcode === '') {
            $this->logger->error('Seller sem CEP de origem.', ['vendor_id' => $vendorId]);
            return;
        }

        $credentials = $this->credentialsResolver->resolve($settings);
        if (! $credentials->isConfigured()) {
            $this->logger->error('Credenciais Correios ausentes.', ['vendor_id' => $vendorId]);
            return;
        }

        $shipmentPackage = $this->packageBuilder->build((array) $package, $settings);
        $handlingDays = (int) ($settings['handling_days'] ?? 0);
        $services = $this->servicesForVendor($settings);

        foreach ($services as $serviceCode) {
            try {
                $quote = $this->quoteService->quote(
                    $credentials,
                    $serviceCode,
                    $originPostcode,
                    $destinationPostcode,
                    $shipmentPackage,
                    $handlingDays
                );

                if ((float) $quote['amount'] <= 0) {
                    continue;
                }

                $this->add_rate([
                    'id' => $this->id . ':' . $vendorId . ':' . $serviceCode,
                    'label' => $this->rateLabel($serviceCode, (int) $quote['delivery_days'], (bool) $quote['fallback']),
                    'cost' => (float) $quote['amount'],
                    'package' => $package,
                    'meta_data' => [
                        'vendor_id' => $vendorId,
                        'correios_service' => $serviceCode,
                        'delivery_days' => (int) $quote['delivery_days'],
                        'origin_postcode' => $originPostcode,
                    ],
                ]);
            } catch (\Throwable $exception) {
                $this->logger->error('Falha ao cotar frete Correios.', [
                    'vendor_id' => $vendorId,
                    'service' => $serviceCode,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function detectVendorId(array $package): int
    {
        foreach (($package['contents'] ?? []) as $item) {
            $product = $item['data'] ?? null;
            if ($product && is_a($product, 'WC_Product')) {
                return $this->productVendorResolver->resolveFromProduct($product);
            }
        }

        return 0;
    }

    private function servicesForVendor(array $settings): array
    {
        $vendorServices = (array) ($settings['enabled_services'] ?? []);

        return $vendorServices !== [] ? $vendorServices : Options::enabledServices();
    }

    private function rateLabel(string $serviceCode, int $deliveryDays, bool $fallback): string
    {
        $names = [
            '03220' => 'SEDEX',
            '03298' => 'PAC',
            '04227' => 'Mini Envios',
        ];

        $label = $names[$serviceCode] ?? sprintf(__('Correios %s', 'correios-seller'), $serviceCode);
        if ($deliveryDays > 0) {
            $label .= sprintf(__(' - %d dia(s) uteis', 'correios-seller'), $deliveryDays);
        }

        if ($fallback) {
            $label .= __(' - cotacao em cache', 'correios-seller');
        }

        return $label;
    }
}
