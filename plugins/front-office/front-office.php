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
$front_office_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($front_office_autoload)) {
    require_once $front_office_autoload;
}

final class SD_Front_Office_Scaffold {
    private const PROSPECT_POST_TYPE            = 'sd_prospect';
    private const TENANT_POST_TYPE              = 'sd_tenant';
    private const REQUEST_ACCESS_FORM_ID        = 33;
    private const SUCCESS_PAGE_SLUG             = 'request-received';
    private const REST_NAMESPACE                = 'sd/v1';
    private const STRIPE_API_BASE               = 'https://api.stripe.com/v1';
    private const PAGE_SLUG_START               = 'start';
    private const PAGE_SLUG_CONFIRM             = 'confirm';
    private const PAGE_SLUG_CONNECT_PAYOUTS     = 'connect-payouts';
    private const PAGE_SLUG_SUCCESS             = 'success';
    private const ACTION_CREATE_ACCOUNT         = 'sdfo_create_account';

    private const STAGE_INTAKE_CAPTURED         = 'INTAKE_CAPTURED';
    private const STAGE_ACCOUNT_PENDING         = 'ACCOUNT_PENDING';
    private const STAGE_ACCOUNT_CREATED         = 'ACCOUNT_CREATED';
    private const STAGE_SLUG_PENDING            = 'SLUG_PENDING';
    private const META_PUBLIC_KEY               = 'sd_public_key'; // legacy
    private const META_ACTIVATION_STATE         = 'sd_activation_state';
    private const META_STOREFRONT_URL           = 'sd_storefront_url';
    private const META_OPERATIONS_ENTRY_URL     = 'sd_operations_entry_url';
    private const META_BUSINESS_NAME            = 'sd_business_name';
    private const META_SERVICE_AREA             = 'sd_service_area';
    private const META_PROSPECT_TOKEN           = 'sd_prospect_token';
    private const PAGE_SLUG_PROSPECT            = 'prospect';
    private const META_STRIPE_LAST_REFRESH_URL  = 'sd_stripe_last_refresh_url';
    private const META_STRIPE_LAST_RETURN_URL   = 'sd_stripe_last_return_url';
    private const ACTION_RESERVE_SLUG           = 'sdfo_reserve_slug';
    private const ACTION_START                  = 'sdfo_start';
    private const ACTION_START_PAYOUTS          = 'sdfo_start_payouts';
    private const STAGE_SLUG_RESERVED           = 'SLUG_RESERVED';
    private const STAGE_CHECKOUT_PENDING        = 'CHECKOUT_PENDING';
    private const META_STRIPE_ACCOUNT_ID        = 'sd_stripe_account_id';
    private const META_STRIPE_STATE             = 'sd_stripe_state';
    private const META_STRIPE_COMPLETED_GMT     = 'sd_stripe_completed_gmt';
    private const PROSPECT_PAGE_SLUG            = 'prospect';
    private const STAGE_SUBSCRIPTION_PAID       = 'SUBSCRIPTION_PAID';
    private const STAGE_TENANT_PROVISIONING     = 'TENANT_PROVISIONING';
    private const STAGE_TENANT_INACTIVE         = 'TENANT_INACTIVE';

