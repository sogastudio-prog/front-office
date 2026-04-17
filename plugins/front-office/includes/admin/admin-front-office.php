<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SD_Front_Office_Admin {
    private const PROSPECT_POST_TYPE = 'sd_prospect';
    private const TENANT_POST_TYPE   = 'sd_tenant';
    private const META_PUBLIC_KEY = 'sd_public_key';
    private const META_ACTIVATION_STATE = 'sd_activation_state';
    private const META_STOREFRONT_URL = 'sd_storefront_url';
    private const META_OPERATIONS_ENTRY_URL = 'sd_operations_entry_url';
    private const META_BUSINESS_NAME = 'sd_business_name';
    private const META_SERVICE_AREA = 'sd_service_area';
    private const META_PROSPECT_TOKEN = 'sd_prospect_token';
    private const META_STRIPE_ACCOUNT_ID = 'sd_stripe_account_id';
    private const META_STRIPE_STATE = 'sd_stripe_state';
    private const META_STRIPE_COMPLETED_GMT = 'sd_stripe_completed_gmt';

    public static function bootstrap(): void {
        add_filter('manage_' . self::PROSPECT_POST_TYPE . '_posts_columns', [__CLASS__, 'prospect_columns']);
        add_action('manage_' . self::PROSPECT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_prospect_column'], 10, 2);
        add_filter('manage_edit-' . self::PROSPECT_POST_TYPE . '_sortable_columns', [__CLASS__, 'prospect_sortable_columns']);

        add_filter('manage_' . self::TENANT_POST_TYPE . '_posts_columns', [__CLASS__, 'tenant_columns']);
        add_action('manage_' . self::TENANT_POST_TYPE . '_posts_custom_column', [__CLASS__, 'render_tenant_column'], 10, 2);
        add_filter('manage_edit-' . self::TENANT_POST_TYPE . '_sortable_columns', [__CLASS__, 'tenant_sortable_columns']);

        add_action('add_meta_boxes_' . self::PROSPECT_POST_TYPE, [__CLASS__, 'register_prospect_debug_meta_boxes']);
        add_action('admin_head-post.php', [__CLASS__, 'inject_prospect_debug_admin_css']);
        add_action('admin_head-post-new.php', [__CLASS__, 'inject_prospect_debug_admin_css']);

        add_action('restrict_manage_posts', [__CLASS__, 'admin_filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_admin_filters']);

        add_action('add_meta_boxes_' . self::PROSPECT_POST_TYPE, [__CLASS__, 'register_prospect_debug_meta_boxes']);
        add_action('admin_head-post.php', [__CLASS__, 'inject_prospect_debug_admin_css']);
        add_action('admin_head-post-new.php', [__CLASS__, 'inject_prospect_debug_admin_css']);

        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_settings_page(): void {
        add_submenu_page(
            'edit.php?post_type=' . self::PROSPECT_POST_TYPE,
            'Front Office Settings',
            'Settings',
            'manage_options',
            'sd-front-office-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting('sd_front_office_settings', 'sd_default_stripe_subscription_price_id', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_price_id'],
            'default' => '',
        ]);

        register_setting('sd_front_office_settings', 'sd_default_subscription_plan_label', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('sd_front_office_settings', 'sd_default_subscription_display_price', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        add_settings_section(
            'sd_front_office_pricing',
            'Checkout Pricing',
            '__return_false',
            'sd-front-office-settings'
        );

        add_settings_field(
            'sd_default_stripe_subscription_price_id',
            'Default Stripe Price ID',
            [__CLASS__, 'render_price_id_field'],
            'sd-front-office-settings',
            'sd_front_office_pricing'
        );

        add_settings_field(
            'sd_default_subscription_plan_label',
            'Plan Label',
            [__CLASS__, 'render_plan_label_field'],
            'sd-front-office-settings',
            'sd_front_office_pricing'
        );

        add_settings_field(
            'sd_default_subscription_display_price',
            'Display Price',
            [__CLASS__, 'render_display_price_field'],
            'sd-front-office-settings',
            'sd_front_office_pricing'
        );
    }

    public static function render_settings_page(): void {
        ?>
        <div class="wrap">
            <h1>Front Office Settings</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('sd_front_office_settings');
                do_settings_sections('sd-front-office-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function sanitize_price_id($value): string {
        $value = sanitize_text_field((string) $value);

        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, 'price_')) {
            add_settings_error(
                'sd_default_stripe_subscription_price_id',
                'invalid_price_id',
                'Stripe price ID must start with price_.'
            );

            return (string) get_option('sd_default_stripe_subscription_price_id', '');
        }

        return $value;
    }

    public static function render_price_id_field(): void {
        $value = (string) get_option('sd_default_stripe_subscription_price_id', '');
        echo '<input type="text" class="regular-text" name="sd_default_stripe_subscription_price_id" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Used for public checkout when no invite pricing profile applies.</p>';
    }

    public static function render_plan_label_field(): void {
        $value = (string) get_option('sd_default_subscription_plan_label', '');
        echo '<input type="text" class="regular-text" name="sd_default_subscription_plan_label" value="' . esc_attr($value) . '" />';
    }

    public static function render_display_price_field(): void {
        $value = (string) get_option('sd_default_subscription_display_price', '');
        echo '<input type="text" class="regular-text" name="sd_default_subscription_display_price" value="' . esc_attr($value) . '" />';
    }

    public static function sanitize_price_id($value): string {
        $value = sanitize_text_field((string) $value);

        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, 'price_')) {
            add_settings_error(
                'sd_default_stripe_subscription_price_id',
                'invalid_price_id',
                'Stripe price ID must start with price_.'
            );

            return (string) get_option('sd_default_stripe_subscription_price_id', '');
        }

        return $value;
    }

    public static function render_price_id_field(): void {
        $value = (string) get_option('sd_default_stripe_subscription_price_id', '');
        echo '<input type="text" class="regular-text" name="sd_default_stripe_subscription_price_id" value="' . esc_attr($value) . '" />';
    }

    public static function render_plan_label_field(): void {
        $value = (string) get_option('sd_default_subscription_plan_label', '');
        echo '<input type="text" class="regular-text" name="sd_default_subscription_plan_label" value="' . esc_attr($value) . '" />';
    }

    public static function render_display_price_field(): void {
        $value = (string) get_option('sd_default_subscription_display_price', '');
        echo '<input type="text" class="regular-text" name="sd_default_subscription_display_price" value="' . esc_attr($value) . '" />';
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
                'sd_prospect_token' => get_post_meta($post_id, self::META_PROSPECT_TOKEN, true),
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
            (string) get_post_meta($post_id, 'sd_prospect_token', true),
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

}
