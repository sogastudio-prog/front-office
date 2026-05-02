<?php
/**
 * Plugin Name:       SoloDrive Social (Internal)
 * Description:       Internal social media management for Sales, Marketing, and Customer Support.
 * Version:           0.1.3
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SD_SOCIAL_VERSION', '0.1.3');
define('SD_SOCIAL_PATH', plugin_dir_path(__FILE__));
define('SD_SOCIAL_URL', plugin_dir_url(__FILE__));

// Load classes FIRST - before any static calls
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-credentials.php';
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-publisher.php';
require_once SD_SOCIAL_PATH . 'includes/class-sd-social-google-oauth.php';

class SD_Social_Internal {

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        // Initialize classes
        SD_Social_Credentials::init();
        SD_Social_Publisher::init();
        SD_Social_Google_OAuth::init();

        add_action('admin_menu', [__CLASS__, 'add_admin_menu'], 99);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_sd_social_quick_publish', [__CLASS__, 'handle_quick_publish']);

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
    }

    public static function register_event_types(array $types): array {
        $types[] = 'SOCIAL_POST_PUBLISHED';
        $types[] = 'SOCIAL_ACCOUNT_CONNECTED';
        $types[] = 'GOOGLE_REVIEW_REPLIED';
        $types[] = 'META_COMMENT_REPLIED';
        $types[] = 'SOCIAL_CAMPAIGN_LAUNCHED';
        return $types;
    }

     /**
     * Handle Quick Publish form submission
     */
    public static function handle_quick_publish(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        check_admin_referer('sd_social_quick_publish');

        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $link    = esc_url_raw($_POST['link'] ?? '');

        if (empty($message)) {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_error=Message+required'));
            exit;
        }

        $post_data = [
            'message' => $message,
            'link'    => $link
        ];

        $result = self::publish_to_google($post_data);

        if ($result['success']) {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_success=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_error=' . urlencode($result['error'] ?? 'Unknown error')));
        }
        exit;
    }   
}

add_action('plugins_loaded', ['SD_Social_Internal', 'init']);