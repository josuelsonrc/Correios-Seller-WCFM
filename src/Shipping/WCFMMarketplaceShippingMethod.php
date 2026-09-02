<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\ProductVendorResolver;
use CorreiosSeller\Support\VendorOriginResolver;

final class WCFMMarketplaceShippingMethod extends \WC_Shipping_Method
{
    public const METHOD_ID = 'correios_seller';

    private VendorSettingsRepository $vendorSettings;
    private PackageBuilder $packageBuilder;
    private GatewayQuoteService $gatewayQuoteService;
    private Logger $logger;
    private ProductVendorResolver $productVendorResolver;
    private VendorOriginResolver $vendorOriginResolver;

    public function __construct(int $instanceId = 0)
    {
        $this->id = self::METHOD_ID;
        $this->instance_id = absint($instanceId);
        $this->method_title = __('Frete Melhor Envio por Seller', 'correios-seller');
        $this->method_description = __('Calcula frete por vendedor usando Melhor Envio, com conta centralizada do marketplace ou conta individual do seller.', 'correios-seller');
        $this->supports = ['shipping-zones', 'instance-settings'];

        $this->init();

        $cache = new Cache();
        $this->logger = new Logger();
        $this->vendorSettings = new VendorSettingsRepository();
        $this->packageBuilder = new PackageBuilder();
        $this->gatewayQuoteService = GatewayFactory::createQuoteService($this->vendorSettings, $cache, $this->logger);
        $this->productVendorResolver = new ProductVendorResolver();
        $this->vendorOriginResolver = new VendorOriginResolver();
    }

    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Frete Melhor Envio', 'correios-seller'));

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $this->instance_form_fields = [
            'enabled' => [
                'title' => __('Ativar', 'correios-seller'),
                'type' => 'checkbox',
                'label' => __('Ativar frete por seller nesta zona', 'correios-seller'),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Titulo', 'correios-seller'),
                'type' => 'text',
                'default' => __('Frete Melhor Envio', 'correios-seller'),
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

        $originPostcode = $this->vendorOriginResolver->postcode($vendorId, $settings);
        if ($originPostcode === '') {
            $this->logger->error('Seller sem CEP de origem.', ['vendor_id' => $vendorId]);
            return;
        }

        $shipmentPackage = $this->packageBuilder->build((array) $package, $settings);
        $request = new QuoteRequest(
            $vendorId,
            $originPostcode,
            $destinationPostcode,
            $shipmentPackage,
            (int) ($settings['handling_days'] ?? 0)
        );

        foreach ($this->gatewayQuoteService->quote($request, $settings) as $quote) {
            $this->add_rate([
                'id' => implode(':', [$this->id, $vendorId, $quote->gateway, $quote->serviceId]),
                'label' => $this->rateLabel($quote),
                'cost' => $quote->amount,
                'package' => $package,
                'meta_data' => [
                    'vendor_id' => $vendorId,
                    'shipping_gateway' => $quote->gateway,
                    'shipping_carrier' => $quote->carrier,
                    'shipping_service' => $quote->serviceId,
                    'shipping_service_name' => $quote->serviceName,
                    'delivery_days' => $quote->deliveryDays,
                    'origin_postcode' => $originPostcode,
                    'fallback' => $quote->fallback ? 'yes' : 'no',
                ],
            ]);
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

    private function rateLabel(ShippingQuote $quote): string
    {
        $label = trim($quote->carrier . ' - ' . $quote->serviceName, ' -');
        if ($quote->deliveryDays > 0) {
            $label .= sprintf(__(' - %d dia(s) uteis', 'correios-seller'), $quote->deliveryDays);
        }

        if ($quote->fallback) {
            $label .= __(' - contingencia', 'correios-seller');
        }

        return $label;
    }
}
