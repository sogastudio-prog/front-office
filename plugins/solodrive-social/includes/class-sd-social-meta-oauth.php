<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Social_Meta_OAuth')) {
    return;
}

final class SD_Social_Meta_OAuth {

    public static function init(): void {
        add_action('admin_post_sd_social_connect_meta', [__CLASS__, 'start_oauth_flow']);
        // Callback will be added next
    }

    public static function start_oauth_flow(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        check_admin_referer('sd_social_connect_meta');

        // TODO: Meta OAuth URL construction (Facebook Login for Business)
        // For now, placeholder redirect with success
        SD_Social_Publisher::log_to_ledger('SOCIAL_ACCOUNT_CONNECTED', [
            'platform' => 'meta',
            'status'   => 'oauth_started'
        ]);

        wp_redirect(admin_url('admin.php?page=solodrive-social&meta_connected=1'));
        exit;
    }
}