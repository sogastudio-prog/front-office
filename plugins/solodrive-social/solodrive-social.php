<?php
/**
 * Plugin Name:       SoloDrive Social (Internal)
 * Description:       Internal social media management for Sales, Marketing, and Customer Support.
 * Version:           0.1.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SD_SOCIAL_VERSION', '0.1.4');
define('SD_SOCIAL_PATH', plugin_dir_path(__FILE__));
define('SD_SOCIAL_URL', plugin_dir_url(__FILE__));

// Load classes FIRST
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-credentials.php';
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-publisher.php';
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-google-oauth.php';
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-meta-oauth.php';

// Robust Composer autoload (matching your front-office pattern)
$autoload_path = SD_SOCIAL_PATH . 'vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    error_log('SoloDrive Social: Autoload not found at ' . $autoload_path);
}

class SD_Social_Internal {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        SD_Social_Credentials::init();
        SD_Social_Publisher::init();
        SD_Social_Google_OAuth::init();
        SD_Social_Meta_OAuth::init();

        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 99);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Ledger events
        add_filter('sd_time_space_event_types', [__CLASS__, 'register_event_types']);
    }

    public static function add_admin_menu(): void {
        $parent = menu_page_url('solodrive', false) ? 'solodrive' : '';

        add_menu_page(
            'Social Management',
            'Social',
            'manage_options',
            'solodrive-social',
            [__CLASS__, 'render_admin_page'],
            'dashicons-share',
            80
        );

        if ($parent) {
            add_submenu_page(
                $parent,
                'Social Management',
                'Social',
                'manage_options',
                'solodrive-social',
                [__CLASS__, 'render_admin_page']
            );
        }
    }

    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'solodrive-social') === false) {
            return;
        }
        wp_enqueue_style('sd-ts-ledger-admin');
    }

    public static function render_admin_page(): void {
        include SD_SOCIAL_PATH . 'includes/admin/social-connections.php';
        include SD_SOCIAL_PATH . 'includes/admin/quick-publish.php';
        include SD_SOCIAL_PATH . 'includes/admin/location-diagnostic.php';   // ← Add this
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

add_action('plugins_loaded', ['SD_Social_Internal', 'init']);