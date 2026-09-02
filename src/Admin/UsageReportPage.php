<?php

declare(strict_types=1);

namespace CorreiosSeller\Admin;

use CorreiosSeller\Repository\QuoteUsageRepository;

final class UsageReportPage
{
    public function __construct(private QuoteUsageRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage'], 60);
    }

    public function addPage(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Relatorio de frete', 'correios-seller'),
            __('Relatorio de frete', 'correios-seller'),
            'manage_woocommerce',
            'correios-seller-usage',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $rows = $this->repository->summary(30);

        echo '<div class="wrap"><h1>' . esc_html__('Utilizacao de frete por vendedor', 'correios-seller') . '</h1>';
        echo '<p>' . esc_html__('Cotacoes registradas nos ultimos 30 dias.', 'correios-seller') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Vendedor', 'correios-seller'), __('Gateway', 'correios-seller'), __('Cotacoes', 'correios-seller'), __('Falhas', 'correios-seller'), __('Fallbacks', 'correios-seller'), __('Valor medio', 'correios-seller'), __('Ultima consulta', 'correios-seller')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="7">' . esc_html__('Ainda nao ha cotacoes registradas.', 'correios-seller') . '</td></tr>';
        }

        foreach ($rows as $row) {
            $user = get_userdata((int) $row['vendor_id']);
            $vendor = $user ? $user->display_name : sprintf('#%d', (int) $row['vendor_id']);
            echo '<tr>';
            echo '<td>' . esc_html($vendor) . '</td>';
            echo '<td>' . esc_html($this->gatewayLabel((string) $row['gateway'])) . '</td>';
            echo '<td>' . esc_html((string) $row['requests']) . '</td>';
            echo '<td>' . esc_html((string) $row['errors']) . '</td>';
            echo '<td>' . esc_html((string) $row['fallbacks']) . '</td>';
            echo '<td>' . wp_kses_post($row['average_amount'] !== null ? wc_price((float) $row['average_amount']) : '-') . '</td>';
            echo '<td>' . esc_html((string) $row['last_request']) . ' UTC</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function gatewayLabel(string $gateway): string
    {
        return $gateway === 'melhor_envio' ? __('Melhor Envio', 'correios-seller') : $gateway;
    }
}
