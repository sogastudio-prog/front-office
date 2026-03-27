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

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_post_types']);
        add_action('init', [__CLASS__, 'register_meta_keys']);

        add_filter('manage_' . self::PROSPECT_POST_TYPE . '_posts_columns', [__CLASS__, 'prospect_columns']);
        add_action('manage_' . self::PROSPECT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_prospect_column'], 10, 2);
        add_filter('manage_edit-' . self::PROSPECT_POST_TYPE . '_sortable_columns', [__CLASS__, 'prospect_sortable_columns']);

        add_filter('manage_' . self::TENANT_POST_TYPE . '_posts_columns', [__CLASS__, 'tenant_columns']);
        add_action('manage_' . self::TENANT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_tenant_column'], 10, 2);
        add_filter('manage_edit-' . self::TENANT_POST_TYPE . '_sortable_columns', [__CLASS__, 'tenant_sortable_columns']);

        add_action('restrict_manage_posts', [__CLASS__, 'admin_filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_admin_filters']);

        add_action('wpcf7_before_send_mail', [__CLASS__, 'handle_cf7_submission']);

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
        if (!function_exists('WPCF7_Submission::get_instance')) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        if (!is_array($posted_data)) {
            return;
        }

        if (!self::is_request_access_form($contact_form, $posted_data)) {
            return;
        }

        $payload = self::normalize_payload($posted_data);
        $payload['invitation'] = self::evaluate_invitation_code($payload['invitation_code']);

        $prospect_post_id = self::find_existing_prospect($payload);
        if ($prospect_post_id > 0) {
            self::update_existing_prospect($prospect_post_id, $payload);
            return;
        }

        self::create_new_prospect($payload);
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
$invitation_code = sanitize_text_field((string) ($posted_data['invite_code'] ?? ''));

        return [
            'full_name' => $full_name,
            'phone_raw' => $phone_raw,
            'phone_normalized' => self::normalize_phone($phone_raw),
            'email_raw' => $email_raw,
            'email_normalized' => strtolower(trim($email_raw)),
            'invitation_code' => strtoupper(trim($invitation_code)),
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

        $post_id = wp_insert_post([
            'post_type' => self::PROSPECT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => self::build_prospect_title($payload),
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

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
        if (!function_exists('WPCF7_Submission::get_instance')) {
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
