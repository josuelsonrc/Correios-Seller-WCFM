<?php

declare(strict_types=1);

namespace CorreiosSeller\Repository;

final class VendorSettingsRepository
{
    public const META_KEY = '_correios_seller_settings';

    /**
     * @return array<string,mixed>
     */
    public function get(int $vendorId): array
    {
        $settings = get_user_meta($vendorId, self::META_KEY, true);
        $settings = is_array($settings) ? $settings : [];

        return wp_parse_args($settings, [
            'origin_postcode' => '',
            'posting_address' => '',
            'sender_name' => '',
            'sender_email' => '',
            'sender_document' => '',
            'sender_phone' => '',
            'sender_street' => '',
            'sender_number' => '',
            'sender_complement' => '',
            'sender_district' => '',
            'sender_city' => '',
            'sender_state' => '',
            'default_weight' => '0.3',
            'default_length' => '16',
            'default_width' => '11',
            'default_height' => '2',
            'handling_days' => '0',
            'enabled' => 'yes',
            'melhor_envio_access_token' => '',
            'melhor_envio_refresh_token' => '',
            'melhor_envio_token_expires_at' => 0,
            'melhor_envio_enabled_services' => [],
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     */
    public function save(int $vendorId, array $settings): void
    {
        $merged = array_merge($this->get($vendorId), $settings);
        update_user_meta($vendorId, self::META_KEY, $this->sanitize($merged));
    }

    /**
     * @param array<string,mixed> $settings
     */
    public function merge(int $vendorId, array $settings): void
    {
        $this->save($vendorId, $settings);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    public function sanitize(array $settings): array
    {
        $melhorEnvioServices = $settings['melhor_envio_enabled_services'] ?? [];
        if (is_string($melhorEnvioServices)) {
            $melhorEnvioServices = explode(',', $melhorEnvioServices);
        }

        return [
            'origin_postcode' => preg_replace('/\D+/', '', (string) ($settings['origin_postcode'] ?? '')),
            'posting_address' => sanitize_textarea_field((string) ($settings['posting_address'] ?? '')),
            'sender_name' => sanitize_text_field((string) ($settings['sender_name'] ?? '')),
            'sender_email' => sanitize_email((string) ($settings['sender_email'] ?? '')),
            'sender_document' => preg_replace('/\D+/', '', (string) ($settings['sender_document'] ?? '')),
            'sender_phone' => preg_replace('/\D+/', '', (string) ($settings['sender_phone'] ?? '')),
            'sender_street' => sanitize_text_field((string) ($settings['sender_street'] ?? '')),
            'sender_number' => sanitize_text_field((string) ($settings['sender_number'] ?? '')),
            'sender_complement' => sanitize_text_field((string) ($settings['sender_complement'] ?? '')),
            'sender_district' => sanitize_text_field((string) ($settings['sender_district'] ?? '')),
            'sender_city' => sanitize_text_field((string) ($settings['sender_city'] ?? '')),
            'sender_state' => strtoupper(substr(sanitize_text_field((string) ($settings['sender_state'] ?? '')), 0, 2)),
            'default_weight' => wc_format_decimal($settings['default_weight'] ?? '0.3'),
            'default_length' => wc_format_decimal($settings['default_length'] ?? '16'),
            'default_width' => wc_format_decimal($settings['default_width'] ?? '11'),
            'default_height' => wc_format_decimal($settings['default_height'] ?? '2'),
            'handling_days' => absint($settings['handling_days'] ?? 0),
            'enabled' => (($settings['enabled'] ?? 'yes') === 'yes') ? 'yes' : 'no',
            'melhor_envio_access_token' => sanitize_text_field((string) ($settings['melhor_envio_access_token'] ?? '')),
            'melhor_envio_refresh_token' => sanitize_text_field((string) ($settings['melhor_envio_refresh_token'] ?? '')),
            'melhor_envio_token_expires_at' => absint($settings['melhor_envio_token_expires_at'] ?? 0),
            'melhor_envio_enabled_services' => array_values(array_filter(array_map(static fn ($service) => preg_replace('/\D+/', '', (string) $service), (array) $melhorEnvioServices))),
        ];
    }
}