    private const BILLING_CHECKOUT_PENDING      = 'CHECKOUT_PENDING';
    private const BILLING_SUBSCRIPTION_PAID     = 'SUBSCRIPTION_PAID';
    private const BILLING_SUBSCRIPTION_FAILED   = 'SUBSCRIPTION_FAILED';

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_meta_keys']);
        add_action('init', [__CLASS__, 'register_shortcodes']);
        add_action('init', [__CLASS__, 'register_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
     //   add_action('wp_enqueue_scripts', function () {
     //       wp_enqueue_style(
     //           'sd-front-css',
     //           plugins_url('/../solodrive-front-css/assets/css/99-legacy-import.css', __FILE__),
     //           [],
     //           '1.0'
     //       );
     //   });

        if (is_admin() && class_exists('SD_Front_Office_Admin')) {
            SD_Front_Office_Admin::bootstrap();
        }
        add_action('admin_post_nopriv_' . self::ACTION_RESERVE_SLUG, [__CLASS__, 'handle_slug_reservation_submit']);
        add_action('admin_post_' . self::ACTION_RESERVE_SLUG, [__CLASS__, 'handle_slug_reservation_submit']);
        add_action('wpcf7_before_send_mail', [__CLASS__, 'handle_cf7_submission']);
        add_action('save_post_sd_prospect', [__CLASS__, 'ensure_prospect_defaults'], 10, 3);
        add_action('save_post_sd_tenant', [__CLASS__, 'ensure_tenant_defaults'], 10, 3);
        add_action('admin_post_nopriv_' . self::ACTION_CREATE_ACCOUNT, [__CLASS__, 'handle_account_creation_submit']);
        add_action('admin_post_' . self::ACTION_CREATE_ACCOUNT, [__CLASS__, 'handle_account_creation_submit']);
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
            'sd_requested_slug' => 'string',
            'sd_reserved_slug'  => 'string',
            'sd_slug_status'    => 'string',
            'sd_pricing_profile_source' => 'string',
            'sd_pricing_profile_id' => 'string',
            'sd_resolved_stripe_price_id' => 'string',
            'sd_resolved_plan_label' => 'string',
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
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_INTAKE_CAPTURED);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_ACCOUNT_PENDING);
        update_post_meta($prospect_post_id, 'sd_source', 'cf7_request_access');
        update_post_meta($prospect_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_full_name', (string) ($payload['full_name'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_raw', (string) ($payload['phone_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_normalized', (string) ($payload['phone_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_raw', (string) ($payload['email_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_normalized', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_code', (string) ($payload['invitation_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_status', 'none');
        update_post_meta($prospect_post_id, 'sd_review_status', 'new');
        update_post_meta($prospect_post_id, 'sd_last_intake_channel', 'cf7');
        update_post_meta($prospect_post_id, 'sd_last_submission_at_gmt', (string) ($payload['submitted_at_gmt'] ?? current_time('mysql', true)));
        update_post_meta($prospect_post_id, 'sd_submission_count', (int) ($payload['submission_count'] ?? 1));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', (string) ($payload['raw_payload_json'] ?? '{}'));
        update_post_meta($prospect_post_id, 'sd_dedupe_key_email', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_dedupe_key_phone', (string) ($payload['phone_normalized'] ?? ''));

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
        update_post_meta($prospect_post_id, 'sd_invitation_code', (string) ($payload['invitation_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_last_intake_channel', 'cf7');
        update_post_meta($prospect_post_id, 'sd_last_submission_at_gmt', (string) ($payload['submitted_at_gmt'] ?? current_time('mysql', true)));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', (string) ($payload['raw_payload_json'] ?? '{}'));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        $current_stage = (string) get_post_meta($prospect_post_id, 'sd_lifecycle_stage', true);
        if ($current_stage === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_INTAKE_CAPTURED);
        }
        $owner_user_id = (int) get_post_meta($prospect_post_id, 'sd_owner_user_id', true);
        if ($owner_user_id <= 0) {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_INTAKE_CAPTURED);
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_ACCOUNT_PENDING);
        }
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
        $endpoint_secret = self::get_stripe_webhook_secret();

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

        $stripe_secret_key = self::get_stripe_secret_key();
        if ($stripe_secret_key === '') {
            throw new Exception('Stripe secret key missing');
        }
        \Stripe\Stripe::setApiKey($stripe_secret_key);

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
        update_post_meta($prospect_post_id, self::META_STRIPE_STATE, 'account_created');

        return $account->id;
    }

    public static function create_stripe_onboarding_link(int $prospect_post_id): string {

        $stripe_secret_key = self::get_stripe_secret_key();
        if ($stripe_secret_key === '') {
            throw new Exception('Stripe secret key missing');
        }
        \Stripe\Stripe::setApiKey($stripe_secret_key);

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
        update_post_meta($prospect_post_id, self::META_STRIPE_STATE, 'onboarding_started');

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
        if ($charges_enabled) {
            self::maybe_promote_prospect_to_tenant($prospect_post_id);
        }
    }

    private static function maybe_promote_prospect_to_tenant(int $prospect_post_id): void {
        if ($prospect_post_id <= 0) {
            return;
        }

        $existing_tenant_id = (string) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_id', true);
        $existing_tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);

        if ($existing_tenant_id !== '' || $existing_tenant_post_id > 0) {
            return;
        }

        $stripe_account_id = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);
        if ($stripe_account_id === '') {
            error_log('SD Front Office: promotion skipped, missing stripe account id for prospect_post_id=' . $prospect_post_id);
            return;
        }

        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'ACTIVATION_PROCESSING');

        $result = self::promote_prospect_to_tenant($prospect_post_id);

        if (empty($result['ok'])) {
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'ACTIVATION_FAILED');
            error_log('SD Front Office: promotion failed for prospect_post_id=' . $prospect_post_id . ' error=' . wp_json_encode($result));
            return;
        }

        $tenant_id = (string) ($result['tenant_id'] ?? '');
        $tenant_post_id = (int) ($result['tenant_post_id'] ?? 0);
        $storefront_url = (string) ($result['storefront_url'] ?? '');
        $operations_entry_url = (string) ($result['operations_entry_url'] ?? '');

        if ($tenant_id !== '') {
            update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_id', $tenant_id);
        }

        if ($tenant_post_id > 0) {
            update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', $tenant_post_id);
        }

        if ($storefront_url !== '') {
            update_post_meta($prospect_post_id, self::META_STOREFRONT_URL, $storefront_url);
        }

        if ($operations_entry_url !== '') {
            update_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, $operations_entry_url);
        }

        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'TENANT_READY');
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
    }

    private static function render_account_creation(int $prospect_post_id): string {
        $token = self::ensure_prospect_token($prospect_post_id);
        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_raw', true);

        $error_code = isset($_GET['acct_err'])
            ? sanitize_text_field((string) $_GET['acct_err'])
            : '';

        $error_message = $error_code !== ''
            ? self::map_account_error_message($error_code)
            : '';

        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Create your account</h1>
                <p class="sd-front-body">
                    You're one step away from setting up your SoloDrive storefront.
                </p>
            </div>

            <?php if ($error_message !== '') : ?>
                <div class="sd-front-error">
                    <?php echo esc_html($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sd-front-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CREATE_ACCOUNT); ?>">
                <input type="hidden" name="prospect_token" value="<?php echo esc_attr($token); ?>">
                <?php wp_nonce_field('sdfo_create_account_' . $prospect_post_id, 'sdfo_account_nonce'); ?>

                <div class="sd-front-field">
                    <label>Full Name</label>
                    <input
                        type="text"
                        name="full_name"
                        value="<?php echo esc_attr($full_name); ?>"
                        autocomplete="name"
                        required
                    >
                </div>

                <div class="sd-front-field">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        value="<?php echo esc_attr($email); ?>"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="sd-front-field">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>

                <div class="sd-front-field">
                    <label>Confirm Password</label>
                    <input type="password" name="password_confirm" required>
                </div>

                <div class="sd-front-actions">
                    <button type="submit" class="sd-front-btn sd-front-btn--primary">
                        Create Account
                    </button>
                </div>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public static function shortcode_prospect_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Prospect status block</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_token_request();

        self::maybe_handle_front_checkout_submit($prospect_post_id);
        self::maybe_finalize_checkout_success($prospect_post_id);

        $lifecycle = (string) get_post_meta($prospect_post_id, 'sd_lifecycle_stage', true);
        if ($lifecycle === '') {
            $lifecycle = self::STAGE_INTAKE_CAPTURED;
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', $lifecycle);
        }

        switch ($lifecycle) {
            case self::STAGE_INTAKE_CAPTURED:
            case self::STAGE_ACCOUNT_PENDING:
                return self::render_account_creation($prospect_post_id);

            case self::STAGE_ACCOUNT_CREATED:
            case self::STAGE_SLUG_PENDING:
                return self::render_slug_reservation($prospect_post_id);

            case self::STAGE_SLUG_RESERVED:
            case self::STAGE_CHECKOUT_PENDING:
                return self::render_checkout($prospect_post_id);

            case self::STAGE_SUBSCRIPTION_PAID:
            case self::STAGE_TENANT_PROVISIONING:
                return self::render_provisioning_state($prospect_post_id);

            case self::STAGE_TENANT_INACTIVE:
                return self::render_ready_state($prospect_post_id);

            case 'CONNECT_PENDING':
                return self::render_stripe_connect($prospect_post_id);

            case 'ACTIVATED':
                return self::render_ready_state($prospect_post_id);

            default:
                return '<div class="sd-front-container"><h1>Unknown state</h1></div>';
        }
    }

