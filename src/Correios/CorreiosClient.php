<?php

declare(strict_types=1);

namespace CorreiosSeller\Correios;

use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;
use WP_Error;

final class CorreiosClient
{
    private const PRODUCTION_BASE_URL = 'https://api.correios.com.br';
    private const HOMOLOGATION_BASE_URL = 'https://apihom.correios.com.br';
    private const TOKEN_ENDPOINT = '/token/v1/autentica';
    private const TOKEN_POSTING_CARD_ENDPOINT = '/token/v1/autentica/cartaopostagem';
    private const PRICE_ENDPOINT = '/preco/v1/nacional';
    private const DEADLINE_ENDPOINT = '/prazo/v3/v1/nacional';
    private const PRE_POSTING_ENDPOINT = '/prepostagem/v1/prepostagens';
    private const LABEL_ASYNC_ENDPOINT = '/prepostagem/v1/prepostagens/rotulo/assincrono/pdf';
    private const LABEL_DOWNLOAD_ENDPOINT = '/prepostagem/v1/prepostagens/rotulo/download/assincrono/%s';

    public function __construct(
        private Cache $cache,
        private Logger $logger
    ) {
    }

    public function quote(Credentials $credentials, array $payload): array
    {
        $headers = $this->jsonHeaders($credentials);

        $price = $this->postJson($this->url(self::PRICE_ENDPOINT), $headers, $payload['price']);
        $deadline = $this->postJson($this->url(self::DEADLINE_ENDPOINT), $headers, $payload['deadline']);

        return [
            'price' => $price,
            'deadline' => $deadline,
        ];
    }

    public function createPrePosting(Credentials $credentials, array $payload): array
    {
        return $this->postJson($this->url(self::PRE_POSTING_ENDPOINT), $this->jsonHeaders($credentials), $payload);
    }

    public function requestAsyncLabel(Credentials $credentials, array $payload): array
    {
        return $this->postJson($this->url(self::LABEL_ASYNC_ENDPOINT), $this->jsonHeaders($credentials), $payload);
    }

    public function downloadAsyncLabel(Credentials $credentials, string $receiptId): array
    {
        $endpoint = sprintf(self::LABEL_DOWNLOAD_ENDPOINT, rawurlencode($receiptId));

        return $this->getJson($this->url($endpoint), $this->jsonHeaders($credentials));
    }

    private function jsonHeaders(Credentials $credentials): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token($credentials),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function token(Credentials $credentials): string
    {
        $cacheKey = 'token_' . Options::get('api_environment', 'production') . '_' . $credentials->cacheKey();

        return (string) $this->cache->remember($cacheKey, 45 * MINUTE_IN_SECONDS, function () use ($credentials): string {
            $headers = [
                'Authorization' => 'Basic ' . base64_encode($credentials->username . ':' . $credentials->password),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            $body = [];
            $endpoint = self::TOKEN_ENDPOINT;
            if ($credentials->postingCard !== '') {
                $body['numero'] = $credentials->postingCard;
                $endpoint = self::TOKEN_POSTING_CARD_ENDPOINT;
            }
            if ($credentials->adminCode !== '') {
                $body['contrato'] = $credentials->adminCode;
            }

            $response = wp_remote_post($this->url($endpoint), [
                'timeout' => 20,
                'headers' => $headers,
                'body' => $body === [] ? null : wp_json_encode($body),
            ]);

            $data = $this->decodeResponse($response, $this->url($endpoint));
            $token = (string) ($data['token'] ?? '');

            if ($token === '') {
                throw new \RuntimeException('Token dos Correios nao retornado.');
            }

            return $token;
        });
    }

    private function getJson(string $endpoint, array $headers): array
    {
        $this->logger->info('Correios request', [
            'endpoint' => $endpoint,
            'method' => 'GET',
        ]);

        $response = wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => $headers,
        ]);

        $data = $this->decodeResponse($response, $endpoint);

        $this->logger->info('Correios response', [
            'endpoint' => $endpoint,
            'response' => $data,
        ]);

        return $data;
    }

    private function postJson(string $endpoint, array $headers, array $payload): array
    {
        $this->logger->info('Correios request', [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ]);

        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
        ]);

        $data = $this->decodeResponse($response, $endpoint);

        $this->logger->info('Correios response', [
            'endpoint' => $endpoint,
            'response' => $data,
        ]);

        return $data;
    }

    private function url(string $endpoint): string
    {
        $baseUrl = Options::get('api_environment', 'production') === 'homologation'
            ? self::HOMOLOGATION_BASE_URL
            : self::PRODUCTION_BASE_URL;

        return $baseUrl . $endpoint;
    }

    private function decodeResponse(array|WP_Error $response, string $endpoint): array
    {
        if (is_wp_error($response)) {
            $this->logger->error('Correios HTTP error', [
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ]);

            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300 || ! is_array($data)) {
            $this->logger->error('Correios invalid response', [
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $body,
            ]);

            throw new \RuntimeException('Resposta invalida dos Correios.');
        }

        return $data;
    }
}
