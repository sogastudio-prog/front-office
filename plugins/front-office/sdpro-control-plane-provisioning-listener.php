<?php
/**
 * Plugin Name: SDPRO Control Plane Provisioning Listener
 * Description: Receives tenant provisioning requests from the SoloDrive control plane and creates or updates runtime tenant records idempotently.
 * Version: 0.1.0
 * Author: SoloDrive
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SDPRO_Control_Plane_Provisioning_Listener {
    private const REST_NAMESPACE = 'sd/v1';
    private const ROUTE_PROVISION = '/control-plane/provision-tenant';
    private const MANIFEST_OPTION = 'sd_runtime_tenants_manifest';
    private const REQUEST_LOG_OPTION = 'sd_runtime_provisioning_log';
    private const TENANT_POST_TYPE = 'sd_tenant';

    public static function bootstrap(): void {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_rest_routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE_PROVISION, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_provision_tenant'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_provision_tenant(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_body();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = $request->get_json_params();
        }
        if (!is_array($payload)) {
            return self::error_response('invalid_json', 'Expected a JSON object payload.', 400);
        }

        $auth = self::authorize_request($request, $body, $payload);
        if (is_wp_error($auth)) {
            return self::error_response($auth->get_error_code(), $auth->get_error_message(), (int) ($auth->get_error_data()['status'] ?? 401));
        }

        $normalized = self::normalize_payload($payload);
        $validation = self::validate_payload($normalized);
        if (is_wp_error($validation)) {
            return self::error_response($validation->get_error_code(), $validation->get_error_message(), (int) ($validation->get_error_data()['status'] ?? 400));
        }

        $request_id = self::derive_request_id($request, $normalized);
        $existing_log = self::get_request_log($request_id);
        if (is_array($existing_log) && !empty($existing_log['response'])) {
            return new WP_REST_Response([
                'ok' => true,
                'idempotent_replay' => true,
                'request_id' => $request_id,
                'tenant_slug' => $existing_log['tenant_slug'] ?? $normalized['tenant_slug'],
                'runtime_tenant_post_id' => (int) ($existing_log['runtime_tenant_post_id'] ?? 0),
                'owner_user_id' => (int) ($existing_log['owner_user_id'] ?? 0),
                'provisioning_status' => $existing_log['provisioning_status'] ?? 'provisioned',
                'activation_ready' => (bool) ($existing_log['activation_ready'] ?? false),
                'storefront_status' => $existing_log['storefront_status'] ?? 'ready',
                'runtime_storefront_path' => $existing_log['runtime_storefront_path'] ?? ('/t/' . $normalized['tenant_slug']),
            ], 200);
        }

        $owner_user_id = self::ensure_owner_user($normalized);
        $tenant_post_id = self::ensure_runtime_tenant_record($normalized, $owner_user_id);
        $runtime_path = '/t/' . $normalized['tenant_slug'];
        $manifest = self::upsert_runtime_manifest($normalized, $owner_user_id, $tenant_post_id, $request_id, $runtime_path);

        $response = [
            'ok' => true,
            'request_id' => $request_id,
            'tenant_id' => $normalized['tenant_id'],
            'tenant_slug' => $normalized['tenant_slug'],
            'runtime_tenant_post_id' => $tenant_post_id,
            'owner_user_id' => $owner_user_id,
            'provisioning_status' => 'provisioned',
            'activation_ready' => true,
            'storefront_status' => 'ready',
            'runtime_storefront_path' => $runtime_path,
            'manifest_count' => count($manifest),
        ];

        self::store_request_log($request_id, [
            'tenant_slug' => $normalized['tenant_slug'],
            'runtime_tenant_post_id' => $tenant_post_id,
            'owner_user_id' => $owner_user_id,
            'provisioning_status' => 'provisioned',
            'activation_ready' => true,
            'storefront_status' => 'ready',
            'runtime_storefront_path' => $runtime_path,
            'response' => $response,
            'recorded_at_gmt' => current_time('mysql', true),
        ]);

        /**
         * Fires after a control-plane provisioning request has been applied to the runtime.
         *
         * @param array $normalized_payload Normalized control-plane payload.
         * @param int   $tenant_post_id     Runtime tenant post ID if created/found, else 0.
         * @param int   $owner_user_id      Owner user ID if created/found, else 0.
         * @param array $response           REST response payload.
         */
        do_action('sdpro_control_plane_tenant_provisioned', $normalized, $tenant_post_id, $owner_user_id, $response);

        return new WP_REST_Response($response, 200);
    }

    private static function authorize_request(WP_REST_Request $request, string $raw_body, array $payload) {
        $secret = self::get_provisioning_secret();
        if ($secret === '') {
            return new WP_Error('missing_provisioning_secret', 'Missing provisioning secret in SDPRO configuration.', ['status' => 500]);
        }

        $header_secret = trim((string) $request->get_header('x-sd-provisioning-secret'));
        if ($header_secret !== '' && hash_equals($secret, $header_secret)) {
            return true;
        }

        $sig_header = trim((string) $request->get_header('x-sd-signature'));
        if ($sig_header !== '' && $raw_body !== '') {
            $expected = hash_hmac('sha256', $raw_body, $secret);
            if (hash_equals($expected, $sig_header)) {
                return true;
            }
        }

        $payload_secret = isset($payload['provisioning_secret']) ? trim((string) $payload['provisioning_secret']) : '';
        if ($payload_secret !== '' && hash_equals($secret, $payload_secret)) {
            return true;
        }

        return new WP_Error('unauthorized', 'Provisioning authorization failed.', ['status' => 401]);
    }

    private static function normalize_payload(array $payload): array {
        $tenant_slug = sanitize_title((string) ($payload['tenant_slug'] ?? ''));
        $email = sanitize_email((string) ($payload['email'] ?? ''));
        $full_name = sanitize_text_field((string) ($payload['full_name'] ?? ''));

        return [
            'tenant_post_id' => isset($payload['tenant_post_id']) ? absint($payload['tenant_post_id']) : 0,
            'tenant_id' => sanitize_text_field((string) ($payload['tenant_id'] ?? '')),
            'tenant_slug' => $tenant_slug,
            'prospect_post_id' => isset($payload['prospect_post_id']) ? absint($payload['prospect_post_id']) : 0,
            'prospect_id' => sanitize_text_field((string) ($payload['prospect_id'] ?? '')),
            'full_name' => $full_name,
            'email' => $email,
            'phone' => self::normalize_phone((string) ($payload['phone'] ?? '')),
            'stripe_account_id' => sanitize_text_field((string) ($payload['stripe_account_id'] ?? '')),
            'stripe_customer_id' => sanitize_text_field((string) ($payload['stripe_customer_id'] ?? '')),
            'stripe_subscription_id' => sanitize_text_field((string) ($payload['stripe_subscription_id'] ?? '')),
            'billing_status' => sanitize_text_field((string) ($payload['billing_status'] ?? '')),
            'activation_mode' => sanitize_text_field((string) ($payload['activation_mode'] ?? 'inactive_until_provisioned')),
        ];
    }

    private static function validate_payload(array $payload) {
        if ($payload['tenant_id'] === '') {
            return new WP_Error('missing_tenant_id', 'tenant_id is required.', ['status' => 400]);
        }
        if ($payload['tenant_slug'] === '') {
            return new WP_Error('missing_tenant_slug', 'tenant_slug is required.', ['status' => 400]);
        }
        if ($payload['email'] === '') {
            return new WP_Error('missing_email', 'email is required.', ['status' => 400]);
        }
        if ($payload['billing_status'] !== 'paid') {
            return new WP_Error('billing_not_paid', 'billing_status must be paid before runtime provisioning.', ['status' => 409]);
        }
        if ($payload['stripe_account_id'] === '') {
            return new WP_Error('missing_stripe_account_id', 'stripe_account_id is required.', ['status' => 400]);
        }
        return true;
    }

    private static function ensure_owner_user(array $payload): int {
        $existing = get_user_by('email', $payload['email']);
        if ($existing instanceof WP_User) {
            self::update_owner_user_meta((int) $existing->ID, $payload);
            return (int) $existing->ID;
        }

        $base_login = sanitize_user(current(explode('@', $payload['email'])), true);
        if ($base_login === '') {
            $base_login = 'tenantowner';
        }
        $user_login = $base_login;
        $suffix = 2;
        while (username_exists($user_login)) {
            $user_login = $base_login . $suffix;
            $suffix++;
        }

        $display_name = $payload['full_name'] !== '' ? $payload['full_name'] : $payload['tenant_slug'];
        $user_id = wp_insert_user([
            'user_login' => $user_login,
            'user_pass' => wp_generate_password(24, true, true),
            'user_email' => $payload['email'],
            'display_name' => $display_name,
            'first_name' => self::infer_first_name($payload['full_name']),
            'last_name' => self::infer_last_name($payload['full_name']),
            'role' => self::owner_role(),
        ]);

        if (is_wp_error($user_id) || (int) $user_id <= 0) {
            return 0;
        }

        self::update_owner_user_meta((int) $user_id, $payload);
        return (int) $user_id;
    }

    private static function update_owner_user_meta(int $user_id, array $payload): void {
        if ($user_id <= 0) {
            return;
        }

        update_user_meta($user_id, 'sd_tenant_id', $payload['tenant_id']);
        update_user_meta($user_id, 'sd_tenant_slug', $payload['tenant_slug']);
        update_user_meta($user_id, 'sd_stripe_account_id', $payload['stripe_account_id']);
        update_user_meta($user_id, 'sd_stripe_customer_id', $payload['stripe_customer_id']);
        update_user_meta($user_id, 'sd_stripe_subscription_id', $payload['stripe_subscription_id']);
        update_user_meta($user_id, 'sd_control_plane_prospect_id', $payload['prospect_id']);
        update_user_meta($user_id, 'sd_control_plane_prospect_post_id', $payload['prospect_post_id']);
        update_user_meta($user_id, 'sd_phone_normalized', $payload['phone']);
        update_user_meta($user_id, 'sd_provisioned_at_gmt', current_time('mysql', true));
    }

    private static function ensure_runtime_tenant_record(array $payload, int $owner_user_id): int {
        if (!post_type_exists(self::TENANT_POST_TYPE)) {
            return 0;
        }

        $tenant_post_id = self::find_runtime_tenant_post($payload['tenant_id'], $payload['tenant_slug']);
        $postarr = [
            'post_type' => self::TENANT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $payload['full_name'] !== '' ? $payload['full_name'] : $payload['tenant_slug'],
        ];

        if ($tenant_post_id > 0) {
            $postarr['ID'] = $tenant_post_id;
            wp_update_post($postarr);
        } else {
            $tenant_post_id = wp_insert_post($postarr, true);
            if (is_wp_error($tenant_post_id) || (int) $tenant_post_id <= 0) {
                return 0;
            }
            $tenant_post_id = (int) $tenant_post_id;
        }

        update_post_meta($tenant_post_id, 'sd_tenant_id', $payload['tenant_id']);
        update_post_meta($tenant_post_id, 'sd_slug', $payload['tenant_slug']);
        update_post_meta($tenant_post_id, 'sd_status', 'inactive');
        update_post_meta($tenant_post_id, 'sd_origin_prospect_id', $payload['prospect_id']);
        update_post_meta($tenant_post_id, 'sd_origin_prospect_post_id', $payload['prospect_post_id']);
        update_post_meta($tenant_post_id, 'sd_connected_account_id', $payload['stripe_account_id']);
        update_post_meta($tenant_post_id, 'sd_stripe_customer_id', $payload['stripe_customer_id']);
        update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', $payload['stripe_subscription_id']);
        update_post_meta($tenant_post_id, 'sd_billing_status', $payload['billing_status']);
        update_post_meta($tenant_post_id, 'sd_provisioning_status', 'provisioned');
        update_post_meta($tenant_post_id, 'sd_storefront_status', 'ready');
        update_post_meta($tenant_post_id, 'sd_activation_ready', 1);
        update_post_meta($tenant_post_id, 'sd_runtime_storefront_path', '/t/' . $payload['tenant_slug']);
        update_post_meta($tenant_post_id, 'sd_owner_user_id', $owner_user_id);
        update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        if (!get_post_meta($tenant_post_id, 'sd_created_at_gmt', true)) {
            update_post_meta($tenant_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        return $tenant_post_id;
    }

    private static function find_runtime_tenant_post(string $tenant_id, string $tenant_slug): int {
        $posts = get_posts([
            'post_type' => self::TENANT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'sd_tenant_id',
                    'value' => $tenant_id,
                ],
                [
                    'key' => 'sd_slug',
                    'value' => $tenant_slug,
                ],
            ],
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private static function upsert_runtime_manifest(array $payload, int $owner_user_id, int $tenant_post_id, string $request_id, string $runtime_path): array {
        $manifest = get_option(self::MANIFEST_OPTION, []);
        if (!is_array($manifest)) {
            $manifest = [];
        }

        $manifest[$payload['tenant_id']] = [
            'tenant_id' => $payload['tenant_id'],
            'tenant_slug' => $payload['tenant_slug'],
            'tenant_post_id' => $tenant_post_id,
            'owner_user_id' => $owner_user_id,
            'control_plane_prospect_id' => $payload['prospect_id'],
            'control_plane_prospect_post_id' => $payload['prospect_post_id'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'stripe_account_id' => $payload['stripe_account_id'],
            'stripe_customer_id' => $payload['stripe_customer_id'],
            'stripe_subscription_id' => $payload['stripe_subscription_id'],
            'billing_status' => $payload['billing_status'],
            'provisioning_status' => 'provisioned',
            'storefront_status' => 'ready',
            'activation_ready' => true,
            'runtime_storefront_path' => $runtime_path,
            'last_request_id' => $request_id,
            'updated_at_gmt' => current_time('mysql', true),
        ];

        update_option(self::MANIFEST_OPTION, $manifest, false);
        return $manifest;
    }

    private static function derive_request_id(WP_REST_Request $request, array $payload): string {
        $header = trim((string) $request->get_header('x-sd-request-id'));
        if ($header !== '') {
            return sanitize_text_field($header);
        }

        $seed = implode('|', [
            $payload['tenant_id'],
            $payload['tenant_slug'],
            $payload['stripe_account_id'],
            $payload['stripe_subscription_id'],
            $payload['billing_status'],
        ]);

        return 'sdpro_req_' . md5($seed);
    }

    private static function get_request_log(string $request_id): ?array {
        $log = get_option(self::REQUEST_LOG_OPTION, []);
        if (!is_array($log)) {
            return null;
        }
        return isset($log[$request_id]) && is_array($log[$request_id]) ? $log[$request_id] : null;
    }

    private static function store_request_log(string $request_id, array $entry): void {
        $log = get_option(self::REQUEST_LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        $log[$request_id] = $entry;
        if (count($log) > 200) {
            $log = array_slice($log, -200, null, true);
        }
        update_option(self::REQUEST_LOG_OPTION, $log, false);
    }

    private static function get_provisioning_secret(): string {
        if (defined('SD_CONTROL_PLANE_PROVISIONING_SECRET') && is_string(constant('SD_CONTROL_PLANE_PROVISIONING_SECRET'))) {
            return trim((string) constant('SD_CONTROL_PLANE_PROVISIONING_SECRET'));
        }

        $option = get_option('sd_control_plane_provisioning_secret', '');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $env = getenv('SD_CONTROL_PLANE_PROVISIONING_SECRET');
        return is_string($env) ? trim($env) : '';
    }

    private static function owner_role(): string {
        return get_role('sd_owner') ? 'sd_owner' : (get_role('administrator') ? 'administrator' : 'subscriber');
    }

    private static function infer_first_name(string $full_name): string {
        $parts = preg_split('/\s+/', trim($full_name));
        return is_array($parts) && !empty($parts[0]) ? sanitize_text_field((string) $parts[0]) : '';
    }

    private static function infer_last_name(string $full_name): string {
        $parts = preg_split('/\s+/', trim($full_name));
        if (!is_array($parts) || count($parts) < 2) {
            return '';
        }
        array_shift($parts);
        return sanitize_text_field(implode(' ', $parts));
    }

    private static function normalize_phone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        return is_string($digits) ? $digits : '';
    }

    private static function error_response(string $code, string $message, int $status): WP_REST_Response {
        return new WP_REST_Response([
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}

SDPRO_Control_Plane_Provisioning_Listener::bootstrap();