    private static function render_slug_reservation(int $prospect_post_id): string {
        $token = self::ensure_prospect_token($prospect_post_id);
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $requested_slug = (string) get_post_meta($prospect_post_id, 'sd_requested_slug', true);
        $slug_status = (string) get_post_meta($prospect_post_id, 'sd_slug_status', true);

        $error_code = isset($_GET['slug_err']) ? sanitize_text_field((string) $_GET['slug_err']) : '';
        $message = self::map_slug_error_message($error_code);

        if ($reserved_slug !== '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_RESERVED);

            ob_start();
            ?>
            <div class="sd-front-container">
                <div class="sd-front-hero">
                    <h1 class="sd-front-headline">Your storefront name is reserved</h1>
                    <p class="sd-front-body">
                        Your future storefront will live at:
                    </p>
                    <p class="sd-front-body">
                        <strong><?php echo esc_html('app.solodrive.pro/t/' . $reserved_slug); ?></strong>
                    </p>
                </div>

                <div class="sd-front-actions">
                    <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url(self::get_prospect_url_for_post($prospect_post_id)); ?>">
                        Continue to checkout
                    </a>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        $value = $requested_slug !== '' ? $requested_slug : '';

        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Choose your storefront name</h1>
                <p class="sd-front-body">
                    Pick the name that will appear in your future storefront URL.
                </p>
            </div>

            <?php if ($message !== '') : ?>
                <div class="sd-front-alert sd-front-alert--error">
                    <?php echo esc_html($message); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sd-front-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESERVE_SLUG); ?>">
                <input type="hidden" name="prospect_token" value="<?php echo esc_attr($token); ?>">
                <?php wp_nonce_field('sdfo_reserve_slug_' . $prospect_post_id, 'sdfo_slug_nonce'); ?>

                <div class="sd-front-field">
                    <label for="sd-storefront-slug">Storefront Name</label>
                    <input
                        type="text"
                        id="sd-storefront-slug"
                        name="requested_slug"
                        value="<?php echo esc_attr($value); ?>"
                        autocomplete="off"
                        required
                    >
                </div>

                <p class="sd-front-body">
                    Preview: <strong>app.solodrive.pro/t/<span><?php echo esc_html($value !== '' ? $value : 'your-name'); ?></span></strong>
                </p>

                <div class="sd-front-actions">
                    <button type="submit" class="sd-front-btn sd-front-btn--primary">
                        Reserve Name
                    </button>
                </div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_checkout(int $prospect_post_id): string {
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $checkout_session_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', true);
        $billing_status = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        $token = self::ensure_prospect_token($prospect_post_id);

        if ($reserved_slug === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
            return self::render_slug_reservation($prospect_post_id);
        }

        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_CHECKOUT_PENDING);

        $error_code = isset($_GET['checkout_err']) ? sanitize_text_field((string) $_GET['checkout_err']) : '';
        $message = self::map_checkout_error_message($error_code);

        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Ready for checkout</h1>
                <p class="sd-front-body">
                    Your storefront name <strong><?php echo esc_html($reserved_slug); ?></strong> is reserved.
                </p>
                <p class="sd-front-body">
                    Future storefront: <strong><?php echo esc_html('app.solodrive.pro/t/' . $reserved_slug); ?></strong>
                </p>
            </div>

            <?php if ($message !== '') : ?>
                <div class="sd-front-alert sd-front-alert--error">
                    <?php echo esc_html($message); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(self::get_prospect_url_for_post($prospect_post_id)); ?>" class="sd-front-form">
                <input type="hidden" name="sd_front_action" value="start_checkout">
                <input type="hidden" name="prospect_token" value="<?php echo esc_attr($token); ?>">
                <?php wp_nonce_field('sdfo_start_checkout_' . $prospect_post_id, 'sdfo_checkout_nonce'); ?>

                <div class="sd-front-actions">
                    <button type="submit" class="sd-front-btn sd-front-btn--primary">
                        Continue to checkout
                    </button>
                </div>
            </form>

            <?php if ($checkout_session_id !== '') : ?>
                <p class="sd-front-body">
                    Checkout session: <strong><?php echo esc_html($checkout_session_id); ?></strong>
                </p>
            <?php endif; ?>

            <?php if ($billing_status !== '') : ?>
                <p class="sd-front-body">
                    Billing status: <strong><?php echo esc_html($billing_status); ?></strong>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function maybe_handle_front_checkout_submit(int $prospect_post_id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $front_action = isset($_POST['sd_front_action']) ? sanitize_text_field((string) $_POST['sd_front_action']) : '';
        if ($front_action !== 'start_checkout') {
            return;
        }

        if (
            !isset($_POST['sdfo_checkout_nonce']) ||
            !wp_verify_nonce((string) $_POST['sdfo_checkout_nonce'], 'sdfo_start_checkout_' . $prospect_post_id)
        ) {
            self::redirect_checkout_error($prospect_post_id, 'invalid_request');
        }

        $posted_token = isset($_POST['prospect_token']) ? sanitize_text_field((string) $_POST['prospect_token']) : '';
        $actual_token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        if ($posted_token === '' || $actual_token === '' || !hash_equals($actual_token, $posted_token)) {
            self::redirect_checkout_error($prospect_post_id, 'invalid_request');
        }

        $owner_user_id = (int) get_post_meta($prospect_post_id, 'sd_owner_user_id', true);
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);

        if ($owner_user_id <= 0) {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_ACCOUNT_PENDING);
            wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
            exit;
        }

