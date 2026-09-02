<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class Options
{
    private const DEFAULT_MELHOR_ENVIO_SERVICE_IDS = ['1', '2', '17', '3', '4'];

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $options = get_option('correios_seller_settings', []);

        return is_array($options) ? $options : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $option = get_option('correios_seller_' . $key, null);
        if ($option !== null) {
            return $option;
        }

        $options = self::all();

        return array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /**
     * @return array<int,string>
     */
    public static function enabledServices(string $gateway = 'melhor_envio'): array
    {
        $key = 'melhor_envio_enabled_services_csv';
        $legacyKey = 'melhor_envio_enabled_services';
        $csv = trim((string) self::get($key, ''));

        if ($csv !== '') {
            return self::sanitizeServiceIds(explode(',', $csv));
        }

        $services = self::sanitizeServiceIds((array) self::get($legacyKey, []));

        return $services !== [] ? $services : self::defaultMelhorEnvioServiceIds();
    }

    /**
     * @return array<int,string>
     */
    public static function defaultMelhorEnvioServiceIds(): array
    {
        $services = apply_filters('frete_marketplace_default_melhor_envio_services', self::DEFAULT_MELHOR_ENVIO_SERVICE_IDS);

        return self::sanitizeServiceIds((array) $services);
    }

    public static function defaultMelhorEnvioServicesCsv(): string
    {
        return implode(',', self::defaultMelhorEnvioServiceIds());
    }

    public static function shippingGateway(): string
    {
        return 'melhor_envio';
    }

    public static function fallbackGateway(): string
    {
        return 'none';
    }

    public static function melhorEnvioAccountMode(): string
    {
        $mode = (string) self::get('melhor_envio_account_mode', '');
        if (in_array($mode, ['admin', 'seller'], true)) {
            return $mode;
        }

        $legacyMode = (string) self::get('credential_mode', 'admin');
        $legacyResponsibility = (string) self::get('logistics_responsibility', 'marketplace');

        return ($legacyMode === 'vendor' && $legacyResponsibility !== 'marketplace') ? 'seller' : 'admin';
    }

    /**
     * @param array<int|string,mixed> $services
     * @return array<int,string>
     */
    private static function sanitizeServiceIds(array $services): array
    {
        $sanitized = [];

        foreach ($services as $service) {
            $serviceId = preg_replace('/\D+/', '', (string) $service);
            if ($serviceId === null || $serviceId === '' || in_array($serviceId, $sanitized, true)) {
                continue;
            }

            $sanitized[] = $serviceId;
        }

        return $sanitized;
    }
}
