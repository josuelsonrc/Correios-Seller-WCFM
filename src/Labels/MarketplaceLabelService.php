<?php

declare(strict_types=1);

namespace CorreiosSeller\Labels;

use CorreiosSeller\Correios\CorreiosClient;
use CorreiosSeller\Correios\Credentials;
use CorreiosSeller\Correios\CredentialsResolver;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Shipping\PackageBuilder;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use CorreiosSeller\Support\ProductVendorResolver;

final class MarketplaceLabelService
{
    private CorreiosClient $client;
    private CredentialsResolver $credentialsResolver;
    private PackageBuilder $packageBuilder;
    private ProductVendorResolver $productVendorResolver;

    public function __construct(
        private VendorSettingsRepository $vendorSettings,
        private LabelRepository $labels,
        private Logger $logger
    ) {
        $cache = new Cache();
        $this->client = new CorreiosClient($cache, $this->logger);
        $this->credentialsResolver = new CredentialsResolver();
        $this->packageBuilder = new PackageBuilder();
        $this->productVendorResolver = new ProductVendorResolver();
    }

    /**
     * @return array<string,mixed>
     */
    public function generateForOrder(\WC_Order $order, int $vendorId): array
    {
        if (Options::get('labels_enabled', 'yes') !== 'yes') {
            throw new \RuntimeException('Emissao de etiquetas Correios desativada nas configuracoes.');
        }

        if (Options::get('logistics_responsibility', 'marketplace') !== 'marketplace') {
            throw new \RuntimeException('Este fluxo exige responsabilidade logistica do marketplace.');
        }

        if (! $this->orderHasVendorItems($order, $vendorId)) {
            throw new \RuntimeException('Pedido nao pertence ao vendedor informado.');
        }

        if (! $order->is_paid() && ! in_array($order->get_status(), ['processing', 'completed'], true)) {
            throw new \RuntimeException('A etiqueta so pode ser gerada para pedido pago ou em processamento.');
        }

        $credentials = $this->credentialsResolver->resolveMarketplace();
        if (! $credentials->isConfigured() || ! $credentials->hasPostingCard()) {
            throw new \RuntimeException('Credenciais/cartao de postagem do marketplace ausentes.');
        }

        $existing = $this->labels->get($order, $vendorId);
        if (($existing['status'] ?? '') === 'generated' && ! empty($existing['label_url'])) {
            return $existing;
        }

        if (! empty($existing['label_receipt_id'])) {
            return $this->downloadLabel($credentials, $order, $vendorId, $existing);
        }

        $shippingItem = $this->shippingItemForVendor($order, $vendorId);
        if (! $shippingItem) {
            throw new \RuntimeException('Metodo de envio Correios do vendedor nao encontrado no pedido.');
        }

        $settings = $this->vendorSettings->get($vendorId);
        $package = $this->packageForVendor($order, $vendorId, $settings);
        $serviceCode = $this->serviceCodeFromShippingItem($shippingItem);
        if ($serviceCode === '') {
            throw new \RuntimeException('Servico Correios nao identificado no item de frete.');
        }

        $prePostingPayload = $this->prePostingPayload($credentials, $order, $vendorId, $settings, $shippingItem, $serviceCode, $package);
        $prePostingPayload = (array) apply_filters(
            'correios_seller_preposting_payload',
            $prePostingPayload,
            $order,
            $vendorId,
            $shippingItem,
            $package
        );

        $prePosting = $this->client->createPrePosting($credentials, $prePostingPayload);
        $prePostingId = (string) ($prePosting['id'] ?? $prePosting['idPrePostagem'] ?? '');
        if ($prePostingId === '') {
            throw new \RuntimeException('Pre-postagem criada sem ID retornado pelos Correios.');
        }

        $label = [
            'status' => 'preposted',
            'order_id' => $order->get_id(),
            'vendor_id' => $vendorId,
            'preposting_id' => $prePostingId,
            'tracking_code' => (string) ($prePosting['codigoObjeto'] ?? ''),
            'service_code' => $serviceCode,
            'shipping_item_id' => $shippingItem->get_id(),
            'shipping_cost' => (float) $shippingItem->get_total(),
            'preposting_response' => $prePosting,
            'created_at' => current_time('mysql'),
        ];
        $this->labels->save($order, $vendorId, $label);

        $labelPayload = [
            'idsPrePostagem' => [$prePostingId],
            'tipoRotulo' => 'P',
            'formatoRotulo' => 'ET',
            'imprimeRemetente' => 'S',
            'layoutImpressao' => (string) Options::get('label_layout', 'PADRAO'),
        ];
        $labelPayload = (array) apply_filters('correios_seller_label_payload', $labelPayload, $order, $vendorId, $prePosting);

        $labelRequest = $this->client->requestAsyncLabel($credentials, $labelPayload);
        $receiptId = (string) ($labelRequest['idRecibo'] ?? $labelRequest['recibo'] ?? '');
        if ($receiptId === '') {
            throw new \RuntimeException('Solicitacao de rotulo criada sem recibo retornado pelos Correios.');
        }

        $label = array_merge($label, [
            'status' => 'label_requested',
            'label_receipt_id' => $receiptId,
            'label_request_response' => $labelRequest,
        ]);
        $this->labels->save($order, $vendorId, $label);

        return $this->downloadLabel($credentials, $order, $vendorId, $label);
    }

