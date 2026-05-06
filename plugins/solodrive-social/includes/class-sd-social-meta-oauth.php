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

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        // State validation
        $received_state = $_GET['state'] ?? '';
        $stored_state   = get_transient('sd_social_meta_oauth_state');
        if (empty($received_state) || $received_state !== $stored_state) {
            delete_transient('sd_social_meta_oauth_state');
            wp_die('Security check failed.');
        }
        delete_transient('sd_social_meta_oauth_state');

        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            wp_die('Authorization code missing.');
        }

        $client_id     = defined('SD_META_APP_ID') ? SD_META_APP_ID : '';
        $client_secret = defined('SD_META_APP_SECRET') ? SD_META_APP_SECRET : '';

        if (empty($client_secret)) {
            wp_die('Meta App Secret missing in wp-config.php');
        }

        // Exchange code for User Access Token
        $token_response = wp_remote_post('https://graph.facebook.com/v20.0/oauth/access_token', [
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => admin_url('admin-post.php?action=sd_social_meta_callback'),
                'code'          => $code,
            ]
        ]);

        $token_body = wp_remote_retrieve_body($token_response);
        $token_data = json_decode($token_body, true);

        if (empty($token_data['access_token'])) {
            error_log('Meta Token Error: ' . $token_body);
            wp_die('Failed to get access token.');
        }

        $user_token = $token_data['access_token'];

        // Get Long-Lived Token
        $long_url = "https://graph.facebook.com/v20.0/oauth/access_token?grant_type=fb_exchange_token&client_id={$client_id}&client_secret={$client_secret}&fb_exchange_token={$user_token}";
        $long_response = wp_remote_get($long_url);
        $long_data = json_decode(wp_remote_retrieve_body($long_response), true);

        $long_token = $long_data['access_token'] ?? $user_token;

        // Fetch Managed Pages
        $pages_response = wp_remote_get("https://graph.facebook.com/v20.0/me/accounts?access_token=" . urlencode($long_token));
        $pages_data = json_decode(wp_remote_retrieve_body($pages_response), true);

        $pages = [];
        if (!empty($pages_data['data'])) {
            foreach ($pages_data['data'] as $page) {
                $pages[] = [
                    'id'           => $page['id'],
                    'name'         => $page['name'],
                    'access_token' => $page['access_token'],
                ];
            }
        }

        // Save credentials (first page as default for now)
        $default_page = $pages[0] ?? null;

        $credentials = [
            'access_token' => $long_token,
            'pages'        => $pages,
            'default_page' => $default_page,
            'connected_at' => time(),
        ];

        $saved = SD_Social_Credentials::save('meta', $credentials);

        if ($saved && $default_page) {
            SD_Social_Publisher::log_to_ledger('SOCIAL_ACCOUNT_CONNECTED', [
                'platform' => 'meta',
                'page_name'=> $default_page['name'],
                'status'   => 'connected'
            ]);

            wp_redirect(admin_url('admin.php?page=solodrive-social&meta_connected=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=solodrive-social&meta_error=1'));
        }
        exit;
    }
}