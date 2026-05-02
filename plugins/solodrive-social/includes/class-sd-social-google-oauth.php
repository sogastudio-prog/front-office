<?php
if (!defined('ABSPATH')) { exit; }

// Prevent re-declaration
if (class_exists('SD_Social_Google_OAuth')) {
    return;
}

final class SD_Social_Google_OAuth {

    private const REDIRECT_ACTION = 'sd_social_google_callback';

    public static function init(): void {
        add_action('admin_post_sd_social_connect_google', [__CLASS__, 'start_oauth_flow']);
        add_action('admin_post_' . self::REDIRECT_ACTION, [__CLASS__, 'handle_callback']);
    }

    public static function start_oauth_flow(): void {
        check_admin_referer('sd_social_connect_google');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $client = self::get_google_client_safe();
        if (is_wp_error($client)) {
            wp_die($client->get_error_message());
        }

        $client->addScope('https://www.googleapis.com/auth/business.manage');
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');

        $auth_url = $client->createAuthUrl();

        set_transient('sd_social_google_oauth_state', wp_create_nonce('google_oauth_state'), 600);

        wp_redirect($auth_url);
        exit;
    }

    public static function handle_callback(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        // Debug: Log what we received
        error_log('Google OAuth Callback - GET: ' . print_r($_GET, true));

        $state = $_GET['state'] ?? '';
        $stored_state = get_transient('sd_social_google_oauth_state');

        if (empty($state) || empty($stored_state) || !wp_verify_nonce($state, 'google_oauth_state')) {
            error_log('State mismatch - Received: ' . $state . ' | Stored: ' . $stored_state);
            wp_die('Security check failed. Please try connecting again.');
        }

        delete_transient('sd_social_google_oauth_state'); // Clean up

        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            wp_die('Authorization code missing.');
        }

        $client = self::get_google_client_safe();
        if (is_wp_error($client)) {
            wp_die($client->get_error_message());
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            wp_die('Token exchange failed: ' . esc_html($token['error']));
        }

        $client->setAccessToken($token);
        $oauth2 = new Google_Service_Oauth2($client);
        $userInfo = $oauth2->userinfo->get();

        $data = [
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_in'    => $token['expires_in'] ?? 3600,
            'created'       => time(),
            'email'         => $userInfo->getEmail() ?? '',
        ];

        $saved = SD_Social_Credentials::save('google', $data);

        if ($saved) {
            SD_Social_Publisher::log_to_ledger('SOCIAL_ACCOUNT_CONNECTED', [
                'platform' => 'google',
                'email'    => $userInfo->getEmail() ?? 'unknown'
            ]);

            $redirect = admin_url('admin.php?page=solodrive-social&google_connected=1&success=1');
        } else {
            $redirect = admin_url('admin.php?page=solodrive-social&google_error=1');
        }

        wp_redirect($redirect);
        exit;
    }

    /**
     * Hardened Google Client loader with multiple fallback paths
     */
    private static function get_google_client_safe() {
        $possible_paths = [
            SD_SOCIAL_PATH . 'vendor/autoload.php',
            '/home/u995421351/domains/solodrive.pro/public_html/git-deploy/front-office/plugins/solodrive-social/vendor/autoload.php',
            plugin_dir_path(__FILE__) . '../../vendor/autoload.php',
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('Google_Client')) {
                    $client = new Google_Client();
                    $client->setClientId(SD_GOOGLE_SOCIAL_CLIENT_ID);
                    $client->setClientSecret(SD_GOOGLE_SOCIAL_CLIENT_SECRET);
                    $client->setRedirectUri(admin_url('admin-post.php?action=' . self::REDIRECT_ACTION));
                    $client->setAccessType('offline');
                    $client->setPrompt('consent');
                    return $client;
                }
            }
        }

        return new WP_Error('google_lib_missing', 'Google Client Library not found. Paths checked:<br>' . implode('<br>', $possible_paths));
    }
}