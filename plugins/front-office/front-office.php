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

    private const META_STRIPE_ACCOUNT_ID    = 'sd_stripe_account_id';
    private const META_STRIPE_STATE         = 'sd_stripe_state';
    private const META_STRIPE_COMPLETED_GMT = 'sd_stripe_completed_gmt';

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
        add_action('add_meta_boxes_' . self::PROSPECT_POST_TYPE, [__CLASS__, 'register_prospect_debug_meta_boxes']);
        add_action('admin_head-post.php', [__CLASS__, 'inject_prospect_debug_admin_css']);
        add_action('admin_head-post-new.php', [__CLASS__, 'inject_prospect_debug_admin_css']);

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


    public static function register_prospect_debug_meta_boxes(WP_Post $post): void {
        remove_meta_box('slugdiv', self::PROSPECT_POST_TYPE, 'normal');

        add_meta_box(
            'sd-prospect-debug-panel',
            'Prospect Debug Panel',
            [__CLASS__, 'render_prospect_debug_panel'],
            self::PROSPECT_POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'sd-prospect-debug-tools',
            'Debug Tools',
            [__CLASS__, 'render_prospect_debug_tools'],
            self::PROSPECT_POST_TYPE,
            'side',
            'high'
        );
    }

    public static function inject_prospect_debug_admin_css(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::PROSPECT_POST_TYPE) {
            return;
        }

        echo '<style>
'
            . '#post-body-content{margin-bottom:12px;}
'
            . '#postdivrich{display:none !important;}
'
            . '.sd-debug-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin:12px 0 0;}
'
            . '.sd-debug-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px;}
'
            . '.sd-debug-card h3{margin:0 0 10px;font-size:13px;line-height:1.4;}
'
            . '.sd-debug-table{width:100%;border-collapse:collapse;}
'
            . '.sd-debug-table th,.sd-debug-table td{padding:6px 8px;border-top:1px solid #f0f0f1;vertical-align:top;text-align:left;font-size:12px;line-height:1.5;word-break:break-word;}
'
            . '.sd-debug-table tr:first-child th,.sd-debug-table tr:first-child td{border-top:0;}
'
            . '.sd-debug-table th{width:34%;color:#50575e;font-weight:600;}
'
            . '.sd-debug-pre{margin:0;max-height:420px;overflow:auto;padding:12px;background:#0f172a;color:#e2e8f0;border-radius:6px;font:12px/1.5 Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;}
'
            . '.sd-debug-note{margin:0 0 10px;color:#50575e;}
'
            . '.sd-debug-tools-list{margin:0;padding-left:18px;}
'
            . '.sd-debug-tools-list li{margin:0 0 8px;}
'
            . '.sd-debug-badge{display:inline-block;margin:0 6px 6px 0;padding:3px 8px;border-radius:999px;background:#eef4ff;border:1px solid #c3dafe;font-size:12px;}
