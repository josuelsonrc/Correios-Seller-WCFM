<?php

declare(strict_types=1);

namespace CorreiosSeller\WCFM;

use CorreiosSeller\Labels\LabelRepository;
use CorreiosSeller\Labels\MarketplaceLabelService;
use CorreiosSeller\Support\Options;

final class OrderLabelActions
{
    public function __construct(
        private MarketplaceLabelService $labelService,
        private LabelRepository $labels
    ) {
    }

    public function register(): void
    {
        add_action('after_wcfm_orders_details_items', [$this, 'renderOrderBlock'], 30, 3);
        add_action('admin_post_correios_seller_generate_label', [$this, 'handleGenerate']);
    }

    public function renderOrderBlock($orderId, $order = null, $lineItems = []): void
    {
        if (Options::get('labels_enabled', 'yes') !== 'yes') {
            return;
        }

        $order = $order instanceof \WC_Order ? $order : wc_get_order(absint($orderId));
        if (! $order instanceof \WC_Order) {
            return;
        }

        $vendorIds = $this->visibleVendorIds($order);
        if ($vendorIds === []) {
            return;
        }

        $notice = $this->notice();

        echo '<div class="page_collapsible orders_details_correios_seller" id="wcfm_orders_correios_seller_label_options">';
        echo esc_html__('Etiquetas Correios', 'correios-seller');
        echo '<span></span></div>';
        echo '<div class="wcfm-container"><div id="wcfm_orders_correios_seller_label_expander" class="wcfm-content">';

        if ($notice !== '') {
            echo wp_kses_post($notice);
        }

        echo '<table class="woocommerce_order_items widefat" style="width:100%"><tbody>';
        foreach ($vendorIds as $vendorId) {
            $label = $this->labels->get($order, $vendorId);
            $status = (string) ($label['status'] ?? 'not_generated');
            $trackingCode = (string) ($label['tracking_code'] ?? '');

            echo '<tr>';
            echo '<td class="name">';
            echo '<strong>' . esc_html($this->vendorName($vendorId)) . '</strong><br />';
            echo '<span>' . esc_html($this->statusLabel($status)) . '</span>';
            if ($trackingCode !== '') {
                echo '<br /><span>' . esc_html__('Rastreio:', 'correios-seller') . ' ' . esc_html($trackingCode) . '</span>';
            }
            echo '</td>';

            echo '<td class="line_cost" style="text-align:right">';
            if (! empty($label['label_url'])) {
                echo '<a class="wcfm_submit_button" target="_blank" href="' . esc_url((string) $label['label_url']) . '">';
                echo esc_html__('Baixar etiqueta', 'correios-seller');
                echo '</a> ';
            }

            if ($this->canGenerate($order, $vendorId)) {
                $buttonLabel = in_array($status, ['label_requested', 'label_pending'], true)
                    ? __('Atualizar etiqueta', 'correios-seller')
                    : __('Gerar etiqueta', 'correios-seller');

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0">';
                echo '<input type="hidden" name="action" value="correios_seller_generate_label" />';
                echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '" />';
                echo '<input type="hidden" name="vendor_id" value="' . esc_attr((string) $vendorId) . '" />';
                wp_nonce_field('correios_seller_generate_label_' . $order->get_id() . '_' . $vendorId);
                echo '<button type="submit" class="wcfm_submit_button">' . esc_html($buttonLabel) . '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<div class="wcfm_clearfix"></div>';
        echo '</div></div>';
    }

    public function handleGenerate(): void
    {
        $orderId = absint($_POST['order_id'] ?? 0);
        $vendorId = absint($_POST['vendor_id'] ?? 0);
        $nonce = (string) ($_POST['_wpnonce'] ?? '');

        if ($orderId <= 0 || $vendorId <= 0 || ! wp_verify_nonce($nonce, 'correios_seller_generate_label_' . $orderId . '_' . $vendorId)) {
            $this->redirectBack('error', 'Solicitacao de etiqueta invalida.');
        }

        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            $this->redirectBack('error', 'Pedido invalido.');
        }

        if (! $this->canGenerate($order, $vendorId)) {
            $this->redirectBack('error', 'Voce nao tem permissao para gerar esta etiqueta.');
        }

        try {
            $label = $this->labelService->generateForOrder($order, $vendorId);
            $status = ($label['status'] ?? '') === 'generated' ? 'success' : 'pending';
            $message = $status === 'success'
                ? 'Etiqueta Correios gerada com sucesso.'
                : 'Etiqueta solicitada aos Correios. Clique em atualizar etiqueta em alguns segundos.';

            $this->redirectBack($status, $message);
        } catch (\Throwable $exception) {
            $this->redirectBack('error', $exception->getMessage());
        }
    }

    /**
     * @return array<int,int>
     */
    private function visibleVendorIds(\WC_Order $order): array
    {
        if (function_exists('wcfm_is_vendor') && wcfm_is_vendor()) {
            $vendorId = get_current_user_id();

            return $this->orderHasVendorItems($order, $vendorId) ? [$vendorId] : [];
        }

        return $this->labelService->vendorIdsForOrder($order);
    }

    private function canGenerate(\WC_Order $order, int $vendorId): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        if (! is_user_logged_in()) {
            return false;
        }

        if (function_exists('wcfm_is_vendor') && wcfm_is_vendor() && get_current_user_id() === $vendorId) {
            return $this->orderHasVendorItems($order, $vendorId);
        }

        return false;
    }

    private function orderHasVendorItems(\WC_Order $order, int $vendorId): bool
    {
        foreach ($this->labelService->vendorIdsForOrder($order) as $orderVendorId) {
            if ($orderVendorId === $vendorId) {
                return true;
            }
        }

        return false;
    }

    private function notice(): string
    {
        $status = sanitize_key((string) ($_GET['correios_seller_label_status'] ?? ''));
        $message = sanitize_text_field((string) ($_GET['correios_seller_label_message'] ?? ''));
        if ($status === '' || $message === '') {
            return '';
        }

        $class = $status === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        if ($status === 'pending') {
            $class = 'woocommerce-info';
        }

        return '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private function redirectBack(string $status, string $message): void
    {
        $url = wp_get_referer() ?: admin_url();
        $url = remove_query_arg(['correios_seller_label_status', 'correios_seller_label_message'], $url);
        $url = add_query_arg([
            'correios_seller_label_status' => sanitize_key($status),
            'correios_seller_label_message' => $message,
        ], $url);

        wp_safe_redirect($url);
        exit;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'preposted' => __('Pre-postagem criada', 'correios-seller'),
            'label_requested' => __('Rotulo solicitado', 'correios-seller'),
            'label_pending' => __('Rotulo em processamento', 'correios-seller'),
            'generated' => __('Etiqueta gerada', 'correios-seller'),
            'error' => __('Erro na etiqueta', 'correios-seller'),
            default => __('Etiqueta nao gerada', 'correios-seller'),
        };
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
}
