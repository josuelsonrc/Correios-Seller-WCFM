<?php

declare(strict_types=1);

namespace CorreiosSeller\Correios;

use CorreiosSeller\Support\Options;

final class CredentialsResolver
{
    /**
     * @param array<string,mixed> $vendorSettings
     */
    public function resolve(array $vendorSettings): Credentials
    {
        if (Options::get('logistics_responsibility', 'marketplace') === 'marketplace') {
            return $this->resolveMarketplace();
        }

        $globalMode = (string) Options::get('credential_mode', 'admin');
        $vendorMode = (string) ($vendorSettings['credential_mode'] ?? 'inherit');
        $useVendor = $globalMode === 'vendor' || $vendorMode === 'vendor';

        if ($useVendor) {
            return new Credentials(
                (string) ($vendorSettings['api_username'] ?? ''),
                (string) ($vendorSettings['api_password'] ?? ''),
                (string) ($vendorSettings['posting_card'] ?? ''),
                (string) ($vendorSettings['admin_code'] ?? '')
            );
        }

        return new Credentials(
            (string) Options::get('admin_username', ''),
            (string) Options::get('admin_password', ''),
            (string) Options::get('admin_posting_card', ''),
            (string) Options::get('admin_code', '')
        );
    }

    public function resolveMarketplace(): Credentials
    {
        return new Credentials(
            (string) Options::get('admin_username', ''),
            (string) Options::get('admin_password', ''),
            (string) Options::get('admin_posting_card', ''),
            (string) Options::get('admin_code', '')
        );
    }
}