'
            . '</style>';
    }

    public static function render_prospect_debug_panel(WP_Post $post): void {
        $post_id = (int) $post->ID;
        $all_meta = get_post_meta($post_id);
        $tenant_post_id = (int) get_post_meta($post_id, 'sd_promoted_to_tenant_post_id', true);
        $tenant_edit_url = $tenant_post_id > 0 ? get_edit_post_link($tenant_post_id, '') : '';

        $sections = [
            'Core Record Identity' => [
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_status' => $post->post_status,
                'post_type' => $post->post_type,
                'post_author' => (int) $post->post_author,
                'created_gmt' => $post->post_date_gmt,
                'modified_gmt' => $post->post_modified_gmt,
                'sd_prospect_id' => get_post_meta($post_id, 'sd_prospect_id', true),
                'sd_public_key' => get_post_meta($post_id, self::META_PUBLIC_KEY, true),
                'sd_source' => get_post_meta($post_id, 'sd_source', true),
                'sd_owner_user_id' => get_post_meta($post_id, 'sd_owner_user_id', true),
                'sd_created_at_gmt' => get_post_meta($post_id, 'sd_created_at_gmt', true),
                'sd_updated_at_gmt' => get_post_meta($post_id, 'sd_updated_at_gmt', true),
            ],
            'Lifecycle + State' => [
                'sd_lifecycle_stage' => get_post_meta($post_id, 'sd_lifecycle_stage', true),
                'sd_invitation_status' => get_post_meta($post_id, 'sd_invitation_status', true),
                'sd_review_status' => get_post_meta($post_id, 'sd_review_status', true),
                'sd_activation_state' => get_post_meta($post_id, self::META_ACTIVATION_STATE, true),
                'sd_stripe_onboarding_status' => get_post_meta($post_id, 'sd_stripe_onboarding_status', true),
                'sd_stripe_state' => get_post_meta($post_id, self::META_STRIPE_STATE, true),
                'sd_billing_status' => get_post_meta($post_id, 'sd_billing_status', true),
                'sd_priority_lane' => get_post_meta($post_id, 'sd_priority_lane', true),
                'sd_submission_count' => get_post_meta($post_id, 'sd_submission_count', true),
                'sd_last_intake_channel' => get_post_meta($post_id, 'sd_last_intake_channel', true),
                'sd_last_submission_at_gmt' => get_post_meta($post_id, 'sd_last_submission_at_gmt', true),
                'sd_last_staff_action_at_gmt' => get_post_meta($post_id, 'sd_last_staff_action_at_gmt', true),
            ],
            'Contact + Business' => [
                'sd_full_name' => get_post_meta($post_id, 'sd_full_name', true),
                'sd_phone_raw' => get_post_meta($post_id, 'sd_phone_raw', true),
                'sd_phone_normalized' => get_post_meta($post_id, 'sd_phone_normalized', true),
                'sd_email_raw' => get_post_meta($post_id, 'sd_email_raw', true),
                'sd_email_normalized' => get_post_meta($post_id, 'sd_email_normalized', true),
                'sd_business_name' => get_post_meta($post_id, self::META_BUSINESS_NAME, true),
                'sd_service_area' => get_post_meta($post_id, self::META_SERVICE_AREA, true),
                'sd_city' => get_post_meta($post_id, 'sd_city', true),
                'sd_repeat_clients' => get_post_meta($post_id, 'sd_repeat_clients', true),
                'sd_driving_status' => get_post_meta($post_id, 'sd_driving_status', true),
                'sd_weekly_gross' => get_post_meta($post_id, 'sd_weekly_gross', true),
                'sd_staff_notes' => get_post_meta($post_id, 'sd_staff_notes', true),
            ],
            'Invite + Access' => [
                'sd_invitation_code' => get_post_meta($post_id, 'sd_invitation_code', true),
                'sd_invited_by' => get_post_meta($post_id, 'sd_invited_by', true),
            ],
            'Stripe' => [
                'sd_stripe_account_id' => get_post_meta($post_id, self::META_STRIPE_ACCOUNT_ID, true),
                'sd_stripe_onboarding_started_at_gmt' => get_post_meta($post_id, 'sd_stripe_onboarding_started_at_gmt', true),
                'sd_stripe_onboarding_status' => get_post_meta($post_id, 'sd_stripe_onboarding_status', true),
                'sd_stripe_state' => get_post_meta($post_id, self::META_STRIPE_STATE, true),
                'sd_stripe_completed_gmt' => get_post_meta($post_id, self::META_STRIPE_COMPLETED_GMT, true),
                'sd_stripe_customer_id' => get_post_meta($post_id, 'sd_stripe_customer_id', true),
                'sd_stripe_subscription_id' => get_post_meta($post_id, 'sd_stripe_subscription_id', true),
                'sd_stripe_checkout_session_id' => get_post_meta($post_id, 'sd_stripe_checkout_session_id', true),
                'sd_subscription_paid_at_gmt' => get_post_meta($post_id, 'sd_subscription_paid_at_gmt', true),
                'sd_stripe_last_event_id' => get_post_meta($post_id, 'sd_stripe_last_event_id', true),
                'sd_stripe_status_snapshot_json' => get_post_meta($post_id, 'sd_stripe_status_snapshot_json', true),
            ],
            'Tenant Handoff + Activation' => [
                'sd_promoted_to_tenant_id' => get_post_meta($post_id, 'sd_promoted_to_tenant_id', true),
                'sd_promoted_to_tenant_post_id' => $tenant_post_id,
                'tenant_edit_link' => $tenant_edit_url,
                'sd_storefront_url' => get_post_meta($post_id, self::META_STOREFRONT_URL, true),
                'sd_operations_entry_url' => get_post_meta($post_id, self::META_OPERATIONS_ENTRY_URL, true),
            ],
        ];

        echo '<p class="sd-debug-note">Read-only debug surface for raw prospect state, control-plane fields, Stripe continuity, and tenant handoff.</p>';
        echo '<div class="sd-debug-grid">';
        foreach ($sections as $title => $rows) {
            echo '<section class="sd-debug-card">';
            echo '<h3>' . esc_html($title) . '</h3>';
            self::render_debug_table($rows);
            echo '</section>';
        }
        echo '</div>';

        echo '<div class="sd-debug-grid">';
        echo '<section class="sd-debug-card">';
        echo '<h3>Raw Post Object</h3>';
        echo '<pre class="sd-debug-pre">' . esc_html(self::debug_export($post)) . '</pre>';
        echo '</section>';

        echo '<section class="sd-debug-card">';
        echo '<h3>All Post Meta</h3>';
        echo '<pre class="sd-debug-pre">' . esc_html(self::debug_export(self::normalize_meta_for_debug($all_meta))) . '</pre>';
        echo '</section>';
        echo '</div>';
    }

    public static function render_prospect_debug_tools(WP_Post $post): void {
        $post_id = (int) $post->ID;
        $badges = array_filter([
            (string) get_post_meta($post_id, 'sd_lifecycle_stage', true),
            (string) get_post_meta($post_id, 'sd_invitation_status', true),
            (string) get_post_meta($post_id, 'sd_review_status', true),
            (string) get_post_meta($post_id, self::META_ACTIVATION_STATE, true),
            (string) get_post_meta($post_id, 'sd_stripe_onboarding_status', true),
            (string) get_post_meta($post_id, self::META_STRIPE_STATE, true),
        ]);

        echo '<p class="sd-debug-note">This side panel is intentionally read-only. Use it to spot missing meta, mismatched states, and bad transitions fast.</p>';
        if (!empty($badges)) {
            echo '<div>';
            foreach ($badges as $badge) {
                echo '<span class="sd-debug-badge">' . esc_html($badge) . '</span>';
            }
            echo '</div>';
        }

        echo '<ul class="sd-debug-tools-list">';
        echo '<li><strong>Post ID:</strong> ' . esc_html((string) $post_id) . '</li>';
        echo '<li><strong>Prospect ID:</strong> ' . esc_html((string) get_post_meta($post_id, 'sd_prospect_id', true)) . '</li>';
        echo '<li><strong>Public key:</strong> ' . esc_html((string) get_post_meta($post_id, self::META_PUBLIC_KEY, true)) . '</li>';
        echo '<li><strong>Last updated:</strong> ' . esc_html((string) get_post_meta($post_id, 'sd_updated_at_gmt', true)) . '</li>';
        echo '<li>Use the main panel below to inspect the raw post object and every saved meta key.</li>';
        echo '</ul>';
    }

    private static function render_debug_table(array $rows): void {
        echo '<table class="sd-debug-table"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html($label) . '</th>';
            echo '<td>' . esc_html(self::stringify_debug_value($value)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function stringify_debug_value($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            $string = (string) $value;
            return $string === '' ? '—' : $string;
        }

        return self::debug_export($value);
    }

    private static function normalize_meta_for_debug(array $all_meta): array {
        $normalized = [];

        foreach ($all_meta as $key => $values) {
            $normalized[$key] = [];
            foreach ((array) $values as $value) {
                $maybe = maybe_unserialize($value);
                if (is_string($maybe)) {
                    $json = json_decode($maybe, true);
                    if (json_last_error() === JSON_ERROR_NONE && (is_array($json) || is_object($json))) {
                        $maybe = $json;
                    }
                }
                $normalized[$key][] = $maybe;
            }
        }

        ksort($normalized);
        return $normalized;
    }

    private static function debug_export($value): string {
        return trim(print_r($value, true));
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

        if ($name === '' || $email_normalized === '' || $business_name === '') {
            $redirect = add_query_arg('error', 'missing_required', home_url('/' . self::PAGE_SLUG_START . '/'));
            wp_safe_redirect($redirect);
            exit;
        }

        if ($mobile_normalized === '' || strlen($mobile_normalized) < 10) {
            $redirect = add_query_arg('error', 'invalid_mobile', home_url('/' . self::PAGE_SLUG_START . '/'));
            wp_safe_redirect($redirect);
            exit;
        }

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

    private static function require_prospect_post_id_from_request(): int {
        $public_key = self::get_public_key_from_request();
        $post_id = self::get_prospect_post_id_by_public_key($public_key);

        if ($post_id <= 0) {
            wp_safe_redirect(home_url('/' . self::PAGE_SLUG_START . '/'));
            exit;
        }

        return $post_id;
    }

    private static function get_activation_payload_for_success(int $prospect_post_id): array {
        $prospect_id = (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true);

        $state = self::get_activation_state($prospect_post_id);
        $storefront_url = (string) get_post_meta($prospect_post_id, self::META_STOREFRONT_URL, true);
        $operations_entry_url = (string) get_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, true);

        if (
            $prospect_id !== '' &&
            class_exists('SD_Activation_Service') &&
            method_exists('SD_Activation_Service', 'activate_prospect')
        ) {
            $result = SD_Activation_Service::activate_prospect($prospect_id);

            if (is_array($result)) {
                $state = isset($result['activation_state'])
                    ? sanitize_text_field((string) $result['activation_state'])
                    : $state;

                $storefront_url = isset($result['storefront_url'])
                    ? esc_url_raw((string) $result['storefront_url'])
                    : $storefront_url;

                $operations_entry_url = isset($result['operations_entry_url'])
                    ? esc_url_raw((string) $result['operations_entry_url'])
                    : $operations_entry_url;

                if ($state !== '') {
                    update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, $state);
                }

                if ($storefront_url !== '') {
                    update_post_meta($prospect_post_id, self::META_STOREFRONT_URL, $storefront_url);
                }

                if ($operations_entry_url !== '') {
                    update_post_meta($prospect_post_id, self::META_OPERATIONS_ENTRY_URL, $operations_entry_url);
                }
            }
        }

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

    public static function shortcode_confirm_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Confirm block preview</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_request();
        $public_key = (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true);

        $payload = self::build_runtime_prospect_contract($prospect_post_id);
        $confirm = self::post_control_plane_endpoint('confirm', $payload);

        if (!empty($confirm['ok'])) {
            if (!empty($confirm['stripe_account_id'])) {
                update_post_meta(
                    $prospect_post_id,
                    self::META_STRIPE_ACCOUNT_ID,
                    sanitize_text_field((string) $confirm['stripe_account_id'])
                );
            }

            if (!empty($confirm['stripe_state'])) {
                update_post_meta(
                    $prospect_post_id,
                    self::META_STRIPE_STATE,
                    sanitize_text_field((string) $confirm['stripe_state'])
                );
            }

            error_log(
                'SOLODRIVE.PRO confirm: persisted stripe continuity for prospect post ' .
                $prospect_post_id . ' => ' .
                ((string) ($confirm['stripe_account_id'] ?? ''))
            );
        } else {
            error_log(
                'SOLODRIVE.PRO confirm: control-plane confirm failed for prospect post ' .
                $prospect_post_id . ' => ' . wp_json_encode($confirm)
            );
        }

        $state = self::get_activation_state($prospect_post_id);

        if ($state === 'STARTED') {
            update_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, 'CONFIRMED');
            $state = 'CONFIRMED';
        }

        $status_label = self::map_public_status_label($state);
        $cta_url = add_query_arg(
            'k',
            rawurlencode($public_key),
            home_url('/' . self::PAGE_SLUG_CONNECT_PAYOUTS . '/')
        );

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
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Connect payouts block</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_request();
        $public_key = (string) get_post_meta($prospect_post_id, self::META_PUBLIC_KEY, true);
        $stripe_account_id = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);

        if ($stripe_account_id === '') {
            wp_safe_redirect(add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_CONFIRM . '/')));
            exit;
        }

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

        $stripe_account_id = (string) get_post_meta($prospect_post_id, self::META_STRIPE_ACCOUNT_ID, true);
        if ($stripe_account_id === '') {
            error_log('SOLODRIVE.PRO connect-payouts: no persisted sd_stripe_account_id for prospect post ' . $prospect_post_id);
            wp_safe_redirect(add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_CONFIRM . '/')));
            exit;
        }

        $payload = [
            'prospect_id'      => (string) get_post_meta($prospect_post_id, 'sd_prospect_id', true),
            'public_key'       => $public_key,
            'prospect_post_id' => $prospect_post_id,
        ];

        $result = self::post_control_plane_endpoint('payouts-start', $payload);

        if (!empty($result['ok']) && !empty($result['stripe_account_id'])) {
            update_post_meta(
                $prospect_post_id,
                self::META_STRIPE_ACCOUNT_ID,
                sanitize_text_field((string) $result['stripe_account_id'])
            );
        }

        if (!empty($result['redirect_url'])) {
            wp_safe_redirect(esc_url_raw((string) $result['redirect_url']));
            exit;
        }

        error_log(
            'SOLODRIVE.PRO connect-payouts: payouts-start failed for prospect post ' .
            $prospect_post_id . ' => ' . wp_json_encode($result)
        );

        wp_safe_redirect(add_query_arg('k', rawurlencode($public_key), home_url('/' . self::PAGE_SLUG_CONFIRM . '/')));
        exit;
    }

    public static function shortcode_success_state(): string {
        if (self::is_editor_request()) {
            return '<div class="sd-front-placeholder">SOLODRIVE.PRO Success block</div>';
        }

        $prospect_post_id = self::require_prospect_post_id_from_request();
        $payload = self::get_activation_payload_for_success($prospect_post_id);

        $state = (string) ($payload['activation_state'] ?? 'STARTED');
        $storefront_url = (string) ($payload['storefront_url'] ?? '');
        $operations_entry_url = (string) ($payload['operations_entry_url'] ?? '');
        $status_label = self::map_public_status_label($state);

        ob_start();

        if (self::is_success_ready_payload($payload)) :
            ?>
            <div class="sd-front-status">
                <span class="sd-front-status__label">Status</span>
                <strong class="sd-front-status__value"><?php echo esc_html($status_label); ?></strong>
            </div>

            <div class="sd-front-copy">
                <p class="sd-front-eyebrow">Step 4 of 4</p>
                <h1>Your SOLODRIVE.PRO booking page is live.</h1>
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
                <p class="sd-front-subhead">Your booking page is not live yet.</p>
            </div>

            <div class="sd-front-card">
                <p>Please try again shortly.</p>
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

    public static function register_rest_routes(): void {
        // Temporary no-op to prevent fatal while funnel wiring is stabilized.
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

    private static function get_activation_state(int $prospect_post_id): string {
        $state = (string) get_post_meta($prospect_post_id, self::META_ACTIVATION_STATE, true);

        if ($state === '') {
            return 'STARTED';
        }

        return $state;
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

        $created_at = (string) get_post_meta($post_id, 'sd_created_at_gmt', true);
        if ($created_at === '') {
            update_post_meta($post_id, 'sd_created_at_gmt', current_time('mysql', true));
        }

        update_post_meta($post_id, 'sd_updated_at_gmt', current_time('mysql', true));
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
