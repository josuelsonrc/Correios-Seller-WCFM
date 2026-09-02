<?php

declare(strict_types=1);

namespace CorreiosSeller\MelhorEnvio;

use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Logger;
use CorreiosSeller\Support\Options;

final class MelhorEnvioTokenResolver
{
    /** @var array<string,string> */
    private array $resolved = [];

    public function __construct(
        private VendorSettingsRepository $repository,
        private MelhorEnvioOAuthService $oauth,
        private Logger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $vendorSettings
     */
    public function resolve(array $vendorSettings, int $vendorId): string
    {
        $centralized = Options::melhorEnvioAccountMode() === 'admin';
        $cacheKey = ($centralized ? 'admin' : 'vendor') . ':' . $vendorId;

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        if ($centralized) {
            $token = trim((string) Options::get('melhor_envio_admin_access_token', ''));
            $refreshToken = trim((string) Options::get('melhor_envio_admin_refresh_token', ''));
            $expiresAt = (int) Options::get('melhor_envio_admin_token_expires_at', 0);

            if ($this->shouldRefresh($token, $expiresAt, $refreshToken)) {
                $refreshed = $this->refresh($refreshToken);
                $token = (string) $refreshed['access_token'];
                update_option('correios_seller_melhor_envio_admin_access_token', $token, false);
                update_option('correios_seller_melhor_envio_admin_refresh_token', (string) ($refreshed['refresh_token'] ?? $refreshToken), false);
                update_option('correios_seller_melhor_envio_admin_token_expires_at', (int) ($refreshed['expires_at'] ?? 0), false);
            }
        } else {
            $token = trim((string) ($vendorSettings['melhor_envio_access_token'] ?? ''));
            $refreshToken = trim((string) ($vendorSettings['melhor_envio_refresh_token'] ?? ''));
            $expiresAt = (int) ($vendorSettings['melhor_envio_token_expires_at'] ?? 0);

            if ($this->shouldRefresh($token, $expiresAt, $refreshToken)) {
                $refreshed = $this->refresh($refreshToken);
                $token = (string) $refreshed['access_token'];
                $this->repository->merge($vendorId, [
                    'melhor_envio_access_token' => $token,
                    'melhor_envio_refresh_token' => (string) ($refreshed['refresh_token'] ?? $refreshToken),
                    'melhor_envio_token_expires_at' => (int) ($refreshed['expires_at'] ?? 0),
                ]);
            }
        }

        $this->resolved[$cacheKey] = $token;

        return $token;
    }

    private function shouldRefresh(string $token, int $expiresAt, string $refreshToken): bool
    {
        return ($token === '' || ($expiresAt > 0 && $expiresAt <= (time() + 120)))
            && $refreshToken !== ''
            && $this->oauth->isConfigured();
    }

    /**
     * @return array<string,mixed>
     */
    private function refresh(string $refreshToken): array
    {
        try {
            return $this->oauth->refresh($refreshToken);
        } catch (\Throwable $exception) {
            $this->logger->error('Falha ao renovar token do Melhor Envio.', ['error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
