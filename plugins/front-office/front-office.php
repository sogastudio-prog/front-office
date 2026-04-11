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
 * Notes:
 * - Scaffold only. Review capabilities, nonce checks, invitation validation,
 *   and tenant-promotion rules before production use.
 * - Designed for a lightweight custom plugin or mu-plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SD_Front_Office_Scaffold {
    private const PROSPECT_POST_TYPE = 'sd_prospect';
    private const TENANT_POST_TYPE   = 'sd_tenant';
    private const REQUEST_ACCESS_FORM_ID = 33;
    private const INVITE_READY_FORM_ID   = 387;
    private const SUCCESS_PAGE_SLUG  = 'request-received';
    private const REST_NAMESPACE = 'sd/v1';
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';
    private const PAGE_SLUG_START            = 'start';
    private const PAGE_SLUG_CONFIRM          = 'confirm';
    private const PAGE_SLUG_CONNECT_PAYOUTS  = 'connect-payouts';
    private const PAGE_SLUG_SUCCESS          = 'success';

    private const META_PUBLIC_KEY            = 'sd_public_key';
    private const META_ACTIVATION_STATE      = 'sd_activation_state';
    private const META_STOREFRONT_URL        = 'sd_storefront_url';
    private const META_OPERATIONS_ENTRY_URL  = 'sd_operations_entry_url';
    private const META_BUSINESS_NAME         = 'sd_business_name';
    private const META_SERVICE_AREA          = 'sd_service_area';

    private const ACTION_START               = 'sdfo_start';
    private const ACTION_START_PAYOUTS       = 'sdfo_start_payouts';

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_meta_keys']);
        add_action('init', [__CLASS__, 'register_shortcodes']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

        add_filter('manage_' . self::PROSPECT_POST_TYPE . '_posts_columns', [__CLASS__, 'prospect_columns']);
        add_action('manage_' . self::PROSPECT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_prospect_column'], 10, 2);
        add_filter('manage_edit-' . self::PROSPECT_POST_TYPE . '_sortable_columns', [__CLASS__, 'prospect_sortable_columns']);

        add_filter('manage_' . self::TENANT_POST_TYPE . '_posts_columns', [__CLASS__, 'tenant_columns']);
        add_action('manage_' . self::TENANT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_tenant_column'], 10, 2);
        add_filter('manage_edit-' . self::TENANT_POST_TYPE . '_sortable_columns', [__CLASS__, 'tenant_sortable_columns']);

        add_action('restrict_manage_posts', [__CLASS__, 'admin_filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_admin_filters']);

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

    public static function prospect_columns(array $columns): array {
        unset($columns['date']);
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => 'Title',
            'prospect_id' => 'Prospect ID',
            'lifecycle' => 'Lifecycle',
            'name' => 'Name',
            'phone' => 'Phone',
            'email' => 'Email',
            'invite' => 'Invite',
            'review' => 'Review',
            'stripe' => 'Stripe',
            'owner' => 'Owner',
            'updated' => 'Updated',
        ];
    }

    public static function tenant_columns(array $columns): array {
        unset($columns['date']);
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => 'Title',
            'tenant_id' => 'Tenant ID',
            'slug' => 'Slug',
            'domain' => 'Domain',
            'status' => 'Status',
            'storefront' => 'Storefront',
            'stripe' => 'Stripe',
            'health' => 'Health',
            'last_activity' => 'Last Activity',
            'updated' => 'Updated',
        ];
    }

    public static function render_prospect_column(string $column, int $post_id): void {
        switch ($column) {
            case 'prospect_id':
                echo esc_html((string) get_post_meta($post_id, 'sd_prospect_id', true));
                break;
            case 'lifecycle':
                echo esc_html((string) get_post_meta($post_id, 'sd_lifecycle_stage', true));
                break;
            case 'name':
                echo esc_html((string) get_post_meta($post_id, 'sd_full_name', true));
                break;
            case 'phone':
                echo esc_html((string) get_post_meta($post_id, 'sd_phone_normalized', true));
                break;
            case 'email':
                echo esc_html((string) get_post_meta($post_id, 'sd_email_normalized', true));
                break;
            case 'invite':
                echo esc_html((string) get_post_meta($post_id, 'sd_invitation_status', true));
                break;
            case 'review':
                echo esc_html((string) get_post_meta($post_id, 'sd_review_status', true));
                break;
            case 'stripe':
                echo esc_html((string) get_post_meta($post_id, 'sd_stripe_onboarding_status', true));
                break;
            case 'owner':
                $owner_id = (int) get_post_meta($post_id, 'sd_owner_user_id', true);
                $user = $owner_id ? get_userdata($owner_id) : null;
                echo esc_html($user ? $user->display_name : '');
                break;
            case 'updated':
                echo esc_html((string) get_post_meta($post_id, 'sd_updated_at_gmt', true));
                break;
        }
    }

    public static function render_tenant_column(string $column, int $post_id): void {
        switch ($column) {
            case 'tenant_id':
                echo esc_html((string) get_post_meta($post_id, 'sd_tenant_id', true));
                break;
            case 'slug':
                echo esc_html((string) get_post_meta($post_id, 'sd_slug', true));
                break;
            case 'domain':
                echo esc_html((string) get_post_meta($post_id, 'sd_domain', true));
                break;
            case 'status':
                echo esc_html((string) get_post_meta($post_id, 'sd_status', true));
                break;
            case 'storefront':
                echo esc_html((string) get_post_meta($post_id, 'sd_storefront_status', true));
                break;
            case 'stripe':
                $charges = (int) get_post_meta($post_id, 'sd_charges_enabled', true);
                $payouts = (int) get_post_meta($post_id, 'sd_payouts_enabled', true);
                echo esc_html(sprintf('charges:%d payouts:%d', $charges, $payouts));
                break;
            case 'health':
                echo esc_html((string) get_post_meta($post_id, 'sd_health_status', true));
                break;
            case 'last_activity':
                echo esc_html((string) get_post_meta($post_id, 'sd_last_activity_at_gmt', true));
                break;
            case 'updated':
                echo esc_html((string) get_post_meta($post_id, 'sd_updated_at_gmt', true));
                break;
        }
    }

    public static function prospect_sortable_columns(array $columns): array {
        $columns['updated'] = 'updated';
        $columns['lifecycle'] = 'lifecycle';
        return $columns;
    }

    public static function tenant_sortable_columns(array $columns): array {
        $columns['updated'] = 'updated';
        $columns['status'] = 'status';
        return $columns;
    }

    public static function admin_filters(string $post_type): void {
        if ($post_type === self::PROSPECT_POST_TYPE) {
            self::render_select_filter('sd_lifecycle_stage', 'Lifecycle', [
                'prospect' => 'Prospect',
                'invited_prospect' => 'Invited Prospect',
                'lead' => 'Lead',
            ]);
            self::render_select_filter('sd_review_status', 'Review', [
                'new' => 'New',
                'reviewed' => 'Reviewed',
                'qualified' => 'Qualified',
                'disqualified' => 'Disqualified',
                'hold' => 'Hold',
            ]);
            self::render_select_filter('sd_invitation_status', 'Invite', [
                'none' => 'None',
                'submitted' => 'Submitted',
                'valid' => 'Valid',
                'invalid' => 'Invalid',
                'manual_override' => 'Manual Override',
            ]);
            self::render_select_filter('sd_stripe_onboarding_status', 'Stripe', [
                'not_started' => 'Not Started',
                'started' => 'Started',
                'account_created' => 'Account Created',
                'requirements_due' => 'Requirements Due',
                'charges_enabled' => 'Charges Enabled',
                'failed' => 'Failed',
            ]);
        }

        if ($post_type === self::TENANT_POST_TYPE) {
            self::render_select_filter('sd_status', 'Status', [
                'inactive' => 'Inactive',
                'active' => 'Active',
            ]);
            self::render_select_filter('sd_storefront_status', 'Storefront', [
                'not_live' => 'Not Live',
                'live' => 'Live',
            ]);
            self::render_select_filter('sd_health_status', 'Health', [
                'healthy' => 'Healthy',
                'attention' => 'Attention',
                'critical' => 'Critical',
            ]);
        }
    }

    private static function render_select_filter(string $key, string $label, array $options): void {
        $current = isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        echo '<select name="' . esc_attr($key) . '">';
        echo '<option value="">' . esc_html($label) . '</option>';
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_admin_filters(WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $post_type = $query->get('post_type');
        if (!in_array($post_type, [self::PROSPECT_POST_TYPE, self::TENANT_POST_TYPE], true)) {
            return;
        }

        $meta_query = ['relation' => 'AND'];
        $filter_keys = [
            'sd_lifecycle_stage',
            'sd_review_status',
            'sd_invitation_status',
            'sd_stripe_onboarding_status',
            'sd_status',
            'sd_storefront_status',
            'sd_health_status',
        ];

        foreach ($filter_keys as $key) {
            if (!empty($_GET[$key])) {
                $meta_query[] = [
                    'key' => $key,
                    'value' => sanitize_text_field(wp_unslash($_GET[$key])),
                    'compare' => '=',
                ];
            }
        }

        if (count($meta_query) > 1) {
            $query->set('meta_query', $meta_query);
        }
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
        $payload['invitation'] = self::evaluate_invitation_code($payload['invitation_code']);

        error_log('SD Front Office: normalized payload = ' . wp_json_encode($payload));

        $prospect_post_id = self::find_existing_prospect($payload);
        error_log('SD Front Office: existing prospect id = ' . $prospect_post_id);

        if ($prospect_post_id > 0) {
        self::update_existing_prospect($prospect_post_id, $payload);
        error_log('SD Front Office: updated existing prospect');
        return;
        }

        $created_id = self::create_new_prospect($payload);
        error_log('SD Front Office: create_new_prospect returned = ' . $created_id);
    }

    public static function handle_start_submit(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_safe_redirect(home_url('/' . self::PAGE_SLUG_START . '/'));
            exit;
        }

        check_admin_referer('sdfo_start_submit', 'sdfo_nonce');

        $name          = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $email         = sanitize_email((string) ($_POST['email'] ?? ''));
        $mobile        = sanitize_text_field((string) ($_POST['mobile'] ?? ''));
        $business_name = sanitize_text_field((string) ($_POST['business_display_name'] ?? ''));
        $service_area  = sanitize_text_field((string) ($_POST['service_area'] ?? ''));

        $mobile_normalized = self::normalize_phone($mobile);
        $email_normalized  = strtolower(trim($email));

        if ($name === '' || $email_normalized === '' || $mobile_normalized === '' || $business_name === '') {
            $redirect = add_query_arg('error', 'missing_required', home_url('/' . self::PAGE_SLUG_START . '/'));
            wp_safe_redirect($redirect);
            exit;
        }

        $existing_post_id = self::find_existing_prospect([
            'email_normalized' => $email_normalized,
            'phone_normalized' => $mobile_normalized,
        ]);

        if ($existing_post_id > 0) {
            $prospect_post_id = $existing_post_id;
        } else {
            $prospect_post_id = wp_insert_post([
                'post_type'   => self::PROSPECT_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => sprintf('Prospect - %s - %s', $name, $mobile_normalized),
            ], true);

            if (is_wp_error($prospect_post_id) || !$prospect_post_id) {
                wp_die('Unable to create prospect record.');
            }

            update_post_meta($prospect_post_id, 'sd_prospect_id', 'prs_' . wp_generate_uuid4());
            update_post_meta($prospect_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        $public_key = (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true);
        if ($public_key === '') {
            $public_key = self::generate_public_key();
            update_post_meta($prospect_post_id, self::META_PUBLIC_KEY, $public_key);
        }

        update_post_meta($prospect_post_id, 'sd_full_name', $name);
        update_post_meta($prospect_post_id, 'sd_email_raw', $email);
        update_post_meta($prospect_post_id, 'sd_email_normalized', $email_normalized);
        update_post_meta($prospect_post_id, 'sd_phone_raw', $mobile);
        update_post_meta($prospect_post_id, 'sd_phone_normalized', $mobile_normalized);
        update_post_meta($prospect_post_id, self::META_BUSINESS_NAME, $business_name);
        update_post_meta($prospect_post_id, self::META_SERVICE_AREA, $service_area);
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'STARTED');
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $redirect = add_query_arg(
            'k',
            rawurlencode($public_key),
            home_url('/' . self::PAGE_SLUG_CONFIRM . '/')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    private static function is_request_access_form($contact_form, array $posted_data): bool {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return false;
        }

        return (int) $contact_form->id() === self::REQUEST_ACCESS_FORM_ID;
    }

    public static function register_shortcodes(): void {
        add_shortcode('sdfo_start_form', [__CLASS__, 'shortcode_start_form']);
        add_shortcode('sdfo_confirm_state', [__CLASS__, 'shortcode_confirm_state']);
        add_shortcode('sdfo_connect_payouts_state', [__CLASS__, 'shortcode_connect_payouts_state']);
        add_shortcode('sdfo_success_state', [__CLASS__, 'shortcode_success_state']);
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

    private static function get_public_key_from_request(): string {
        return sanitize_text_field((string) ($_GET['k'] ?? ''));
    }

    private static function get_prospect_post_id_by_public_key(string $public_key): int {
        if ($public_key === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type'      => self::PROSPECT_POST_TYPE,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'numberposts'    => 1,
            'meta_query'     => [[
                'key'     => self::META_PUBLIC_KEY,
                'value'   => $public_key,
                'compare' => '=',
            ]],
            'no_found_rows'  => true,
            'suppress_filters' => false,
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private static function require_prospect_post_id_from_request(): int {
        $public_key = self::get_public_key_from_request();
        $post_id = self::get_prospect_post_id_by_public_key($public_key);

        if ($post_id <= 0) {
            wp_safe_redirect(home_url('/' . self::PAGE_SLUG_START . '/'));
            exit;
        }

        return $post_id;
    }

    private static function normalize_payload(array $posted_data): array {
        $full_name = sanitize_text_field((string) ($posted_data['full_name'] ?? ''));
        $phone_raw = sanitize_text_field((string) ($posted_data['phone'] ?? ''));
        $email_raw = sanitize_email((string) ($posted_data['email'] ?? ''));
        $invite_code_input = sanitize_text_field((string) ($posted_data['invite_code'] ?? ''));
        $invitation_code = strtoupper(trim($invite_code_input));

        $payload = [
        'full_name' => $full_name,
        'phone_raw' => $phone_raw,
        'phone_normalized' => self::normalize_phone($phone_raw),
        'email_raw' => $email_raw,
        'email_normalized' => strtolower(trim($email_raw)),
        'invitation_code' => $invitation_code,
        'submitted_at_gmt' => current_time('mysql', true),
        'source' => 'request_access_form',
        'channel' => 'cf7_request_access',
        'payload_json' => wp_json_encode([
            'full_name' => $full_name,
            'phone' => $phone_raw,
            'email' => $email_raw,
            'invitation_code' => $invitation_code,
        ]),
        ];

        error_log('SD Front Office: normalized payload = ' . wp_json_encode($payload));

        return $payload;
    }

    private static function normalize_phone(string $phone): string {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private static function evaluate_invitation_code(string $code): array {
        if ($code === '') {
            return ['status' => 'none', 'priority_lane' => 0, 'invited_by' => ''];
        }

        // TODO: Replace with real invitation lookup.
        $valid_codes = ['SOLODRIVE', 'PARTNER', 'DRIVER'];
        if (in_array($code, $valid_codes, true)) {
            return ['status' => 'valid', 'priority_lane' => 1, 'invited_by' => 'system'];
        }

        return ['status' => 'submitted', 'priority_lane' => 0, 'invited_by' => ''];
    }

    private static function find_existing_prospect(array $payload): int {
        $email = $payload['email_normalized'];
        $phone = $payload['phone_normalized'];

        if ($email !== '') {
            $posts = get_posts([
                'post_type' => self::PROSPECT_POST_TYPE,
                'post_status' => 'publish',
                'fields' => 'ids',
                'numberposts' => 1,
                'meta_query' => [[
                    'key' => 'sd_email_normalized',
                    'value' => $email,
                    'compare' => '=',
                ]],
            ]);
            if (!empty($posts)) {
                return (int) $posts[0];
            }
        }

        if ($phone !== '') {
            $posts = get_posts([
                'post_type' => self::PROSPECT_POST_TYPE,
                'post_status' => 'publish',
                'fields' => 'ids',
                'numberposts' => 1,
                'meta_query' => [[
                    'key' => 'sd_phone_normalized',
                    'value' => $phone,
                    'compare' => '=',
                ]],
            ]);
            if (!empty($posts)) {
                return (int) $posts[0];
            }
        }

        return 0;
    }

    private static function update_existing_prospect(int $post_id, array $payload): void {
        $current_stage = (string) get_post_meta($post_id, 'sd_lifecycle_stage', true);
        $new_stage = self::resolve_lifecycle_stage($current_stage, $payload['invitation']['status']);
        $submission_count = (int) get_post_meta($post_id, 'sd_submission_count', true);
        $existing_invite = (string) get_post_meta($post_id, 'sd_invitation_status', true);
        $invite_status = self::merge_invitation_status($existing_invite, $payload['invitation']['status']);

        update_post_meta($post_id, 'sd_lifecycle_stage', $new_stage);
        update_post_meta($post_id, 'sd_updated_at_gmt', $payload['submitted_at_gmt']);
        update_post_meta($post_id, 'sd_full_name', $payload['full_name']);
        update_post_meta($post_id, 'sd_phone_raw', $payload['phone_raw']);
        update_post_meta($post_id, 'sd_phone_normalized', $payload['phone_normalized']);
        update_post_meta($post_id, 'sd_email_raw', $payload['email_raw']);
        update_post_meta($post_id, 'sd_email_normalized', $payload['email_normalized']);
        update_post_meta($post_id, 'sd_invitation_code', $payload['invitation_code']);
        update_post_meta($post_id, 'sd_invitation_status', $invite_status);
        update_post_meta($post_id, 'sd_priority_lane', $payload['invitation']['priority_lane']);
        update_post_meta($post_id, 'sd_invited_by', $payload['invitation']['invited_by']);
        update_post_meta($post_id, 'sd_dedupe_key_email', $payload['email_normalized']);
        update_post_meta($post_id, 'sd_dedupe_key_phone', $payload['phone_normalized']);
        update_post_meta($post_id, 'sd_last_intake_channel', $payload['channel']);
        update_post_meta($post_id, 'sd_last_submission_at_gmt', $payload['submitted_at_gmt']);
        update_post_meta($post_id, 'sd_submission_count', $submission_count + 1);
        update_post_meta($post_id, 'sd_last_submission_payload_json', $payload['payload_json']);

        wp_update_post([
            'ID' => $post_id,
            'post_title' => self::build_prospect_title($payload),
        ]);
    }

    private static function create_new_prospect(array $payload): int {
        $is_invited = $payload['invitation']['status'] === 'valid';
        $lifecycle = $is_invited ? 'invited_prospect' : 'prospect';
        $now = $payload['submitted_at_gmt'];

        error_log('SD Front Office: creating prospect');

        $post_id = wp_insert_post([
            'post_type' => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => self::build_prospect_title($payload),
        ], true);

        if (is_wp_error($post_id)) {
            error_log('SD Front Office: insert error = ' . $post_id->get_error_message());
            return 0;
        }

        if (!$post_id) {
            error_log('SD Front Office: insert returned empty post id');
            return 0;
        }

        error_log('SD Front Office: created prospect post id = ' . $post_id);

        $prospect_id = 'prs_' . wp_generate_uuid4();

        update_post_meta($post_id, 'sd_prospect_id', $prospect_id);
        update_post_meta($post_id, 'sd_lifecycle_stage', $lifecycle);
        update_post_meta($post_id, 'sd_source', $payload['source']);
        update_post_meta($post_id, 'sd_created_at_gmt', $now);
        update_post_meta($post_id, 'sd_updated_at_gmt', $now);
        update_post_meta($post_id, 'sd_full_name', $payload['full_name']);
        update_post_meta($post_id, 'sd_phone_raw', $payload['phone_raw']);
        update_post_meta($post_id, 'sd_phone_normalized', $payload['phone_normalized']);
        update_post_meta($post_id, 'sd_email_raw', $payload['email_raw']);
        update_post_meta($post_id, 'sd_email_normalized', $payload['email_normalized']);
        update_post_meta($post_id, 'sd_invitation_code', $payload['invitation_code']);
        update_post_meta($post_id, 'sd_invitation_status', $payload['invitation']['status']);
        update_post_meta($post_id, 'sd_invited_by', $payload['invitation']['invited_by']);
        update_post_meta($post_id, 'sd_priority_lane', $payload['invitation']['priority_lane']);
        update_post_meta($post_id, 'sd_review_status', 'new');
        update_post_meta($post_id, 'sd_stripe_onboarding_status', 'not_started');
        update_post_meta($post_id, 'sd_dedupe_key_email', $payload['email_normalized']);
        update_post_meta($post_id, 'sd_dedupe_key_phone', $payload['phone_normalized']);
        update_post_meta($post_id, 'sd_last_intake_channel', $payload['channel']);
        update_post_meta($post_id, 'sd_last_submission_at_gmt', $now);
        update_post_meta($post_id, 'sd_submission_count', 1);
        update_post_meta($post_id, 'sd_last_submission_payload_json', $payload['payload_json']);

        return (int) $post_id;
    }

    private static function get_activation_state(int $prospect_post_id): string {
        $state = (string) get_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, true);
        return $state !== '' ? $state : 'STARTED';
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

    private static function is_success_ready(int $prospect_post_id): bool {
        $state = self::get_activation_state($prospect_post_id);
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);

        return $state === 'ACTIVATION_COMPLETE' && $storefront_url !== '';
    }

    private static function resolve_lifecycle_stage(string $current_stage, string $invite_status): string {
        if ($current_stage === 'lead') {
            return 'lead';
        }
        if ($current_stage === 'invited_prospect' || $invite_status === 'valid') {
            return 'invited_prospect';
        }
        return 'prospect';
    }

    private static function merge_invitation_status(string $existing, string $incoming): string {
        $protected = ['valid', 'manual_override'];
        if (in_array($existing, $protected, true)) {
            return $existing;
        }
        return $incoming ?: $existing;
    }

    private static function build_prospect_title(array $payload): string {
        if ($payload['full_name'] !== '' && $payload['phone_normalized'] !== '') {
            return sprintf('Prospect - %s - %s', $payload['full_name'], $payload['phone_normalized']);
        }
        if ($payload['email_normalized'] !== '') {
            return sprintf('Prospect - %s', $payload['email_normalized']);
        }
        return 'Prospect';
    }

    public static function inject_cf7_redirect(array $response, $contact_form): array {
        if (!class_exists('WPCF7_Submission') || !method_exists('WPCF7_Submission', 'get_instance')) {
        return $response;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
        return $response;
        }

        $posted_data = $submission->get_posted_data();
        if (!is_array($posted_data)) {
            return $response;
        }

        $submission_form_id = isset($posted_data['_wpcf7']) ? (int) $posted_data['_wpcf7'] : 0;
        if ($submission_form_id !== self::REQUEST_ACCESS_FORM_ID) {
            return $response;
        }

        $redirect_url = home_url('/' . self::SUCCESS_PAGE_SLUG . '/');
        $response['sd_redirect_url'] = esc_url_raw($redirect_url);
        return $response;
    }



    public static function register_rest_routes(): void {
        register_rest_route(self::REST_NAMESPACE, '/onboarding/start', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_start_onboarding'],
            'permission_callback' => '__return_true',
            'args' => [
                'prospect_id' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => sanitize_text_field((string) $value),
                ],
                'prospect_post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => static fn($value) => absint($value),
                ],
                'return_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => esc_url_raw((string) $value),
                ],
                'refresh_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => esc_url_raw((string) $value),
                ],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/billing/checkout/start', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_start_billing_checkout'],
            'permission_callback' => '__return_true',
            'args' => [
                'prospect_id' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => sanitize_text_field((string) $value),
                ],
                'prospect_post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => static fn($value) => absint($value),
                ],
                'success_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => esc_url_raw((string) $value),
                ],
                'cancel_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => esc_url_raw((string) $value),
                ],
                'price_id' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => sanitize_text_field((string) $value),
                ],
                'trial_period_days' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => static fn($value) => max(0, absint($value)),
                ],
                'coupon' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => static fn($value) => sanitize_text_field((string) $value),
                ],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/stripe/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_stripe_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_start_onboarding(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_request($request);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'Prospect not found.', ['status' => 404]);
        }

        $eligibility = self::validate_prospect_for_onboarding($prospect_post_id);
        if (is_wp_error($eligibility)) {
            return $eligibility;
        }

        $secret_key = self::get_stripe_secret_key();
        if ($secret_key === '') {
            return new WP_Error('sd_stripe_not_configured', 'Stripe control-plane secret key is not configured.', ['status' => 500]);
        }

        $return_url = self::resolve_onboarding_return_url($request);
        $refresh_url = self::resolve_onboarding_refresh_url($request);

        $account_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_account_id', true);
        $created_new_account = false;

        if ($account_id === '') {
            $account = self::stripe_create_connected_account($prospect_post_id, $secret_key);
            if (is_wp_error($account)) {
                self::mark_prospect_stripe_failure($prospect_post_id, $account);
                return $account;
            }

            $account_id = (string) ($account['id'] ?? '');
            if ($account_id === '') {
                $error = new WP_Error('sd_stripe_account_missing', 'Stripe account ID was not returned.', ['status' => 502]);
                self::mark_prospect_stripe_failure($prospect_post_id, $error);
                return $error;
            }

            $created_new_account = true;
            update_post_meta($prospect_post_id, 'sd_stripe_account_id', $account_id);
            update_post_meta($prospect_post_id, 'sd_stripe_onboarding_started_at_gmt', current_time('mysql', true));
            update_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', 'account_created');
        }

        $account_link = self::stripe_create_account_link($account_id, $return_url, $refresh_url, $secret_key);
        if (is_wp_error($account_link)) {
            self::mark_prospect_stripe_failure($prospect_post_id, $account_link);
            return $account_link;
        }

        $snapshot = [
            'account_id' => $account_id,
            'account_link_url' => (string) ($account_link['url'] ?? ''),
            'account_link_expires_at' => isset($account_link['expires_at']) ? (int) $account_link['expires_at'] : null,
            'return_url' => $return_url,
            'refresh_url' => $refresh_url,
            'created_new_account' => $created_new_account,
        ];

        update_post_meta($prospect_post_id, 'sd_lifecycle_stage', 'lead');
        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', 'started');
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_last_staff_action_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', wp_json_encode([
            'event' => 'stripe_onboarding_started',
            'snapshot' => $snapshot,
        ]));

        return new WP_REST_Response([
            'ok' => true,
            'prospect_post_id' => $prospect_post_id,
            'prospect_id' => (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true),
            'lifecycle_stage' => 'lead',
            'stripe_account_id' => $account_id,
            'stripe_onboarding_status' => 'started',
            'onboarding_url' => (string) ($account_link['url'] ?? ''),
            'onboarding_expires_at' => isset($account_link['expires_at']) ? (int) $account_link['expires_at'] : null,
            'created_new_account' => $created_new_account,
        ], 200);
    }


    public static function rest_start_billing_checkout(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_request($request);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'Prospect not found.', ['status' => 404]);
        }

        $eligibility = self::validate_prospect_for_billing_checkout($prospect_post_id);
        if (is_wp_error($eligibility)) {
            return $eligibility;
        }

        $secret_key = self::get_stripe_secret_key();
        if ($secret_key === '') {
            return new WP_Error('sd_stripe_not_configured', 'Stripe control-plane secret key is not configured.', ['status' => 500]);
        }

        $price_id = self::resolve_billing_price_id($request);
        if ($price_id === '') {
            return new WP_Error('sd_missing_billing_price', 'Stripe billing price ID is not configured.', ['status' => 500]);
        }

        $success_url = self::resolve_checkout_success_url($request);
        $cancel_url = self::resolve_checkout_cancel_url($request);
        $customer_email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $existing_customer_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_customer_id', true);
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $trial_period_days = max(0, (int) $request->get_param('trial_period_days'));
        $coupon = sanitize_text_field((string) $request->get_param('coupon'));

        $session = self::stripe_create_billing_checkout_session($prospect_post_id, [
            'price_id' => $price_id,
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'customer_email' => $customer_email,
            'customer_id' => $existing_customer_id,
            'trial_period_days' => $trial_period_days,
            'coupon' => $coupon,
            'metadata' => [
                'sd_prospect_id' => $prospect_id,
                'sd_prospect_post_id' => (string) $prospect_post_id,
            ],
        ], $secret_key);

        if (is_wp_error($session)) {
            update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
            update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', wp_json_encode([
                'event' => 'stripe_checkout_session_failed',
                'code' => $session->get_error_code(),
                'message' => $session->get_error_message(),
                'data' => $session->get_error_data(),
            ]));
            return $session;
        }

        $session_id = sanitize_text_field((string) ($session['id'] ?? ''));
        $session_url = esc_url_raw((string) ($session['url'] ?? ''));
        $customer_id = sanitize_text_field((string) ($session['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($session['subscription'] ?? ''));

        if ($session_id === '' || $session_url === '') {
            return new WP_Error('sd_stripe_checkout_missing_fields', 'Stripe checkout session did not return the expected fields.', ['status' => 502]);
        }

        update_post_meta($prospect_post_id, 'sd_billing_status', 'checkout_open');
        update_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', $session_id);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_last_staff_action_at_gmt', current_time('mysql', true));

        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }

        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }

        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', wp_json_encode([
            'event' => 'stripe_checkout_session_started',
            'session_id' => $session_id,
            'session_mode' => (string) ($session['mode'] ?? ''),
            'session_status' => (string) ($session['status'] ?? ''),
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
            'price_id' => $price_id,
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
        ]));

        return new WP_REST_Response([
            'ok' => true,
            'prospect_post_id' => $prospect_post_id,
            'prospect_id' => $prospect_id,
            'billing_status' => 'checkout_open',
            'stripe_checkout_session_id' => $session_id,
            'stripe_customer_id' => $customer_id,
            'stripe_subscription_id' => $subscription_id,
            'checkout_url' => $session_url,
        ], 200);
    }

    public static function rest_stripe_webhook(WP_REST_Request $request): WP_REST_Response {
        $payload = file_get_contents('php://input');
        if (!is_string($payload) || $payload === '') {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Empty webhook payload.',
            ], 400);
        }

        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) wp_unslash($_SERVER['HTTP_STRIPE_SIGNATURE']) : '';
        $webhook_secret = self::get_stripe_webhook_secret();
        if ($webhook_secret === '') {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Stripe webhook secret is not configured.',
            ], 500);
        }

        $verification = self::verify_stripe_webhook_signature($payload, $signature, $webhook_secret);
        if (is_wp_error($verification)) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => $verification->get_error_message(),
            ], (int) ($verification->get_error_data()['status'] ?? 400));
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Invalid Stripe webhook payload.',
            ], 400);
        }

        $event_id = sanitize_text_field((string) ($event['id'] ?? ''));
        $event_type = sanitize_text_field((string) ($event['type'] ?? ''));
        if ($event_id === '' || $event_type === '') {
            return new WP_REST_Response([
                'ok' => false,
                'message' => 'Missing Stripe event identifiers.',
            ], 400);
        }

        if (self::is_stripe_event_already_processed($event_id)) {
            return new WP_REST_Response([
                'ok' => true,
                'duplicate' => true,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ], 200);
        }

        $result = self::handle_stripe_webhook_event($event);
        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'ok' => false,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'message' => $result->get_error_message(),
            ], (int) ($result->get_error_data()['status'] ?? 422));
        }

        self::mark_stripe_event_processed($event_id);

        return new WP_REST_Response([
            'ok' => true,
            'event_id' => $event_id,
            'event_type' => $event_type,
            'handled' => true,
            'result' => $result,
        ], 200);
    }

    private static function handle_stripe_webhook_event(array $event): array|WP_Error {
        $event_id = sanitize_text_field((string) ($event['id'] ?? ''));
        $event_type = sanitize_text_field((string) ($event['type'] ?? ''));
        $object = $event['data']['object'] ?? null;

        if (!is_array($object)) {
            return new WP_Error('sd_invalid_stripe_event', 'Stripe event object missing.', ['status' => 400]);
        }

        return match ($event_type) {
            'account.updated' => self::handle_stripe_account_updated($event_id, $object),
            'checkout.session.completed' => self::handle_stripe_checkout_completed($event_id, $object),
            'invoice.paid' => self::handle_stripe_invoice_paid($event_id, $object),
            'invoice.payment_failed' => self::handle_stripe_invoice_payment_failed($event_id, $object),
            'customer.subscription.updated' => self::handle_stripe_subscription_updated($event_id, $object),
            'customer.subscription.deleted' => self::handle_stripe_subscription_deleted($event_id, $object),
            default => [
                'ignored' => true,
                'reason' => 'Unhandled event type.',
            ],
        };
    }

    private static function handle_stripe_account_updated(string $event_id, array $account): array|WP_Error {
        $account_id = sanitize_text_field((string) ($account['id'] ?? ''));
        if ($account_id === '') {
            return new WP_Error('sd_missing_account_id', 'Stripe account.updated event missing account ID.', ['status' => 400]);
        }

        $prospect_post_id = self::find_prospect_by_meta('sd_stripe_account_id', $account_id);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for connected account.', ['status' => 404]);
        }

        $charges_enabled = !empty($account['charges_enabled']);
        $payouts_enabled = !empty($account['payouts_enabled']);
        $requirements_currently_due = is_array($account['requirements']['currently_due'] ?? null) ? $account['requirements']['currently_due'] : [];
        $requirements_eventually_due = is_array($account['requirements']['eventually_due'] ?? null) ? $account['requirements']['eventually_due'] : [];
        $details_submitted = !empty($account['details_submitted']);

        $status = 'requirements_due';
        if ($charges_enabled) {
            $status = 'charges_enabled';
        } elseif ($details_submitted && empty($requirements_currently_due)) {
            $status = 'account_ready';
        } elseif ($details_submitted) {
            $status = 'started';
        }

        $snapshot = [
            'event_id' => $event_id,
            'account_id' => $account_id,
            'charges_enabled' => $charges_enabled,
            'payouts_enabled' => $payouts_enabled,
            'details_submitted' => $details_submitted,
            'requirements_currently_due' => array_values($requirements_currently_due),
            'requirements_eventually_due' => array_values($requirements_eventually_due),
            'disabled_reason' => (string) ($account['requirements']['disabled_reason'] ?? ''),
            'capabilities' => is_array($account['capabilities'] ?? null) ? $account['capabilities'] : new stdClass(),
            'raw_updated_at_gmt' => current_time('mysql', true),
        ];

        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', $status);
        update_post_meta($prospect_post_id, 'sd_stripe_status_snapshot_json', wp_json_encode($snapshot));
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_connected_account_id', $account_id);
            update_post_meta($tenant_post_id, 'sd_charges_enabled', $charges_enabled ? 1 : 0);
            update_post_meta($tenant_post_id, 'sd_payouts_enabled', $payouts_enabled ? 1 : 0);
            update_post_meta($tenant_post_id, 'sd_stripe_status_snapshot_json', wp_json_encode($snapshot));
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        $promotion = self::maybe_promote_prospect_to_inactive_tenant($prospect_post_id);

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'stripe_account_id' => $account_id,
            'stripe_onboarding_status' => $status,
            'charges_enabled' => $charges_enabled,
            'payouts_enabled' => $payouts_enabled,
            'promotion' => $promotion,
        ];
    }

    private static function handle_stripe_checkout_completed(string $event_id, array $session): array|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_stripe_object($session);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for checkout.session.completed.', ['status' => 404]);
        }

        $session_id = sanitize_text_field((string) ($session['id'] ?? ''));
        $customer_id = sanitize_text_field((string) ($session['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($session['subscription'] ?? ''));

        update_post_meta($prospect_post_id, 'sd_billing_status', 'checkout_completed');
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        update_post_meta($prospect_post_id, 'sd_stripe_checkout_session_id', $session_id);
        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }
        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_billing_status', 'checkout_completed');
            if ($customer_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_customer_id', $customer_id);
            }
            if ($subscription_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', $subscription_id);
            }
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'billing_status' => 'checkout_completed',
            'checkout_session_id' => $session_id,
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
        ];
    }

    private static function handle_stripe_invoice_paid(string $event_id, array $invoice): array|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_stripe_object($invoice);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for invoice.paid.', ['status' => 404]);
        }

        $customer_id = sanitize_text_field((string) ($invoice['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($invoice['subscription'] ?? ''));
        $invoice_id = sanitize_text_field((string) ($invoice['id'] ?? ''));

        update_post_meta($prospect_post_id, 'sd_billing_status', 'paid');
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        update_post_meta($prospect_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }
        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', wp_json_encode([
            'event' => 'invoice_paid',
            'event_id' => $event_id,
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
        ]));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_billing_status', 'paid');
            update_post_meta($tenant_post_id, 'sd_subscription_paid_at_gmt', current_time('mysql', true));
            if ($customer_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_customer_id', $customer_id);
            }
            if ($subscription_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', $subscription_id);
            }
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        $promotion = self::maybe_promote_prospect_to_inactive_tenant($prospect_post_id);

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'billing_status' => 'paid',
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
            'promotion' => $promotion,
        ];
    }

    private static function handle_stripe_invoice_payment_failed(string $event_id, array $invoice): array|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_stripe_object($invoice);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for invoice.payment_failed.', ['status' => 404]);
        }

        $customer_id = sanitize_text_field((string) ($invoice['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($invoice['subscription'] ?? ''));
        $invoice_id = sanitize_text_field((string) ($invoice['id'] ?? ''));

        update_post_meta($prospect_post_id, 'sd_billing_status', 'failed');
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_billing_status', 'failed');
            update_post_meta($tenant_post_id, 'sd_payment_flag', 1);
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'billing_status' => 'failed',
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
        ];
    }

    private static function handle_stripe_subscription_updated(string $event_id, array $subscription): array|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_stripe_object($subscription);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for customer.subscription.updated.', ['status' => 404]);
        }

        $status = sanitize_text_field((string) ($subscription['status'] ?? ''));
        $customer_id = sanitize_text_field((string) ($subscription['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($subscription['id'] ?? ''));

        update_post_meta($prospect_post_id, 'sd_billing_status', $status !== '' ? $status : 'updated');
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }
        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_billing_status', $status !== '' ? $status : 'updated');
            if ($customer_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_customer_id', $customer_id);
            }
            if ($subscription_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', $subscription_id);
            }
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'billing_status' => $status !== '' ? $status : 'updated',
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
        ];
    }

    private static function handle_stripe_subscription_deleted(string $event_id, array $subscription): array|WP_Error {
        $prospect_post_id = self::resolve_prospect_post_id_from_stripe_object($subscription);
        if ($prospect_post_id <= 0) {
            return new WP_Error('sd_prospect_not_found', 'No prospect found for customer.subscription.deleted.', ['status' => 404]);
        }

        $customer_id = sanitize_text_field((string) ($subscription['customer'] ?? ''));
        $subscription_id = sanitize_text_field((string) ($subscription['id'] ?? ''));

        update_post_meta($prospect_post_id, 'sd_billing_status', 'canceled');
        update_post_meta($prospect_post_id, 'sd_stripe_last_event_id', $event_id);
        if ($customer_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_customer_id', $customer_id);
        }
        if ($subscription_id !== '') {
            update_post_meta($prospect_post_id, 'sd_stripe_subscription_id', $subscription_id);
        }
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));

        $tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($tenant_post_id > 0) {
            update_post_meta($tenant_post_id, 'sd_billing_status', 'canceled');
            update_post_meta($tenant_post_id, 'sd_payment_flag', 1);
            if ($customer_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_customer_id', $customer_id);
            }
            if ($subscription_id !== '') {
                update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', $subscription_id);
            }
            update_post_meta($tenant_post_id, 'sd_stripe_last_event_id', $event_id);
            update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        }

        return [
            'prospect_post_id' => $prospect_post_id,
            'tenant_post_id' => $tenant_post_id,
            'billing_status' => 'canceled',
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
        ];
    }

    private static function resolve_prospect_post_id_from_stripe_object(array $object): int {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $prospect_post_id = isset($metadata['sd_prospect_post_id']) ? absint($metadata['sd_prospect_post_id']) : 0;
        if ($prospect_post_id > 0) {
            $post = get_post($prospect_post_id);
            if ($post instanceof WP_Post && $post->post_type === self::PROSPECT_POST_TYPE) {
                return $prospect_post_id;
            }
        }

        $prospect_id = sanitize_text_field((string) ($metadata['sd_prospect_id'] ?? ''));
        if ($prospect_id !== '') {
            $resolved_by_prospect_id = self::find_prospect_by_meta('sd_prospect_id', $prospect_id);
            if ($resolved_by_prospect_id > 0) {
                return $resolved_by_prospect_id;
            }
        }

        foreach (['customer', 'subscription'] as $meta_key) {
            $value = sanitize_text_field((string) ($object[$meta_key] ?? ''));
            if ($value !== '') {
                $key = $meta_key === 'customer' ? 'sd_stripe_customer_id' : 'sd_stripe_subscription_id';
                $resolved = self::find_prospect_by_meta($key, $value);
                if ($resolved > 0) {
                    return $resolved;
                }
            }
        }

        if (!empty($object['lines']['data']) && is_array($object['lines']['data'])) {
            foreach ($object['lines']['data'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $line_metadata = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
                $line_prospect_id = sanitize_text_field((string) ($line_metadata['sd_prospect_id'] ?? ''));
                if ($line_prospect_id !== '') {
                    $resolved = self::find_prospect_by_meta('sd_prospect_id', $line_prospect_id);
                    if ($resolved > 0) {
                        return $resolved;
                    }
                }
            }
        }

        return 0;
    }

    private static function maybe_promote_prospect_to_inactive_tenant(int $prospect_post_id): array {
        $existing_tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($existing_tenant_post_id > 0) {
            return [
                'action' => 'noop',
                'reason' => 'already_promoted',
                'tenant_post_id' => $existing_tenant_post_id,
            ];
        }

        $account_status = (string) get_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', true);
        $billing_status = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);

        if (!in_array($account_status, ['charges_enabled', 'account_ready'], true)) {
            return [
                'action' => 'noop',
                'reason' => 'account_not_ready',
                'stripe_onboarding_status' => $account_status,
                'billing_status' => $billing_status,
            ];
        }

        if ($billing_status !== 'paid') {
            return [
                'action' => 'noop',
                'reason' => 'billing_not_paid',
                'stripe_onboarding_status' => $account_status,
                'billing_status' => $billing_status,
            ];
        }

        $tenant_post_id = self::create_inactive_tenant_from_prospect($prospect_post_id);
        if ($tenant_post_id <= 0) {
            return [
                'action' => 'error',
                'reason' => 'tenant_creation_failed',
            ];
        }

        return [
            'action' => 'created_inactive_tenant',
            'tenant_post_id' => $tenant_post_id,
        ];
    }

    private static function create_inactive_tenant_from_prospect(int $prospect_post_id): int {
        $prospect_post = get_post($prospect_post_id);
        if (!$prospect_post instanceof WP_Post || $prospect_post->post_type !== self::PROSPECT_POST_TYPE) {
            return 0;
        }

        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);
        $existing_tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        if ($existing_tenant_post_id > 0) {
            return $existing_tenant_post_id;
        }

        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $title = $full_name !== '' ? $full_name : ($email !== '' ? $email : 'Tenant ' . $prospect_id);

        $tenant_post_id = wp_insert_post([
            'post_type' => self::TENANT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);

        if (is_wp_error($tenant_post_id) || $tenant_post_id <= 0) {
            return 0;
        }

        $tenant_id = 'ten_' . wp_generate_uuid4();
        $slug_seed = $full_name !== '' ? $full_name : ($email !== '' ? sanitize_email($email) : $prospect_id);
        $slug = self::generate_unique_tenant_slug($slug_seed, $tenant_post_id);

        update_post_meta($tenant_post_id, 'sd_tenant_id', $tenant_id);
        update_post_meta($tenant_post_id, 'sd_slug', $slug);
        update_post_meta($tenant_post_id, 'sd_status', 'inactive');
        update_post_meta($tenant_post_id, 'sd_created_at_gmt', current_time('mysql', true));
        update_post_meta($tenant_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($tenant_post_id, 'sd_origin_prospect_id', $prospect_id);
        update_post_meta($tenant_post_id, 'sd_origin_prospect_post_id', $prospect_post_id);
        update_post_meta($tenant_post_id, 'sd_connected_account_id', (string) get_post_meta($prospect_post_id, 'sd_stripe_account_id', true));
        update_post_meta($tenant_post_id, 'sd_stripe_customer_id', (string) get_post_meta($prospect_post_id, 'sd_stripe_customer_id', true));
        update_post_meta($tenant_post_id, 'sd_stripe_subscription_id', (string) get_post_meta($prospect_post_id, 'sd_stripe_subscription_id', true));
        update_post_meta($tenant_post_id, 'sd_billing_status', (string) get_post_meta($prospect_post_id, 'sd_billing_status', true));
        update_post_meta($tenant_post_id, 'sd_subscription_paid_at_gmt', (string) get_post_meta($prospect_post_id, 'sd_subscription_paid_at_gmt', true));
        update_post_meta($tenant_post_id, 'sd_stripe_status_snapshot_json', (string) get_post_meta($prospect_post_id, 'sd_stripe_status_snapshot_json', true));
        update_post_meta($tenant_post_id, 'sd_charges_enabled', ((string) get_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', true) === 'charges_enabled') ? 1 : 0);
        update_post_meta($tenant_post_id, 'sd_payouts_enabled', 0);
        update_post_meta($tenant_post_id, 'sd_storefront_status', 'not_started');
        update_post_meta($tenant_post_id, 'sd_activation_ready', 0);
        update_post_meta($tenant_post_id, 'sd_provisioning_status', 'queued');
        update_post_meta($tenant_post_id, 'sd_last_provisioning_payload_json', wp_json_encode(self::build_provisioning_payload($tenant_post_id, $prospect_post_id)));

        update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_id', $tenant_id);
        update_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', $tenant_post_id);
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_last_staff_action_at_gmt', current_time('mysql', true));

        do_action('sd_control_plane_tenant_provisioning_requested', $tenant_post_id, $prospect_post_id, self::build_provisioning_payload($tenant_post_id, $prospect_post_id));

        return (int) $tenant_post_id;
    }

    private static function build_provisioning_payload(int $tenant_post_id, int $prospect_post_id): array {
        return [
            'tenant_post_id' => $tenant_post_id,
            'tenant_id' => (string) get_post_meta($tenant_post_id, 'sd_tenant_id', true),
            'tenant_slug' => (string) get_post_meta($tenant_post_id, 'sd_slug', true),
            'prospect_post_id' => $prospect_post_id,
            'prospect_id' => (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true),
            'full_name' => (string) get_post_meta($prospect_post_id, 'sd_full_name', true),
            'email' => (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true),
            'phone' => (string) get_post_meta($prospect_post_id, 'sd_phone_normalized', true),
            'stripe_account_id' => (string) get_post_meta($prospect_post_id, 'sd_stripe_account_id', true),
            'stripe_customer_id' => (string) get_post_meta($prospect_post_id, 'sd_stripe_customer_id', true),
            'stripe_subscription_id' => (string) get_post_meta($prospect_post_id, 'sd_stripe_subscription_id', true),
            'billing_status' => (string) get_post_meta($prospect_post_id, 'sd_billing_status', true),
            'activation_mode' => 'inactive_until_provisioned',
        ];
    }

    private static function generate_unique_tenant_slug(string $seed, int $tenant_post_id = 0): string {
        $base = sanitize_title($seed);
        if ($base === '') {
            $base = 'tenant';
        }

        $slug = $base;
        $suffix = 2;

        while (self::tenant_slug_exists($slug, $tenant_post_id)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private static function tenant_slug_exists(string $slug, int $exclude_post_id = 0): bool {
        $posts = get_posts([
            'post_type' => self::TENANT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [[
                'key' => 'sd_slug',
                'value' => $slug,
                'compare' => '=',
            ]],
            'exclude' => $exclude_post_id > 0 ? [$exclude_post_id] : [],
        ]);

        return !empty($posts);
    }

    private static function find_prospect_by_meta(string $meta_key, string $meta_value): int {
        if ($meta_value === '') {
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
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private static function get_stripe_webhook_secret(): string {
        $constant_value = defined('SD_STRIPE_CONTROL_PLANE_WEBHOOK_SECRET') ? (string) SD_STRIPE_CONTROL_PLANE_WEBHOOK_SECRET : '';
        if ($constant_value !== '') {
            return $constant_value;
        }

        $option_value = (string) get_option('sd_stripe_control_plane_webhook_secret', '');
        if ($option_value !== '') {
            return $option_value;
        }

        $env_value = getenv('SD_STRIPE_CONTROL_PLANE_WEBHOOK_SECRET');
        return is_string($env_value) ? trim($env_value) : '';
    }

    private static function verify_stripe_webhook_signature(string $payload, string $signature_header, string $webhook_secret): true|WP_Error {
        if ($signature_header === '') {
            return new WP_Error('sd_missing_stripe_signature', 'Missing Stripe-Signature header.', ['status' => 400]);
        }

        $parts = [];
        foreach (explode(',', $signature_header) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $segment, 2));
            if ($key !== '' && $value !== '') {
                $parts[$key][] = $value;
            }
        }

        $timestamp = isset($parts['t'][0]) ? (string) $parts['t'][0] : '';
        $signatures = isset($parts['v1']) && is_array($parts['v1']) ? $parts['v1'] : [];

        if ($timestamp === '' || empty($signatures)) {
            return new WP_Error('sd_invalid_stripe_signature', 'Invalid Stripe signature header.', ['status' => 400]);
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('sd_expired_stripe_signature', 'Stripe signature timestamp is outside the tolerance window.', ['status' => 400]);
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $webhook_secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return new WP_Error('sd_signature_verification_failed', 'Stripe webhook signature verification failed.', ['status' => 400]);
    }

    private static function is_stripe_event_already_processed(string $event_id): bool {
        return (bool) get_transient('sd_stripe_webhook_event_' . $event_id);
    }

    private static function mark_stripe_event_processed(string $event_id): void {
        set_transient('sd_stripe_webhook_event_' . $event_id, 1, WEEK_IN_SECONDS);
    }

    private static function resolve_prospect_post_id_from_request(WP_REST_Request $request): int {
        $prospect_post_id = absint($request->get_param('prospect_post_id'));
        if ($prospect_post_id > 0) {
            $post = get_post($prospect_post_id);
            if ($post instanceof WP_Post && $post->post_type === self::PROSPECT_POST_TYPE) {
                return $prospect_post_id;
            }
        }

        $prospect_id = sanitize_text_field((string) $request->get_param('prospect_id'));
        if ($prospect_id === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type' => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [[
                'key' => 'sd_prospect_id',
                'value' => $prospect_id,
                'compare' => '=',
            ]],
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private static function validate_prospect_for_onboarding(int $prospect_post_id): true|WP_Error {
        $post = get_post($prospect_post_id);
        if (!$post instanceof WP_Post || $post->post_type !== self::PROSPECT_POST_TYPE) {
            return new WP_Error('sd_prospect_not_found', 'Prospect not found.', ['status' => 404]);
        }

        $invite_status = (string) get_post_meta($prospect_post_id, 'sd_invitation_status', true);
        $lifecycle_stage = (string) get_post_meta($prospect_post_id, 'sd_lifecycle_stage', true);
        $promoted_tenant_post_id = (int) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_post_id', true);
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);

        if (!in_array($invite_status, ['valid', 'manual_override'], true)) {
            return new WP_Error('sd_invitation_required', 'A valid invitation is required before Stripe onboarding can start.', ['status' => 403]);
        }

        if ($promoted_tenant_post_id > 0) {
            return new WP_Error('sd_prospect_already_promoted', 'This prospect has already been promoted to a tenant.', ['status' => 409]);
        }

        if ($email === '' || !is_email($email)) {
            return new WP_Error('sd_email_required', 'A valid email is required before Stripe onboarding can start.', ['status' => 422]);
        }

        if ($lifecycle_stage !== '' && !in_array($lifecycle_stage, ['prospect', 'invited_prospect', 'lead'], true)) {
            return new WP_Error('sd_invalid_lifecycle', 'This prospect is not in a startable onboarding stage.', ['status' => 409]);
        }

        return true;
    }


    private static function validate_prospect_for_billing_checkout(int $prospect_post_id): true|WP_Error {
        $invite_status = (string) get_post_meta($prospect_post_id, 'sd_invitation_status', true);
        if (!in_array($invite_status, ['valid', 'manual_override'], true)) {
            return new WP_Error('sd_invite_not_valid', 'Prospect invitation is not valid for billing checkout.', ['status' => 409]);
        }

        $account_status = (string) get_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', true);
        $allowed_account_statuses = [
            'started',
            'charges_enabled',
            'account_ready',
            'details_submitted',
            'under_review',
            'pending_verification',
        ];

        if (!in_array($account_status, $allowed_account_statuses, true)) {
            return new WP_Error('sd_onboarding_not_started', 'Stripe onboarding must be started before billing checkout.', ['status' => 409]);
        }

        $billing_status = (string) get_post_meta($prospect_post_id, 'sd_billing_status', true);
        if (in_array($billing_status, ['paid', 'active'], true)) {
            return new WP_Error('sd_billing_already_paid', 'This prospect already has an active paid billing state.', ['status' => 409]);
        }

        if ((string) get_post_meta($prospect_post_id, 'sd_promoted_to_tenant_id', true) !== '') {
            return new WP_Error('sd_tenant_already_promoted', 'This prospect has already been promoted to a tenant.', ['status' => 409]);
        }

        return true;
    }

    private static function resolve_billing_price_id(WP_REST_Request $request): string {
        $request_value = sanitize_text_field((string) $request->get_param('price_id'));
        if ($request_value !== '') {
            return $request_value;
        }

        $constant_value = defined('SD_STRIPE_CONTROL_PLANE_SUBSCRIPTION_PRICE_ID') ? (string) SD_STRIPE_CONTROL_PLANE_SUBSCRIPTION_PRICE_ID : '';
        if ($constant_value !== '') {
            return $constant_value;
        }

        $option_value = (string) get_option('sd_stripe_control_plane_subscription_price_id', '');
        if ($option_value !== '') {
            return $option_value;
        }

        $env_value = getenv('SD_STRIPE_CONTROL_PLANE_SUBSCRIPTION_PRICE_ID');
        return is_string($env_value) ? trim($env_value) : '';
    }

    private static function resolve_checkout_success_url(WP_REST_Request $request): string {
        $request_url = esc_url_raw((string) $request->get_param('success_url'));
        if ($request_url !== '') {
            return $request_url;
        }

        return home_url('/request-received/?sd-billing=success&session_id={CHECKOUT_SESSION_ID}');
    }

    private static function resolve_checkout_cancel_url(WP_REST_Request $request): string {
        $request_url = esc_url_raw((string) $request->get_param('cancel_url'));
        if ($request_url !== '') {
            return $request_url;
        }

        return home_url('/request-access/?sd-billing=cancelled');
    }

    private static function get_stripe_secret_key(): string {
        $constant_value = defined('SD_STRIPE_CONTROL_PLANE_SECRET_KEY') ? (string) SD_STRIPE_CONTROL_PLANE_SECRET_KEY : '';
        if ($constant_value !== '') {
            return $constant_value;
        }

        $option_value = (string) get_option('sd_stripe_control_plane_secret_key', '');
        if ($option_value !== '') {
            return $option_value;
        }

        $env_value = getenv('SD_STRIPE_CONTROL_PLANE_SECRET_KEY');
        return is_string($env_value) ? trim($env_value) : '';
    }

    private static function resolve_onboarding_return_url(WP_REST_Request $request): string {
        $request_url = esc_url_raw((string) $request->get_param('return_url'));
        if ($request_url !== '') {
            return $request_url;
        }

        return home_url('/request-received/?sd-onboarding=return');
    }

    private static function resolve_onboarding_refresh_url(WP_REST_Request $request): string {
        $request_url = esc_url_raw((string) $request->get_param('refresh_url'));
        if ($request_url !== '') {
            return $request_url;
        }

        return home_url('/request-access/?sd-onboarding=refresh');
    }

    private static function stripe_create_connected_account(int $prospect_post_id, string $secret_key): array|WP_Error {
        $email = (string) get_post_meta($prospect_post_id, 'sd_email_normalized', true);
        $full_name = (string) get_post_meta($prospect_post_id, 'sd_full_name', true);
        $phone = (string) get_post_meta($prospect_post_id, 'sd_phone_normalized', true);
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);

        $body = [
            'type' => 'express',
            'country' => apply_filters('sd_stripe_connected_account_country', 'US', $prospect_post_id),
            'email' => $email,
            'capabilities[card_payments][requested]' => 'true',
            'capabilities[transfers][requested]' => 'true',
            'metadata[sd_prospect_id]' => $prospect_id,
            'metadata[sd_prospect_post_id]' => (string) $prospect_post_id,
        ];

        if ($full_name !== '') {
            $name_parts = preg_split('/\s+/', trim($full_name)) ?: [];
            if (!empty($name_parts)) {
                $body['business_type'] = 'individual';
                $body['individual[first_name]'] = (string) array_shift($name_parts);
                if (!empty($name_parts)) {
                    $body['individual[last_name]'] = trim(implode(' ', $name_parts));
                }
                if ($phone !== '') {
                    $body['individual[phone]'] = $phone;
                }
            }
        }

        return self::stripe_api_post('/accounts', $body, $secret_key);
    }

    private static function stripe_create_account_link(string $account_id, string $return_url, string $refresh_url, string $secret_key): array|WP_Error {
        $body = [
            'account' => $account_id,
            'refresh_url' => $refresh_url,
            'return_url' => $return_url,
            'type' => 'account_onboarding',
            'collection_options[fields]' => 'eventually_due',
            'collection_options[future_requirements]' => 'include',
        ];

        return self::stripe_api_post('/account_links', $body, $secret_key);
    }


    private static function stripe_create_billing_checkout_session(int $prospect_post_id, array $args, string $secret_key): array|WP_Error {
        $price_id = sanitize_text_field((string) ($args['price_id'] ?? ''));
        $success_url = esc_url_raw((string) ($args['success_url'] ?? ''));
        $cancel_url = esc_url_raw((string) ($args['cancel_url'] ?? ''));
        $customer_email = sanitize_email((string) ($args['customer_email'] ?? ''));
        $customer_id = sanitize_text_field((string) ($args['customer_id'] ?? ''));
        $trial_period_days = max(0, (int) ($args['trial_period_days'] ?? 0));
        $coupon = sanitize_text_field((string) ($args['coupon'] ?? ''));
        $metadata = is_array($args['metadata'] ?? null) ? $args['metadata'] : [];

        $body = [
            'mode' => 'subscription',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'line_items[0][price]' => $price_id,
            'line_items[0][quantity]' => '1',
            'metadata[sd_prospect_post_id]' => (string) $prospect_post_id,
            'subscription_data[metadata][sd_prospect_post_id]' => (string) $prospect_post_id,
            'allow_promotion_codes' => 'true',
        ];

        foreach ($metadata as $meta_key => $meta_value) {
            $meta_key = sanitize_key((string) $meta_key);
            $meta_value = sanitize_text_field((string) $meta_value);
            if ($meta_key === '' || $meta_value === '') {
                continue;
            }

            $body['metadata[' . $meta_key . ']'] = $meta_value;
            $body['subscription_data[metadata][' . $meta_key . ']'] = $meta_value;
        }

        if ($customer_id !== '') {
            $body['customer'] = $customer_id;
        } elseif ($customer_email !== '') {
            $body['customer_email'] = $customer_email;
        }

        if ($trial_period_days > 0) {
            $body['subscription_data[trial_period_days]'] = (string) $trial_period_days;
        }

        if ($coupon !== '') {
            $body['discounts[0][coupon]'] = $coupon;
        }

        $connected_account_id = (string) get_post_meta($prospect_post_id, 'sd_stripe_account_id', true);
        if ($connected_account_id !== '') {
            $body['subscription_data[metadata][sd_stripe_account_id]'] = $connected_account_id;
        }

        return self::stripe_api_post('/checkout/sessions', $body, $secret_key);
    }

    private static function stripe_api_post(string $path, array $body, string $secret_key): array|WP_Error {
        $response = wp_remote_post(self::STRIPE_API_BASE . $path, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('sd_stripe_http_error', $response->get_error_message(), ['status' => 502]);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status_code < 200 || $status_code >= 300) {
            $message = 'Stripe request failed.';
            if (is_array($payload) && isset($payload['error']['message'])) {
                $message = (string) $payload['error']['message'];
            }

            return new WP_Error('sd_stripe_api_error', $message, [
                'status' => 502,
                'stripe_status_code' => $status_code,
                'stripe_payload' => $payload,
            ]);
        }

        if (!is_array($payload)) {
            return new WP_Error('sd_stripe_invalid_response', 'Stripe returned an invalid response.', ['status' => 502]);
        }

        return $payload;
    }

    private static function mark_prospect_stripe_failure(int $prospect_post_id, WP_Error $error): void {
        update_post_meta($prospect_post_id, 'sd_stripe_onboarding_status', 'failed');
        update_post_meta($prospect_post_id, 'sd_updated_at_gmt', current_time('mysql', true));
        update_post_meta($prospect_post_id, 'sd_last_submission_payload_json', wp_json_encode([
            'event' => 'stripe_onboarding_failed',
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
        ]));
    }

    public static function ensure_prospect_defaults(int $post_id, WP_Post $post, bool $update): void {
        if ($post->post_type !== self::PROSPECT_POST_TYPE) {
            return;
        }

        if (wp_is_post_revision($post_id) || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $now = current_time('mysql', true);

        if (!get_post_meta($post_id, 'sd_prospect_id', true)) {
            update_post_meta($post_id, 'sd_prospect_id', 'prs_' . wp_generate_uuid4());
        }

        if (!get_post_meta($post_id, 'sd_lifecycle_stage', true)) {
            update_post_meta($post_id, 'sd_lifecycle_stage', 'prospect');
        }

        if (!get_post_meta($post_id, 'sd_source', true)) {
            update_post_meta($post_id, 'sd_source', 'admin_user');
        }

        if (!get_post_meta($post_id, 'sd_created_at_gmt', true)) {
            update_post_meta($post_id, 'sd_created_at_gmt', $now);
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', $now);

        if (!get_post_meta($post_id, 'sd_invitation_status', true)) {
            update_post_meta($post_id, 'sd_invitation_status', 'none');
        }

        if (!get_post_meta($post_id, 'sd_priority_lane', true)) {
            update_post_meta($post_id, 'sd_priority_lane', 0);
        }

        if (!get_post_meta($post_id, 'sd_review_status', true)) {
            update_post_meta($post_id, 'sd_review_status', 'new');
        }

        if (!get_post_meta($post_id, 'sd_stripe_onboarding_status', true)) {
            update_post_meta($post_id, 'sd_stripe_onboarding_status', 'not_started');
        }

        if (!get_post_meta($post_id, 'sd_billing_status', true)) {
            update_post_meta($post_id, 'sd_billing_status', 'not_started');
        }

        if (!get_post_meta($post_id, 'sd_last_intake_channel', true)) {
            update_post_meta($post_id, 'sd_last_intake_channel', 'admin_user');
        }

        if (!get_post_meta($post_id, 'sd_last_submission_at_gmt', true)) {
            update_post_meta($post_id, 'sd_last_submission_at_gmt', $now);
        }

        if (!get_post_meta($post_id, 'sd_submission_count', true)) {
            update_post_meta($post_id, 'sd_submission_count', 1);
        }

        if (!get_post_meta($post_id, 'sd_last_submission_payload_json', true)) {
            update_post_meta($post_id, 'sd_last_submission_payload_json', wp_json_encode([
                'origin' => 'admin_user',
                'post_id' => $post_id,
            ]));
        }

        $title = get_the_title($post_id);

        if (!get_post_meta($post_id, 'sd_full_name', true) && $title) {
            update_post_meta($post_id, 'sd_full_name', $title);
        }

        $phone_raw = (string) get_post_meta($post_id, 'sd_phone_raw', true);
        if ($phone_raw && !get_post_meta($post_id, 'sd_phone_normalized', true)) {
            update_post_meta($post_id, 'sd_phone_normalized', self::normalize_phone($phone_raw));
        }

        $email_raw = (string) get_post_meta($post_id, 'sd_email_raw', true);
        if ($email_raw && !get_post_meta($post_id, 'sd_email_normalized', true)) {
            update_post_meta($post_id, 'sd_email_normalized', strtolower(trim($email_raw)));
        }

        if (!get_post_meta($post_id, 'sd_dedupe_key_email', true)) {
            update_post_meta($post_id, 'sd_dedupe_key_email', (string) get_post_meta($post_id, 'sd_email_normalized', true));
        }

        if (!get_post_meta($post_id, 'sd_dedupe_key_phone', true)) {
            update_post_meta($post_id, 'sd_dedupe_key_phone', (string) get_post_meta($post_id, 'sd_phone_normalized', true));
        }
    }

    public static function ensure_tenant_defaults(int $post_id, WP_Post $post, bool $update): void {
        if ($post->post_type !== self::TENANT_POST_TYPE) {
            return;
        }

        if (wp_is_post_revision($post_id) || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $now = current_time('mysql', true);

        if (!get_post_meta($post_id, 'sd_tenant_id', true)) {
            update_post_meta($post_id, 'sd_tenant_id', 'ten_' . wp_generate_uuid4());
        }

        if (!get_post_meta($post_id, 'sd_status', true)) {
            update_post_meta($post_id, 'sd_status', 'inactive');
        }

        if (!get_post_meta($post_id, 'sd_created_at_gmt', true)) {
            update_post_meta($post_id, 'sd_created_at_gmt', $now);
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', $now);

        if (!get_post_meta($post_id, 'sd_storefront_status', true)) {
            update_post_meta($post_id, 'sd_storefront_status', 'not_started');
        }

        if (!get_post_meta($post_id, 'sd_activation_ready', true)) {
            update_post_meta($post_id, 'sd_activation_ready', 0);
        }

        if (!get_post_meta($post_id, 'sd_billing_status', true)) {
            update_post_meta($post_id, 'sd_billing_status', 'not_started');
        }

        if (!get_post_meta($post_id, 'sd_provisioning_status', true)) {
            update_post_meta($post_id, 'sd_provisioning_status', 'not_started');
        }

        if (!get_post_meta($post_id, 'sd_charges_enabled', true)) {
            update_post_meta($post_id, 'sd_charges_enabled', 0);
        }

        if (!get_post_meta($post_id, 'sd_payouts_enabled', true)) {
            update_post_meta($post_id, 'sd_payouts_enabled', 0);
        }
    }

    public static function shortcode_confirm_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Confirm block preview</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_request();      

        $state = self::get_activation_state($prospect_post_id);

        if ($state === 'STARTED') {
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'CONFIRMED');
            $state = 'CONFIRMED';
        }

        $status_label = self::map_public_status_label($state);
        $public_key   = (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true);
        $cta_url      = add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_CONNECT_PAYOUTS . '/'));

        ob_start();
        ?>
        <div class="sd-front-status">
        <span class="sd-front-status__label">Status</span>
        <strong class="sd-front-status__value"><?php echo esc_html($status_label); ?></strong>
        </div>

        <div class="sd-front-copy">
        <p class="sd-front-eyebrow">Step 2 of 4</p>
        <h1>Your booking page is ready to activate.</h1>
        <p class="sd-front-subhead">Next step: connect payouts so you can receive customer payments.</p>
        </div>

        <div class="sd-front-card">
        <p>Once payouts are connected, your booking page can move to live status.</p>
        </div>

        <div class="sd-front-actions">
        <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($cta_url); ?>">Connect payouts</a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function shortcode_connect_payouts_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Confirm block preview</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_request();

        $public_key = (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true);

        $cta_url = add_query_arg([
            'action' => self::ACTION_START_PAYOUTS,
            'k'      => $public_key,
        ], admin_url('admin-post.php'));

        ob_start();
        ?>
        <div class="sd-front-copy">
        <p class="sd-front-eyebrow">Step 3 of 4</p>
        <h1>Connect payouts</h1>
        <p class="sd-front-subhead">This is how you get paid by your customers.</p>
        </div>

        <div class="sd-front-card">
        <h2>Why this step matters</h2>
        <p>Your booking page needs payouts connected before it can go live and accept customer payments.</p>
        </div>

        <div class="sd-front-actions">
        <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($cta_url); ?>">Continue to payouts</a>
        </div>

        <div class="sd-front-fineprint">
        <p>You will return here automatically after payouts are connected.</p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_start_payouts(): void {
        $public_key = sanitize_text_field((string) ($_GET['k'] ?? ''));
        $prospect_post_id = self::get_prospect_post_id_by_public_key($public_key);

        if ($prospect_post_id <= 0) {
            wp_safe_redirect(home_url('/' . self::PAGE_SLUG_START . '/'));
            exit;
        }

        $state = self::get_activation_state($prospect_post_id);
        if ($state === 'STARTED') {
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'CONFIRMED');
        }

        /**
         * Replace this block with your real Stripe onboarding starter.
         * It should:
         * - ensure/create connected account
         * - create account onboarding link
         * - use:
         *   refresh_url = /connect-payouts/?k=...
         *   return_url  = /success/?k=...
         * - wp_safe_redirect() to Stripe
         */

        $return_url = add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_SUCCESS . '/'));
        $refresh_url = add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_CONNECT_PAYOUTS . '/'));

        // Temporary skeleton behavior.
        // Remove when real Stripe redirect is wired.
        update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'PAYOUTS_CONNECTED');

        wp_safe_redirect($return_url);
        exit;
    }

    public static function shortcode_success_state(): string {
    if (self::is_editor_request()) {
        return '<div class="sd-front-placeholder">SOLODRIVE.PRO Confirm block preview</div>';
    }

    $prospect_post_id = self::require_prospect_post_id_from_request();

        $state                = self::get_activation_state($prospect_post_id);
        $status_label         = self::map_public_status_label($state);
        $storefront_url       = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        ob_start();

        if (self::is_success_ready($prospect_post_id)) :
            ?>
            <div class="sd-front-status">
            <span class="sd-front-status__label">Status</span>
            <strong class="sd-front-status__value"><?php echo esc_html($status_label); ?></strong>
            </div>

            <div class="sd-front-copy">
            <p class="sd-front-eyebrow">Step 4 of 4</p>
            <h1>Your booking page is live.</h1>
            <p class="sd-front-subhead">Share your link with riders to start accepting direct bookings.</p>
            </div>

            <div class="sd-front-card sd-front-success-card">
            <label class="sd-front-inline-label" for="sd-storefront-link">Your booking page</label>
            <div class="sd-front-linkbox">
                <input
                id="sd-storefront-link"
                class="sd-front-linkbox__input"
                type="text"
                value="<?php echo esc_attr($storefront_url); ?>"
                readonly
                >
                <button class="sd-front-btn sd-front-btn--secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('sd-storefront-link').value);">
                Copy
                </button>
            </div>
            </div>

            <div class="sd-front-actions">
            <a class="sd-front-btn sd-front-btn--primary" href="<?php echo esc_url($storefront_url); ?>">Open your booking page</a>

            <?php if ($operations_entry_url !== '') : ?>
                <a class="sd-front-btn sd-front-btn--secondary" href="<?php echo esc_url($operations_entry_url); ?>">Log in to operations</a>
            <?php endif; ?>
            </div>

            <div class="sd-front-card">
            <p>Next time a rider asks if you are available, send them your booking link.</p>
            </div>
            <?php

        elseif (in_array($state, [
            'PAYOUTS_CONNECTED',
            'TENANT_CREATING',
            'TENANT_READY',
            'STOREFRONT_READY',
            'FRONTEND_SYNC_PENDING',
            'OPERATIONS_READY',
        ], true)) :
            ?>
            <div class="sd-front-status">
            <span class="sd-front-status__label">Status</span>
            <strong class="sd-front-status__value">Payouts connected</strong>
            </div>

            <div class="sd-front-copy">
            <p class="sd-front-eyebrow">Step 4 of 4</p>
            <h1>We’re finishing your booking page.</h1>
            <p class="sd-front-subhead">Your setup is still being completed.</p>
            </div>

            <div class="sd-front-card">
            <p>Please keep this page open. We’ll show your booking page here as soon as it is ready.</p>
            </div>
            <?php

        elseif (in_array($state, ['ACTIVATION_FAILED', 'PARTIAL_SYNC_FAILED'], true)) :
            ?>
            <div class="sd-front-status">
            <span class="sd-front-status__label">Status</span>
            <strong class="sd-front-status__value">Activation issue</strong>
            </div>

            <div class="sd-front-copy">
            <p class="sd-front-eyebrow">Step 4 of 4</p>
            <h1>We hit a setup issue.</h1>
            <p class="sd-front-subhead">Your payouts may be connected, but your booking page is not live yet.</p>
            </div>

            <div class="sd-front-card">
            <p>Please try again shortly or contact support.</p>
            </div>
            <?php

        else :
            ?>
            <div class="sd-front-status">
            <span class="sd-front-status__label">Status</span>
            <strong class="sd-front-status__value"><?php echo esc_html($status_label); ?></strong>
            </div>

            <div class="sd-front-copy">
            <p class="sd-front-eyebrow">Step 4 of 4</p>
            <h1>We’re still setting things up.</h1>
            </div>
            <?php
        endif;

        return (string) ob_get_clean();
    }

    public static function shortcode_start_form(): string {
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
