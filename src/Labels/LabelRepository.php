<?php

declare(strict_types=1);

namespace CorreiosSeller\Labels;

final class LabelRepository
{
    public const META_KEY = '_frete_marketplace_melhor_envio_labels';

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(\WC_Order $order): array
    {
        $labels = $order->get_meta(self::META_KEY, true);

        return is_array($labels) ? $labels : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function get(\WC_Order $order, int $vendorId): array
    {
        $labels = $this->all($order);
        $label = $labels[$vendorId] ?? $labels[(string) $vendorId] ?? [];

        return is_array($label) ? $label : [];
    }

    /**
     * @param array<string,mixed> $label
     */
    public function save(\WC_Order $order, int $vendorId, array $label): void
    {
        $labels = $this->all($order);
        $previous = $labels[$vendorId] ?? $labels[(string) $vendorId] ?? [];
        $labels[$vendorId] = array_merge(is_array($previous) ? $previous : [], $label, [
            'vendor_id' => $vendorId,
            'updated_at' => current_time('mysql'),
        ]);

        $order->update_meta_data(self::META_KEY, $labels);
        $order->save();
    }
}
