<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Labels\LabelRepository;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Shipping\PackageBuilder;
use CorreiosSeller\Shipping\ShipmentPackage;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\ProductVendorResolver;
use CorreiosSeller\Support\VendorOriginResolver;

final class MelhorEnvioShipmentService
{
    private MelhorEnvioClient $client;
    private MelhorEnvioTokenResolver $tokenResolver;
    private PackageBuilder $packageBuilder;
    private ProductVendorResolver $productVendorResolver;
    private VendorOriginResolver $vendorOriginResolver;

    public function __construct(
        private VendorSettingsRepository $vendorSettings,
        private LabelRepository $labels,
        private Logger $logger,
        ?MelhorEnvioClient $client = null,
        ?MelhorEnvioTokenResolver $tokenResolver = null
    ) {
        $this->client = $client ?? new MelhorEnvioClient($this->logger);
        $this->tokenResolver = $tokenResolver ?? new MelhorEnvioTokenResolver(
            $this->vendorSettings,
            new MelhorEnvioOAuthService($this->logger),
            $this->logger
        );
        $this->packageBuilder = new PackageBuilder();
        $this->productVendorResolver = new ProductVendorResolver();
        $this->vendorOriginResolver = new VendorOriginResolver();
    }

    /**
     * @return array<string,mixed>
     */
    public function generateForOrder(\WC_Order $order, int $vendorId): array
    {
        if (! $this->orderHasVendorItems($order, $vendorId)) {
            throw new \RuntimeException('Pedido nao pertence ao vendedor informado.');
        }

        if (! $order->is_paid() && ! in_array($order->get_status(), ['processing', 'completed'], true)) {
            throw new \RuntimeException('A etiqueta so pode ser gerada para pedido pago ou em processamento.');
        }

        $shippingItem = $this->shippingItemForVendor($order, $vendorId);
        if (! $shippingItem) {
            throw new \RuntimeException('Metodo de envio Melhor Envio do vendedor nao encontrado no pedido.');
        }

        $settings = $this->vendorSettings->get($vendorId);
        $accessToken = $this->tokenResolver->resolve($settings, $vendorId);
        if ($accessToken === '') {
            throw new \RuntimeException('Conta do Melhor Envio nao conectada.');
        }

        $label = $this->labels->get($order, $vendorId);
        if (($label['status'] ?? '') === 'printed' && ! empty($label['label_url'])) {
            return $label;
        }

        $melhorEnvioOrderId = (string) ($label['melhor_envio_order_id'] ?? '');
        if ($melhorEnvioOrderId === '') {
            $package = $this->packageForVendor($order, $vendorId, $settings);
            $payload = $this->cartPayload($order, $vendorId, $settings, $shippingItem, $package);
            $cartResponse = $this->client->addToCart($accessToken, $payload);
            $melhorEnvioOrderId = $this->extractOrderId($cartResponse);
            if ($melhorEnvioOrderId === '') {
                throw new \RuntimeException('Melhor Envio adicionou o frete ao carrinho sem retornar o ID do envio.');
            }

            $label = array_merge($label, [
                'status' => 'carted',
                'order_id' => $order->get_id(),
                'vendor_id' => $vendorId,
                'melhor_envio_order_id' => $melhorEnvioOrderId,
                'shipping_item_id' => $shippingItem->get_id(),
                'shipping_service' => $this->serviceIdFromShippingItem($shippingItem),
                'shipping_cost' => (float) $shippingItem->get_total(),
                'protocol' => $this->extractProtocol($cartResponse),
                'cart_response' => $cartResponse,
                'created_at' => current_time('mysql'),
            ]);
            $this->labels->save($order, $vendorId, $label);
        }

        if (! $this->isCheckedOut($label)) {
            $checkoutResponse = $this->client->checkout($accessToken, [$melhorEnvioOrderId]);
            $label = array_merge($label, [
                'status' => 'checked_out',
                'checkout_response' => $checkoutResponse,
                'checked_out_at' => current_time('mysql'),
            ]);
            $this->labels->save($order, $vendorId, $label);
        }

        if (! $this->isGenerated($label)) {
            $generateResponse = $this->client->generate($accessToken, [$melhorEnvioOrderId]);
            $label = array_merge($label, [
                'status' => 'generated',
                'generate_response' => $generateResponse,
                'generated_at' => current_time('mysql'),
            ]);
            $trackingCode = $this->extractTrackingCode($generateResponse);
            if ($trackingCode !== '') {
                $label['tracking_code'] = $trackingCode;
            }
            $this->labels->save($order, $vendorId, $label);
        }

        if (empty($label['label_path'])) {
            try {
                $file = $this->client->printFile($accessToken, $melhorEnvioOrderId, 'pdf');
                $saved = $this->savePdf($order, $vendorId, $file['body']);
                $label = array_merge($label, [
                    'status' => 'printed',
                    'label_path' => $saved['path'],
                    'label_url' => $saved['url'],
                    'label_content_type' => $file['content_type'],
                    'printed_at' => current_time('mysql'),
                ]);
                $this->labels->save($order, $vendorId, $label);
            } catch (\Throwable $exception) {
                $label = array_merge($label, [
                    'print_file_error' => $exception->getMessage(),
                ]);
                $this->labels->save($order, $vendorId, $label);
                $this->logger->info('PDF da etiqueta Melhor Envio ainda indisponivel.', [
                    'order_id' => $order->get_id(),
                    'vendor_id' => $vendorId,
                    'melhor_envio_order_id' => $melhorEnvioOrderId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (empty($label['label_url'])) {
            $printResponse = $this->client->print($accessToken, [$melhorEnvioOrderId], 'public');
            $labelUrl = $this->extractPrintUrl($printResponse);
            if ($labelUrl === '') {
                throw new \RuntimeException('Etiqueta gerada, mas o Melhor Envio nao retornou link de impressao.');
            }

            $label = array_merge($label, [
                'status' => 'printed',
                'label_url' => $labelUrl,
                'print_response' => $printResponse,
                'printed_at' => current_time('mysql'),
            ]);
            $this->labels->save($order, $vendorId, $label);
        }

        $this->addGeneratedOrderNote($order, $vendorId, $label);

        return $label;
    }

    /**
     * @return array<int,int>
     */
    public function vendorIdsForOrder(\WC_Order $order): array
    {
        $vendorIds = [];
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $vendorId = $this->vendorIdFromOrderItem($item);
            if ($vendorId > 0) {
                $vendorIds[$vendorId] = $vendorId;
            }
        }

        return array_values($vendorIds);
    }

    public function orderUsesMelhorEnvio(\WC_Order $order, int $vendorId): bool
    {
        return $this->shippingItemForVendor($order, $vendorId) instanceof \WC_Order_Item_Shipping;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function cartPayload(\WC_Order $order, int $vendorId, array $settings, \WC_Order_Item_Shipping $shippingItem, ShipmentPackage $package): array
    {
        $serviceId = $this->serviceIdFromShippingItem($shippingItem);
        if ($serviceId === '') {
            throw new \RuntimeException('Servico Melhor Envio nao identificado no item de frete.');
        }

        $payload = [
            'service' => (int) $serviceId,
            'from' => $this->sender($vendorId, $settings),
            'to' => $this->recipient($order),
            'products' => $this->products($order, $vendorId),
            'volumes' => [$this->volume($package)],
            'options' => $this->options($order, $vendorId, $package),
        ];

        return (array) apply_filters(
            'frete_marketplace_melhor_envio_cart_payload',
            $payload,
            $order,
            $vendorId,
            $shippingItem,
            $package
        );
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function sender(int $vendorId, array $settings): array
    {
        $profile = $this->vendorProfile($vendorId);
        $address = is_array($profile['address'] ?? null) ? $profile['address'] : [];
        $user = get_userdata($vendorId);

        $name = $this->firstScalar($settings['sender_name'] ?? '');
        if ($name === '' && function_exists('wcfm_get_vendor_store_name')) {
            $name = $this->firstScalar(wcfm_get_vendor_store_name($vendorId));
        }
        if ($name === '' && $user) {
            $name = $user->display_name;
        }

        $email = sanitize_email($this->firstScalar($settings['sender_email'] ?? '', $user ? $user->user_email : '', get_option('admin_email')));
        $phone = $this->digits($this->firstScalar($settings['sender_phone'] ?? '', $profile['phone'] ?? '', get_user_meta($vendorId, 'billing_phone', true)));
        $document = $this->digits($this->firstScalar($settings['sender_document'] ?? '', get_user_meta($vendorId, 'billing_cpf', true), get_user_meta($vendorId, 'billing_cnpj', true)));
        $postcode = $this->vendorOriginResolver->postcode($vendorId, $settings);
        $street = $this->firstScalar($settings['sender_street'] ?? '', $address['street_1'] ?? '', $address['address_1'] ?? '', $address['street'] ?? '');
        $city = $this->firstScalar($settings['sender_city'] ?? '', $address['city'] ?? '');
        $state = substr(strtoupper($this->firstScalar($settings['sender_state'] ?? '', $address['state'] ?? '')), 0, 2);

        $this->requireValue($name, 'Nome do remetente ausente.');
        $this->requireValue($email, 'E-mail do remetente ausente.');
        $this->requireValue($phone, 'Telefone do remetente ausente.');
        $this->requireValue($document, 'CPF/CNPJ do remetente ausente nas configuracoes de frete do vendedor.');
        $this->requireValue($postcode, 'CEP de origem do remetente ausente.');
        $this->requireValue($street, 'Logradouro do remetente ausente.');
        $this->requireValue($city, 'Cidade do remetente ausente.');
        $this->requireValue($state, 'UF do remetente ausente.');

        $sender = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $street,
            'complement' => $this->firstScalar($settings['sender_complement'] ?? '', $address['complement'] ?? ''),
            'number' => $this->firstScalar($settings['sender_number'] ?? '', $address['number'] ?? '', $address['numero'] ?? '', 'S/N'),
            'district' => $this->firstScalar($settings['sender_district'] ?? '', $address['neighborhood'] ?? '', $address['bairro'] ?? '', $address['district'] ?? ''),
            'city' => $city,
            'postal_code' => $postcode,
            'state_abbr' => $state,
        ];

        return $this->withDocument($sender, $document);
    }

    /**
     * @return array<string,mixed>
     */
    private function recipient(\WC_Order $order): array
    {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        if ($name === '') {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        $street = $this->firstScalar($order->get_shipping_address_1(), $order->get_billing_address_1());
        $city = $this->firstScalar($order->get_shipping_city(), $order->get_billing_city());
        $state = substr(strtoupper($this->firstScalar($order->get_shipping_state(), $order->get_billing_state())), 0, 2);
        $postcode = $this->normalizePostcode($this->firstScalar($order->get_shipping_postcode(), $order->get_billing_postcode()));
        $phone = $this->digits($this->firstScalar($order->get_billing_phone(), $this->orderMetaAny($order, ['shipping_phone', 'phone'])));
        $email = sanitize_email($this->firstScalar($order->get_billing_email(), get_option('admin_email')));
        $document = $this->digits($this->orderMetaAny($order, [
            'billing_cpf',
            'billing_cnpj',
            'shipping_cpf',
            'shipping_cnpj',
            'cpf',
            'cnpj',
        ]));

        $this->requireValue($name, 'Nome do destinatario ausente.');
        $this->requireValue($email, 'E-mail do destinatario ausente.');
        $this->requireValue($phone, 'Telefone do destinatario ausente.');
        $this->requireValue($document, 'CPF/CNPJ do destinatario ausente no pedido.');
        $this->requireValue($street, 'Logradouro do destinatario ausente.');
        $this->requireValue($city, 'Cidade do destinatario ausente.');
        $this->requireValue($state, 'UF do destinatario ausente.');
        $this->requireValue($postcode, 'CEP do destinatario ausente.');

        $recipient = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $street,
            'complement' => $this->firstScalar($order->get_shipping_address_2(), $order->get_billing_address_2()),
            'number' => $this->firstScalar($this->orderMetaAny($order, ['shipping_number', 'billing_number', 'numero', 'number']), 'S/N'),
            'district' => $this->orderMetaAny($order, ['shipping_neighborhood', 'billing_neighborhood', 'shipping_bairro', 'billing_bairro', 'neighborhood', 'bairro']),
            'city' => $city,
            'postal_code' => $postcode,
            'country_id' => $this->firstScalar($order->get_shipping_country(), $order->get_billing_country(), 'BR'),
            'state_abbr' => $state,
        ];

        return $this->withDocument($recipient, $document);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function products(\WC_Order $order, int $vendorId): array
    {
        $products = [];
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product || $this->vendorIdFromOrderItem($item) !== $vendorId) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            $unitaryValue = $quantity > 0 ? ((float) $item->get_total() / $quantity) : (float) $item->get_total();
            $name = $item->get_name();
            $products[] = [
                'name' => function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255),
                'quantity' => $quantity,
                'unitary_value' => round(max(0.01, $unitaryValue), 2),
            ];
        }

        if ($products === []) {
            throw new \RuntimeException('Nenhum produto do vendedor encontrado para declarar na etiqueta.');
        }

        return $products;
    }

    /**
     * @return array<string,mixed>
     */
    private function volume(ShipmentPackage $package): array
    {
        return [
            'height' => max(2, round($package->heightCm, 2)),
            'width' => max(11, round($package->widthCm, 2)),
            'length' => max(16, round($package->lengthCm, 2)),
            'weight' => max(0.01, round($package->weightKg, 3)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function options(\WC_Order $order, int $vendorId, ShipmentPackage $package): array
    {
        $options = [
            'platform' => get_bloginfo('name') ?: 'Frete Marketplace',
            'reminder' => sprintf('Pedido WooCommerce #%s - seller #%d', $order->get_order_number(), $vendorId),
            'insurance_value' => round(max(0.01, $package->declaredValue), 2),
            'receipt' => false,
            'own_hand' => false,
            'reverse' => false,
            'tags' => [[
                'tag' => sprintf('Pedido #%s / Seller #%d', $order->get_order_number(), $vendorId),
                'url' => $this->orderAdminUrl($order),
            ]],
        ];

        $invoiceKey = $this->orderMetaAny($order, ['nfe_key', 'invoice_key', 'billing_nfe_key', 'chave_nfe']);
        if ($invoiceKey !== '') {
            $options['invoice'] = ['key' => preg_replace('/\D+/', '', $invoiceKey)];
        }

        $dceKey = $this->orderMetaAny($order, ['dce_key', 'melhor_envio_dce_key']);
        if ($dceKey !== '') {
            $options['dce'] = ['key' => preg_replace('/\D+/', '', $dceKey)];
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function packageForVendor(\WC_Order $order, int $vendorId, array $settings): ShipmentPackage
    {
        $contents = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (! $item instanceof \WC_Order_Item_Product || $this->vendorIdFromOrderItem($item) !== $vendorId) {
                continue;
            }

            $product = $item->get_product();
            if (! $product) {
                continue;
            }

            $contents[$itemId] = [
                'data' => $product,
                'quantity' => $item->get_quantity(),
                'line_total' => (float) $item->get_total(),
            ];
        }

        return $this->packageBuilder->build(['contents' => $contents], $settings);
    }

    private function shippingItemForVendor(\WC_Order $order, int $vendorId): ?\WC_Order_Item_Shipping
    {
        foreach ($order->get_shipping_methods() as $item) {
            if (! $item instanceof \WC_Order_Item_Shipping) {
                continue;
            }

            $methodId = (string) $item->get_method_id();
            if (! str_starts_with($methodId, 'correios_seller')) {
                continue;
            }

            $gateway = (string) $item->get_meta('shipping_gateway');
            if ($gateway !== '' && $gateway !== 'melhor_envio') {
                continue;
            }

            $itemVendorId = $this->vendorIdFromShippingItem($item);
            if ($itemVendorId === $vendorId) {
                return $item;
            }
        }

        return null;
    }

    private function serviceIdFromShippingItem(\WC_Order_Item_Shipping $item): string
    {
        $serviceId = (string) $item->get_meta('shipping_service');
        if ($serviceId !== '') {
            return preg_replace('/\D+/', '', $serviceId) ?: '';
        }

        $parts = explode(':', (string) $item->get_method_id());

        return preg_replace('/\D+/', '', (string) end($parts)) ?: '';
    }

    private function vendorIdFromShippingItem(\WC_Order_Item_Shipping $item): int
    {
        foreach (['vendor_id', '_frete_marketplace_vendor_id', '_correios_seller_vendor_id'] as $key) {
            $vendorId = (int) $item->get_meta($key);
            if ($vendorId > 0) {
                return $vendorId;
            }
        }

        $parts = explode(':', (string) $item->get_method_id());
        if (count($parts) >= 2) {
            return absint($parts[1]);
        }

        return 0;
    }

    private function orderHasVendorItems(\WC_Order $order, int $vendorId): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && $this->vendorIdFromOrderItem($item) === $vendorId) {
                return true;
            }
        }

        return false;
    }

    private function vendorIdFromOrderItem(\WC_Order_Item_Product $item): int
    {
        $product = $item->get_product();
        if (! $product) {
            return 0;
        }

        return $this->productVendorResolver->resolveFromProduct($product);
    }

    /**
     * @return array<string,mixed>
     */
    private function vendorProfile(int $vendorId): array
    {
        $profile = get_user_meta($vendorId, 'wcfmmp_profile_settings', true);
        if (! is_array($profile)) {
            $profile = get_user_meta($vendorId, '_wcfm_store_settings', true);
        }

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function withDocument(array $data, string $document): array
    {
        if (strlen($document) > 11) {
            $data['company_document'] = $document;

            return $data;
        }

        $data['document'] = $document;

        return $data;
    }

    private function isCheckedOut(array $label): bool
    {
        return ! empty($label['checked_out_at'])
            || in_array((string) ($label['status'] ?? ''), ['checked_out', 'generated', 'printed'], true);
    }

    private function isGenerated(array $label): bool
    {
        return ! empty($label['generated_at'])
            || in_array((string) ($label['status'] ?? ''), ['generated', 'printed'], true);
    }

    private function extractOrderId(array $response): string
    {
        return $this->findScalarByKeys($response, ['id', 'order_id', 'orderid', 'shipment_id']);
    }

    private function extractProtocol(array $response): string
    {
        return $this->findScalarByKeys($response, ['protocol', 'protocolo']);
    }

    private function extractTrackingCode(array $response): string
    {
        return $this->findScalarByKeys($response, ['tracking', 'tracking_code', 'tracking_number']);
    }

    private function extractPrintUrl(array $response): string
    {
        return $this->findUrl($response);
    }

    /**
     * @param array<mixed> $data
     * @param array<int,string> $keys
     */
    private function findScalarByKeys(array $data, array $keys): string
    {
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $keys, true) && is_scalar($value)) {
                return sanitize_text_field((string) $value);
            }

            if (is_array($value)) {
                $found = $this->findScalarByKeys($value, $keys);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    private function findUrl(mixed $value): string
    {
        if (is_scalar($value)) {
            $candidate = (string) $value;

            return filter_var($candidate, FILTER_VALIDATE_URL) ? esc_url_raw($candidate) : '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $found = $this->findUrl($item);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    /**
     * @return array{path:string,url:string}
     */
    private function savePdf(\WC_Order $order, int $vendorId, string $bytes): array
    {
        if ($bytes === '' || substr($bytes, 0, 4) !== '%PDF') {
            throw new \RuntimeException('Arquivo PDF da etiqueta retornado em formato invalido.');
        }

        $uploads = wp_upload_dir();
        if (! empty($uploads['error'])) {
            throw new \RuntimeException((string) $uploads['error']);
        }

        $directory = trailingslashit($uploads['basedir']) . 'frete-marketplace-labels';
        if (! wp_mkdir_p($directory)) {
            throw new \RuntimeException('Nao foi possivel criar o diretorio de etiquetas.');
        }

        $filename = wp_unique_filename(
            $directory,
            sprintf('order-%d-vendor-%d-%s.pdf', $order->get_id(), $vendorId, strtolower(wp_generate_password(12, false, false)))
        );
        $path = trailingslashit($directory) . $filename;
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Nao foi possivel salvar o PDF da etiqueta.');
        }

        return [
            'path' => $path,
            'url' => trailingslashit($uploads['baseurl']) . 'frete-marketplace-labels/' . $filename,
        ];
    }

    private function addGeneratedOrderNote(\WC_Order $order, int $vendorId, array $label): void
    {
        if (! empty($label['noted_at'])) {
            return;
        }

        $tracking = (string) ($label['tracking_code'] ?? '');
        if ($tracking !== '') {
            $order->add_order_note(sprintf('Etiqueta Melhor Envio gerada para vendedor #%d. Rastreio: %s', $vendorId, $tracking));
        } else {
            $protocol = (string) ($label['protocol'] ?? '');
            $suffix = $protocol !== '' ? ' Protocolo: ' . $protocol : '';
            $order->add_order_note(sprintf('Etiqueta Melhor Envio gerada para vendedor #%d.%s', $vendorId, $suffix));
        }

        $label['noted_at'] = current_time('mysql');
        $this->labels->save($order, $vendorId, $label);
    }

    private function orderMetaAny(\WC_Order $order, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $order->get_meta('_' . $key, true);
            if ($value === '') {
                $value = $order->get_meta($key, true);
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    private function orderAdminUrl(\WC_Order $order): string
    {
        if (method_exists($order, 'get_edit_order_url')) {
            return esc_url_raw((string) $order->get_edit_order_url());
        }

        return esc_url_raw(admin_url('post.php?post=' . $order->get_id() . '&action=edit'));
    }

    private function firstScalar(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function normalizePostcode(string $postcode): string
    {
        $postcode = $this->digits($postcode);

        return strlen($postcode) === 8 ? $postcode : '';
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    private function requireValue(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new \RuntimeException($message);
        }
    }
}
