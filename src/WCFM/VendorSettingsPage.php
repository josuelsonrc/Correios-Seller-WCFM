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
        add_action('wcfm_vendor_settings_after_shipping', [$this, 'renderFields'], 50, 1);
        add_action('wcfm_vendor_settings_update', [$this, 'saveFields'], 50, 2);
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
        echo esc_html__('Correios Seller', 'correios-seller');
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
            'label' => __('Ativar Correios', 'correios-seller'),
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
            'value' => '<div class="wcfm_clearfix"></div><div class="wcfm_vendor_settings_heading"><h2>' . esc_html__('Dados para etiqueta', 'correios-seller') . '</h2></div><div class="wcfm_clearfix"></div>',
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
        $fields['correios_seller_credentials_heading'] = [
            'type' => 'html',
            'value' => '<div class="wcfm_clearfix"></div><div class="wcfm_vendor_settings_heading"><h2>' . esc_html__('Credenciais Correios', 'correios-seller') . '</h2></div><div class="wcfm_clearfix"></div>',
        ];

        if (Options::get('logistics_responsibility', 'marketplace') === 'marketplace') {
            $fields['correios_seller_credentials_heading']['value'] .= '<p class="description">' . esc_html__('O marketplace e o responsavel logistico. As etiquetas e cotacoes usam o contrato Correios central configurado pelo admin.', 'correios-seller') . '</p>';

            return $fields;
        }

        $fields['correios_seller_credential_mode'] = [
            'label' => __('Credenciais', 'correios-seller'),
            'name' => 'correios_seller[credential_mode]',
            'type' => 'select',
            'class' => 'wcfm-select wcfm_ele',
            'label_class' => 'wcfm_title',
            'options' => [
                'inherit' => __('Usar modo do marketplace', 'correios-seller'),
                'vendor' => __('Usar minha conta Correios', 'correios-seller'),
            ],
            'value' => $settings['credential_mode'],
        ];
        $fields['correios_seller_admin_code'] = [
            'label' => __('Codigo administrativo', 'correios-seller'),
            'name' => 'correios_seller[admin_code]',
            'type' => 'text',
            'value' => $settings['admin_code'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_posting_card'] = [
            'label' => __('Cartao de postagem', 'correios-seller'),
            'name' => 'correios_seller[posting_card]',
            'type' => 'text',
            'value' => $settings['posting_card'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_api_username'] = [
            'label' => __('Usuario/API', 'correios-seller'),
            'name' => 'correios_seller[api_username]',
            'type' => 'text',
            'value' => $settings['api_username'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_api_password'] = [
            'label' => __('Senha/API', 'correios-seller'),
            'name' => 'correios_seller[api_password]',
            'type' => 'password',
            'value' => $settings['api_password'],
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
        ];
        $fields['correios_seller_enabled_services'] = [
            'label' => __('Servicos habilitados', 'correios-seller'),
            'name' => 'correios_seller[enabled_services]',
            'type' => 'text',
            'value' => implode(',', (array) $settings['enabled_services']),
            'class' => 'wcfm-text wcfm_ele',
            'label_class' => 'wcfm_title',
            'hints' => __('Opcional. Codigos separados por virgula.', 'correios-seller'),
        ];

        return $fields;
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

            $this->repository->save($vendorId, $settings);
        }
    }
}
