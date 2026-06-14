<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class Options
{
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
        $options = self::all();

        if (array_key_exists($key, $options)) {
            return $options[$key];
        }

        $option = get_option('correios_seller_' . $key, null);

        return $option !== null ? $option : $default;
    }

    /**
     * @return array<int,string>
     */
    public static function enabledServices(): array
    {
        $csv = (string) self::get('enabled_services_csv', '');
        $services = $csv !== '' ? explode(',', $csv) : (array) self::get('enabled_services', []);

        return array_values(array_filter(array_map(static fn ($service) => preg_replace('/\D+/', '', (string) $service), $services)));
    }
}
