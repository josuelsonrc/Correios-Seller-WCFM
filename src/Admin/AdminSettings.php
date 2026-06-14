<?php

declare(strict_types=1);

namespace CorreiosSeller\Admin;

final class AdminSettings
{
    public const OPTION = 'correios_seller_settings';

    public function register(): void
    {
        add_filter('woocommerce_get_sections_shipping', [$this, 'addSection']);
        add_filter('woocommerce_get_settings_shipping', [$this, 'addSettings'], 20, 2);
    }

    /**
     * @param array<string,string> $sections
     * @return array<string,string>
     */
    public function addSection(array $sections): array
    {
        $sections['correios_seller'] = __('Correios Seller', 'correios-seller');

        return $sections;
    }

    /**
     * @param array<int,array<string,mixed>> $settings
     * @return array<int,array<string,mixed>>
     */
    public function addSettings(array $settings, ?string $section): array
    {
        if ($section !== 'correios_seller') {
            return $settings;
        }

        return [
            [
                'title' => __('Correios Seller', 'correios-seller'),
                'type' => 'title',
                'desc' => __('Configuracoes globais para frete Correios por vendedor.', 'correios-seller'),
                'id' => self::OPTION . '_title',
            ],
            [
                'title' => __('Modo de credenciais', 'correios-seller'),
                'id' => 'correios_seller_credential_mode',
                'type' => 'select',
                'default' => 'admin',
                'options' => [
                    'admin' => __('Conta centralizada do administrador', 'correios-seller'),
                    'vendor' => __('Conta individual do vendedor', 'correios-seller'),
                ],
            ],
            [
                'title' => __('Responsabilidade logistica', 'correios-seller'),
                'id' => 'correios_seller_logistics_responsibility',
                'type' => 'select',
                'default' => 'marketplace',
                'options' => [
                    'marketplace' => __('Marketplace responsavel pelo contrato Correios', 'correios-seller'),
                    'vendor' => __('Vendedor responsavel pelo proprio contrato', 'correios-seller'),
                ],
                'desc' => __('No modo marketplace, as cotacoes e etiquetas usam sempre as credenciais centrais do admin.', 'correios-seller'),
            ],
            [
                'title' => __('Ambiente da API Correios', 'correios-seller'),
                'id' => 'correios_seller_api_environment',
                'type' => 'select',
                'default' => 'production',
                'options' => [
                    'production' => __('Producao', 'correios-seller'),
                    'homologation' => __('Homologacao', 'correios-seller'),
                ],
            ],
            [
                'title' => __('Usuario/API admin', 'correios-seller'),
                'id' => 'correios_seller_admin_username',
                'type' => 'text',
                'desc' => __('Usuario usado para gerar token quando o modo centralizado estiver ativo.', 'correios-seller'),
            ],
            [
                'title' => __('Senha/API admin', 'correios-seller'),
                'id' => 'correios_seller_admin_password',
                'type' => 'password',
            ],
            [
                'title' => __('Cartao de postagem admin', 'correios-seller'),
                'id' => 'correios_seller_admin_posting_card',
                'type' => 'text',
            ],
            [
                'title' => __('Contrato admin', 'correios-seller'),
                'id' => 'correios_seller_admin_code',
                'type' => 'text',
                'desc' => __('Opcional. Usado na geracao de token por cartao de postagem quando o contrato precisa ser informado.', 'correios-seller'),
            ],
            [
                'title' => __('Emissao de etiquetas', 'correios-seller'),
                'id' => 'correios_seller_labels_enabled',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Exibe o bloco de geracao de etiqueta Correios na tela de pedido do WCFM.', 'correios-seller'),
            ],
            [
                'title' => __('Layout da etiqueta', 'correios-seller'),
                'id' => 'correios_seller_label_layout',
                'type' => 'select',
                'default' => 'PADRAO',
                'options' => [
                    'PADRAO' => __('Padrao', 'correios-seller'),
                    'LINEAR_100_150' => __('Linear 100x150', 'correios-seller'),
                    'LINEAR_100_80' => __('Linear 100x80', 'correios-seller'),
                    'LINEAR_A4' => __('Linear A4', 'correios-seller'),
                ],
            ],
            [
                'title' => __('Simulador no produto', 'correios-seller'),
                'id' => 'correios_seller_product_simulator_enabled',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Exibe calculo de frete na pagina do produto usando os metodos WooCommerce/WCFM ativos para o vendedor.', 'correios-seller'),
            ],
            [
                'title' => __('Cache do simulador em segundos', 'correios-seller'),
                'id' => 'correios_seller_product_simulator_cache_ttl',
                'type' => 'number',
                'default' => 300,
            ],
            [
                'title' => __('Servicos habilitados', 'correios-seller'),
                'id' => 'correios_seller_enabled_services_csv',
                'type' => 'text',
                'default' => '03220,03298,04227',
                'desc' => __('Informe os codigos separados por virgula. Ex.: PAC, SEDEX e Mini Envios conforme contrato.', 'correios-seller'),
            ],
            [
                'title' => __('TTL do cache em segundos', 'correios-seller'),
                'id' => 'correios_seller_cache_ttl',
                'type' => 'number',
                'default' => 900,
            ],
            [
                'title' => __('Fallback de cotacao', 'correios-seller'),
                'id' => 'correios_seller_fallback_enabled',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Usa a ultima cotacao valida em caso de indisponibilidade temporaria da API.', 'correios-seller'),
            ],
            [
                'type' => 'sectionend',
                'id' => self::OPTION . '_end',
            ],
        ];
    }
}
