<?php
/**
 * Plugin Name: SoloDrive Front Office
 * Description: Front-office intake, lifecycle, and onboarding control plane.
 * Version: 0.1.0
 * Author: SoloDrive
 *
 * SoloDrive Front-Office Scaffold
 *
 * Purpose:
 * - Register sd_prospect and sd_tenant CPTs
 * - Register lifecycle meta keys
 * - Add admin columns and filters
 * - Handle CF7 request-access intake
 * - Redirect to staged success screen
 *
 * Canonical enrollment continuity:
 * - sd_prospect_token is the public continuity key.
 * - /prospect/<token> is the canonical public enrollment surface.
 * - sd_public_key and staged pages remain temporary compatibility only.
 * - Stripe redirects are UX only.
 * - Stripe webhooks are truth.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-front-office.php';

final class SD_Front_Office_Scaffold {
    private const PROSPECT_POST_TYPE = 'sd_prospect';
    private const TENANT_POST_TYPE   = 'sd_tenant';
    private const REQUEST_ACCESS_FORM_ID = 33;
    private const INVITE_READY_FORM_ID   = 387;
    private const SUCCESS_PAGE_SLUG  = 'request-received';
    private const REST_NAMESPACE = 'wp-json/sd/v1';
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';
    private const PAGE_SLUG_START            = 'start';
    private const PAGE_SLUG_CONFIRM          = 'confirm';
    private const PAGE_SLUG_CONNECT_PAYOUTS  = 'connect-payouts';
    private const PAGE_SLUG_SUCCESS          = 'success';

    private const META_PUBLIC_KEY            = 'sd_public_key'; // legacy
    private const META_ACTIVATION_STATE      = 'sd_activation_state';
    private const META_STOREFRONT_URL        = 'sd_storefront_url';
    private const META_OPERATIONS_ENTRY_URL  = 'sd_operations_entry_url';
    private const META_BUSINESS_NAME         = 'sd_business_name';
    private const META_SERVICE_AREA          = 'sd_service_area';
    private const META_PROSPECT_TOKEN       = 'sd_prospect_token';
    private const PAGE_SLUG_PROSPECT        = 'prospect';
    private const META_STRIPE_LAST_REFRESH_URL = 'sd_stripe_last_refresh_url';
    private const META_STRIPE_LAST_RETURN_URL  = 'sd_stripe_last_return_url';

    private const ACTION_START               = 'sdfo_start';
    private const ACTION_START_PAYOUTS       = 'sdfo_start_payouts';

    private const META_STRIPE_ACCOUNT_ID    = 'sd_stripe_account_id';
    private const META_STRIPE_STATE         = 'sd_stripe_state';
    private const META_STRIPE_COMPLETED_GMT = 'sd_stripe_completed_gmt';
    private const PROSPECT_PAGE_SLUG        = 'prospect';

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_meta_keys']);
        add_action('init', [__CLASS__, 'register_shortcodes']);
        add_action('init', [__CLASS__, 'register_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

        if (is_admin() && class_exists('SD_Front_Office_Admin')) {
            SD_Front_Office_Admin::bootstrap();
        }

        add_action('wpcf7_before_send_mail', [__CLASS__, 'handle_cf7_submission']);
        add_action('save_post_sd_prospect', [__CLASS__, 'ensure_prospect_defaults'], 10, 3);
        add_action('save_post_sd_tenant', [__CLASS__, 'ensure_tenant_defaults'], 10, 3);

        add_action('admin_post_nopriv_' . self::ACTION_START, [__CLASS__, 'handle_start_submit']);
        add_action('admin_post_' . self::ACTION_START, [__CLASS__, 'handle_start_submit']);

        add_action('admin_post_nopriv_' . self::ACTION_START_PAYOUTS, [__CLASS__, 'handle_start_payouts']);
        add_action('admin_post_' . self::ACTION_START_PAYOUTS, [__CLASS__, 'handle_start_payouts']);
        if (!is_admin()) {
            add_filter('wpcf7_feedback_response', [__CLASS__, 'inject_cf7_redirect'], 10, 2);
        }
    }

    public static function register_post_types(): void {
        register_post_type(self::PROSPECT_POST_TYPE, [
            'labels' => [
                'name' => 'Prospects',
                'singular_name' => 'Prospect',
                'menu_name' => 'Prospects',
                'add_new' => 'Add Prospect',
                'add_new_item' => 'Add New Prospect',
                'edit_item' => 'Edit Prospect',
                'new_item' => 'New Prospect',
                'view_item' => 'View Prospect',
                'search_items' => 'Search Prospects',
                'not_found' => 'No prospects found',
                'not_found_in_trash' => 'No prospects found in Trash',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-id',
            'show_in_admin_bar' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'hierarchical' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'query_var' => false,
            'rewrite' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::TENANT_POST_TYPE, [
            'labels' => [
                'name' => 'Tenants',
                'singular_name' => 'Tenant',
                'menu_name' => 'Tenants',
                'add_new' => 'Add Tenant',
                'add_new_item' => 'Add New Tenant',
                'edit_item' => 'Edit Tenant',
                'new_item' => 'New Tenant',
                'view_item' => 'View Tenant',
                'search_items' => 'Search Tenants',
                'not_found' => 'No tenants found',
                'not_found_in_trash' => 'No tenants found in Trash',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 26,
            'menu_icon' => 'dashicons-building',
            'show_in_admin_bar' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'hierarchical' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'query_var' => false,
            'rewrite' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public static function register_meta_keys(): void {
        $prospect_meta = [
            'sd_prospect_id' => 'string',
            'sd_lifecycle_stage' => 'string',
            'sd_source' => 'string',
            'sd_created_at_gmt' => 'string',
            'sd_updated_at_gmt' => 'string',
            'sd_full_name' => 'string',
            'sd_phone_raw' => 'string',
            'sd_phone_normalized' => 'string',
            'sd_email_raw' => 'string',
            'sd_email_normalized' => 'string',
            'sd_invitation_code' => 'string',
            'sd_invitation_status' => 'string',
            'sd_invited_by' => 'string',
            'sd_priority_lane' => 'integer',
            'sd_review_status' => 'string',
            'sd_owner_user_id' => 'integer',
            'sd_staff_notes' => 'string',
            'sd_last_staff_action_at_gmt' => 'string',
            'sd_stripe_onboarding_started_at_gmt' => 'string',
            'sd_stripe_account_id' => 'string',
            'sd_stripe_onboarding_status' => 'string',
            'sd_stripe_status_snapshot_json' => 'string',
            'sd_stripe_last_event_id' => 'string',
            'sd_billing_status' => 'string',
            'sd_stripe_customer_id' => 'string',
            'sd_stripe_subscription_id' => 'string',
            'sd_stripe_checkout_session_id' => 'string',
            'sd_subscription_paid_at_gmt' => 'string',
            'sd_promoted_to_tenant_id' => 'string',
            'sd_promoted_to_tenant_post_id' => 'integer',
            'sd_dedupe_key_email' => 'string',
            'sd_dedupe_key_phone' => 'string',
            'sd_last_intake_channel' => 'string',
            'sd_last_submission_at_gmt' => 'string',
            'sd_submission_count' => 'integer',
            'sd_last_submission_payload_json' => 'string',
            'sd_city' => 'string',
            'sd_repeat_clients' => 'string',
            'sd_driving_status' => 'string',
            'sd_weekly_gross' => 'string',
            'sd_public_key' => 'string',
            'sd_activation_state' => 'string',
            'sd_business_name' => 'string',
            'sd_service_area' => 'string',
            'sd_storefront_url' => 'string',
            'sd_operations_entry_url' => 'string',
            'sd_stripe_account_id' => 'string',
            'sd_stripe_state' => 'string',
            'sd_stripe_completed_gmt' => 'string',
            'sd_prospect_token' => 'string',
            'sd_stripe_last_refresh_url' => 'string',
            'sd_stripe_last_return_url' => 'string',
                    ];

        $tenant_meta = [
            'sd_tenant_id' => 'string',
            'sd_slug' => 'string',
            'sd_domain' => 'string',
            'sd_status' => 'string',
            'sd_created_at_gmt' => 'string',
            'sd_updated_at_gmt' => 'string',
            'sd_origin_prospect_id' => 'string',
            'sd_origin_prospect_post_id' => 'integer',
            'sd_connected_account_id' => 'string',
            'sd_stripe_customer_id' => 'string',
            'sd_stripe_subscription_id' => 'string',
            'sd_billing_status' => 'string',
            'sd_subscription_paid_at_gmt' => 'string',
            'sd_provisioning_status' => 'string',
            'sd_last_provisioning_payload_json' => 'string',
            'sd_stripe_last_event_id' => 'string',
            'sd_charges_enabled' => 'integer',
            'sd_payouts_enabled' => 'integer',
            'sd_stripe_status_snapshot_json' => 'string',
            'sd_storefront_status' => 'string',
            'sd_activation_ready' => 'integer',
            'sd_activated_at_gmt' => 'string',
            'sd_last_activity_at_gmt' => 'string',
            'sd_support_flag' => 'integer',
            'sd_payment_flag' => 'integer',
            'sd_health_status' => 'string',
        ];

        foreach ($prospect_meta as $key => $type) {
            register_post_meta(self::PROSPECT_POST_TYPE, $key, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => static fn() => current_user_can('edit_posts'),
                'sanitize_callback' => [__CLASS__, 'sanitize_meta_value'],
            ]);
        }

        foreach ($tenant_meta as $key => $type) {
            register_post_meta(self::TENANT_POST_TYPE, $key, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => static fn() => current_user_can('edit_posts'),
                'sanitize_callback' => [__CLASS__, 'sanitize_meta_value'],
            ]);
        }
    }

    public static function sanitize_meta_value($value) {
        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value);
        }
        if (is_numeric($value) && (string)(int)$value === (string)$value) {
            return (int)$value;
        }
        return sanitize_text_field((string)$value);
    }

    public static function handle_cf7_submission($contact_form): void {
        error_log('SD Front Office: handler fired');

        if (is_object($contact_form) && method_exists($contact_form, 'id')) {
        error_log('SD Front Office: form id = ' . $contact_form->id());
        }

        if (!class_exists('WPCF7_Submission') || !method_exists('WPCF7_Submission', 'get_instance')) {
        error_log('SD Front Office: WPCF7_Submission class or get_instance missing');
        return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
        error_log('SD Front Office: submission instance is null');
        return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
        error_log('SD Front Office: submission instance is null');
        return;
        }

        error_log('SD Front Office: submission instance acquired');

        $posted_data = $submission->get_posted_data();

        if (!is_array($posted_data)) {
        error_log('SD Front Office: posted_data is not array');
        error_log('SD Front Office: posted_data raw = ' . print_r($posted_data, true));
        return;
        }

        error_log('SD Front Office: posted data = ' . wp_json_encode($posted_data));

        if (!self::is_request_access_form($contact_form, $posted_data)) {
        error_log('SD Front Office: form check failed');
        return;
        }

        $payload = self::normalize_payload($posted_data);
        //      $payload['invitation'] = self::evaluate_invitation_code($payload['invitation_code']);

        error_log('SD Front Office: normalized payload = ' . wp_json_encode($payload));

        $prospect_post_id = self::find_existing_prospect($payload);
        error_log('SD Front Office: existing prospect id = ' . $prospect_post_id);

        if ($prospect_post_id > 0) {
            $prospect_post_id = self::update_existing_prospect($prospect_post_id, $payload);
            self::ensure_prospect_token($prospect_post_id);
            self::ensure_cf7_stripe_origin($prospect_post_id, $payload);
            error_log('SD Front Office: updated existing prospect');
            return;
        }

        $created_id = self::create_new_prospect($payload);
        if ($created_id > 0) {
            self::ensure_prospect_token($created_id);
            self::ensure_cf7_stripe_origin($created_id, $payload);
        }
        error_log('SD Front Office: create_new_prospect returned = ' . $created_id);
    }

    private static function create_new_prospect(array $payload): int {
        $full_name = (string) ($payload['full_name'] ?? '');
        $phone_normalized = (string) ($payload['phone_normalized'] ?? '');
        $email_normalized = (string) ($payload['email_normalized'] ?? '');

        $title_bits = array_filter([
            'Prospect',
            $full_name,
            $phone_normalized !== '' ? $phone_normalized : $email_normalized,
        ]);

        $prospect_post_id = wp_insert_post([
            'post_type'   => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => implode(' - ', $title_bits),
        ], true);

        if (is_wp_error($prospect_post_id) || !$prospect_post_id) {
            error_log('SD Front Office: create_new_prospect failed: ' . (is_wp_error($prospect_post_id) ? $prospect_post_id->get_error_message() : 'unknown'));
            return 0;
        }

        update_post_meta($prospect_post_id, 'sd_prospect_id', 'prs_' . wp_generate_uuid4());
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', 'prospect');
        update_post_meta($prospect_post_id, 'sd_source', 'cf7_request_access');
        update_post_meta($prospect_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        update_post_meta($prospect_post_id, 'sd_full_name', (string) ($payload['full_name'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_raw', (string) ($payload['phone_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_normalized', (string) ($payload['phone_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_raw', (string) ($payload['email_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_normalized', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_city', (string) ($payload['city'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_repeat_clients', (string) ($payload['repeat_clients'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_driving_status', (string) ($payload['driving_status'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_weekly_gross', (string) ($payload['weekly_gross'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_code', (string) ($payload['invitation_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_status', 'none');
        update_post_meta($prospect_post_id, 'sd_review_status', 'new');
        update_post_meta($prospect_post_id, 'sd_last_intake_channel', 'cf7');
        update_post_meta($prospect_post_id, 'sd_last_submission_at_gmt', (string) ($payload['submitted_at_gmt'] ?? current_time('mysql', true)));
        update_post_meta($prospect_post_id, 'sd_submission_count', (int) ($payload['submission_count'] ?? 1));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', (string) ($payload['raw_payload_json'] ?? '{}'));
        update_post_meta($prospect_post_id, 'sd_dedupe_key_email', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_dedupe_key_phone', (string) ($payload['phone_normalized'] ?? ''));
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'STARTED');

        self::ensure_prospect_defaults($prospect_post_id, get_post($prospect_post_id), false);

        return (int) $prospect_post_id;
    }

    private static function update_existing_prospect(int $prospect_post_id, array $payload): int {
        if ($prospect_post_id <= 0) {
            return 0;
        }

        update_post_meta($prospect_post_id, 'sd_full_name', (string) ($payload['full_name'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_raw', (string) ($payload['phone_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_normalized', (string) ($payload['phone_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_raw', (string) ($payload['email_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_normalized', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_city', (string) ($payload['city'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_repeat_clients', (string) ($payload['repeat_clients'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_driving_status', (string) ($payload['driving_status'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_weekly_gross', (string) ($payload['weekly_gross'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_code', (string) ($payload['invitation_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_last_intake_channel', 'cf7');
        update_post_meta($prospect_post_id, 'sd_last_submission_at_gmt', (string) ($payload['submitted_at_gmt'] ?? current_time('mysql', true)));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', (string) ($payload['raw_payload_json'] ?? '{}'));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $existing_count = (int) get_post_meta($prospect_post_id, 'sd_submission_count', true);
        update_post_meta($prospect_post_id, 'sd_submission_count', max(1, $existing_count + 1));

        self::ensure_prospect_defaults($prospect_post_id, get_post($prospect_post_id), true);

        return $prospect_post_id;
    }

    public static function inject_cf7_redirect($response, $result) {
        error_log('SD Front Office: inject_cf7_redirect fired');

        if (!is_array($response)) {
            $response = [];
        }

        if (!class_exists('WPCF7_Submission') || !method_exists('WPCF7_Submission', 'get_instance')) {
            error_log('SD Front Office: inject_cf7_redirect missing submission class');
            return $response;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            error_log('SD Front Office: inject_cf7_redirect no submission instance');
            return $response;
        }

        $contact_form = $submission->get_contact_form();
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            error_log('SD Front Office: inject_cf7_redirect no contact form on submission');
            return $response;
        }

        if ((int) $contact_form->id() !== self::REQUEST_ACCESS_FORM_ID) {
            error_log('SD Front Office: inject_cf7_redirect skipped, wrong form id = ' . $contact_form->id());
            return $response;
        }

        $posted_data = $submission->get_posted_data();
        if (!is_array($posted_data)) {
            error_log('SD Front Office: inject_cf7_redirect posted_data not array');
            return $response;
        }

        if (self::is_invitation_required()) {
            $payload = self::normalize_payload($posted_data);
            $invitation = self::evaluate_invitation_code($payload['invitation_code']);

            if (empty($invitation['ok'])) {
                $response['status'] = 'validation_failed';
                $response['message'] = 'An invitation code is required.';
                return $response;
            }
        }

        $payload = self::normalize_payload($posted_data);
        $prospect_post_id = self::find_existing_prospect($payload);

        error_log('SD Front Office: inject_cf7_redirect matched prospect id = ' . $prospect_post_id);

        if ($prospect_post_id <= 0) {
            return $response;
        }

        $redirect_url = self::get_prospect_url_for_post($prospect_post_id);
        $response['sd_redirect_url'] = $redirect_url;

        error_log('SD Front Office: inject_cf7_redirect set sd_redirect_url = ' . $redirect_url);

        return $response;
    }

    public static function handle_stripe_webhook(WP_REST_Request $request) {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $endpoint_secret = get_option('sd_stripe_webhook_secret', '');

        if ($endpoint_secret === '') {
            error_log('SD Front Office: missing Stripe webhook secret');
            return new WP_REST_Response(['error' => 'missing webhook secret'], 500);
        }

        try {
            if (!class_exists('\\Stripe\\Webhook')) {
                throw new Exception('Stripe PHP SDK not loaded');
            }

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (Throwable $e) {
            error_log('SD Front Office: Stripe webhook signature failed: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'invalid signature'], 400);
        }

        $event_id = (string) ($event->id ?? '');
        if ($event_id !== '' && get_option('sd_stripe_event_' . $event_id)) {
            return new WP_REST_Response(['status' => 'duplicate'], 200);
        }

        switch ((string) $event->type) {
            case 'account.updated':
                self::handle_stripe_account_updated($event->data->object ?? null, $event_id);
                break;
            default:
                break;
        }

        if ($event_id !== '') {
            update_option('sd_stripe_event_' . $event_id, 1, false);
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    public static function ensure_stripe_account(int $prospect_post_id): string {

        $existing = get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);

        if (!empty($existing)) {
            return $existing;
        }

        if (!class_exists('\\Stripe\\Stripe')) {
            throw new Exception('Stripe SDK not loaded');
        }

        \Stripe\Stripe::setApiKey(get_option('sd_stripe_secret_key'));

        $email = get_post_meta($prospect_post_id, 'sd_email_normalized', true);

        $account = \Stripe\Account::create([
            'type' => 'express',
            'email' => $email,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'prospect_post_id' => (string)$prospect_post_id,
                'prospect_token'   => get_post_meta($prospect_post_id, 'sd_prospect_token', true),
            ],
        ]);

        update_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, $account->id);
        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', 'ACCOUNT_CREATED');

        return $account->id;
    }

    public static function create_stripe_onboarding_link(int $prospect_post_id): string {

        \Stripe\Stripe::setApiKey(get_option('sd_stripe_secret_key'));

        $account_id = get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);

        if (empty($account_id)) {
            throw new Exception('Missing Stripe account id');
        }

        $token = get_post_meta($prospect_post_id, 'sd_prospect_token', true);

        $link = \Stripe\AccountLink::create([
            'account' => $account_id,
            'refresh_url' => home_url("/prospect/{$token}?refresh=1"),
            'return_url'  => home_url("/prospect/{$token}?return=1"),
            'type' => 'account_onboarding',
        ]);

        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_url', $link->url);
        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_expires', $link->expires_at);

        return $link->url;
    }

    private static function handle_stripe_account_updated($account, string $event_id = ''): void {
        if (!is_object($account) || empty($account->id)) {
            error_log('SD Front Office: account.updated missing account object');
            return;
        }

        $acct_id = (string) $account->id;

        $posts = get_posts([
            'post_type'      => self::PROSPECT_POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'numberposts'    => 1,
            'meta_query'     => [[
                'key'     => self::META_STRIPE_ACCOUNT_ID,
                'value'   => $acct_id,
                'compare' => '=',
            ]],
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        $prospect_post_id = !empty($posts) ? (int) $posts[0] : 0;
        if ($prospect_post_id <= 0) {
            error_log('SD Front Office: no prospect found for Stripe account ' . $acct_id);
            return;
        }

        $charges_enabled = !empty($account->charges_enabled);
        $payouts_enabled = !empty($account->payouts_enabled);
        $details_submitted = !empty($account->details_submitted);

        $requirements = is_object($account->requirements ?? null) ? $account->requirements : null;

        update_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, $acct_id);
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        update_post_meta($prospect_post_id, 'sd_stripe_status_snapshot_json', wp_json_encode($account));
        update_post_meta($prospect_post_id, 'sd_stripe_charges_enabled', $charges_enabled ? '1' : '0');
        update_post_meta($prospect_post_id, 'sd_stripe_payouts_enabled', $payouts_enabled ? '1' : '0');
        update_post_meta($prospect_post_id, 'sd_stripe_details_submitted', $details_submitted ? '1' : '0');
        update_post_meta($prospect_post_id, self::META_STRIPE_STATE, $charges_enabled ? 'payments_enabled' : 'payments_not_enabled');

        update_post_meta(
            $prospect_post_id,
            'sd_stripe_requirements_currently_due_json',
            wp_json_encode($requirements->currently_due ?? [])
        );
        update_post_meta(
            $prospect_post_id,
            'sd_stripe_requirements_past_due_json',
            wp_json_encode($requirements->past_due ?? [])
        );
        update_post_meta(
            $prospect_post_id,
            'sd_stripe_requirements_eventually_due_json',
            wp_json_encode($requirements->eventually_due ?? [])
        );
        update_post_meta(
            $prospect_post_id,
            'sd_stripe_requirements_pending_verification_json',
            wp_json_encode($requirements->pending_verification ?? [])
        );
        update_post_meta(
            $prospect_post_id,
            'sd_stripe_disabled_reason',
            (string) ($requirements->disabled_reason ?? '')
        );

        update_post_meta(
            $prospect_post_id,
            'sd_stripe_onboarding_status',
            $charges_enabled ? 'PAYMENTS_ENABLED' : 'PAYMENTS_NOT_ENABLED'
        );

        if ($charges_enabled) {
            update_post_meta($prospect_post_id, self::META_STRIPE_COMPLETED_GMT, current_time('mysql', true));
        }
    }

    private static function handle_account_updated($account) {

        $acct_id = $account->id;

        $prospect_id = self::find_prospect_by_stripe_account_id($acct_id);

        if ($prospect_id <= 0) {
            error_log("Stripe webhook: no prospect for account {$acct_id}");
            return;
        }

        update_post_meta($prospect_id, 'sd_stripe_charges_enabled', $account->charges_enabled ? '1' : '0');
        update_post_meta($prospect_id, 'sd_stripe_payouts_enabled', $account->payouts_enabled ? '1' : '0');
        update_post_meta($prospect_id, 'sd_stripe_details_submitted', $account->details_submitted ? '1' : '0');

        update_post_meta($prospect_id, 'sd_stripe_requirements_currently_due_json', wp_json_encode($account->requirements->currently_due ?? []));
        update_post_meta($prospect_id, 'sd_stripe_requirements_past_due_json', wp_json_encode($account->requirements->past_due ?? []));
        update_post_meta($prospect_id, 'sd_stripe_disabled_reason', $account->requirements->disabled_reason ?? '');

        update_post_meta($prospect_id, 'sd_stripe_status_snapshot_json', wp_json_encode($account));

        // Promotion trigger (your locked rule)
        if (!empty($account->charges_enabled)) {
            update_post_meta($prospect_id, 'sd_stripe_onboarding_status', 'PAYMENTS_ENABLED');
        }
    }

    private static function is_request_access_form($contact_form, array $posted_data): bool {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return false;
        }

        return (int) $contact_form->id() === self::REQUEST_ACCESS_FORM_ID;
    }

    private static function has_processed_event(string $event_id): bool {
        return (bool) get_option('sd_stripe_event_' . $event_id);
    }

    private static function mark_event_processed(string $event_id): void {
        update_option('sd_stripe_event_' . $event_id, 1, false);
    }

    public static function register_shortcodes(): void {
        add_shortcode('sdfo_prospect_state', [__CLASS__, 'shortcode_prospect_state']);
    }

    public static function shortcode_prospect_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Prospect status block</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_token_request();

        $token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        $state = self::get_activation_state($prospect_post_id);
        $stripe_state = (string) get_post_meta($prospect_post_id, self::META_STRIPE_STATE, true);
        $stripe_account_id = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        $resume_url = '';

        if ($stripe_account_id !== '' && !in_array($stripe_state, ['payments_enabled', 'PAYMENTS_ENABLED'], true)) {
            $expires = (int) get_post_meta($prospect_post_id, 'sd_stripe_onboarding_expires', true);
            $onboarding_url = (string) get_post_meta($prospect_post_id, 'sd_stripe_onboarding_url', true);

            if ($onboarding_url === '' || time() >= $expires) {
                try {
                    $onboarding_url = self::create_stripe_onboarding_link($prospect_post_id);
                } catch (Throwable $e) {
                    error_log('SD Front Office: failed to create onboarding link: ' . $e->getMessage());
                    $onboarding_url = '';
                }
            }

            $resume_url = $onboarding_url;
        }

        ob_start();
        ?>
        <div class="sd-front-status">
            <span class="sd-front-status__label">Status</span>
            <strong class="sd-front-status__value"><?php echo esc_html(self::map_public_status_label($state)); ?></strong>
        </div>

        <div class="sd-front-card">
            <p><strong>Prospect token:</strong> <?php echo esc_html($token); ?></p>
            <p><strong>Stripe state:</strong> <?php echo esc_html($stripe_state ?: 'not_started'); ?></p>
            <p><strong>Stripe account:</strong> <?php echo esc_html($stripe_account_id ?: 'not_created'); ?></p>
        </div>

        <?php if ($resume_url !== '') : ?>
            <div class="sd-front-actions">
                <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($resume_url); ?>">
                    Resume setup
                </a>
            </div>
        <?php endif; ?>

        <?php if ($storefront_url !== '') : ?>
            <div class="sd-front-actions">
                <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($storefront_url); ?>">Open your booking page</a>
                <?php if ($operations_entry_url !== '') : ?>
                    <a class="sd-front-btn sd-front-btn--secondary" href="<?php echo esc_url($operations_entry_url); ?>">Log in to operations</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        return (string) ob_get_clean();
    }

    private static function is_invite_ready_form($contact_form): bool {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return false;
        }

        return (int) $contact_form->id() === self::INVITE_READY_FORM_ID;
    }

    private static function generate_public_key(): string {
        return 'sdp_' . wp_generate_password(24, false, false);
    }

    private static function generate_prospect_token(): string {
        return 'sdpst_' . wp_generate_password(24, false, false);
    }

    private static function get_prospect_token_from_request(): string {
        $token = get_query_var('sd_prospect_token', '');
        if (!is_string($token) || $token === '') {
            $token = (string) ($_GET['token'] ?? '');
        }
        return sanitize_text_field($token);
    }

    private static function get_prospect_post_id_by_token(string $token): int {
        if ($token === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type' => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [[
                'key' => self::META_PROSPECT_TOKEN,
                'value' => $token,
                'compare' => '=',
            ]],
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);

        if (!empty($posts)) {
            return (int) $posts[0];
        }

        return 0;
    }

    private static function require_prospect_post_id_from_token_request(): int {
        $token = self::get_prospect_token_from_request();
        $post_id = self::get_prospect_post_id_by_token($token);

        if ($post_id > 0) {
            return $post_id;
        }

        // hard fail — no redirect dependency on pages
        wp_die('Invalid or expired link.');
    }

    private static function ensure_prospect_token(int $prospect_post_id): string {
        $token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        if ($token === '') {
            $token = self::generate_prospect_token();
            update_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, $token);
        }
        return $token;
    }

    private static function get_public_key_from_request(): string {
        return sanitize_text_field((string) ($_GET['k'] ?? ''));
        }

        private static function get_prospect_post_id_by_public_key(string $public_key): int {
            if ($public_key === '') {
                return 0;
            }

            $posts = get_posts([
                'post_type' => self::PROSPECT_POST_TYPE,
                'post_status' => 'publish',
                'fields' => 'ids',
                'numberposts' => 1,
                'meta_query' => [[
                    'key' => self::META_PUBLIC_KEY,
                    'value' => $public_key,
                    'compare' => '=',
                ]],
                'no_found_rows' => true,
                'suppress_filters' => false,
            ]);

            return !empty($posts) ? (int) $posts[0] : 0;
    }

    private static function ensure_cf7_stripe_origin(int $prospect_post_id, array $payload): array {
        // shared service placeholder
        // this is where the proven CF7-origin Stripe creation/bootstrap logic belongs
        return [];
    }

    private static function get_prospect_url_by_token(string $token): string {
        return home_url('/' . self::PAGE_SLUG_PROSPECT . '/' . rawurlencode($token) . '/');
    }

    private static function get_activation_payload_for_success(int $prospect_post_id): array {
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);

        $state = self::get_activation_state($prospect_post_id);
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        return [
            'prospect_id' => $prospect_id,
            'prospect_token' => (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true),
            'activation_state' => $state,
            'storefront_url' => $storefront_url,
            'operations_entry_url' => $operations_entry_url,
        ];

        return [
            'prospect_id' => $prospect_id,
            'activation_state' => $state,
            'storefront_url' => $storefront_url,
            'operations_entry_url' => $operations_entry_url,
        ];
    }

    public static function shortcode_start_form(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Start form block</div>';
        }

        $action_url = add_query_arg('action', self::ACTION_START, admin_url('admin-post.php'));

        ob_start();
        ?>
        <form class="sd-front-form" method="post" action="<?php echo esc_url($action_url); ?>">
            <?php wp_nonce_field('sdfo_start_submit', 'sdfo_nonce'); ?>

            <div class="sd-front-field">
                <label for="sd-name">Name</label>
                <input id="sd-name" name="name" type="text" required>
            </div>

            <div class="sd-front-field">
                <label for="sd-email">Email</label>
                <input id="sd-email" name="email" type="email" required>
            </div>

            <div class="sd-front-field">
                <label for="sd-mobile">Mobile</label>
                <input id="sd-mobile" name="mobile" type="tel" required>
            </div>

            <div class="sd-front-field">
                <label for="sd-business-name">Business / display name</label>
                <input id="sd-business-name" name="business_display_name" type="text" required>
            </div>

            <div class="sd-front-field">
                <label for="sd-service-area">Service area (optional)</label>
                <input id="sd-service-area" name="service_area" type="text">
            </div>

            <div class="sd-front-form__actions">
                <button class="sd-front-btn sd-front-btn--primary" type="submit">Continue</button>
            </div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function register_rest_routes(): void {
        register_rest_route('wp-json/sd/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function is_editor_request(): bool {
        if (is_admin()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (wp_doing_ajax()) {
            return true;
        }

        return false;
    }

    private static function normalize_phone(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        $digits = is_string($digits) ? $digits : '';

        if ($digits === '') {
            return '';
        }

        // Normalize leading US country code when 11 digits.
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    private static function find_existing_prospect(array $args): int {
        $email = $args['email_normalized'] ?? '';
        $phone = $args['phone_normalized'] ?? '';

        if ($email === '' && $phone === '') {
            return 0;
        }

        $meta_query = ['relation' => 'OR'];

        if ($email !== '') {
            $meta_query[] = [
                'key'   => 'sd_email_normalized',
                'value' => $email,
            ];
        }

        if ($phone !== '') {
            $meta_query[] = [
                'key'   => 'sd_phone_normalized',
                'value' => $phone,
            ];
        }

        $query = new WP_Query([
            'post_type'      => self::PROSPECT_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ]);

        if (!empty($query->posts)) {
            return (int) $query->posts[0];
        }

        return 0;
    }

    private static function is_invitation_required(): bool {
        return (bool) get_option('sd_invitation_required', 0);
    }

    private static function evaluate_invitation_code(string $code): array {
        $code = sanitize_text_field(trim($code));

        if ($code === '') {
            return [
                'ok' => false,
                'status' => 'missing',
                'code' => '',
            ];
        }

        $valid_codes = (array) get_option('sd_valid_invitation_codes', []);

        if (in_array($code, $valid_codes, true)) {
            return [
                'ok' => true,
                'status' => 'valid',
                'code' => $code,
            ];
        }

        return [
            'ok' => false,
            'status' => 'invalid',
            'code' => $code,
        ];
    }

    private static function get_activation_state(int $prospect_post_id): string {
        $state = (string) get_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, true);

        if ($state === '') {
            return 'STARTED';
        }

        return $state;
    }

    private static function get_prospect_url_for_post(int $prospect_post_id): string {
        $token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);

        if ($token === '') {
            $token = self::generate_prospect_token();
            update_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, $token);
        }

        return self::get_prospect_url_by_token($token);
    }

    private static function map_public_status_label(string $state): string {
        return match ($state) {
            'STARTED'               => 'Started',
            'CONFIRMED'             => 'Confirmed',
            'PAYOUTS_CONNECTED'     => 'Payouts connected',
            'TENANT_CREATING'       => 'Payouts connected',
            'TENANT_READY'          => 'Payouts connected',
            'STOREFRONT_READY'      => 'Payouts connected',
            'FRONTEND_SYNC_PENDING' => 'Payouts connected',
            'OPERATIONS_READY'      => 'Payouts connected',
            'ACTIVATION_COMPLETE'   => 'Booking page live',
            'ACTIVATION_FAILED'     => 'Activation issue',
            'PARTIAL_SYNC_FAILED'   => 'Activation issue',
            default                 => 'Started',
        };
    }

    public static function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . self::PAGE_SLUG_PROSPECT . '/([^/]+)/?$',
            'index.php?pagename=' . self::PAGE_SLUG_PROSPECT . '&sd_prospect_token=$matches[1]',
            'top'
        );
    }

    public static function register_query_vars(array $vars): array {
        $vars[] = 'sd_prospect_token';
        return $vars;
    }

    private static function is_success_ready_payload(array $payload): bool {
        $state = (string) ($payload['activation_state'] ?? '');
        $storefront_url = (string) ($payload['storefront_url'] ?? '');

        return $state === 'ACTIVATION_COMPLETE' && $storefront_url !== '';
    }

    private static function get_stripe_account_id(int $prospect_post_id): string {
        return (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);
    }

    public static function ensure_prospect_defaults(int $post_id, WP_Post $post, bool $update): void {
        if ($post->post_type !== self::PROSPECT_POST_TYPE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $prospect_id = (string) get_post_meta($post_id, 'sd_prospect_id', true);
        if ($prospect_id === '') {
            update_post_meta($post_id, 'sd_prospect_id', 'prs_' . wp_generate_uuid4());
        }

        $activation_state = (string) get_post_meta($post_id, self::META_ACTIVATION_STATE, true);
        if ($activation_state === '') {
            update_post_meta($post_id, self::META_ACTIVATION_STATE, 'STARTED');
        }

        $public_key = (string) get_post_meta($post_id, self::META_PUBLIC_KEY, true);
        if ($public_key === '') {
            update_post_meta($post_id, self::META_PUBLIC_KEY, self::generate_public_key());
        }

        $prospect_token = (string) get_post_meta($post_id, self::META_PROSPECT_TOKEN, true);
        if ($prospect_token === '') {
            update_post_meta($post_id, self::META_PROSPECT_TOKEN, self::generate_prospect_token());
        }

        $created_at = (string) get_post_meta($post_id, 'sd_created_at_gmt', true);
        if ($created_at === '') {
            update_post_meta($post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', current_time('mysql', true));
    }

    private static function normalize_payload(array $posted_data): array {
        $first = static function ($value): string {
            if (is_array($value)) {
                $value = reset($value);
            }
            return sanitize_text_field((string) $value);
        };

        $email_raw = sanitize_email((string) ($posted_data['email'] ?? ''));
        $phone_raw = $first($posted_data['phone'] ?? '');

        return [
            'full_name'         => $first($posted_data['full_name'] ?? ''),
            'email_raw'         => $email_raw,
            'email_normalized'  => strtolower(trim($email_raw)),
            'phone_raw'         => $phone_raw,
            'phone_normalized'  => self::normalize_phone($phone_raw),
            'city'              => $first($posted_data['city'] ?? ''),
            'repeat_clients'    => $first($posted_data['repeat_clients'] ?? ''),
            'driving_status'    => $first($posted_data['driving_status'] ?? ''),
            'weekly_gross'      => $first($posted_data['weekly_gross'] ?? ''),
            'invitation_code'   => $first($posted_data['invite_code'] ?? ''),
            'submission_count'  => 1,
            'submitted_at_gmt'  => current_time('mysql', true),
            'raw_payload_json'  => wp_json_encode($posted_data),
        ];
    }

    private static function post_control_plane_endpoint(string $path, array $payload): array {
        $base = 'https://app.solodrive.pro/wp-json/sd/v1/control-plane/';
        $url = $base . ltrim($path, '/');

        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('SOLODRIVE.PRO control-plane POST failed: ' . $path . ' => ' . $response->get_error_message());
            return ['ok' => false, 'error' => 'request_failed'];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (!is_array($json)) {
            error_log('SOLODRIVE.PRO control-plane invalid JSON: ' . $path . ' => HTTP ' . $code . ' body=' . $body);
            return ['ok' => false, 'error' => 'invalid_json', 'http_code' => $code];
        }

        $json['http_code'] = $code;
        return $json;
    }

    private static function build_runtime_prospect_contract(int $prospect_post_id): array {
        return [
            'prospect_id'            => (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true),
            'public_key'             => (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true),
            'prospect_post_id'       => $prospect_post_id,
            'full_name'              => (string) get_post_meta($prospect_post_id, 'sd_full_name', true),
            'email'                  => (string) get_post_meta($prospect_post_id, 'sd_email_raw', true),
            'phone'                  => (string) get_post_meta($prospect_post_id, 'sd_phone_raw', true),
            'business_display_name'  => (string) get_post_meta($prospect_post_id, self::META_BUSINESS_NAME, true),
            'service_area'           => (string) get_post_meta($prospect_post_id, self::META_SERVICE_AREA, true),
            'prospect_token'         => (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true),
        ];
    }
 }

SD_Front_Office_Scaffold::bootstrap();
add_action('sd_control_plane_tenant_provisioning_requested', function ($tenant_post_id, $prospect_post_id, $payload) {
    if (empty($payload) || !is_array($payload)) {
        error_log('[SD Front Office] Provisioning callback aborted: missing payload.');
        return;
    }

    $endpoint = defined('SD_CONTROL_PLANE_PROVISIONING_ENDPOINT')
        ? SD_CONTROL_PLANE_PROVISIONING_ENDPOINT
        : get_option('sd_control_plane_provisioning_endpoint');

    if (!$endpoint) {
        error_log('[SD Front Office] Provisioning callback aborted: endpoint not configured.');
        return;
    }

    $secret = defined('SD_CONTROL_PLANE_PROVISIONING_SECRET')
        ? SD_CONTROL_PLANE_PROVISIONING_SECRET
        : get_option('sd_control_plane_provisioning_secret');

    if (!$secret) {
        error_log('[SD Front Office] Provisioning callback aborted: secret not configured.');
        return;
    }

    $request_id = 'sdprov_' . md5(implode('|', [
        (string) ($payload['tenant_id'] ?? ''),
        (string) ($payload['tenant_slug'] ?? ''),
        (string) ($payload['stripe_account_id'] ?? ''),
        (string) ($payload['stripe_subscription_id'] ?? ''),
        (string) ($payload['billing_status'] ?? ''),
    ]));

    $body = wp_json_encode($payload);

    if (!$body) {
        error_log('[SD Front Office] Provisioning callback aborted: failed to JSON encode payload.');
        return;
    }

    $signature = hash_hmac('sha256', $body, $secret);

    update_post_meta($tenant_post_id, 'sd_provisioning_status', 'sending');
    update_post_meta($tenant_post_id, 'sd_last_provisioning_payload_json', $body);

    $response = wp_remote_post($endpoint, [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-SD-Request-ID' => $request_id,
            'X-SD-Signature' => $signature,
        ],
        'body' => $body,
    ]);

    if (is_wp_error($response)) {
        update_post_meta($tenant_post_id, 'sd_provisioning_status', 'failed');
        update_post_meta($tenant_post_id, 'sd_health_status', 'attention');

        error_log('[SD Front Office] Provisioning request failed: ' . $response->get_error_message());
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw_response = wp_remote_retrieve_body($response);
    $json = json_decode($raw_response, true);

    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['ok'])) {
        update_post_meta($tenant_post_id, 'sd_provisioning_status', 'provisioned');
        update_post_meta($tenant_post_id, 'sd_storefront_status', 'ready');
        update_post_meta($tenant_post_id, 'sd_activation_ready', 1);
        update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        error_log('[SD Front Office] Provisioning request succeeded for tenant_post_id=' . $tenant_post_id);
        return;
    }

    update_post_meta($tenant_post_id, 'sd_provisioning_status', 'failed');
    update_post_meta($tenant_post_id, 'sd_health_status', 'attention');
    update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

    error_log('[SD Front Office] Provisioning request returned HTTP ' . $code . ' body=' . $raw_response);
}, 10, 3);
/**
 * Front-end helper for CF7 redirect.
 *
 * Add this to the request-access page or enqueue as a tiny script.
 * It looks for the custom redirect URL in the CF7 AJAX response.
 */
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('wpcf7mailsent', function(event) {
      if (event && event.detail && event.detail.apiResponse && event.detail.apiResponse.sd_redirect_url) {
        window.location.href = event.detail.apiResponse.sd_redirect_url;
      }
    }, false);
    </script>
    <?php
});
