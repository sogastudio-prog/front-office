<?php
/**
 * Plugin Name:       SoloDrive Social (Internal)
 * Description:       Internal social media management for Sales, Marketing, and Customer Support. Google Business Profile + Meta integration.
 * Version:           0.1.0
 * Author:            SoloDrive Team
 * Text Domain:       solodrive-social
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SD_SOCIAL_VERSION', '0.1.0');
define('SD_SOCIAL_PATH', plugin_dir_path(__FILE__));
define('SD_SOCIAL_URL', plugin_dir_url(__FILE__));

class SD_Social_Internal {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        
        // Register Ledger Event Types
        add_filter('sd_time_space_event_types', [__CLASS__, 'register_event_types']);

        // Initialize core classes
        require_once SD_SOCIAL_PATH . 'includes/class-sd-social-credentials.php';
        require_once SD_SOCIAL_PATH . 'includes/class-sd-social-publisher.php';
        
        SD_Social_Credentials::init();
        SD_Social_Publisher::init();
    }

    public static function add_admin_menu(): void {
        add_submenu_page(
            'solodrive',                    // Parent slug (assuming SoloDrive main menu exists)
            'Social Management',
            'Social',
            'manage_options',               // Internal team only for now
            'solodrive-social',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'solodrive-social') === false) {
            return;
        }

        wp_enqueue_style('sd-ts-ledger-admin'); // Reuse existing ledger styles

        wp_add_inline_style('sd-ts-ledger-admin', '
            .sd-social-card { border: 1px solid #dcdcde; border-radius: 10px; padding: 20px; background: #fff; margin-bottom: 20px; }
            .sd-social-status { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; }
            .sd-social-status--connected { background: #edf9f0; color: #166534; }
            .sd-social-status--disconnected { background: #fef2f2; color: #991b1b; }
        ');
    }

    public static function render_admin_page(): void {
        ?>
        <div class="wrap">
            <h1>SoloDrive Social Management (Internal)</h1>
            <p>Manage official SoloDrive social profiles for marketing, sales, and support.</p>
            
            <div class="sd-ts-debug-grid">
                <?php do_action('sd_social_admin_tabs'); ?>
            </div>
        </div>
        <?php
    }

    public static function register_event_types(array $types): array {
        $types[] = 'SOCIAL_POST_PUBLISHED';
        $types[] = 'SOCIAL_ACCOUNT_CONNECTED';
        $types[] = 'GOOGLE_REVIEW_REPLIED';
        $types[] = 'META_COMMENT_REPLIED';
        $types[] = 'SOCIAL_CAMPAIGN_LAUNCHED';
        return $types;
    }
}

// Initialize the module
add_action('plugins_loaded', ['SD_Social_Internal', 'init']);