    /**
     * @return array<int,int>
     */
    public function vendorIdsForOrder(\WC_Order $order): array
    {
        $vendorIds = [];
        foreach ($order->get_items('line_item') as $item) {
            $vendorId = $this->vendorIdFromOrderItem($item);
            if ($vendorId > 0) {
                $vendorIds[$vendorId] = $vendorId;
            }
        }

        return array_values($vendorIds);
    }

    private function downloadLabel(Credentials $credentials, \WC_Order $order, int $vendorId, array $label): array
    {
        $receiptId = (string) ($label['label_receipt_id'] ?? '');
        if ($receiptId === '') {
            return $label;
        }

        try {
            $download = $this->client->downloadAsyncLabel($credentials, $receiptId);
        } catch (\Throwable $exception) {
            $pending = array_merge($label, [
                'status' => 'label_pending',
                'label_download_error' => $exception->getMessage(),
            ]);
            $this->labels->save($order, $vendorId, $pending);
            $this->logger->info('Etiqueta Correios ainda nao disponivel para download.', [
                'order_id' => $order->get_id(),
                'vendor_id' => $vendorId,
                'receipt_id' => $receiptId,
                'error' => $exception->getMessage(),
            ]);

            return $pending;
        }

        $base64 = $this->extractBase64Pdf($download);
        if ($base64 === '') {
            $pending = array_merge($label, [
                'status' => 'label_pending',
                'label_download_response' => $download,
            ]);
            $this->labels->save($order, $vendorId, $pending);

            return $pending;
        }

        $file = $this->savePdf($order, $vendorId, $base64);
        $generated = array_merge($label, [
            'status' => 'generated',
            'label_path' => $file['path'],
            'label_url' => $file['url'],
            'label_download_response' => $download,
        ]);
        $this->labels->save($order, $vendorId, $generated);

        if (! empty($generated['tracking_code'])) {
            $order->add_order_note(sprintf(
                'Etiqueta Correios gerada para vendedor #%d. Rastreio: %s',
                $vendorId,
                $generated['tracking_code']
            ));
        } else {
            $order->add_order_note(sprintf('Etiqueta Correios gerada para vendedor #%d.', $vendorId));
        }

        return $generated;
    }

