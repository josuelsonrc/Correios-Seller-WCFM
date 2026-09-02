<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use WP_Error;

final class MelhorEnvioOAuthService
{
    private const PRODUCTION_BASE_URL = 'https://melhorenvio.com.br';
    private const SANDBOX_BASE_URL = 'https://sandbox.melhorenvio.com.br';
    private const DEFAULT_SCOPES = 'shipping-calculate shipping-companies cart-read cart-write shipping-checkout shipping-generate shipping-print shipping-tracking orders-read';

    public function __construct(private Logger $logger)
    {
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function authorizationUrl(string $state): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('OAuth do Melhor Envio nao configurado pelo administrador.');
        }

        return add_query_arg([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => (string) Options::get('melhor_envio_scopes', self::DEFAULT_SCOPES),
            'state' => $state,
        ], $this->baseUrl() . '/oauth/authorize');
    }

    /**
     * @return array<string,mixed>
     */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
        ]);
    }

    public function redirectUri(): string
    {
        $configured = trim((string) Options::get('melhor_envio_redirect_uri', ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
            return esc_url_raw($configured);
        }

        return admin_url('admin-post.php?action=frete_marketplace_melhor_envio_callback');
    }

    /**
     * @param array<string,string> $body
     * @return array<string,mixed>
     */
    private function tokenRequest(array $body): array
    {
        $endpoint = $this->baseUrl() . '/oauth/token';
        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent(),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($data) || empty($data['access_token'])) {
            $message = is_array($data)
                ? (string) ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? 'Falha ao autenticar no Melhor Envio.')
                : 'Falha ao autenticar no Melhor Envio.';
            $this->logger->error('Melhor Envio OAuth error', ['status' => $status, 'message' => $message]);

            throw new \RuntimeException($message);
        }

        $data['expires_at'] = time() + max(0, (int) ($data['expires_in'] ?? 0));

        return $data;
    }

    private function baseUrl(): string
    {
        return Options::get('melhor_envio_environment', 'production') === 'sandbox'
            ? self::SANDBOX_BASE_URL
            : self::PRODUCTION_BASE_URL;
    }

    private function clientId(): string
    {
        return trim((string) Options::get('melhor_envio_client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) Options::get('melhor_envio_client_secret', ''));
    }

    private function userAgent(): string
    {
        $email = sanitize_email((string) Options::get('melhor_envio_user_agent_email', get_option('admin_email')));

        return sprintf(
            'FreteMarketplace/%s (%s; %s)',
            defined('FRETE_MARKETPLACE_VERSION') ? FRETE_MARKETPLACE_VERSION : '1.0',
            home_url('/'),
            $email
        );
    }
}
