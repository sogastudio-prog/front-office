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

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_meta_keys']);
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

    private static function is_request_access_form($contact_form, array $posted_data): bool {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return false;
        }

        return (int) $contact_form->id() === self::REQUEST_ACCESS_FORM_ID;
    }

    private static function is_invite_ready_form($contact_form): bool {
        if (!is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return false;
        }

        return (int) $contact_form->id() === self::INVITE_READY_FORM_ID;
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
}

SD_Front_Office_Scaffold::bootstrap();

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