    private function prePostingPayload(Credentials $credentials, \WC_Order $order, int $vendorId, array $settings, \WC_Order_Item_Shipping $shippingItem, string $serviceCode, \CorreiosSeller\Shipping\ShipmentPackage $package): array
    {
        return [
            'idCorreios' => $credentials->username,
            'numeroCartaoPostagem' => $credentials->postingCard,
            'remetente' => $this->sender($vendorId, $settings),
            'destinatario' => $this->recipient($order),
            'codigoServico' => $serviceCode,
            'precoPrePostagem' => $this->money((float) $shippingItem->get_total()),
            'itensDeclaracaoConteudo' => $this->declarationItems($order, $vendorId),
            'pesoInformado' => (string) max(1, (int) ceil($package->billableWeightKg() * 1000)),
            'codigoFormatoObjetoInformado' => '2',
            'alturaInformada' => (string) max(2, (int) ceil($package->heightCm)),
            'larguraInformada' => (string) max(11, (int) ceil($package->widthCm)),
            'comprimentoInformado' => (string) max(16, (int) ceil($package->lengthCm)),
            'cienteObjetoNaoProibido' => '1',
            'modalidadePagamento' => '2',
            'logisticaReversa' => 'N',
            'observacao' => sprintf('Pedido WooCommerce #%s', $order->get_order_number()),
        ];
    }

    private function sender(int $vendorId, array $settings): array
    {
        $profile = get_user_meta($vendorId, 'wcfmmp_profile_settings', true);
        $profile = is_array($profile) ? $profile : [];
        $address = isset($profile['address']) && is_array($profile['address']) ? $profile['address'] : [];
        $user = get_userdata($vendorId);

        $name = (string) ($settings['sender_name'] ?? '');
        if ($name === '' && function_exists('wcfm_get_vendor_store_name')) {
            $name = (string) wcfm_get_vendor_store_name($vendorId);
        }
        if ($name === '' && $user) {
            $name = $user->display_name;
        }

        $postcode = (string) ($settings['origin_postcode'] ?: ($address['zip'] ?? ''));
        $street = (string) ($settings['sender_street'] ?: ($address['street_1'] ?? ''));
        $city = (string) ($settings['sender_city'] ?: ($address['city'] ?? ''));
        $state = strtoupper((string) ($settings['sender_state'] ?: ($address['state'] ?? '')));
        $phone = (string) ($settings['sender_phone'] ?: ($profile['phone'] ?? ''));
        $document = (string) ($settings['sender_document'] ?? '');

        $this->requireValue($name, 'Nome do remetente ausente.');
        $this->requireValue($document, 'CPF/CNPJ do remetente ausente nas configuracoes Correios Seller do vendedor.');
        $this->requireValue($postcode, 'CEP de origem do remetente ausente.');
        $this->requireValue($street, 'Logradouro do remetente ausente.');
        $this->requireValue($city, 'Cidade do remetente ausente.');
        $this->requireValue($state, 'UF do remetente ausente.');

        $sender = [
            'nome' => $name,
            'cpfCnpj' => preg_replace('/\D+/', '', $document),
            'endereco' => [
                'cep' => preg_replace('/\D+/', '', $postcode),
                'logradouro' => $street,
                'numero' => (string) (($settings['sender_number'] ?? '') ?: 'S/N'),
                'bairro' => (string) ($settings['sender_district'] ?? ''),
                'cidade' => $city,
                'uf' => substr($state, 0, 2),
            ],
        ];

        if (! empty($settings['sender_complement'])) {
            $sender['endereco']['complemento'] = (string) $settings['sender_complement'];
        }
        if ($phone !== '') {
            $sender['telefone'] = preg_replace('/\D+/', '', $phone);
        }

        return $sender;
    }

