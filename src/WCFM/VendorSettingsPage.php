<?php

declare(strict_types=1);

namespace CorreiosSeller\WCFM;

use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Options;

final class VendorSettingsPage
{
    public function __construct(private VendorSettingsRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets'], 30);
        add_action('wcfm_vendor_settings_after_shipping', [$this, 'renderFields'], 50, 1);
        add_action('wcfm_vendor_settings_update', [$this, 'saveFields'], 50, 2);
    }

    public function enqueueAssets(): void
    {
        $stylePath = FRETE_MARKETPLACE_PATH . 'assets/admin/correios-seller.css';

        wp_enqueue_style(
            'frete-marketplace-wcfm-settings',
            FRETE_MARKETPLACE_URL . 'assets/admin/correios-seller.css',
            [],
            file_exists($stylePath) ? (string) filemtime($stylePath) : FRETE_MARKETPLACE_VERSION
        );
    }

    public function renderFields($vendorId): void
    {
        global $WCFM;

        $vendorId = absint($vendorId);
        if ($vendorId <= 0 || ! isset($WCFM->wcfm_fields) || ! method_exists($WCFM->wcfm_fields, 'wcfm_generate_form_field')) {
            return;
        }

        echo '<div class="page_collapsible" id="wcfm_settings_form_correios_seller_head">';
        echo '<label class="wcfmfa fa-truck"></label>';
        echo esc_html__('Frete Melhor Envio', 'correios-seller');
        echo '<span></span></div>';
        echo '<div class="wcfm-container"><div id="wcfm_settings_form_correios_seller_expander" class="wcfm-content">';
        echo '<div class="wcfm_clearfix"></div>';
        echo '<div class="store_address">';

        $WCFM->wcfm_fields->wcfm_generate_form_field($this->fieldsForVendor($vendorId));

        echo '</div></div></div>';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function fieldsForVendor(int $vendorId): array
    {
        $settings = $this->repository->get($vendorId);

        $fields = [];
        $fields['correios_seller_enabled'] = [
            'label' => __('Ativar frete por API', 'correios-seller'),
            'name' => 'correios_seller[enabled]',
            'type' => 'checkbox',
            'value' => 'yes',
            'dfvalue' => $settings['enabled'],
            'class' => 'wcfm-checkbox wcfm_ele',
            'label_class' => 'wcfm_title checkbox_title',
        ];
        $fields['correios_seller_origin_postcode'] = [
            'label' => __('CEP de origem', 'correios-seller'),
            'name' => 'correios_seller[origin_postcode]',
            'type' => 'text',
            'value' => $settings['origin_postcode'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa automaticamente o CEP do endereco da loja WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_posting_address'] = [
            'label' => __('Endereco de postagem', 'correios-seller'),
            'name' => 'correios_seller[posting_address]',
            'type' => 'textarea',
            'value' => $settings['posting_address'],
            'class' => 'wcfm-textarea wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_sender_heading'] = [
            'type' => 'html',
            'value' => '<div class="wcfm_clearfix"></div><div class="wcfm_vendor_settings_heading"><h2>' . esc_html__('Dados do remetente', 'correios-seller') . '</h2></div><div class="wcfm_clearfix"></div>',
        ];
        $fields['correios_seller_sender_name'] = [
            'label' => __('Nome do remetente', 'correios-seller'),
            'name' => 'correios_seller[sender_name]',
            'type' => 'text',
            'value' => $settings['sender_name'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa o nome da loja no WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_sender_email'] = [
            'label' => __('E-mail do remetente', 'correios-seller'),
            'name' => 'correios_seller[sender_email]',
            'type' => 'email',
            'value' => $settings['sender_email'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa o e-mail do usuario vendedor.', 'correios-seller'),
        ];
        $fields['correios_seller_sender_document'] = [
            'label' => __('CPF/CNPJ do remetente', 'correios-seller'),
            'name' => 'correios_seller[sender_document]',
            'type' => 'text',
            'value' => $settings['sender_document'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_sender_phone'] = [
            'label' => __('Telefone do remetente', 'correios-seller'),
            'name' => 'correios_seller[sender_phone]',
            'type' => 'text',
            'value' => $settings['sender_phone'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa o telefone da loja no WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_sender_street'] = [
            'label' => __('Logradouro do remetente', 'correios-seller'),
            'name' => 'correios_seller[sender_street]',
            'type' => 'text',
            'value' => $settings['sender_street'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa o endereco da loja no WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_sender_number'] = [
            'label' => __('Numero', 'correios-seller'),
            'name' => 'correios_seller[sender_number]',
            'type' => 'text',
            'value' => $settings['sender_number'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_sender_complement'] = [
            'label' => __('Complemento', 'correios-seller'),
            'name' => 'correios_seller[sender_complement]',
            'type' => 'text',
            'value' => $settings['sender_complement'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_sender_district'] = [
            'label' => __('Bairro', 'correios-seller'),
            'name' => 'correios_seller[sender_district]',
            'type' => 'text',
            'value' => $settings['sender_district'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_sender_city'] = [
            'label' => __('Cidade', 'correios-seller'),
            'name' => 'correios_seller[sender_city]',
            'type' => 'text',
            'value' => $settings['sender_city'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Se vazio, usa a cidade da loja no WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_sender_state'] = [
            'label' => __('UF', 'correios-seller'),
            'name' => 'correios_seller[sender_state]',
            'type' => 'text',
            'value' => $settings['sender_state'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['maxlength' => '2'],
            'hints' => __('Opcional. Se vazio, usa o estado da loja no WCFM.', 'correios-seller'),
        ];
        $fields['correios_seller_defaults'] = [
            'type' => 'html',
            'value' => '<div class="wcfm_clearfix"></div><div class="wcfm_vendor_settings_heading"><h2>' . esc_html__('Peso e dimensoes padrao', 'correios-seller') . '</h2></div><div class="wcfm_clearfix"></div><p class="description">' . esc_html__('Usados quando o produto nao tiver peso ou dimensoes.', 'correios-seller') . '</p>',
        ];
        $fields['correios_seller_default_weight'] = [
            'label' => __('Peso padrao (kg)', 'correios-seller'),
            'name' => 'correios_seller[default_weight]',
            'type' => 'number',
            'value' => $settings['default_weight'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['step' => '0.001', 'min' => '0'],
        ];
        $fields['correios_seller_default_length'] = [
            'label' => __('Comprimento padrao (cm)', 'correios-seller'),
            'name' => 'correios_seller[default_length]',
            'type' => 'number',
            'value' => $settings['default_length'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['step' => '0.1', 'min' => '0'],
        ];
        $fields['correios_seller_default_width'] = [
            'label' => __('Largura padrao (cm)', 'correios-seller'),
            'name' => 'correios_seller[default_width]',
            'type' => 'number',
            'value' => $settings['default_width'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['step' => '0.1', 'min' => '0'],
        ];
        $fields['correios_seller_default_height'] = [
            'label' => __('Altura padrao (cm)', 'correios-seller'),
            'name' => 'correios_seller[default_height]',
            'type' => 'number',
            'value' => $settings['default_height'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['step' => '0.1', 'min' => '0'],
        ];
        $fields['correios_seller_handling_days'] = [
            'label' => __('Prazo adicional de postagem', 'correios-seller'),
            'name' => 'correios_seller[handling_days]',
            'type' => 'number',
            'value' => $settings['handling_days'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'attributes' => ['step' => '1', 'min' => '0'],
        ];
        $centralized = Options::melhorEnvioAccountMode() === 'admin';

        return $this->addMelhorEnvioFields($fields, $settings, $centralized);
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @param array<string,mixed> $settings
     * @return array<string,array<string,mixed>>
     */
    private function addMelhorEnvioFields(array $fields, array $settings, bool $centralized): array
    {
        $connected = ! empty($settings['melhor_envio_access_token']);
        $fields['correios_seller_melhor_envio_heading'] = [
            'type' => 'html',
            'value' => '<div class="wcfm_clearfix"></div><div class="wcfm_vendor_settings_heading"><h2>' . esc_html__('Melhor Envio', 'correios-seller') . '</h2></div><div class="wcfm_clearfix"></div>'
                . ($centralized ? '<p class="description">' . esc_html__('A conta do Melhor Envio e centralizada pelo marketplace. A cotacao usa o seu CEP de origem.', 'correios-seller') . '</p>' : $this->melhorEnvioConnectionHtml($connected)),
        ];
        $fields['correios_seller_melhor_envio_enabled_services'] = [
            'label' => __('Servicos Melhor Envio', 'correios-seller'),
            'name' => 'correios_seller[melhor_envio_enabled_services]',
            'type' => 'text',
            'value' => implode(',', (array) $settings['melhor_envio_enabled_services']),
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('IDs separados por virgula. Vazio usa o padrao do marketplace: Correios e Jadlog.', 'correios-seller'),
        ];

        if (! $centralized) {
            $fields['correios_seller_melhor_envio_access_token'] = [
                'label' => __('Token pessoal', 'correios-seller'),
                'name' => 'correios_seller[melhor_envio_access_token]',
                'type' => 'password',
                'value' => '',
                'class' => 'wcfm-text wcfm_ele',
                'label_class' => 'wcfm_title',
                'hints' => $connected ? __('Conta conectada. Deixe vazio para manter o token.', 'correios-seller') : __('Use OAuth acima ou informe um token pessoal.', 'correios-seller'),
            ];
        }

        return $fields;
    }

    private function melhorEnvioConnectionHtml(bool $connected): string
    {
        $action = $connected ? 'frete_marketplace_melhor_envio_disconnect' : 'frete_marketplace_melhor_envio_connect';
        $nonceAction = $connected ? 'frete_marketplace_melhor_envio_disconnect' : 'frete_marketplace_melhor_envio_connect';
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . $action . '&target=vendor'), $nonceAction);
        $label = $connected ? __('Desconectar conta', 'correios-seller') : __('Conectar conta do Melhor Envio', 'correios-seller');
        $status = $connected ? __('Conta conectada.', 'correios-seller') : __('Conta ainda nao conectada.', 'correios-seller');

        return '<p class="description">' . esc_html($status) . ' <a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
    }

    public function saveFields($vendorId, $formData): void
    {
        $vendorId = absint($vendorId);
        if ($vendorId <= 0 || ! is_array($formData)) {
            return;
        }

        if (isset($formData['correios_seller']) && is_array($formData['correios_seller'])) {
            $settings = $formData['correios_seller'];
            $settings['enabled'] = $settings['enabled'] ?? 'no';

            if (empty($settings['melhor_envio_access_token'])) {
                unset($settings['melhor_envio_access_token']);
            }

            $this->repository->save($vendorId, $settings);
        }
    }
}
