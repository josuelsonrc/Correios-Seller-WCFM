<?php

declare(strict_types=1);

namespace CorreiosSeller\Admin;

use CorreiosSeller\Support\Options;

final class AdminSettings
{
    public const OPTION = 'correios_seller_settings';

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderOAuthNotice']);
        add_filter('woocommerce_get_sections_shipping', [$this, 'addSection']);
        add_filter('woocommerce_get_settings_shipping', [$this, 'addSettings'], 20, 2);
        add_filter('woocommerce_admin_settings_sanitize_option_correios_seller_melhor_envio_account_mode', [$this, 'sanitizeAccountMode'], 10, 3);
        add_filter('woocommerce_admin_settings_sanitize_option_correios_seller_melhor_envio_redirect_uri', [$this, 'sanitizeRedirectUri'], 10, 3);

        foreach ([
            'correios_seller_melhor_envio_admin_access_token',
            'correios_seller_melhor_envio_client_secret',
        ] as $optionId) {
            add_filter('woocommerce_admin_settings_sanitize_option_' . $optionId, [$this, 'preserveSecret'], 10, 3);
        }
    }

    /**
     * @param array<string,string> $sections
     * @return array<string,string>
     */
    public function addSection(array $sections): array
    {
        $sections['frete_marketplace'] = __('Frete Melhor Envio', 'correios-seller');

        return $sections;
    }

    public function renderOAuthNotice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $status = sanitize_key((string) ($_GET['frete_marketplace_me'] ?? ''));
        if ($status === '') {
            return;
        }

        $message = sanitize_text_field((string) ($_GET['frete_marketplace_message'] ?? ''));
        if ($message === '') {
            $message = match ($status) {
                'connected' => __('Conta geral do Melhor Envio conectada com sucesso.', 'correios-seller'),
                'disconnected' => __('Conta geral do Melhor Envio desconectada.', 'correios-seller'),
                default => __('Nao foi possivel concluir a conexao OAuth do Melhor Envio.', 'correios-seller'),
            };
        }

        $class = match ($status) {
            'connected' => 'notice notice-success is-dismissible',
            'disconnected' => 'notice notice-info is-dismissible',
            default => 'notice notice-error is-dismissible',
        };

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $settings
     * @return array<int,array<string,mixed>>
     */
    public function addSettings(array $settings, ?string $section): array
    {
        if (! in_array($section, ['frete_marketplace', 'correios_seller'], true)) {
            return $settings;
        }

        return array_merge(
            $this->accountSettings(),
            $this->melhorEnvioSettings(),
            $this->operationSettings()
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function accountSettings(): array
    {
        return [
            [
                'title' => __('Conta Melhor Envio', 'correios-seller'),
                'type' => 'title',
                'desc' => __('Defina se o marketplace usa uma conta central ou se cada seller conecta a propria conta.', 'correios-seller'),
                'id' => self::OPTION . '_account',
            ],
            [
                'title' => __('Modo de conta', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_account_mode',
                'type' => 'select',
                'default' => 'admin',
                'options' => [
                    'admin' => __('Conta geral do administrador', 'correios-seller'),
                    'seller' => __('Conta individual por seller', 'correios-seller'),
                ],
                'desc' => __('A origem do envio continua sendo resolvida por seller em ambos os modos.', 'correios-seller'),
            ],
            ['type' => 'sectionend', 'id' => self::OPTION . '_account_end'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function melhorEnvioSettings(): array
    {
        return [
            [
                'title' => __('Melhor Envio', 'correios-seller'),
                'type' => 'title',
                'desc' => __('Configure a API do Melhor Envio, os servicos globais de cotacao e as permissoes de emissao de etiquetas.', 'correios-seller'),
                'id' => self::OPTION . '_melhor_envio',
            ],
            [
                'title' => __('Ambiente', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_environment',
                'type' => 'select',
                'default' => 'production',
                'options' => [
                    'production' => __('Producao', 'correios-seller'),
                    'sandbox' => __('Sandbox', 'correios-seller'),
                ],
            ],
            [
                'title' => __('Token da conta geral', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_admin_access_token',
                'type' => 'password',
                'desc' => $this->oauthActionsDescription(),
            ],
            ['title' => __('OAuth Client ID', 'correios-seller'), 'id' => 'correios_seller_melhor_envio_client_id', 'type' => 'text'],
            ['title' => __('OAuth Client Secret', 'correios-seller'), 'id' => 'correios_seller_melhor_envio_client_secret', 'type' => 'password'],
            [
                'title' => __('URL de retorno OAuth', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_redirect_uri',
                'type' => 'text',
                'default' => '',
                'placeholder' => $this->defaultOAuthRedirectUri(),
                'desc' => __('Opcional. Use quando o WordPress estiver atras de proxy, CDN ou SSL e copie exatamente esta URL no aplicativo do Melhor Envio. Em branco usa a URL padrao abaixo.', 'correios-seller'),
            ],
            [
                'title' => __('Escopos OAuth', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_scopes',
                'type' => 'text',
                'default' => 'shipping-calculate shipping-companies cart-read cart-write shipping-checkout shipping-generate shipping-print shipping-tracking orders-read',
                'desc' => __('Para emitir etiquetas, contas ja conectadas antes desta atualizacao devem ser desconectadas e conectadas novamente.', 'correios-seller'),
            ],
            [
                'title' => __('E-mail do User-Agent', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_user_agent_email',
                'type' => 'email',
                'default' => get_option('admin_email'),
                'desc' => __('Identificacao de contato enviada a API do Melhor Envio.', 'correios-seller'),
            ],
            [
                'title' => __('Servicos habilitados', 'correios-seller'),
                'id' => 'correios_seller_melhor_envio_enabled_services_csv',
                'type' => 'text',
                'default' => Options::defaultMelhorEnvioServicesCsv(),
                'desc' => __('IDs separados por virgula. Padrao: Correios PAC, SEDEX, Mini Envios, Jadlog Package e Jadlog .Com.', 'correios-seller'),
            ],
            ['type' => 'sectionend', 'id' => self::OPTION . '_melhor_envio_end'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function operationSettings(): array
    {
        return [
            [
                'title' => __('Operacao', 'correios-seller'),
                'type' => 'title',
                'desc' => __('Simulacao, cache e tolerancia a falhas para cotacoes.', 'correios-seller'),
                'id' => self::OPTION . '_operation',
            ],
            [
                'title' => __('Simulador no produto', 'correios-seller'),
                'id' => 'correios_seller_product_simulator_enabled',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Usa as mesmas regras do carrinho e checkout.', 'correios-seller'),
            ],
            ['title' => __('Cache do simulador (segundos)', 'correios-seller'), 'id' => 'correios_seller_product_simulator_cache_ttl', 'type' => 'number', 'default' => 300],
            ['title' => __('Cache da API (segundos)', 'correios-seller'), 'id' => 'correios_seller_cache_ttl', 'type' => 'number', 'default' => 900],
            [
                'title' => __('Ultima cotacao valida', 'correios-seller'),
                'id' => 'correios_seller_fallback_enabled',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __('Reutiliza temporariamente a ultima resposta valida se a API ficar indisponivel.', 'correios-seller'),
            ],
            ['type' => 'sectionend', 'id' => self::OPTION . '_operation_end'],
        ];
    }

    private function oauthActionsDescription(): string
    {
        $connected = trim((string) Options::get('melhor_envio_admin_access_token', '')) !== '';
        $action = $connected ? 'frete_marketplace_melhor_envio_disconnect' : 'frete_marketplace_melhor_envio_connect';
        $nonceAction = $connected ? 'frete_marketplace_melhor_envio_disconnect' : 'frete_marketplace_melhor_envio_connect';
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . $action . '&target=admin'), $nonceAction);
        $label = $connected ? __('Desconectar conta geral', 'correios-seller') : __('Conectar conta geral via OAuth', 'correios-seller');

        return sprintf(
            '%s <a href="%s">%s</a><br />%s <code>%s</code>',
            esc_html__('O token pode ser informado manualmente ou obtido pela conexao OAuth.', 'correios-seller'),
            esc_url($url),
            esc_html($label),
            esc_html__('URL de retorno:', 'correios-seller'),
            esc_html($this->oauthRedirectUri())
        );
    }

    /**
     * @param array<string,mixed> $option
     */
    public function preserveSecret(mixed $value, array $option, mixed $rawValue): string
    {
        $optionId = (string) ($option['id'] ?? '');
        if (trim((string) $rawValue) === '' && $optionId !== '') {
            return (string) get_option($optionId, '');
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<string,mixed> $option
     */
    public function sanitizeAccountMode(mixed $value, array $option, mixed $rawValue): string
    {
        $mode = sanitize_key((string) $value);

        return in_array($mode, ['admin', 'seller'], true) ? $mode : 'admin';
    }

    /**
     * @param array<string,mixed> $option
     */
    public function sanitizeRedirectUri(mixed $value, array $option, mixed $rawValue): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? esc_url_raw($url) : '';
    }

    private function oauthRedirectUri(): string
    {
        $configured = trim((string) get_option('correios_seller_melhor_envio_redirect_uri', ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
            return esc_url_raw($configured);
        }

        return $this->defaultOAuthRedirectUri();
    }

    private function defaultOAuthRedirectUri(): string
    {
        return admin_url('admin-post.php?action=frete_marketplace_melhor_envio_callback');
    }
}