    private function recipient(\WC_Order $order): array
    {
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        if ($name === '') {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        $street = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $city = $order->get_shipping_city() ?: $order->get_billing_city();
        $state = $order->get_shipping_state() ?: $order->get_billing_state();
        $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $number = $this->orderMeta($order, 'shipping_number') ?: $this->orderMeta($order, 'billing_number') ?: 'S/N';
        $district = $this->orderMeta($order, 'shipping_neighborhood')
            ?: $this->orderMeta($order, 'billing_neighborhood')
            ?: $this->orderMeta($order, 'shipping_bairro')
            ?: $this->orderMeta($order, 'billing_bairro');
        $document = $this->orderMeta($order, 'billing_cpf') ?: $this->orderMeta($order, 'billing_cnpj');

        $this->requireValue($name, 'Nome do destinatario ausente.');
        $this->requireValue($street, 'Logradouro do destinatario ausente.');
        $this->requireValue($city, 'Cidade do destinatario ausente.');
        $this->requireValue($state, 'UF do destinatario ausente.');
        $this->requireValue($postcode, 'CEP do destinatario ausente.');

        $recipient = [
            'nome' => $name,
            'endereco' => [
                'cep' => preg_replace('/\D+/', '', $postcode),
                'logradouro' => $street,
                'numero' => (string) $number,
                'bairro' => (string) $district,
                'cidade' => $city,
                'uf' => substr(strtoupper($state), 0, 2),
            ],
        ];

        $complement = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        if ($complement !== '') {
            $recipient['endereco']['complemento'] = $complement;
        }
        if ($document !== '') {
            $recipient['cpfCnpj'] = preg_replace('/\D+/', '', $document);
        }
        if ($order->get_billing_phone() !== '') {
            $recipient['telefone'] = preg_replace('/\D+/', '', $order->get_billing_phone());
        }

        return $recipient;
    }

    private function packageForVendor(\WC_Order $order, int $vendorId, array $settings): \CorreiosSeller\Shipping\ShipmentPackage
    {
        $contents = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if ($this->vendorIdFromOrderItem($item) !== $vendorId) {
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
            if (! str_starts_with((string) $item->get_method_id(), 'correios_seller')) {
                continue;
            }

            $itemVendorId = (int) ($item->get_meta('vendor_id') ?: $item->get_meta('_correios_seller_vendor_id'));
            if ($itemVendorId === $vendorId) {
                return $item;
            }
        }

        return null;
    }

    private function serviceCodeFromShippingItem(\WC_Order_Item_Shipping $item): string
    {
        $serviceCode = (string) ($item->get_meta('correios_service') ?: $item->get_meta('_correios_seller_service'));
        if ($serviceCode !== '') {
            return preg_replace('/\D+/', '', $serviceCode) ?: '';
        }

        $parts = explode(':', (string) $item->get_method_id());

        return preg_replace('/\D+/', '', (string) end($parts)) ?: '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function declarationItems(\WC_Order $order, int $vendorId): array
    {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            if ($this->vendorIdFromOrderItem($item) !== $vendorId) {
                continue;
            }

            $items[] = [
                'conteudo' => function_exists('mb_substr') ? mb_substr($item->get_name(), 0, 60) : substr($item->get_name(), 0, 60),
                'quantidade' => max(1, (int) $item->get_quantity()),
                'valor' => $this->money((float) $item->get_total()),
            ];
        }

        return $items;
    }

    private function orderHasVendorItems(\WC_Order $order, int $vendorId): bool
    {
        foreach ($order->get_items('line_item') as $item) {
            if ($this->vendorIdFromOrderItem($item) === $vendorId) {
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

    private function extractBase64Pdf(array $download): string
    {
        $data = $download['dados'] ?? $download['pdf'] ?? $download['arquivo'] ?? $download['rotulo'] ?? '';
        if (is_array($data)) {
            $data = $data['dados'] ?? $data['base64'] ?? '';
        }

        $data = (string) $data;
        if (str_contains($data, ',')) {
            $data = substr($data, strpos($data, ',') + 1);
        }

        return trim($data);
    }

    /**
     * @return array{path:string,url:string}
     */
    private function savePdf(\WC_Order $order, int $vendorId, string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('PDF da etiqueta retornado em formato invalido.');
        }

        $uploads = wp_upload_dir();
        if (! empty($uploads['error'])) {
            throw new \RuntimeException((string) $uploads['error']);
        }

        $directory = trailingslashit($uploads['basedir']) . 'correios-seller-labels';
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
            'url' => trailingslashit($uploads['baseurl']) . 'correios-seller-labels/' . $filename,
        ];
    }

    private function orderMeta(\WC_Order $order, string $key): string
    {
        $value = $order->get_meta('_' . $key, true);
        if ($value === '') {
            $value = $order->get_meta($key, true);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function money(float $amount): string
    {
        return number_format(max(0, $amount), 2, '.', '');
    }

    private function requireValue(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new \RuntimeException($message);
        }
    }
}
