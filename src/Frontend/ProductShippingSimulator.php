<?php

declare(strict_types=1);

namespace CorreiosSeller\Frontend;

use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;

final class ProductShippingSimulator
{
    private const ACTION = 'frete_marketplace_product_shipping_rates';
    private const LEGACY_ACTION = 'correios_seller_product_shipping_rates';
    private const NONCE_ACTION = 'frete_marketplace_product_shipping';
    private const LEGACY_NONCE_ACTION = 'correios_seller_product_shipping';

    private bool $rendered = false;

    public function __construct(
        private ProductShippingPackageFactory $packageFactory,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets'], 30);
        add_action('woocommerce_after_add_to_cart_form', [$this, 'render']);
        add_action('woocommerce_single_product_summary', [$this, 'render'], 35);
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handleAjax']);
        add_action('wp_ajax_' . self::LEGACY_ACTION, [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_' . self::LEGACY_ACTION, [$this, 'handleAjax']);
    }

    public function enqueueAssets(): void
    {
        if (! function_exists('is_product') || ! is_product() || Options::get('product_simulator_enabled', 'yes') !== 'yes') {
            return;
        }

        $config = $this->frontendConfig();
        $nativeScriptHandle = $this->nativeThemeScriptHandle();
        if ($nativeScriptHandle !== '' && wp_script_is($nativeScriptHandle, 'enqueued')) {
            wp_localize_script($nativeScriptHandle, 'FreteMarketplaceProductShipping', $config);
            wp_localize_script($nativeScriptHandle, 'CorreiosSellerProductShipping', $config);

            return;
        }

        $scriptPath = FRETE_MARKETPLACE_PATH . 'assets/js/product-shipping-simulator.js';
        $stylePath = FRETE_MARKETPLACE_PATH . 'assets/css/product-shipping-simulator.css';

        wp_enqueue_style(
            'correios-seller-product-shipping',
            FRETE_MARKETPLACE_URL . 'assets/css/product-shipping-simulator.css',
            [],
            file_exists($stylePath) ? (string) filemtime($stylePath) : FRETE_MARKETPLACE_VERSION
        );

        wp_enqueue_script(
            'correios-seller-product-shipping',
            FRETE_MARKETPLACE_URL . 'assets/js/product-shipping-simulator.js',
            [],
            file_exists($scriptPath) ? (string) filemtime($scriptPath) : FRETE_MARKETPLACE_VERSION,
            true
        );

        wp_localize_script('correios-seller-product-shipping', 'FreteMarketplaceProductShipping', $config);
        wp_localize_script('correios-seller-product-shipping', 'CorreiosSellerProductShipping', $config);
    }

    public function render(): void
    {
        if ($this->rendered || $this->nativeThemeScriptHandle() !== '') {
            return;
        }

        if (Options::get('product_simulator_enabled', 'yes') !== 'yes') {
            return;
        }

        global $product;
        if (! $product instanceof \WC_Product || ! $product->needs_shipping()) {
            return;
        }

        if (! in_array($product->get_type(), ['simple', 'variable', 'variation'], true)) {
            return;
        }

        $this->rendered = true;

        echo '<div class="correios-seller-product-shipping" data-product-id="' . esc_attr((string) $product->get_id()) . '">';
        echo '<form class="correios-seller-product-shipping__form">';
        echo '<label class="correios-seller-product-shipping__label" for="correios-seller-product-shipping-postcode">';
        echo esc_html__('Calcule o frete', 'correios-seller');
        echo '</label>';
        echo '<div class="correios-seller-product-shipping__row">';
        echo '<input id="correios-seller-product-shipping-postcode" class="correios-seller-product-shipping__postcode" type="text" inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000" />';
        echo '<button class="correios-seller-product-shipping__button" type="submit">';
        echo esc_html__('Calcular', 'correios-seller');
        echo '</button>';
        echo '</div>';
        echo '</form>';
        echo '<div class="correios-seller-product-shipping__result" aria-live="polite"></div>';
        echo '</div>';
    }

    public function handleAjax(): void
    {
        if (
            ! check_ajax_referer(self::NONCE_ACTION, 'nonce', false)
            && ! check_ajax_referer(self::LEGACY_NONCE_ACTION, 'nonce', false)
        ) {
            wp_send_json_error(['message' => __('Sessao expirada. Recarregue a pagina.', 'correios-seller')], 403);
        }

        $productId = absint($_POST['product_id'] ?? 0);
        $variationId = absint($_POST['variation_id'] ?? 0);
        $quantity = max(1, absint($_POST['quantity'] ?? 1));
        $postcode = preg_replace('/\D+/', '', (string) ($_POST['postcode'] ?? ''));

        if ($postcode === '' || strlen($postcode) !== 8) {
            wp_send_json_error(['message' => __('Informe um CEP valido.', 'correios-seller')], 400);
        }

        try {
            $this->ensureCartLoaded();
            $product = $this->productForRequest($productId, $variationId);
            $rates = $this->ratesForProduct($product, $quantity, $postcode);

            wp_send_json_success([
                'rates' => $rates,
                'postcode' => $this->formatPostcode($postcode),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Falha ao simular frete na pagina de produto.', [
                'product_id' => $productId,
                'variation_id' => $variationId,
                'postcode' => $postcode,
                'error' => $exception->getMessage(),
            ]);

            wp_send_json_error(['message' => $exception->getMessage()], 400);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ratesForProduct(\WC_Product $product, int $quantity, string $postcode): array
    {
        if (! $product->needs_shipping()) {
            throw new \RuntimeException('Este produto nao exige frete.');
        }

        $package = $this->packageFactory->build($product, $quantity, $postcode);
        $package = (array) apply_filters('frete_marketplace_product_shipping_package', $package, $product, $quantity, $postcode);
        $package = (array) apply_filters('correios_seller_product_shipping_package', $package, $product, $quantity, $postcode);
        $cacheKey = $this->cacheKey($package, $product, $quantity, $postcode);

        if (get_option('woocommerce_shipping_debug_mode', 'no') !== 'yes') {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $calculated = WC()->shipping()->calculate_shipping_for_package(
            $package,
            'frete_marketplace_product_' . md5($cacheKey)
        );

        $rates = is_array($calculated) && isset($calculated['rates']) && is_array($calculated['rates'])
            ? $this->normalizeRates($calculated['rates'])
            : [];

        $rates = (array) apply_filters('frete_marketplace_product_shipping_rates', $rates, $package, $product, $quantity, $postcode);
        $rates = (array) apply_filters('correios_seller_product_shipping_rates', $rates, $package, $product, $quantity, $postcode);
        set_transient($cacheKey, $rates, (int) Options::get('product_simulator_cache_ttl', 300));

        return $rates;
    }

    /**
     * @param array<string,\WC_Shipping_Rate> $rates
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRates(array $rates): array
    {
        $normalized = [];

        foreach ($rates as $rate) {
            if (! $rate instanceof \WC_Shipping_Rate) {
                continue;
            }

            $meta = $rate->get_meta_data();
            $displayCost = WC()->cart && WC()->cart->display_prices_including_tax()
                ? (float) $rate->get_cost() + (float) $rate->get_shipping_tax()
                : (float) $rate->get_cost();
            $deliveryDays = isset($meta['delivery_days']) ? (int) $meta['delivery_days'] : 0;

            $normalized[] = [
                'id' => $rate->get_id(),
                'method_id' => $rate->get_method_id(),
                'instance_id' => $rate->get_instance_id(),
                'label' => $rate->get_label(),
                'cost' => $displayCost,
                'cost_html' => $displayCost > 0 ? wc_price($displayCost) : esc_html__('Gratis', 'correios-seller'),
                'delivery_days' => $deliveryDays,
                'delivery_label' => $deliveryDays > 0 ? sprintf(_n('%d dia util', '%d dias uteis', $deliveryDays, 'correios-seller'), $deliveryDays) : '',
                'description' => isset($meta['description']) ? wp_strip_all_tags((string) $meta['description']) : '',
            ];
        }

        return $normalized;
    }

    private function productForRequest(int $productId, int $variationId): \WC_Product
    {
        if ($productId <= 0) {
            throw new \RuntimeException('Produto invalido.');
        }

        $parent = wc_get_product($productId);
        if (! $parent instanceof \WC_Product) {
            throw new \RuntimeException('Produto invalido.');
        }

        if ($parent->is_type('variable')) {
            if ($variationId <= 0) {
                throw new \RuntimeException('Selecione as opcoes do produto para calcular o frete.');
            }

            $variation = wc_get_product($variationId);
            if (! $variation instanceof \WC_Product || ! $variation->is_type('variation') || (int) $variation->get_parent_id() !== $productId) {
                throw new \RuntimeException('Variacao invalida.');
            }

            return $variation;
        }

        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            if ($variation instanceof \WC_Product && $variation->is_type('variation')) {
                return $variation;
            }
        }

        return $parent;
    }

    private function ensureCartLoaded(): void
    {
        if (function_exists('wc_load_cart') && (! WC()->cart || ! WC()->session || ! WC()->customer)) {
            wc_load_cart();
        }
    }

    private function cacheKey(array $package, \WC_Product $product, int $quantity, string $postcode): string
    {
        $packageForHash = $package;
        foreach (($packageForHash['contents'] ?? []) as $key => $item) {
            unset($packageForHash['contents'][$key]['data']);
        }

        $payload = [
            'plugin_version' => defined('FRETE_MARKETPLACE_VERSION') ? FRETE_MARKETPLACE_VERSION : '',
            'package' => $packageForHash,
            'product_id' => $product->get_id(),
            'quantity' => $quantity,
            'postcode' => $postcode,
            'shipping_version' => class_exists('\WC_Cache_Helper') ? \WC_Cache_Helper::get_transient_version('shipping') : '',
            'coupons' => WC()->cart ? WC()->cart->get_applied_coupons() : [],
        ];

        return 'frete_marketplace_product_rates_' . md5(wp_json_encode($payload));
    }

    private function formatPostcode(string $postcode): string
    {
        return substr($postcode, 0, 5) . '-' . substr($postcode, 5);
    }

    /**
     * @return array<string,mixed>
     */
    private function frontendConfig(): array
    {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => [
                'loading' => __('Calculando...', 'correios-seller'),
                'invalidPostcode' => __('Informe um CEP valido.', 'correios-seller'),
                'chooseVariation' => __('Selecione as opcoes do produto para calcular o frete.', 'correios-seller'),
                'empty' => __('Nenhum metodo de entrega disponivel para este CEP.', 'correios-seller'),
                'error' => __('Nao foi possivel calcular o frete agora.', 'correios-seller'),
            ],
        ];
    }

    private function nativeThemeScriptHandle(): string
    {
        $support = get_theme_support('frete-marketplace-product-shipping');
        if (! is_array($support)) {
            $support = get_theme_support('correios-seller-product-shipping');
        }
        $handle = is_array($support) && isset($support[0]['script_handle'])
            ? (string) $support[0]['script_handle']
            : '';

        $handle = (string) apply_filters('frete_marketplace_product_simulator_native_script_handle', $handle);

        return sanitize_key((string) apply_filters('correios_seller_product_simulator_native_script_handle', $handle));
    }
}
