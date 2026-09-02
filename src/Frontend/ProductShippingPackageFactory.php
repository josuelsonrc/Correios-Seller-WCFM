<?php

declare(strict_types=1);

namespace CorreiosSeller\Frontend;

use CorreiosSeller\Support\ProductVendorResolver;

final class ProductShippingPackageFactory
{
    public function __construct(private ProductVendorResolver $vendorResolver)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(\WC_Product $product, int $quantity, string $postcode): array
    {
        $quantity = max(1, $quantity);
        $ownerProductId = $this->vendorResolver->ownerProductId($product);
        $vendorId = $this->vendorResolver->resolveFromProductId($ownerProductId);

        if ($vendorId <= 0) {
            throw new \RuntimeException('Vendedor do produto nao identificado.');
        }

        $lineTotal = (float) wc_get_price_excluding_tax($product, ['qty' => $quantity]);
        $lineTotalWithTax = (float) wc_get_price_including_tax($product, ['qty' => $quantity]);
        $lineTax = max(0.0, $lineTotalWithTax - $lineTotal);
        $cartItemKey = 'frete_marketplace_product_' . md5($product->get_id() . '|' . $quantity . '|' . $postcode);

        $item = [
            'key' => $cartItemKey,
            'product_id' => $ownerProductId,
            'variation_id' => $product->is_type('variation') ? $product->get_id() : 0,
            'variation' => [],
            'quantity' => $quantity,
            'data' => $product,
            'line_total' => $lineTotal,
            'line_subtotal' => $lineTotal,
            'line_tax' => $lineTax,
            'line_subtotal_tax' => $lineTax,
            'line_tax_data' => [
                'total' => [],
                'subtotal' => [],
            ],
        ];

        return [
            'contents' => [$cartItemKey => $item],
            'contents_cost' => $lineTotal,
            'applied_coupons' => $this->appliedCoupons(),
            'user' => [
                'ID' => get_current_user_id(),
            ],
            'destination' => [
                'country' => 'BR',
                'state' => $this->stateFromPostcode($postcode),
                'postcode' => $postcode,
                'city' => '',
                'address' => '',
                'address_1' => '',
                'address_2' => '',
            ],
            'cart_subtotal' => $lineTotal,
            'seller_id' => $vendorId,
            'vendor_id' => $vendorId,
            'package_id' => 'frete_marketplace_product_' . $cartItemKey,
            'package_name' => sprintf(__('Produto de %s', 'correios-seller'), $this->vendorName($vendorId)),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function appliedCoupons(): array
    {
        if (function_exists('WC') && WC()->cart) {
            return (array) WC()->cart->get_applied_coupons();
        }

        return [];
    }

    private function vendorName(int $vendorId): string
    {
        if (function_exists('wcfm_get_vendor_store_name')) {
            $name = (string) wcfm_get_vendor_store_name($vendorId);
            if ($name !== '') {
                return $name;
            }
        }

        $user = get_userdata($vendorId);

        return $user ? $user->display_name : sprintf('Vendor #%d', $vendorId);
    }

    private function stateFromPostcode(string $postcode): string
    {
        $prefix = (int) substr(preg_replace('/\D+/', '', $postcode), 0, 5);

        return match (true) {
            $prefix >= 1000 && $prefix <= 19999 => 'SP',
            $prefix >= 20000 && $prefix <= 28999 => 'RJ',
            $prefix >= 29000 && $prefix <= 29999 => 'ES',
            $prefix >= 30000 && $prefix <= 39999 => 'MG',
            $prefix >= 40000 && $prefix <= 48999 => 'BA',
            $prefix >= 49000 && $prefix <= 49999 => 'SE',
            $prefix >= 50000 && $prefix <= 56999 => 'PE',
            $prefix >= 57000 && $prefix <= 57999 => 'AL',
            $prefix >= 58000 && $prefix <= 58999 => 'PB',
            $prefix >= 59000 && $prefix <= 59999 => 'RN',
            $prefix >= 60000 && $prefix <= 63999 => 'CE',
            $prefix >= 64000 && $prefix <= 64999 => 'PI',
            $prefix >= 65000 && $prefix <= 65999 => 'MA',
            $prefix >= 66000 && $prefix <= 68899 => 'PA',
            $prefix >= 68900 && $prefix <= 68999 => 'AP',
            $prefix >= 69000 && $prefix <= 69299 => 'AM',
            $prefix >= 69300 && $prefix <= 69399 => 'RR',
            $prefix >= 69400 && $prefix <= 69899 => 'AM',
            $prefix >= 69900 && $prefix <= 69999 => 'AC',
            $prefix >= 70000 && $prefix <= 72799 => 'DF',
            $prefix >= 72800 && $prefix <= 72999 => 'GO',
            $prefix >= 73000 && $prefix <= 73699 => 'DF',
            $prefix >= 73700 && $prefix <= 76799 => 'GO',
            $prefix >= 77000 && $prefix <= 77999 => 'TO',
            $prefix >= 78000 && $prefix <= 78899 => 'MT',
            $prefix >= 78900 && $prefix <= 78999 => 'RO',
            $prefix >= 79000 && $prefix <= 79999 => 'MS',
            $prefix >= 80000 && $prefix <= 87999 => 'PR',
            $prefix >= 88000 && $prefix <= 89999 => 'SC',
            $prefix >= 90000 && $prefix <= 99999 => 'RS',
            default => '',
        };
    }
}
