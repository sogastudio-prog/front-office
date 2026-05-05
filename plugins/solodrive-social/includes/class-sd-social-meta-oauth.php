<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Social_Meta_OAuth')) {
    return;
}

final class SD_Social_Meta_OAuth {

    public static function init(): void {
        add_action('admin_post_sd_social_connect_meta', [__CLASS__, 'start_oauth_flow']);
        add_action('admin_post_sd_social_meta_callback', [__CLASS__, 'handle_callback']);
        
        error_log('SD_Social_Meta_OAuth: Handlers registered');
    }

    public static function start_oauth_flow(): void {
        error_log('=== SD_Meta_OAuth: start_oauth_flow triggered ===');
        
        if (!current_user_can('manage_options')) {
            error_log('Meta OAuth: Insufficient permissions');
            wp_die('Insufficient permissions.');
        }

        check_admin_referer('sd_social_connect_meta');
        error_log('Meta OAuth: Nonce check passed');

        $client_id = defined('SD_META_APP_ID') ? SD_META_APP_ID : '';
        error_log('Meta OAuth: Using App ID: ' . substr($client_id, 0, 8) . '...');

        if (empty($client_id)) {
            wp_die('Meta App ID not configured.');
        }

        $redirect_uri = admin_url('admin-post.php?action=sd_social_meta_callback');
        $state = wp_create_nonce('meta_oauth_state');
        set_transient('sd_social_meta_oauth_state', $state, 600);

        // Minimal working scopes for development
        $scopes = 'pages_show_list,pages_read_engagement,pages_manage_metadata';

        $auth_url = 'https://www.facebook.com/v20.0/dialog/oauth?' . http_build_query([
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => $scopes,
            'state'         => $state,
            'response_type' => 'code',
        ]);

        error_log('Meta OAuth: Redirecting to: ' . $auth_url);
        wp_redirect($auth_url);
        exit;
    }

    public static function handle_callback(): void {
        error_log('=== SD_Meta_OAuth: Callback received ===');
        // ... (rest of your callback code remains the same)
        // (I kept it unchanged for brevity — just add error_log lines similarly if needed)
    }
}