        if ($reserved_slug === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
            wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
            exit;
        }

        $session = self::create_stripe_checkout_session($prospect_post_id);
        if (empty($session['ok'])) {
            self::redirect_checkout_error($prospect_post_id, (string) ($session['error'] ?? 'checkout_failed'));
        }

        update_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', (string) $session['session_id']);
        update_post_meta($prospect_post_id, 'sd_billing_status', self::BILLING_CHECKOUT_PENDING);
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_CHECKOUT_PENDING);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        wp_redirect((string) $session['url']);
        exit;
    }

    private static function create_stripe_checkout_session(int $prospect_post_id): array {
        if (!class_exists('\\Stripe\\Stripe') || !class_exists('\\Stripe\\Checkout\\Session')) {
            return ['ok' => false, 'error' => 'stripe_sdk_missing'];
        }

        $stripe_secret_key = self::get_stripe_secret_key();
        if ($stripe_secret_key === '') {
            return ['ok' => false, 'error' => 'stripe_secret_missing'];
        }

        $pricing = self::resolve_checkout_pricing_for_prospect($prospect_post_id);
        if (empty($pricing['ok'])) {
            return ['ok' => false, 'error' => (string) ($pricing['error'] ?? 'stripe_price_missing')];
        }

        $price_id = (string) ($pricing['stripe_price_id'] ?? '');
        if ($price_id === '') {
            return ['ok' => false, 'error' => 'stripe_price_missing'];
        }

        update_post_meta($prospect_post_id, 'sd_pricing_profile_source', (string) ($pricing['profile_source'] ?? 'default'));
        update_post_meta($prospect_post_id, 'sd_pricing_profile_id', (string) ($pricing['profile_id'] ?? 'default_public'));
        update_post_meta($prospect_post_id, 'sd_resolved_stripe_price_id', $price_id);
        update_post_meta($prospect_post_id, 'sd_resolved_plan_label', (string) ($pricing['plan_label'] ?? ''));

        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $prospect_token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);

        try {
            \Stripe\Stripe::setApiKey($stripe_secret_key);

            $success_url = add_query_arg(
                ['checkout' => 'success', 'session_id' => '{CHECKOUT_SESSION_ID}'],
                self::get_prospect_url_for_post($prospect_post_id)
            );

            $cancel_url = add_query_arg(
                ['checkout' => 'cancel'],
                self::get_prospect_url_for_post($prospect_post_id)
            );

            $session = \Stripe\Checkout\Session::create([
                'mode' => 'subscription',
                'customer_email' => $email !== '' ? $email : null,
                'line_items' => [[
                    'price' => $price_id,
                    'quantity' => 1,
                ]],
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'metadata' => [
                    'prospect_post_id' => (string) $prospect_post_id,
                    'prospect_id'      => $prospect_id,
                    'prospect_token'   => $prospect_token,
                    'reserved_slug'    => $reserved_slug,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'prospect_post_id' => (string) $prospect_post_id,
                        'prospect_id'      => $prospect_id,
                        'reserved_slug'    => $reserved_slug,
                    ],
                ],
            ]);

            return [
                'ok' => true,
                'session_id' => (string) $session->id,
                'url' => (string) $session->url,
            ];
        } catch (Throwable $e) {
            error_log('SD Front Office: Stripe Checkout session create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'checkout_failed'];
        }
    }

    private static function render_provisioning_state(int $prospect_post_id): string {
        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Provisioning in progress</h1>
                <p class="sd-front-body">
                    Your tenant is being prepared.
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_stripe_connect(int $prospect_post_id): string {
        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Connect Stripe</h1>
                <p class="sd-front-body">
                    Stripe Connect will be launched from this step.
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
        }

        public static function handle_slug_reservation_submit(): void {
        $token = isset($_POST['prospect_token']) ? sanitize_text_field((string) $_POST['prospect_token']) : '';
        $prospect_post_id = self::get_prospect_post_id_by_token($token);

        if ($prospect_post_id <= 0) {
            wp_die('Invalid or expired link.');
        }

        if (
            !isset($_POST['sdfo_slug_nonce']) ||
            !wp_verify_nonce((string) $_POST['sdfo_slug_nonce'], 'sdfo_reserve_slug_' . $prospect_post_id)
        ) {
            self::redirect_slug_error($prospect_post_id, 'invalid_request');
        }

        $owner_user_id = (int) get_post_meta($prospect_post_id, 'sd_owner_user_id', true);
        if ($owner_user_id <= 0) {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_ACCOUNT_PENDING);
            wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
            exit;
        }

        $candidate = isset($_POST['requested_slug']) ? (string) $_POST['requested_slug'] : '';
        $normalized = self::normalize_slug_candidate($candidate);

        update_post_meta($prospect_post_id, 'sd_requested_slug', $normalized);

        if (!self::is_valid_slug_candidate($normalized)) {
            update_post_meta($prospect_post_id, 'sd_slug_status', 'invalid');
            self::redirect_slug_error($prospect_post_id, 'invalid_slug');
        }

        if (!self::is_slug_available($normalized, $prospect_post_id)) {
            update_post_meta($prospect_post_id, 'sd_slug_status', 'unavailable');
            self::redirect_slug_error($prospect_post_id, 'slug_taken');
        }

        update_post_meta($prospect_post_id, 'sd_requested_slug', $normalized);
        update_post_meta($prospect_post_id, 'sd_reserved_slug', $normalized);
        update_post_meta($prospect_post_id, 'sd_slug_status', 'reserved');
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_RESERVED);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
        exit;
    }

    private static function render_ready_state(int $prospect_post_id): string {
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Your account is ready</h1>
                <p class="sd-front-body">
                    Your storefront and operations access are available.
                </p>
            </div>

            <?php if ($storefront_url !== '') : ?>
                <div class="sd-front-actions">
                    <a class="sd-front-btn sd-front-btn--secondary" href="<?php echo esc_url($storefront_url); ?>">
                        Open your booking page
                    </a>

                    <?php if ($operations_entry_url !== '') : ?>
                        <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($operations_entry_url); ?>">
                            Go to Operations
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function maybe_finalize_checkout_success(int $prospect_post_id): void {
        $checkout_flag = isset($_GET['checkout']) ? sanitize_text_field((string) $_GET['checkout']) : '';
        $session_id = isset($_GET['session_id']) ? sanitize_text_field((string) $_GET['session_id']) : '';

        error_log('SD Front Office: maybe_finalize_checkout_success entered for prospect_post_id=' . $prospect_post_id . ' checkout=' . $checkout_flag . ' session_id=' . $session_id);

        if ($checkout_flag !== 'success' || $session_id === '') {
            error_log('SD Front Office: checkout success finalizer skipped due to missing success/session');
            return;
        }

        $existing_paid = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        if ($existing_paid === self::BILLING_SUBSCRIPTION_PAID) {
            error_log('SD Front Office: checkout success finalizer skipped, already marked paid for prospect_post_id=' . $prospect_post_id);
            return;
        }

        if (!class_exists('\\Stripe\\Stripe') || !class_exists('\\Stripe\\Checkout\\Session')) {
            error_log('SD Front Office: checkout success finalizer skipped, Stripe SDK missing');
            return;
        }

        $stripe_secret_key = self::get_stripe_secret_key();
        if ($stripe_secret_key === '') {
            error_log('SD Front Office: checkout success finalizer skipped, Stripe secret missing');
            return;
        }

        try {
            \Stripe\Stripe::setApiKey($stripe_secret_key);

            $session = \Stripe\Checkout\Session::retrieve($session_id, []);
            if (!$session) {
                error_log('SD Front Office: checkout success finalizer failed, no session returned for session_id=' . $session_id);
                return;
            }

            error_log('SD Front Office: checkout session retrieved. payment_status=' . (string) $session->payment_status . ' subscription=' . (string) ($session->subscription ?? '') . ' customer=' . (string) ($session->customer ?? ''));

            if ((string) $session->payment_status !== 'paid') {
                error_log('SD Front Office: checkout session not marked paid yet for session_id=' . $session_id);
                return;
            }

            $subscription_id = isset($session->subscription) ? (string) $session->subscription : '';
            $customer_id = isset($session->customer) ? (string) $session->customer : '';

            update_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', (string) $session_id);
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
            update_post_meta($prospect_post_id, 'sd_billing_status', self::BILLING_SUBSCRIPTION_PAID);
            update_post_meta($prospect_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SUBSCRIPTION_PAID);
            update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

            error_log('SD Front Office: marked subscription paid for prospect_post_id=' . $prospect_post_id . ' subscription_id=' . $subscription_id . ' customer_id=' . $customer_id);

            self::maybe_provision_inactive_tenant($prospect_post_id);
            $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);
            if ($operations_entry_url !== '') {
                wp_redirect($operations_entry_url);
                exit;
            }
        } catch (Throwable $e) {
            error_log('SD Front Office: checkout success verify failed: ' . $e->getMessage());
        }
    }

    private static function promote_prospect_to_tenant(int $prospect_post_id): array {
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $prospect_token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_raw', true);
        $phone = (string) get_post_meta($prospect_post_id, 'sd_phone_raw', true);
        $business_name = (string) get_post_meta($prospect_post_id, self::META_BUSINESS_NAME, true);

        $stripe_account_id = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);
        $stripe_state = (string) get_post_meta($prospect_post_id, self::META_STRIPE_STATE, true);
        $billing_status = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        $stripe_customer_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_customer_id', true);
        $stripe_subscription_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_subscription_id', true);

        $payload = [
            'prospect_id' => $prospect_id,
            'prospect_post_id' => $prospect_post_id,
            'prospect_token' => $prospect_token,
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'business_display_name' => $business_name !== '' ? $business_name : $full_name,
            'stripe_account_id' => $stripe_account_id,
            'stripe_state' => $stripe_state,
            'billing_status' => $billing_status,
            'stripe_customer_id' => $stripe_customer_id,
            'stripe_subscription_id' => $stripe_subscription_id,
        ];

        $response = self::post_control_plane_endpoint('promote-prospect', $payload);

        if (!is_array($response)) {
            return ['ok' => false, 'error' => 'invalid_response'];
        }

        return $response;
    }

    private static function maybe_provision_inactive_tenant(int $prospect_post_id): void {
        error_log('SD Front Office: maybe_provision_inactive_tenant entered for prospect_post_id=' . $prospect_post_id);

        $existing_tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($existing_tenant_post_id > 0) {
            error_log('SD Front Office: provisioning skipped, tenant already exists. tenant_post_id=' . $existing_tenant_post_id);
            return;
        }

        $billing_status = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);

        error_log('SD Front Office: provisioning precheck billing_status=' . $billing_status . ' reserved_slug=' . $reserved_slug . ' prospect_id=' . $prospect_id);

        if ($billing_status !== self::BILLING_SUBSCRIPTION_PAID || $reserved_slug === '') {
            error_log('SD Front Office: provisioning aborted due to unmet preconditions for prospect_post_id=' . $prospect_post_id);
            return;
        }

        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_TENANT_PROVISIONING);

        $tenant_post_id = wp_insert_post([
            'post_type'   => self::TENANT_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => 'Tenant - ' . $reserved_slug,
        ], true);

        if (is_wp_error($tenant_post_id) || !$tenant_post_id) {
            error_log('SD Front Office: tenant provision failed for prospect_post_id=' . $prospect_post_id . ' error=' . (is_wp_error($tenant_post_id) ? $tenant_post_id->get_error_message() : 'unknown'));
            return;
        }

        error_log('SD Front Office: tenant post created. tenant_post_id=' . $tenant_post_id);

        $tenant_id = 'tnt_' . wp_generate_uuid4();

        update_post_meta($tenant_post_id, 'sd_tenant_id', $tenant_id);
        update_post_meta($tenant_post_id, 'sd_slug', $reserved_slug);
        update_post_meta($tenant_post_id, 'sd_status', 'inactive');
        update_post_meta($tenant_post_id, 'sd_origin_prospect_id', $prospect_id);
        update_post_meta($tenant_post_id, 'sd_origin_prospect_post_id', $prospect_post_id);
        update_post_meta($tenant_post_id, 'sd_billing_status', self::BILLING_SUBSCRIPTION_PAID);
        update_post_meta($tenant_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
        update_post_meta($tenant_post_id, 'sd_provisioning_status', 'inactive_ready');
        update_post_meta($tenant_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $storefront_url = 'https://app.solodrive.pro/t/' . rawurlencode($reserved_slug);

        update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_id', $tenant_id);
        update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', (int) $tenant_post_id);
        update_post_meta($prospect_post_id, self::META_STOREFRONT_URL, $storefront_url);
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_TENANT_INACTIVE);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'TENANT_INACTIVE');            
        error_log('SD Front Office: provisioning completed. tenant_id=' . $tenant_id . ' tenant_post_id=' . $tenant_post_id . ' storefront_url=' . $storefront_url);
        self::provision_runtime_operator_access($prospect_post_id, $tenant_post_id);
    }

    private static function provision_runtime_operator_access(int $prospect_post_id, int $tenant_post_id): void {
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $tenant_id = (string) get_post_meta($tenant_post_id, 'sd_tenant_id', true);
        $slug = (string) get_post_meta($tenant_post_id, 'sd_slug', true);

        if ($email === '' || $tenant_id === '') {
            error_log('SD Front Office: runtime provisioning skipped (missing email or tenant_id)');
            return;
        }

        $endpoint = 'https://app.solodrive.pro/wp-json/sd/v1/provision-operator';

        $payload = [
            'tenant_id' => $tenant_id,
            'tenant_slug' => $slug,
            'email' => $email,
            'full_name' => $full_name,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('SD Front Office: runtime provisioning failed: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['ok']) && !empty($body['login_url'])) {
            update_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, (string) $body['login_url']);

            error_log('SD Front Office: runtime operator access provisioned: ' . $body['login_url']);
        } else {
            error_log('SD Front Office: runtime provisioning response invalid');
        }
    }

    public static function ensure_tenant_defaults(int $post_id, WP_Post $post, bool $update): void {
        if ($post->post_type !== self::TENANT_POST_TYPE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $tenant_id = (string) get_post_meta($post_id, 'sd_tenant_id', true);
        if ($tenant_id === '') {
            update_post_meta($post_id, 'sd_tenant_id', 'tnt_' . wp_generate_uuid4());
        }

        $status = (string) get_post_meta($post_id, 'sd_status', true);
        if ($status === '') {
            update_post_meta($post_id, 'sd_status', 'inactive');
        }

        $created_at = (string) get_post_meta($post_id, 'sd_created_at_gmt', true);
        if ($created_at === '') {
            update_post_meta($post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        $provisioning_status = (string) get_post_meta($post_id, 'sd_provisioning_status', true);
        if ($provisioning_status === '') {
            update_post_meta($post_id, 'sd_provisioning_status', 'inactive_ready');
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', current_time('mysql', true));
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

    private static function render_account_creation_success(int $prospect_post_id): string {
        $continue_url = self::get_prospect_url_for_post($prospect_post_id);

        ob_start();
        ?>
        <div class="sd-front-container">
            <div class="sd-front-hero">
                <h1 class="sd-front-headline">Account created</h1>
                <p class="sd-front-body">
                    Your account is ready. Continue to choose your storefront slug.
                </p>
            </div>

            <div class="sd-front-actions">
                <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($continue_url); ?>">
                    Continue
                </a>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
        }

        private static function normalize_slug_candidate(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        $value = preg_replace('/\-+/', '-', (string) $value);
        $value = trim((string) $value, '-');
        return sanitize_title($value);
    }

    private static function is_valid_slug_candidate(string $slug): bool {
        if ($slug === '') {
            return false;
        }

        if (strlen($slug) < 3 || strlen($slug) > 40) {
            return false;
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return false;
        }

        $reserved = [
            'app', 'admin', 'api', 'www', 'solodrive', 'operator', 'operations',
            'support', 'help', 'billing', 'stripe', 'login', 'signup', 'prospect',
            'tenant', 'dashboard', 'settings'
        ];

        return !in_array($slug, $reserved, true);
    }

    private static function is_slug_available(string $slug, int $current_prospect_post_id = 0): bool {
        $prospects = get_posts([
            'post_type'      => self::PROSPECT_POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'numberposts'    => 1,
            'post__not_in'   => $current_prospect_post_id > 0 ? [$current_prospect_post_id] : [],
            'meta_query'     => [[
                'key'     => 'sd_reserved_slug',
                'value'   => $slug,
                'compare' => '=',
            ]],
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        if (!empty($prospects)) {
            return false;
        }

        $tenants = get_posts([
            'post_type'      => self::TENANT_POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'numberposts'    => 1,
            'meta_query'     => [[
                'key'     => 'sd_slug',
                'value'   => $slug,
                'compare' => '=',
            ]],
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        return empty($tenants);
    }

    private static function redirect_slug_error(int $prospect_post_id, string $code): void {
        $url = add_query_arg(
            ['slug_err' => rawurlencode($code)],
            self::get_prospect_url_for_post($prospect_post_id)
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function map_slug_error_message(string $code): string {
        return match ($code) {
            'invalid_request' => 'We could not verify your request. Please try again.',
            'invalid_slug'    => 'Use 3-40 lowercase letters, numbers, or single hyphens.',
            'slug_taken'      => 'That storefront name is already taken.',
            default           => '',
        };
    }

    public static function handle_account_creation_submit(): void {
        $token = isset($_POST['prospect_token']) ? sanitize_text_field((string) $_POST['prospect_token']) : '';
        $prospect_post_id = self::get_prospect_post_id_by_token($token);

        if ($prospect_post_id <= 0) {
            wp_die('Invalid or expired link.');
        }

        if (
            !isset($_POST['sdfo_account_nonce']) ||
            !wp_verify_nonce((string) $_POST['sdfo_account_nonce'], 'sdfo_create_account_' . $prospect_post_id)
        ) {
            self::redirect_account_error($prospect_post_id, 'invalid_request');
        }

        $full_name = sanitize_text_field((string) ($_POST['full_name'] ?? ''));
        $email = sanitize_email((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $password_confirm = (string) ($_POST['password_confirm'] ?? '');

        if ($full_name === '' || $email === '') {
            self::redirect_account_error($prospect_post_id, 'missing_fields');
        }

        if (!is_email($email)) {
            self::redirect_account_error($prospect_post_id, 'invalid_email');
        }

        if (strlen($password) < 8) {
            self::redirect_account_error($prospect_post_id, 'weak_password');
        }

        if (!hash_equals($password, $password_confirm)) {
            self::redirect_account_error($prospect_post_id, 'password_mismatch');
        }

        $existing_owner_user_id = (int) get_post_meta($prospect_post_id, 'sd_owner_user_id', true);
        if ($existing_owner_user_id > 0) {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_ACCOUNT_CREATED);
            wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
            exit;
        }

        $user_result = self::resolve_or_create_frontend_owner_user($email, $password, $full_name);

        if (empty($user_result['ok'])) {
            self::redirect_account_error($prospect_post_id, (string) ($user_result['error'] ?? 'account_failed'));
        }

        $user_id = (int) $user_result['user_id'];

        update_post_meta($prospect_post_id, 'sd_owner_user_id', $user_id);
        update_post_meta($prospect_post_id, 'sd_full_name', $full_name);
        update_post_meta($prospect_post_id, 'sd_email_raw', $email);
        update_post_meta($prospect_post_id, 'sd_email_normalized', strtolower(trim($email)));
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_ACCOUNT_CREATED);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));


        wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
        exit;
    }

    private static function resolve_or_create_frontend_owner_user(string $email, string $password, string $full_name): array {
        $email = sanitize_email($email);
        $full_name = sanitize_text_field($full_name);

        if ($email === '' || !is_email($email)) {
            return ['ok' => false, 'error' => 'invalid_email'];
        }

        $existing_user = get_user_by('email', $email);
        if ($existing_user instanceof WP_User) {
            return ['ok' => false, 'error' => 'email_exists'];
        }

        $base_login = sanitize_user(current(explode('@', $email)), true);
        if ($base_login === '') {
            $base_login = 'sduser';
        }

        $login = $base_login;
        $suffix = 1;
        while (username_exists($login)) {
            $suffix++;
            $login = $base_login . $suffix;
        }

        $user_id = wp_create_user($login, $password, $email);
        if (is_wp_error($user_id) || $user_id <= 0) {
            return ['ok' => false, 'error' => 'create_failed'];
        }

        wp_update_user([
            'ID'           => $user_id,
            'display_name' => $full_name,
            'first_name'   => self::extract_first_name($full_name),
            'last_name'    => self::extract_last_name($full_name),
        ]);

        return ['ok' => true, 'user_id' => (int) $user_id];
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

    public static function register_rest_routes(): void {
        register_rest_route('sd/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function redirect_account_error(int $prospect_post_id, string $code): void {
        $url = add_query_arg(
            ['acct_err' => rawurlencode($code)],
            self::get_prospect_url_for_post($prospect_post_id)
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function map_account_error_message(string $code): string {
        return match ($code) {
            'invalid_request'   => 'We could not verify your request. Please try again.',
            'missing_fields'    => 'Please complete all required fields.',
            'invalid_email'     => 'Please enter a valid email address.',
            'weak_password'     => 'Your password must be at least 8 characters.',
            'password_mismatch' => 'Your passwords do not match.',
            'email_exists'      => 'An account already exists for this email. Login/claim flow comes next.',
            'create_failed'     => 'We could not create your account right now.',
            default             => '',
        };
    }

    private static function extract_first_name(string $full_name): string {
        $parts = preg_split('/\s+/', trim($full_name));
        return isset($parts[0]) ? sanitize_text_field($parts[0]) : '';
    }

    private static function extract_last_name(string $full_name): string {
        $parts = preg_split('/\s+/', trim($full_name));
        if (!$parts || count($parts) < 2) {
            return '';
        }
        array_shift($parts);
        return sanitize_text_field(implode(' ', $parts));
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

    private static function get_public_prospect_state(int $prospect_post_id): string {
        $activation_state = (string) get_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, true);
        $stripe_state = (string) get_post_meta($prospect_post_id, self::META_STRIPE_STATE, true);
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        if ($storefront_url !== '' || $operations_entry_url !== '') {
            return 'tenant_ready';
        }

        if ($activation_state === 'ACTIVATION_PROCESSING') {
            return 'activation_processing';
            if (in_array($public_state, ['payments_enabled', 'activation_processing'], true)) {
                echo '<script>setTimeout(() => location.reload(), 6000);</script>';
            }
        }

        if ($stripe_state === 'payments_enabled') {
            return 'payments_enabled';
            if (in_array($public_state, ['payments_enabled', 'activation_processing'], true)) {
                echo '<script>setTimeout(() => location.reload(), 6000);</script>';
            }
        }

        if ($stripe_state === 'payments_not_enabled') {
            return 'payments_not_enabled';
        }

        if ($stripe_state === 'onboarding_started') {
            return 'onboarding_started';
        }

        if ($stripe_state === 'account_created') {
            return 'account_created';
        }

        return 'started';
    }

    private static function get_public_prospect_view_model(int $prospect_post_id): array {
        $public_state = self::get_public_prospect_state($prospect_post_id);

        return match ($public_state) {

            // Stripe not started yet
            'started' => [
                'headline' => 'Connect your payments',
                'body' => 'Your setup page is ready. The next step is connecting payments through Stripe.',
                'button_label' => 'Start setup',
            ],

            // Account exists, onboarding not finished
            'account_created', 'onboarding_started' => [
                'headline' => 'Finish setting up payments',
                'body' => 'Your account has been created. Complete Stripe onboarding to continue.',
                'button_label' => 'Continue setup',
            ],

            // Stripe needs more info or is reviewing
            'payments_not_enabled' => [
                'headline' => 'Payments setup is still in progress',
                'body' => 'Stripe is reviewing your account or waiting on required information.',
                'button_label' => 'Resume setup',
            ],

            // Stripe complete → system now working
            'payments_enabled' => [
                'headline' => 'Payments are connected',
                'body' => 'Your account is ready. We’re activating your booking system now.',
                'button_label' => '',
            ],

            // Backend provisioning
            'activation_processing' => [
                'headline' => 'We’re activating your account',
                'body' => 'Your booking system is being prepared. This usually takes a few seconds.',
                'button_label' => '',
            ],

            // Done
            'tenant_ready' => [
                'headline' => 'Your lane is ready',
                'body' => 'Your booking page and operations access are now available.',
                'button_label' => '',
            ],

            // Fallback
            default => [
                'headline' => 'We received your request',
                'body' => 'Your setup page is ready. The next step is connecting payments.',
                'button_label' => 'Start setup',
            ],
        };
    }

    private static function map_public_status_label(string $state): string {
        return match ($state) {
            'STARTED' => 'Request received',
            'ACTIVATION_PROCESSING' => 'Activating your account',
            'TENANT_READY' => 'Ready',
            'ACTIVATION_FAILED' => 'Setup issue',
            default => 'Request received',
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

    private static function resolve_checkout_pricing_for_prospect(int $prospect_post_id): array {
        $invite_code = (string) get_post_meta($prospect_post_id, 'sd_invitation_code', true);

        // Future hook: invite-profile resolution
        if ($invite_code !== '') {
            $invite_profile = self::resolve_invite_pricing_profile($invite_code, $prospect_post_id);
            if (!empty($invite_profile['ok'])) {
                return $invite_profile;
            }
        }

        $default_price_id = (string) get_option('sd_default_stripe_subscription_price_id', '');
        $default_plan_label = (string) get_option('sd_default_subscription_plan_label', '');
        $default_display_price = (string) get_option('sd_default_subscription_display_price', '');

        if ($default_price_id === '') {
            return [
                'ok' => false,
                'error' => 'stripe_price_missing',
            ];
        }

        return [
            'ok' => true,
            'profile_source' => 'default',
            'profile_id' => 'default_public',
            'stripe_price_id' => $default_price_id,
            'plan_label' => $default_plan_label,
            'display_price' => $default_display_price,
        ];
    }

    private static function resolve_invite_pricing_profile(string $invite_code, int $prospect_post_id): array {
        // Stub for future invite-profile resolution.
        // Current doctrine: unresolved invite pricing falls through to default public pricing.
        return ['ok' => false, 'error' => 'invite_profile_unresolved'];
    }

    private static function get_stripe_secret_key(): string {
        if (defined('SD_FRONT_STRIPE_SECRET_KEY') && SD_FRONT_STRIPE_SECRET_KEY !== '') {
            return SD_FRONT_STRIPE_SECRET_KEY;
        }

        return (string) get_option('sd_stripe_secret_key', '');
    }

    private static function get_stripe_webhook_secret(): string {
        if (defined('SD_FRONT_STRIPE_WEBHOOK_SECRET') && SD_FRONT_STRIPE_WEBHOOK_SECRET !== '') {
            return SD_FRONT_STRIPE_WEBHOOK_SECRET;
        }

        return (string) get_option('sd_stripe_webhook_secret', '');
    }

    private static function redirect_checkout_error(int $prospect_post_id, string $code): void {
        $url = add_query_arg(
            ['checkout_err' => rawurlencode($code)],
            self::get_prospect_url_for_post($prospect_post_id)
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function map_checkout_error_message(string $code): string {
        return match ($code) {
            'invalid_request'      => 'We could not verify your request. Please try again.',
            'stripe_sdk_missing'   => 'Stripe is not available right now.',
            'stripe_secret_missing'=> 'Stripe secret key is missing.',
            'stripe_price_missing' => 'Subscription price is not configured.',
            'checkout_failed'      => 'We could not start checkout right now.',
            default                => '',
        };
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
