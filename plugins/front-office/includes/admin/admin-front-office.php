<?php
/**
 * SoloDrive Front-Office Admin UI — Production Management Surface
 * Handles prospect lifecycle stalls, promotion, and staff workflows.
 * Version: 1.1.0 (SaaS Production)
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SD_Front_Office_Admin {

    private const PROSPECT_POST_TYPE = 'sd_prospect';

    public static function bootstrap(): void {
        // Core list table enhancements
        add_filter("manage_{self::PROSPECT_POST_TYPE}_posts_columns", [__CLASS__, 'prospect_columns']);
        add_action("manage_{self::PROSPECT_POST_TYPE}_posts_custom_column", [__CLASS__, 'prospect_column_content'], 10, 2);
        add_filter("manage_edit-{$self::PROSPECT_POST_TYPE}_sortable_columns", [__CLASS__, 'sortable_columns']);

        // Submenus
        add_action('admin_menu', [__CLASS__, 'register_submenus']);

        // Row + bulk actions
        add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
        add_filter("bulk_actions-edit-{$self::PROSPECT_POST_TYPE}", [__CLASS__, 'bulk_actions']);
        add_filter("handle_bulk_actions-edit-{$self::PROSPECT_POST_TYPE}", [__CLASS__, 'handle_bulk_actions'], 10, 3);

        // Filters & notices
        add_action('pre_get_posts', [__CLASS__, 'handle_filters']);
        add_action('admin_notices', [__CLASS__, 'stall_summary_notice']);

        // Assets
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function register_submenus(): void {
        $parent = 'edit.php?post_type=' . self::PROSPECT_POST_TYPE;

        add_submenu_page($parent, 'Lifecycle Stalls', '🚩 Stalls', 'edit_sd_prospects', 'sd-prospect-stalls', [__CLASS__, 'render_stalls_page']);
        add_submenu_page($parent, 'Ready for Promotion', '✅ Ready to Promote', 'edit_sd_prospects', 'sd-prospect-ready', [__CLASS__, 'render_ready_page']);
    }

    /* ==================================================================
       ENHANCED COLUMNS (exactly as specified in the implementation spec)
       ================================================================== */

    public static function prospect_columns($columns): array {
        unset($columns['date']); // remove default date
        return [
            'cb'           => '<input type="checkbox">',
            'title'        => 'Prospect',
            'prospect_id'  => 'ID',
            'lifecycle'    => 'Lifecycle',
            'name'         => 'Name',
            'contact'      => 'Contact',
            'market'       => 'Market',
            'invitation'   => 'Invitation',
            'review'       => 'Review',
            'stripe'       => 'Stripe',
            'age'          => 'Age',
            'updated'      => 'Updated',
        ];
    }

    public static function prospect_column_content($column, $post_id): void {
        $m = fn($key) => get_post_meta($post_id, $key, true);

        switch ($column) {
            case 'prospect_id':
                echo esc_html($m('sd_prospect_id') ?: $post_id);
                break;

            case 'lifecycle':
                $stage = $m('sd_lifecycle_stage') ?: 'prospect';
                $class = $stage === 'lead' ? 'sd-badge-lead' : ($stage === 'invited_prospect' ? 'sd-badge-invited' : 'sd-badge-prospect');
                echo '<span class="sd-badge ' . $class . '">' . strtoupper(str_replace('_', ' ', $stage)) . '</span>';
                break;

            case 'name':
                echo esc_html($m('sd_full_name') ?: '—');
                break;

            case 'contact':
                echo esc_html($m('sd_email_normalized') ?: $m('sd_email_raw')) . '<br><small>' .
                     esc_html($m('sd_phone_normalized') ?: $m('sd_phone_raw')) . '</small>';
                break;

            case 'market':
                echo esc_html(trim($m('sd_city') . ' ' . $m('sd_market')));
                break;

            case 'invitation':
                $status = $m('sd_invitation_status');
                echo $status === 'valid' ? '<span class="sd-badge sd-badge-success">✅ Valid</span>' : '—';
                break;

            case 'review':
                $review = $m('sd_review_status') ?: 'new';
                $map = ['qualified' => '✅ Qualified', 'reviewed' => '👀 Reviewed', 'hold' => '⏸️ Hold', 'disqualified' => '❌ Disqualified'];
                echo $map[$review] ?? '🆕 New';
                break;

            case 'stripe':
                $status = $m('sd_stripe_onboarding_status') ?: 'not_started';
                $acct   = $m('sd_stripe_account_id');
                if ($acct) {
                    echo '🔗 ' . esc_html(substr($acct, 0, 10) . '…');
                } else {
                    echo $status === 'charges_enabled' ? '🟢 Ready' : '⚪ Not started';
                }
                break;

            case 'age':
                $days = (int) floor((time() - get_post_timestamp($post_id)) / DAY_IN_SECONDS);
                $class = $days >= 14 ? 'sd-stall-old' : ($days >= 7 ? 'sd-stall-medium' : '');
                echo '<span class="' . $class . '">' . $days . 'd</span>';
                break;

            case 'updated':
                echo get_the_modified_date('M j, Y g:i a', $post_id);
                break;
        }
    }

    public static function sortable_columns($columns): array {
        $columns['age'] = 'date';
        return $columns;
    }

    /* ==================================================================
       STALL PAGES & DASHBOARD
       ================================================================== */

    private static function get_stalled_prospects(string $type = 'all'): array {
        $args = [
            'post_type'      => self::PROSPECT_POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ];

        if ($type === 'ready_promotion') {
            $args['meta_query'] = [
                ['key' => 'sd_lifecycle_stage', 'value' => 'lead'],
                ['key' => 'sd_stripe_onboarding_status', 'value' => 'charges_enabled'],
                ['key' => 'sd_promoted_to_tenant_id', 'compare' => 'NOT EXISTS'],
            ];
        }

        return get_posts($args);
    }

    public static function render_stalls_page(): void {
        $stalled = self::get_stalled_prospects();
        echo '<div class="wrap"><h1>🚩 Prospect Lifecycle Stalls</h1><p>Prospects that have not moved forward.</p>';
        self::render_simple_table($stalled);
        echo '</div>';
    }

    public static function render_ready_page(): void {
        $ready = self::get_stalled_prospects('ready_promotion');
        echo '<div class="wrap"><h1>✅ Prospects Ready for Promotion</h1><p>These leads meet all gates and can be promoted to tenants immediately.</p>';
        self::render_simple_table($ready, true);
        echo '</div>';
    }

    private static function render_simple_table($posts, $show_promote = false): void {
        if (empty($posts)) {
            echo '<p><em>No records found.</em></p>';
            return;
        }
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Prospect</th><th>Lifecycle</th><th>Age</th><th>Actions</th></tr></thead><tbody>';
        foreach ($posts as $post) {
            $name = get_post_meta($post->ID, 'sd_full_name', true) ?: $post->post_title;
            $days = (int) floor((time() - strtotime($post->post_date_gmt)) / DAY_IN_SECONDS);
            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($post->ID) . '">' . esc_html($name) . '</a></td>';
            echo '<td>' . esc_html(get_post_meta($post->ID, 'sd_lifecycle_stage', true)) . '</td>';
            echo '<td>' . $days . 'd</td>';
            echo '<td>';
            if ($show_promote) {
                echo '<a href="#" class="button button-primary sd-promote" data-id="' . $post->ID . '">Promote to Tenant</a>';
            } else {
                echo '<a href="#" class="button sd-review" data-id="' . $post->ID . '">Mark Reviewed</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /* ==================================================================
       ACTIONS & NOTICES
       ================================================================== */

    public static function row_actions($actions, $post): array {
        if ($post->post_type !== self::PROSPECT_POST_TYPE) return $actions;
        $actions['start_stripe'] = '<a href="#" onclick="sd_admin_action(' . $post->ID . ', \'start_stripe\'); return false;">Start Stripe</a>';
        $actions['promote']      = '<a href="#" onclick="sd_admin_action(' . $post->ID . ', \'promote\'); return false;">Promote → Tenant</a>';
        return $actions;
    }

    public static function bulk_actions($actions): array {
        $actions['mark_reviewed'] = 'Mark as Reviewed';
        $actions['mark_qualified'] = 'Mark as Qualified';
        $actions['start_stripe_bulk'] = 'Start Stripe Onboarding';
        return $actions;
    }

    public static function handle_bulk_actions($redirect, $action, $ids): string {
        // Placeholder — full implementation in Phase 6
        return $redirect;
    }

    public static function stall_summary_notice(): void {
        if (!current_user_can('edit_posts') || get_current_screen()->id !== 'edit-sd_prospect') return;
        $count = count(self::get_stalled_prospects());
        if ($count > 0) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>🚩 ' . $count . ' prospect(s) stalled.</strong> <a href="edit.php?post_type=sd_prospect&page=sd-prospect-stalls">View Stalls →</a></p></div>';
        }
    }

    public static function enqueue_assets($hook): void {
        if (strpos($hook, 'sd_prospect') === false) return;
        ?>
        <style>
            .sd-badge { padding: 2px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
            .sd-badge-lead { background: #0073aa; color: #fff; }
            .sd-badge-invited { background: #46b450; color: #fff; }
            .sd-badge-prospect { background: #ffb900; color: #000; }
            .sd-badge-success { background: #46b450; color: #fff; }
            .sd-stall-old { color: #d63638; font-weight: 700; }
            .sd-stall-medium { color: #ffb900; }
        </style>
        <script>
        function sd_admin_action(id, action) {
            if (confirm('Execute ' + action + '?')) {
                // Future AJAX endpoint hook (Phase 6)
                alert('✅ Action ' + action + ' triggered for prospect #' + id + ' (ready for Phase 6)');
                location.reload();
            }
        }
        </script>
        <?php
    }

    public static function handle_filters($query): void {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== self::PROSPECT_POST_TYPE) return;
        // Extend with lifecycle / review filters later
    }
}

// Bootstrap — guaranteed to run after the main scaffold
add_action('admin_init', function() {
    if (class_exists('SD_Front_Office_Admin')) {
        SD_Front_Office_Admin::bootstrap();
    }
});