<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use WP_Error;

final class MelhorEnvioClient
{
    private const PRODUCTION_BASE_URL = 'https://melhorenvio.com.br';
    private const SANDBOX_BASE_URL = 'https://sandbox.melhorenvio.com.br';
    private const QUOTE_ENDPOINT = '/api/v2/me/shipment/calculate';
    private const CART_ENDPOINT = '/api/v2/me/cart';
    private const CHECKOUT_ENDPOINT = '/api/v2/me/shipment/checkout';
    private const GENERATE_ENDPOINT = '/api/v2/me/shipment/generate';
    private const PRINT_ENDPOINT = '/api/v2/me/shipment/print';
    private const PRINT_FILE_ENDPOINT = '/api/v2/me/imprimir/%s/%s';

    public function __construct(private Logger $logger)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    public function quote(string $accessToken, array $payload): array
    {
        $this->logger->info('Melhor Envio request', [
            'endpoint' => $this->baseUrl() . self::QUOTE_ENDPOINT,
            'services' => $payload['services'] ?? '',
            'from' => $payload['from']['postal_code'] ?? '',
            'to' => $payload['to']['postal_code'] ?? '',
        ]);

        $data = $this->postJson(self::QUOTE_ENDPOINT, $accessToken, $payload);
        $this->logger->info('Melhor Envio response', [
            'endpoint' => $this->baseUrl() . self::QUOTE_ENDPOINT,
            'quotes' => count($data),
        ]);

        return array_values($data);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function addToCart(string $accessToken, array $payload): array
    {
        $this->logger->info('Melhor Envio cart request', [
            'endpoint' => $this->baseUrl() . self::CART_ENDPOINT,
            'service' => $payload['service'] ?? '',
            'from' => $payload['from']['postal_code'] ?? '',
            'to' => $payload['to']['postal_code'] ?? '',
        ]);

        return $this->postJson(self::CART_ENDPOINT, $accessToken, $payload);
    }

    /**
     * @param array<int,string> $orderIds
     * @return array<string,mixed>
     */
    public function checkout(string $accessToken, array $orderIds): array
    {
        return $this->postJson(self::CHECKOUT_ENDPOINT, $accessToken, [
            'orders' => array_values($orderIds),
        ]);
    }

    /**
     * @param array<int,string> $orderIds
     * @return array<string,mixed>
     */
    public function generate(string $accessToken, array $orderIds): array
    {
        return $this->postJson(self::GENERATE_ENDPOINT, $accessToken, [
            'orders' => array_values($orderIds),
        ]);
    }

    /**
     * @param array<int,string> $orderIds
     * @return array<string,mixed>
     */
    public function print(string $accessToken, array $orderIds, string $mode = 'public'): array
    {
        $payload = ['orders' => array_values($orderIds)];
        if (in_array($mode, ['private', 'public'], true)) {
            $payload['mode'] = $mode;
        }

        return $this->postJson(self::PRINT_ENDPOINT, $accessToken, $payload);
    }

    /**
     * @return array{body:string,content_type:string}
     */
    public function printFile(string $accessToken, string $orderId, string $format = 'pdf'): array
    {
        $format = in_array($format, ['pdf', 'zpl', 'jpeg'], true) ? $format : 'pdf';
        $endpoint = $this->baseUrl() . sprintf(self::PRINT_FILE_ENDPOINT, rawurlencode($format), rawurlencode($orderId));

        $response = wp_remote_get($endpoint, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => $format === 'pdf' ? 'application/pdf' : '*/*',
                'User-Agent' => $this->userAgent(),
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Melhor Envio HTTP error', [
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ]);

            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300 || $body === '') {
            $data = json_decode($body, true);
            $message = $this->errorMessage(is_array($data) ? $data : null, $body);
            $this->logger->error('Melhor Envio print file invalid response', [
                'endpoint' => $endpoint,
                'status' => $status,
                'message' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        return [
            'body' => $body,
            'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
        ];
    }

    private function baseUrl(): string
    {
        return Options::get('melhor_envio_environment', 'production') === 'sandbox'
            ? self::SANDBOX_BASE_URL
            : self::PRODUCTION_BASE_URL;
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

    /**
     * @param array<string,mixed> $payload
     * @return array<mixed>
     */
    private function postJson(string $path, string $accessToken, array $payload): array
    {
        $endpoint = $this->baseUrl() . $path;
        $response = wp_remote_post($endpoint, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $this->userAgent(),
            ],
            'body' => wp_json_encode($payload),
        ]);

        return $this->decodeResponse($response, $endpoint);
    }

    /**
     * @return array<mixed>
     */
    private function decodeResponse(array|WP_Error $response, string $endpoint): array
    {
        if (is_wp_error($response)) {
            $this->logger->error('Melhor Envio HTTP error', [
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ]);

            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status >= 200 && $status < 300 && trim($body) === '') {
            return [];
        }

        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300 || ! is_array($data)) {
            $message = $this->errorMessage(is_array($data) ? $data : null, $body);

            $this->logger->error('Melhor Envio invalid response', [
                'endpoint' => $endpoint,
                'status' => $status,
                'message' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        return $data;
    }

    private function errorMessage(?array $data, string $body): string
    {
        if (is_array($data)) {
            foreach (['message', 'error_description', 'error'] as $key) {
                if (! empty($data[$key]) && is_scalar($data[$key])) {
                    return sanitize_text_field((string) $data[$key]);
                }
            }

            if (! empty($data['errors']) && is_array($data['errors'])) {
                $first = reset($data['errors']);
                if (is_array($first)) {
                    $first = reset($first);
                }
                if (is_scalar($first)) {
                    return sanitize_text_field((string) $first);
                }
            }
        }

        $body = trim(wp_strip_all_tags($body));
        if ($body !== '') {
            return sanitize_text_field(substr($body, 0, 200));
        }

        return 'Resposta invalida do Melhor Envio.';
    }
}
