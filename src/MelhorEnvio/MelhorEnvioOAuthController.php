<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Logger;

final class MelhorEnvioOAuthController
{
    private const STATE_PREFIX = 'frete_marketplace_me_oauth_';
    private const ACTION_CONNECT = 'frete_marketplace_melhor_envio_connect';
    private const ACTION_CALLBACK = 'frete_marketplace_melhor_envio_callback';
    private const ACTION_DISCONNECT = 'frete_marketplace_melhor_envio_disconnect';
    private const LEGACY_ACTION_CONNECT = 'correios_seller_melhor_envio_connect';
    private const LEGACY_ACTION_CALLBACK = 'correios_seller_melhor_envio_callback';
    private const LEGACY_ACTION_DISCONNECT = 'correios_seller_melhor_envio_disconnect';

    public function __construct(
        private MelhorEnvioOAuthService $oauth,
        private VendorSettingsRepository $repository,
        private Logger $logger
    ) {
    }

    public function register(): void
    {
        foreach ([self::ACTION_CONNECT, self::LEGACY_ACTION_CONNECT] as $action) {
            add_action('admin_post_' . $action, [$this, 'connect']);
        }
        foreach ([self::ACTION_CALLBACK, self::LEGACY_ACTION_CALLBACK] as $action) {
            add_action('admin_post_' . $action, [$this, 'callback']);
        }
        foreach ([self::ACTION_DISCONNECT, self::LEGACY_ACTION_DISCONNECT] as $action) {
            add_action('admin_post_' . $action, [$this, 'disconnect']);
        }
    }

    public function connect(): void
    {
        $this->assertPermission();
        check_admin_referer($this->nonceAction('connect'));

        $target = sanitize_key((string) ($_GET['target'] ?? 'vendor'));
        if ($target === 'admin' && ! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Sem permissao para conectar a conta central.', 'correios-seller'));
        }

        try {
            $state = wp_generate_password(48, false, false);
            set_transient(self::STATE_PREFIX . hash('sha256', $state), [
                'user_id' => get_current_user_id(),
                'target' => $target === 'admin' ? 'admin' : 'vendor',
                'return_url' => $this->returnUrl($target),
            ], 10 * MINUTE_IN_SECONDS);

            wp_redirect(esc_url_raw($this->oauth->authorizationUrl($state)));
            exit;
        } catch (\Throwable $exception) {
            $this->redirectWithStatus($this->returnUrl($target), 'error', $exception->getMessage());
        }
    }

    public function callback(): void
    {
        $this->assertPermission();
        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $stateKey = self::STATE_PREFIX . hash('sha256', $state);
        $context = get_transient($stateKey);
        delete_transient($stateKey);

        if (! is_array($context) || (int) ($context['user_id'] ?? 0) !== get_current_user_id()) {
            wp_die(esc_html__('Estado OAuth invalido ou expirado.', 'correios-seller'));
        }

        $returnUrl = (string) ($context['return_url'] ?? admin_url());
        $error = sanitize_text_field((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
        if ($error !== '') {
            $this->redirectWithStatus($returnUrl, 'error', $error);
        }

        try {
            $code = sanitize_text_field((string) ($_GET['code'] ?? $_GET['CODE'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException('Codigo de autorizacao nao informado.');
            }

            $tokens = $this->oauth->exchangeCode($code);
            if (($context['target'] ?? 'vendor') === 'admin') {
                update_option('correios_seller_melhor_envio_admin_access_token', (string) $tokens['access_token'], false);
                update_option('correios_seller_melhor_envio_admin_refresh_token', (string) ($tokens['refresh_token'] ?? ''), false);
                update_option('correios_seller_melhor_envio_admin_token_expires_at', (int) ($tokens['expires_at'] ?? 0), false);
            } else {
                $this->repository->merge(get_current_user_id(), [
                    'melhor_envio_access_token' => (string) $tokens['access_token'],
                    'melhor_envio_refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
                    'melhor_envio_token_expires_at' => (int) ($tokens['expires_at'] ?? 0),
                ]);
            }

            $this->redirectWithStatus($returnUrl, 'connected', '');
        } catch (\Throwable $exception) {
            $this->logger->error('Falha no callback OAuth do Melhor Envio.', ['error' => $exception->getMessage()]);
            $this->redirectWithStatus($returnUrl, 'error', $exception->getMessage());
        }
    }

    public function disconnect(): void
    {
        $this->assertPermission();
        check_admin_referer($this->nonceAction('disconnect'));
        $target = sanitize_key((string) ($_GET['target'] ?? 'vendor'));

        if ($target === 'admin' && current_user_can('manage_woocommerce')) {
            delete_option('correios_seller_melhor_envio_admin_access_token');
            delete_option('correios_seller_melhor_envio_admin_refresh_token');
            delete_option('correios_seller_melhor_envio_admin_token_expires_at');
        } else {
            $this->repository->merge(get_current_user_id(), [
                'melhor_envio_access_token' => '',
                'melhor_envio_refresh_token' => '',
                'melhor_envio_token_expires_at' => 0,
            ]);
        }

        $this->redirectWithStatus($this->returnUrl($target), 'disconnected', '');
    }

    private function assertPermission(): void
    {
        if (! is_user_logged_in() || ! (current_user_can('manage_woocommerce') || current_user_can('wcfm_vendor') || current_user_can('seller'))) {
            wp_die(esc_html__('Sem permissao para conectar o Melhor Envio.', 'correios-seller'));
        }
    }

    private function returnUrl(string $target): string
    {
        if ($target === 'admin') {
            return admin_url('admin.php?page=wc-settings&tab=shipping&section=frete_marketplace');
        }

        $referer = wp_get_referer();
        if (is_string($referer) && str_starts_with($referer, home_url('/'))) {
            return $referer;
        }

        return function_exists('get_wcfm_settings_url') ? get_wcfm_settings_url() : home_url('/');
    }

    private function redirectWithStatus(string $url, string $status, string $message): void
    {
        $url = add_query_arg([
            'frete_marketplace_me' => $status,
            'frete_marketplace_message' => $message,
        ], $url);
        wp_safe_redirect($url);
        exit;
    }

    private function nonceAction(string $type): string
    {
        $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));

        return match ($type) {
            'connect' => $action === self::LEGACY_ACTION_CONNECT ? self::LEGACY_ACTION_CONNECT : self::ACTION_CONNECT,
            'disconnect' => $action === self::LEGACY_ACTION_DISCONNECT ? self::LEGACY_ACTION_DISCONNECT : self::ACTION_DISCONNECT,
            default => '',
        };
    }
}
