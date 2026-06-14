<?php

declare(strict_types=1);

namespace CorreiosSeller\Rest;

use CorreiosSeller\Repository\VendorSettingsRepository;
use WP_REST_Request;
use WP_REST_Response;

final class VendorSettingsController
{
    private const NAMESPACE = 'correios-seller/v1';

    public function __construct(private VendorSettingsRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NAMESPACE, '/vendor-settings', [
                [
                    'methods' => 'GET',
                    'callback' => [$this, 'show'],
                    'permission_callback' => [$this, 'canManageCurrentVendor'],
                ],
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'update'],
                    'permission_callback' => [$this, 'canManageCurrentVendor'],
                ],
            ]);
        });
    }

    public function canManageCurrentVendor(): bool
    {
        return is_user_logged_in() && (current_user_can('manage_woocommerce') || current_user_can('wcfm_vendor') || current_user_can('seller'));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->repository->get(get_current_user_id()));
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $params = (array) $request->get_json_params();
        $this->repository->save(get_current_user_id(), $params);

        return new WP_REST_Response($this->repository->get(get_current_user_id()));
    }
}
