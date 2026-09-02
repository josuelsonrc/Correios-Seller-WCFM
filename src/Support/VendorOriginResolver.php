<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class VendorOriginResolver
{
    /**
     * @param array<string,mixed> $vendorSettings
     */
    public function postcode(int $vendorId, array $vendorSettings): string
    {
        $configured = $this->normalize((string) ($vendorSettings['origin_postcode'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $wcfmSettings = get_user_meta($vendorId, 'wcfmmp_profile_settings', true);
        if (! is_array($wcfmSettings)) {
            $wcfmSettings = get_user_meta($vendorId, '_wcfm_store_settings', true);
        }

        if (is_array($wcfmSettings)) {
            $address = is_array($wcfmSettings['address'] ?? null) ? $wcfmSettings['address'] : [];
            foreach (['zip', 'postcode', 'postal_code'] as $key) {
                $postcode = $this->normalize((string) ($address[$key] ?? ''));
                if ($postcode !== '') {
                    return $postcode;
                }
            }
        }

        foreach (['shipping_postcode', 'billing_postcode'] as $metaKey) {
            $postcode = $this->normalize((string) get_user_meta($vendorId, $metaKey, true));
            if ($postcode !== '') {
                return $postcode;
            }
        }

        if (function_exists('WC') && WC()->countries) {
            return $this->normalize((string) WC()->countries->get_base_postcode());
        }

        return '';
    }

    private function normalize(string $postcode): string
    {
        $postcode = preg_replace('/\D+/', '', $postcode);

        return strlen($postcode) === 8 ? $postcode : '';
    }
}
