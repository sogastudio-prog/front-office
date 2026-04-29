<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Plugin Name: SoloDrive Front Office
 * Description: Front-office intake, lifecycle, and onboarding control plane.
 * Version: 0.1.0
 * Author: SoloDrive
 *
 * SoloDrive Front-Office Scaffold
 *
 * Purpose:
 * - Register sd_prospect and sd_provision_package CPTs
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

/*
 * Commercial package resolution lives in the SoloDrive kernel.
 * Public checkout uses the same source of truth instead of duplicating
 * package/profile rules in the front-office plugin.
 */
$sdfo_kernel_commercial_candidates = [
    WP_CONTENT_DIR . '/plugins/solodrive-kernel/includes/modules/commercial/020-commercial-profiles.php',
    dirname(ABSPATH) . '/app/wp-content/plugins/solodrive-kernel/includes/modules/commercial/020-commercial-profiles.php',
    ABSPATH . 'app/wp-content/plugins/solodrive-kernel/includes/modules/commercial/020-commercial-profiles.php',
];

foreach ($sdfo_kernel_commercial_candidates as $sdfo_kernel_commercial_path) {
    if (
        is_readable($sdfo_kernel_commercial_path)
        && !function_exists('sd_resolve_commercial_profile')
    ) {
        require_once $sdfo_kernel_commercial_path;
        break;
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-front-office.php';
$front_office_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($front_office_autoload)) {
    require_once $front_office_autoload;
}

final class SD_Front_Office_Scaffold {
    private const PROSPECT_POST_TYPE            = 'sd_prospect';
    private const PROVISION_PACKAGE_POST_TYPE   = 'sd_provision_package';
    private const REQUEST_ACCESS_FORM_ID        = 33;
    private const SUCCESS_PAGE_SLUG             = 'request-received';
    private const REST_NAMESPACE                = 'sd/v1';
    private const STRIPE_API_BASE               = 'https://api.stripe.com/v1';
    private const PAGE_SLUG_START               = 'start';
    private const PAGE_SLUG_CONFIRM             = 'confirm';
    private const PAGE_SLUG_CONNECT_PAYOUTS     = 'connect-payouts';
    private const PAGE_SLUG_SUCCESS             = 'success';

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
    private const STAGE_PROVISION_PACKAGE_STAGED         = 'PROVISION_PACKAGE_STAGED';

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
        add_action('save_post_sd_provision_package', [__CLASS__, 'ensure_provision_package_defaults'], 10, 3);
        if (!is_admin()) {
            add_filter('wpcf7_feedback_response', [__CLASS__, 'inject_cf7_redirect'], 10, 2);
        }

        add_filter('the_generator', '__return_empty_string');
        add_filter('login_headerurl', [__CLASS__, 'login_header_url']);
        add_filter('login_headertext', [__CLASS__, 'login_header_text']);
        add_filter('login_display_language_dropdown', '__return_false');
        add_action('login_enqueue_scripts', [__CLASS__, 'brand_wp_login']);
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

        register_post_type(self::PROVISION_PACKAGE_POST_TYPE, [
            'labels' => [
                'name' => 'Provision Packages',
                'singular_name' => 'Provision Package',
                'menu_name' => 'Provision Packages',
                'add_new' => 'Add Provision Package',
                'add_new_item' => 'Add New Provision Package',
                'edit_item' => 'Edit Provision Package',
                'new_item' => 'New Provision Package',
                'view_item' => 'View Provision Package',
                'search_items' => 'Search Provision Packages',
                'not_found' => 'No provision packages found',
                'not_found_in_trash' => 'No provision packages found in Trash',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 26,
            'menu_icon' => 'dashicons-archive',
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
            'sd_provision_package_id' => 'string',
            'sd_provision_package_post_id' => 'integer',
            'sd_runtime_tenant_id' => 'string',
            'sd_runtime_tenant_post_id' => 'integer',
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
            'sd_package_key' => 'string',
            'sd_commercial_profile_key' => 'string',
            'sd_authorization_code' => 'string',
            'sd_discount_policy_json' => 'string',
            'sd_application_fee_policy_json' => 'string',
            'sd_commercial_terms_snapshot_json' => 'string',
            'sd_pricing_profile_source' => 'string',
            'sd_pricing_profile_id' => 'string',
            'sd_resolved_stripe_price_id' => 'string',
            'sd_resolved_plan_label' => 'string',
                    ];

        $provision_package_meta = [
            'sd_provision_package_id' => 'string',
            'sd_reserved_slug' => 'string',
            'sd_package_status' => 'string',
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
            'sd_last_provisioning_response_json' => 'string',
            'sd_runtime_tenant_id' => 'string',
            'sd_runtime_tenant_post_id' => 'integer',
            'sd_provisioned_at_gmt' => 'string',
            'sd_package_key' => 'string',
            'sd_commercial_profile_key' => 'string',
            'sd_authorization_code' => 'string',
            'sd_discount_policy_json' => 'string',
            'sd_application_fee_policy_json' => 'string',
            'sd_commercial_terms_snapshot_json' => 'string',
            'sd_resolved_stripe_price_id' => 'string',
            'sd_resolved_plan_label' => 'string',
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

        foreach ($provision_package_meta as $key => $type) {
            register_post_meta(self::PROVISION_PACKAGE_POST_TYPE, $key, [
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
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_SLUG_PENDING);
        update_post_meta($prospect_post_id, 'sd_source', 'cf7_request_access');
        update_post_meta($prospect_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_full_name', (string) ($payload['full_name'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_raw', (string) ($payload['phone_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_phone_normalized', (string) ($payload['phone_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_raw', (string) ($payload['email_raw'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_email_normalized', (string) ($payload['email_normalized'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_invitation_code', (string) ($payload['invitation_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_package_key', (string) ($payload['package_key'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_authorization_code', (string) ($payload['authorization_code'] ?? ''));
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
        update_post_meta($prospect_post_id, 'sd_package_key', (string) ($payload['package_key'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_authorization_code', (string) ($payload['authorization_code'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_last_intake_channel', 'cf7');
        update_post_meta($prospect_post_id, 'sd_last_submission_at_gmt', (string) ($payload['submitted_at_gmt'] ?? current_time('mysql', true)));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', (string) ($payload['raw_payload_json'] ?? '{}'));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        $current_stage = (string) get_post_meta($prospect_post_id, 'sd_lifecycle_stage', true);
        if ($current_stage === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_INTAKE_CAPTURED);
        }
        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);
        $provision_package_id = (string) get_post_meta($prospect_post_id, 'sd_provision_package_id', true);

        if ($reserved_slug === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_SLUG_PENDING);
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
            case 'checkout.session.completed':
                self::handle_stripe_checkout_session_completed($event->data->object ?? null, $event_id);
                break;
            case 'invoice.paid':
                self::handle_stripe_invoice_paid($event->data->object ?? null, $event_id);
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


    private static function handle_stripe_checkout_session_completed($session, string $event_id = ''): void {
        if (!is_object($session)) {
            return;
        }

        $payment_status = isset($session->payment_status) ? (string) $session->payment_status : '';
        if ($payment_status !== 'paid') {
            return;
        }

        $prospect_post_id = 0;
        if (isset($session->metadata) && is_object($session->metadata) && isset($session->metadata->prospect_post_id)) {
            $prospect_post_id = absint((string) $session->metadata->prospect_post_id);
        }

        $session_id = isset($session->id) ? (string) $session->id : '';
        if ($prospect_post_id <= 0 && $session_id !== '') {
            $prospect_post_id = self::find_prospect_by_meta('sd_stripe_checkout_session_id', $session_id);
        }

        if ($prospect_post_id <= 0) {
            return;
        }

        self::mark_subscription_paid(
            $prospect_post_id,
            $session_id,
            isset($session->customer) ? (string) $session->customer : '',
            isset($session->subscription) ? (string) $session->subscription : '',
            'checkout.session.completed'
        );
    }

    private static function handle_stripe_invoice_paid($invoice, string $event_id = ''): void {
        if (!is_object($invoice)) {
            return;
        }

        $prospect_post_id = 0;
        if (isset($invoice->metadata) && is_object($invoice->metadata) && isset($invoice->metadata->prospect_post_id)) {
            $prospect_post_id = absint((string) $invoice->metadata->prospect_post_id);
        }

        $subscription_id = isset($invoice->subscription) ? (string) $invoice->subscription : '';
        $customer_id = isset($invoice->customer) ? (string) $invoice->customer : '';

        if ($prospect_post_id <= 0 && $subscription_id !== '') {
            $prospect_post_id = self::find_prospect_by_meta('sd_stripe_subscription_id', $subscription_id);
        }
        if ($prospect_post_id <= 0 && $customer_id !== '') {
            $prospect_post_id = self::find_prospect_by_meta('sd_stripe_customer_id', $customer_id);
        }

        if ($prospect_post_id <= 0) {
            return;
        }

        self::mark_subscription_paid(
            $prospect_post_id,
            '',
            $customer_id,
            $subscription_id,
            'invoice.paid'
        );
    }

    private static function mark_subscription_paid(
        int $prospect_post_id,
        string $session_id = '',
        string $customer_id = '',
        string $subscription_id = '',
        string $source = ''
    ): void {
        if ($prospect_post_id <= 0 || get_post_type($prospect_post_id) !== self::PROSPECT_POST_TYPE) {
            return;
        }

        update_post_meta($prospect_post_id, 'sd_billing_status', self::BILLING_SUBSCRIPTION_PAID);
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SUBSCRIPTION_PAID);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_SUBSCRIPTION_PAID);
        if ($session_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', $session_id);
        }
        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }
        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }
        update_post_meta($prospect_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);
        if ($provision_package_post_id > 0) {
            update_post_meta($provision_package_post_id, 'sd_billing_status', self::BILLING_SUBSCRIPTION_PAID);
            update_post_meta($provision_package_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
            update_post_meta($provision_package_post_id, 'sd_package_status', 'purchased');
            update_post_meta($provision_package_post_id, 'sd_provisioning_status', 'ready_for_runtime_provisioning');
            update_post_meta($provision_package_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        error_log('[SD Front Office] subscription paid via ' . ($source !== '' ? $source : 'direct') . ' for prospect_post_id=' . $prospect_post_id);
    }

    private static function find_prospect_by_meta(string $meta_key, string $meta_value): int {
        $meta_key = trim($meta_key);
        $meta_value = trim($meta_value);
        if ($meta_key === '' || $meta_value === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type' => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [[
                'key' => $meta_key,
                'value' => $meta_value,
                'compare' => '=',
            ]],
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
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
            case self::STAGE_ACCOUNT_CREATED:
            case self::STAGE_SLUG_PENDING:
                return self::render_slug_reservation($prospect_post_id);

            case self::STAGE_SLUG_RESERVED:
            case self::STAGE_CHECKOUT_PENDING:
                return self::render_checkout($prospect_post_id);

            case self::STAGE_SUBSCRIPTION_PAID:
            case self::STAGE_TENANT_PROVISIONING:
                return self::render_provisioning_state($prospect_post_id);

            case self::STAGE_PROVISION_PACKAGE_STAGED:
                return self::render_ready_state($prospect_post_id);

            case 'CONNECT_PENDING':
                return self::render_stripe_connect($prospect_post_id);

            case 'ACTIVATED':
                return self::render_ready_state($prospect_post_id);

            default:
                return self::render_slug_reservation($prospect_post_id);
        }
    }

    private static function render_slug_reservation(int $prospect_post_id): string {
        $token = self::ensure_prospect_token($prospect_post_id);
        $requested_slug = (string) get_post_meta($prospect_post_id, 'sd_requested_slug', true);

        $error_code = isset($_GET['slug_err']) ? sanitize_text_field((string) $_GET['slug_err']) : '';
        $message = self::map_slug_error_message($error_code);

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

        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);

        if ($reserved_slug === '') {
            update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
            wp_safe_redirect(self::get_prospect_url_for_post($prospect_post_id));
            exit;
        }

        self::maybe_stage_provision_package($prospect_post_id);

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

        update_post_meta($prospect_post_id, 'sd_pricing_profile_source', (string) ($pricing['profile_source'] ?? 'commercial'));
        update_post_meta($prospect_post_id, 'sd_pricing_profile_id', (string) ($pricing['profile_id'] ?? ''));
        update_post_meta($prospect_post_id, 'sd_resolved_stripe_price_id', $price_id);
        update_post_meta($prospect_post_id, 'sd_resolved_plan_label', (string) ($pricing['plan_label'] ?? ''));

        $package_key               = (string) ($pricing['package_key'] ?? '');
        $commercial_profile_key    = (string) ($pricing['profile_id'] ?? '');
        $authorization_code        = (string) ($pricing['authorization_code'] ?? '');

        $email                     = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $prospect_token            = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        $prospect_id               = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $reserved_slug             = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);
        $provision_package_id      = (string) get_post_meta($prospect_post_id, 'sd_provision_package_id', true);

        if ($provision_package_post_id <= 0 || $provision_package_id === '') {
            return ['ok' => false, 'error' => 'provision_package_missing'];
        }

        try {
            \Stripe\Stripe::setApiKey($stripe_secret_key);

            $provisioning_secret = defined('SD_CONTROL_PLANE_PROVISIONING_SECRET')
                ? SD_CONTROL_PLANE_PROVISIONING_SECRET
                : (string) get_option('sd_control_plane_provisioning_secret', '');

            $onboarding_token = hash_hmac(
                'sha256',
                $prospect_post_id . '|' . $prospect_token,
                $provisioning_secret
            );

            $sdpro_base = defined('SD_RUNTIME_BASE_URL')
                ? rtrim(SD_RUNTIME_BASE_URL, '/')
                : rtrim((string) get_option('sd_runtime_base_url', 'https://app.solodrive.pro'), '/');

            $success_url =
                $sdpro_base
                . '/operator/?sd_onboard=1'
                . '&prospect_post_id=' . rawurlencode((string) $prospect_post_id)
                . '&prospect_token=' . rawurlencode($prospect_token)
                . '&session_id={CHECKOUT_SESSION_ID}'
                . '&sig=' . rawurlencode($onboarding_token);

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
                    'prospect_post_id'          => (string) $prospect_post_id,
                    'prospect_id'               => $prospect_id,
                    'prospect_token'            => $prospect_token,
                    'reserved_slug'             => $reserved_slug,
                    'provision_package_post_id' => (string) $provision_package_post_id,
                    'provision_package_id'      => $provision_package_id,
                    'sd_package_key'            => $package_key,
                    'sd_commercial_profile_key' => $commercial_profile_key,
                    'sd_authorization_code'     => $authorization_code,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'prospect_post_id'          => (string) $prospect_post_id,
                        'prospect_id'               => $prospect_id,
                        'reserved_slug'             => $reserved_slug,
                        'provision_package_post_id' => (string) $provision_package_post_id,
                        'provision_package_id'      => $provision_package_id,
                        'sd_package_key'            => $package_key,
                        'sd_commercial_profile_key' => $commercial_profile_key,
                        'sd_authorization_code'     => $authorization_code,
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

    private static function normalize_slug_candidate(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        $value = preg_replace('/\-+/', '-', (string) $value);
        $value = trim((string) $value, '-');
        return sanitize_title($value);
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

        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_SLUG_PENDING);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_SLUG_PENDING);

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
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_SLUG_RESERVED);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        // Create the provision package now. This is the product being sold.
        self::maybe_stage_provision_package($prospect_post_id);

        // After slug reservation and package staging, the next surface is checkout.
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_CHECKOUT_PENDING);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_CHECKOUT_PENDING);

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

        if ($checkout_flag !== 'success' || $session_id === '') {
            return;
        }

        error_log('SD Front Office: maybe_finalize_checkout_success entered for prospect_post_id=' . $prospect_post_id . ' checkout=' . $checkout_flag . ' session_id=' . $session_id);

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

            if ((string) $session->payment_status !== 'paid') {
                error_log('SD Front Office: checkout session not marked paid yet for session_id=' . $session_id);
                return;
            }

            $subscription_id = isset($session->subscription) ? (string) $session->subscription : '';
            $customer_id = isset($session->customer) ? (string) $session->customer : '';

            self::mark_subscription_paid(
                $prospect_post_id,
                (string) $session_id,
                $customer_id,
                $subscription_id,
                'checkout-success-fallback'
            );

            $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);
            if ($operations_entry_url !== '') {
                wp_redirect($operations_entry_url);
                exit;
            }
        } catch (Throwable $e) {
            error_log('SD Front Office: checkout success verify failed: ' . $e->getMessage());
        }
    }

    private static function maybe_stage_provision_package(int $prospect_post_id): void {
        error_log('SD Front Office: maybe_stage_provision_package entered for prospect_post_id=' . $prospect_post_id);

        $existing_provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);
        if ($existing_provision_package_post_id > 0) {
            error_log('SD Front Office: provision package staging skipped, already exists. provision_package_post_id=' . $existing_provision_package_post_id);
            return;
        }

        $reserved_slug = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $prospect_id   = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);

        error_log('SD Front Office: provision package precheck reserved_slug=' . $reserved_slug . ' prospect_id=' . $prospect_id);

        if ($reserved_slug === '') {
            error_log('SD Front Office: provision package staging aborted, missing reserved_slug for prospect_post_id=' . $prospect_post_id);
            return;
        }

        $provision_package_post_id = wp_insert_post([
            'post_type'   => self::PROVISION_PACKAGE_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => 'Provision Package - ' . $reserved_slug,
        ], true);

        if (is_wp_error($provision_package_post_id) || !$provision_package_post_id) {
            error_log(
                'SD Front Office: provision package create failed for prospect_post_id='
                . $prospect_post_id
                . ' error='
                . (is_wp_error($provision_package_post_id) ? $provision_package_post_id->get_error_message() : 'unknown')
            );
            return;
        }

        $provision_package_id = 'pkg_' . wp_generate_uuid4();

        update_post_meta($provision_package_post_id, 'sd_provision_package_id', $provision_package_id);
        update_post_meta($provision_package_post_id, 'sd_reserved_slug', $reserved_slug);
        update_post_meta($provision_package_post_id, 'sd_package_status', 'staged');
        update_post_meta($provision_package_post_id, 'sd_origin_prospect_id', $prospect_id);
        update_post_meta($provision_package_post_id, 'sd_origin_prospect_post_id', $prospect_post_id);
        update_post_meta($provision_package_post_id, 'sd_billing_status', self::BILLING_CHECKOUT_PENDING);
        update_post_meta($provision_package_post_id, 'sd_subscription_paid_at_gmt', '');
        update_post_meta($provision_package_post_id, 'sd_provisioning_status', 'awaiting_payment');
        update_post_meta($provision_package_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($provision_package_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $storefront_url = 'https://app.solodrive.pro/t/' . rawurlencode($reserved_slug);

        update_post_meta($prospect_post_id, 'sd_provision_package_id', $provision_package_id);
        update_post_meta($prospect_post_id, 'sd_provision_package_post_id', (int) $provision_package_post_id);
        update_post_meta($prospect_post_id, self::META_STOREFRONT_URL, $storefront_url);
        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', self::STAGE_CHECKOUT_PENDING);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, self::STAGE_CHECKOUT_PENDING);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        error_log(
            'SD Front Office: provision package staged. provision_package_id='
            . $provision_package_id
            . ' provision_package_post_id='
            . $provision_package_post_id
            . ' storefront_url='
            . $storefront_url
        );
    }

    private static function deprecated_provision_runtime_operator_access(int $prospect_post_id, int $provision_package_post_id): void {
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $tenant_id = (string) get_post_meta($provision_package_post_id, 'sd_provision_package_id', true);
        $slug = (string) get_post_meta($provision_package_post_id, 'sd_reserved_slug', true);

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

    public static function ensure_provision_package_defaults(int $post_id, WP_Post $post, bool $update): void {
        if ($post->post_type !== self::PROVISION_PACKAGE_POST_TYPE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $tenant_id = (string) get_post_meta($post_id, 'sd_provision_package_id', true);
        if ($tenant_id === '') {
            update_post_meta($post_id, 'sd_provision_package_id', 'pkg_' . wp_generate_uuid4());
        }

        $status = (string) get_post_meta($post_id, 'sd_package_status', true);
        if ($status === '') {
            update_post_meta($post_id, 'sd_package_status', 'staged');
        }

        $created_at = (string) get_post_meta($post_id, 'sd_created_at_gmt', true);
        if ($created_at === '') {
            update_post_meta($post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        $provisioning_status = (string) get_post_meta($post_id, 'sd_provisioning_status', true);
        if ($provisioning_status === '') {
            update_post_meta($post_id, 'sd_provisioning_status', 'staged_for_provisioning');
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', current_time('mysql', true));
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
            'post_type'      => self::PROVISION_PACKAGE_POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'numberposts'    => 1,
            'meta_query'     => [[
                'key'     => 'sd_reserved_slug',
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

    public static function register_rest_routes(): void {
        register_rest_route('sd/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);

        // Called by SDPRO after it verifies the Stripe session on the operator's
        // browser. Marks the prospect paid so the front-office record stays in sync
        // with what happened on the SDPRO side.
        register_rest_route('sd/v1', '/control-plane/confirm-billing', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_confirm_billing'],
            'permission_callback' => '__return_true',
        ]);

        // Called by SDPRO to fetch the prospect package needed to build the
        // provisioning payload (tenant_id, slug, name, email, stripe ids, etc.)
        register_rest_route('sd/v1', '/control-plane/get-prospect-package', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_get_prospect_package'],
            'permission_callback' => '__return_true',
        ]);
    }

    // -------------------------------------------------------------------------
    // REST: confirm-billing
    //
    // SDPRO calls this after verifying the Stripe Checkout session. We trust
    // the call because it carries the same HMAC-SHA256 signature used throughout
    // the control-plane bridge.
    // -------------------------------------------------------------------------
    public static function handle_confirm_billing(WP_REST_Request $request): WP_REST_Response {
        if (!self::verify_provisioning_signature($request)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'signature_invalid'], 401);
        }

        $params = $request->get_json_params();

        $prospect_post_id       = absint($params['prospect_post_id'] ?? 0);
        $prospect_token         = sanitize_text_field((string) ($params['prospect_token'] ?? ''));
        $session_id             = sanitize_text_field((string) ($params['session_id'] ?? ''));
        $stripe_customer_id     = sanitize_text_field((string) ($params['stripe_customer_id'] ?? ''));
        $stripe_subscription_id = sanitize_text_field((string) ($params['stripe_subscription_id'] ?? ''));

        if ($prospect_post_id <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_prospect_post_id'], 400);
        }

        $stored_token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        if ($stored_token === '' || !hash_equals($stored_token, $prospect_token)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'token_mismatch'], 401);
        }

        $current = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        if ($current !== self::BILLING_SUBSCRIPTION_PAID) {
            self::mark_subscription_paid(
                $prospect_post_id,
                $session_id,
                $stripe_customer_id,
                $stripe_subscription_id,
                'confirm-billing'
            );
        }

        return new WP_REST_Response(['ok' => true, 'billing_status' => self::BILLING_SUBSCRIPTION_PAID], 200);
    }

    // -------------------------------------------------------------------------
    // REST: get-prospect-package
    //
    // SDPRO calls this to retrieve the minimal data it needs to build and fire
    // the provisioning payload. Token-verified — no WP session required.
    // -------------------------------------------------------------------------
    public static function handle_get_prospect_package(WP_REST_Request $request): WP_REST_Response {
        if (!self::verify_provisioning_signature($request)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'signature_invalid'], 401);
        }

        $params = $request->get_json_params();

        $prospect_post_id = absint($params['prospect_post_id'] ?? 0);
        $prospect_token   = sanitize_text_field((string) ($params['prospect_token'] ?? ''));

        if ($prospect_post_id <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing_prospect_post_id'], 400);
        }

        $stored_token = (string) get_post_meta($prospect_post_id, self::META_PROSPECT_TOKEN, true);
        if ($stored_token === '' || !hash_equals($stored_token, $prospect_token)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'token_mismatch'], 401);
        }

        $provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);
        $provision_package_id      = (string) get_post_meta($prospect_post_id, 'sd_provision_package_id', true);
        $reserved_slug             = (string) get_post_meta($prospect_post_id, 'sd_reserved_slug', true);
        $prospect_id               = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $full_name                 = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $email                     = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $phone                     = (string) get_post_meta($prospect_post_id, 'sd_phone_raw', true);
        $stripe_acct_id            = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);

        if ($provision_package_id === '' && $provision_package_post_id > 0) {
            $provision_package_id = (string) get_post_meta($provision_package_post_id, 'sd_provision_package_id', true);
        }

        if ($provision_package_id === '') {
            $provision_package_id = 'pkg_' . wp_generate_uuid4();
            update_post_meta($prospect_post_id, 'sd_provision_package_id', $provision_package_id);
        }

        if ($reserved_slug === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'slug_not_reserved'], 409);
        }

        return new WP_REST_Response([
            'ok'                        => true,
            'provision_package_post_id' => $provision_package_post_id,
            'provision_package_id'      => $provision_package_id,
            'reserved_slug'             => $reserved_slug,
            'tenant_id'                 => '',
            'tenant_slug'               => $reserved_slug,
            'prospect_id'               => $prospect_id,
            'prospect_post_id'          => $prospect_post_id,
            'full_name'                 => $full_name,
            'email'                     => $email,
            'phone'                     => $phone,
            'stripe_account_id'         => $stripe_acct_id,
        ], 200);
    }

    // -------------------------------------------------------------------------
    // Shared HMAC signature verification for control-plane REST routes
    // -------------------------------------------------------------------------
    private static function verify_provisioning_signature(WP_REST_Request $request): bool {
        $secret = defined('SD_CONTROL_PLANE_PROVISIONING_SECRET')
            ? SD_CONTROL_PLANE_PROVISIONING_SECRET
            : (string) get_option('sd_control_plane_provisioning_secret', '');

        if ($secret === '') {
            error_log('[SD Front Office] Provisioning secret not configured — rejecting request.');
            return false;
        }

        $raw_body = $request->get_body();
        $sig      = $request->get_header('X-SD-Signature');

        if ($sig !== null && $sig !== '') {
            return hash_equals(hash_hmac('sha256', $raw_body, $secret), strtolower($sig));
        }

        return false;
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
            'package_key'       => sanitize_key((string) ($posted_data['sd_package_key'] ?? $posted_data['sd_package'] ?? 'operator')),
            'authorization_code'=> sanitize_text_field((string) ($posted_data['sd_authorization_code'] ?? $posted_data['authorization_code'] ?? $posted_data['invite_code'] ?? '')),
            'submission_count'  => 1,
            'submitted_at_gmt'  => current_time('mysql', true),
            'raw_payload_json'  => wp_json_encode($posted_data),
        ];
    }

    private static function resolve_checkout_pricing_for_prospect(int $prospect_post_id): array {
        if (!function_exists('sd_resolve_commercial_profile')) {
            return ['ok' => false, 'error' => 'commercial_resolver_missing'];
        }

        $package_key = (string) get_post_meta($prospect_post_id, 'sd_package_key', true);
        $authorization_code = (string) get_post_meta($prospect_post_id, 'sd_authorization_code', true);

        if ($package_key === '') {
            $package_key = 'operator';
            update_post_meta($prospect_post_id, 'sd_package_key', $package_key);
        }

        $resolved = sd_resolve_commercial_profile($package_key, $authorization_code);

        if (empty($resolved['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($resolved['error'] ?? 'commercial_profile_unresolved'),
            ];
        }

        $stripe_price_id = (string) ($resolved['billing']['stripe_price_id'] ?? '');

        if ($stripe_price_id === '') {
            return ['ok' => false, 'error' => 'stripe_price_missing'];
        }

        self::store_commercial_snapshot($prospect_post_id, $resolved);

        return [
            'ok' => true,
            'profile_source' => 'commercial',
            'profile_id' => (string) ($resolved['profile_key'] ?? ''),
            'package_key' => (string) ($resolved['package_key'] ?? $package_key),
            'authorization_code' => (string) ($resolved['authorization_code'] ?? $authorization_code),
            'stripe_price_id' => $stripe_price_id,
            'plan_label' => (string) ($resolved['label'] ?? $resolved['package_label'] ?? 'SoloDrive Subscription'),
            'discount_policy' => $resolved['discount_policy'] ?? [],
            'application_fee_policy' => $resolved['application_fee_policy'] ?? [],
            'terms_snapshot' => $resolved['terms_snapshot'] ?? [],
        ];
    }

    private static function store_commercial_snapshot(int $prospect_post_id, array $resolved): void {
        $package_key = (string) ($resolved['package_key'] ?? '');
        $profile_key = (string) ($resolved['profile_key'] ?? '');
        $authorization_code = (string) ($resolved['authorization_code'] ?? '');
        $stripe_price_id = (string) ($resolved['billing']['stripe_price_id'] ?? '');
        $plan_label = (string) ($resolved['label'] ?? $resolved['package_label'] ?? '');

        update_post_meta($prospect_post_id, 'sd_package_key', $package_key);
        update_post_meta($prospect_post_id, 'sd_commercial_profile_key', $profile_key);
        update_post_meta($prospect_post_id, 'sd_authorization_code', $authorization_code);
        update_post_meta($prospect_post_id, 'sd_resolved_stripe_price_id', $stripe_price_id);
        update_post_meta($prospect_post_id, 'sd_resolved_plan_label', $plan_label);
        update_post_meta($prospect_post_id, 'sd_discount_policy_json', wp_json_encode($resolved['discount_policy'] ?? []));
        update_post_meta($prospect_post_id, 'sd_application_fee_policy_json', wp_json_encode($resolved['application_fee_policy'] ?? []));
        update_post_meta($prospect_post_id, 'sd_commercial_terms_snapshot_json', wp_json_encode($resolved['terms_snapshot'] ?? []));

        $provision_package_post_id = (int) get_post_meta($prospect_post_id, 'sd_provision_package_post_id', true);

        if ($provision_package_post_id > 0) {
            update_post_meta($provision_package_post_id, 'sd_package_key', $package_key);
            update_post_meta($provision_package_post_id, 'sd_commercial_profile_key', $profile_key);
            update_post_meta($provision_package_post_id, 'sd_authorization_code', $authorization_code);
            update_post_meta($provision_package_post_id, 'sd_resolved_stripe_price_id', $stripe_price_id);
            update_post_meta($provision_package_post_id, 'sd_resolved_plan_label', $plan_label);
            update_post_meta($provision_package_post_id, 'sd_discount_policy_json', wp_json_encode($resolved['discount_policy'] ?? []));
            update_post_meta($provision_package_post_id, 'sd_application_fee_policy_json', wp_json_encode($resolved['application_fee_policy'] ?? []));
            update_post_meta($provision_package_post_id, 'sd_commercial_terms_snapshot_json', wp_json_encode($resolved['terms_snapshot'] ?? []));
        }
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


    public static function login_header_url(): string {
        return home_url('/');
    }

    public static function login_header_text(): string {
        return 'SoloDrive';
    }

    public static function brand_wp_login(): void {
        echo '<style>'
            . 'body.login{background:#f6f7fb;color:#0f172a;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}'
            . '#login{width:min(92vw,420px);padding-top:6vh;}'
            . '#login h1 a{background:none!important;text-indent:0;width:auto;height:auto;display:flex;justify-content:center;align-items:center;color:#0f172a;font-size:28px;font-weight:800;text-decoration:none;}'
            . '#login h1 a::after{content:"SoloDrive";}'
            . '.login form{border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 18px 40px rgba(15,23,42,.08);padding:28px 24px;background:#fff;}'
            . '.login .button-primary{background:#111827;border-color:#111827;width:100%;min-height:42px;border-radius:999px;box-shadow:none;}'
            . '.login #nav,.login #backtoblog{display:none;}'
            . '.language-switcher,.privacy-policy-page-link{display:none!important;}'
            . '</style>';
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

 }

SD_Front_Office_Scaffold::bootstrap();
add_action('sd_control_plane_provision_package_requested', function ($provision_package_post_id, $prospect_post_id, $payload) {
    if ($provision_package_post_id > 0) {
        update_post_meta($provision_package_post_id, 'sd_provisioning_status', 'deprecated_front_dispatch_ignored');
        update_post_meta($provision_package_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
    }
    error_log('[SD Front Office] Ignored deprecated front provisioning dispatch. provision_package_post_id=' . (int) $provision_package_post_id);
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
    (function() {
      var params = new URLSearchParams(window.location.search);
      var packageKey = params.get('sd_package') || params.get('sd_package_key');
      var authorizationCode = params.get('sd_authorization_code') || params.get('authorization_code');

      try {
        if (packageKey) {
          sessionStorage.setItem('sd_package_key', packageKey);
        }
        if (authorizationCode) {
          sessionStorage.setItem('sd_authorization_code', authorizationCode);
        }
      } catch (e) {}

      try {
        packageKey = packageKey || sessionStorage.getItem('sd_package_key');
        authorizationCode = authorizationCode || sessionStorage.getItem('sd_authorization_code');
      } catch (e) {}

      document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form.wpcf7-form').forEach(function(form) {
          if (packageKey && !form.querySelector('input[name="sd_package_key"]')) {
            var packageInput = document.createElement('input');
            packageInput.type = 'hidden';
            packageInput.name = 'sd_package_key';
            packageInput.value = packageKey;
            form.appendChild(packageInput);
          }

          if (authorizationCode && !form.querySelector('input[name="sd_authorization_code"]')) {
            var authInput = document.createElement('input');
            authInput.type = 'hidden';
            authInput.name = 'sd_authorization_code';
            authInput.value = authorizationCode;
            form.appendChild(authInput);
          }
        });
      });

      document.addEventListener('wpcf7mailsent', function(event) {
        if (event && event.detail && event.detail.apiResponse && event.detail.apiResponse.sd_redirect_url) {
          window.location.href = event.detail.apiResponse.sd_redirect_url;
        }
      }, false);
    })();
    </script>
    <?php
});
