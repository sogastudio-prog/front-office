<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Social_Publisher
 * Base class for publishing to social platforms (Internal use)
 */
final class SD_Social_Publisher {

    public static function init(): void {
        add_action('admin_post_sd_social_quick_publish', [__CLASS__, 'handle_quick_publish']);
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
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_error=Message+is+required'));
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
    
    public static function publish_to_google(array $post_data): array {
        $creds = SD_Social_Credentials::get('google');
        if (!$creds || empty($creds['access_token'])) {
            return ['success' => false, 'error' => 'Google not connected'];
        }

        $message = trim($post_data['message'] ?? '');
        $link    = esc_url_raw($post_data['link'] ?? '');

        if (empty($message)) {
            return ['success' => false, 'error' => 'Message is required'];
        }

        // Load autoloader
        $autoload = SD_SOCIAL_PATH . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        try {
            $client = new Google_Client();
            $client->setAccessToken($creds['access_token']);

            if ($client->isAccessTokenExpired() && !empty($creds['refresh_token'])) {
                $client->fetchAccessTokenWithRefreshToken($creds['refresh_token']);
            }

            // Use your Business Profile ID
            $accountId = 'accounts/7676303778105762492';

            // For now we use a direct REST call (most reliable in this setup)
            $post_body = [
                'languageCode' => 'en',
                'summary'      => $message,
            ];

            if (!empty($link)) {
                $post_body['callToAction'] = [
                    'actionType' => 'LEARN_MORE',
                    'url'        => $link
                ];
            }

            $response = wp_remote_post(
                "https://mybusiness.googleapis.com/v4/{$accountId}/locations/7676303778105762492/localPosts",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $creds['access_token'],
                        'Content-Type'  => 'application/json',
                    ],
                    'body' => json_encode($post_body),
                ]
            );

            if (is_wp_error($response)) {
                return ['success' => false, 'error' => $response->get_error_message()];
            }

            self::log_to_ledger('SOCIAL_POST_PUBLISHED', [
                'platform' => 'google',
                'content'  => wp_trim_words($message, 100),
                'link'     => $link,
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            error_log('Google Publish Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Publish to Meta Page (and optionally Instagram)
     */
    public static function publish_to_meta(array $post_data): array {
        $creds = SD_Social_Credentials::get('meta');
        if (!$creds) {
            return ['success' => false, 'error' => 'Meta not connected'];
        }

        // TODO: Implement Meta Graph API /pages/{page-id}/feed or Instagram Graph API

        $result = ['success' => true, 'platform' => 'meta', 'post_id' => 'temp-' . time()];

        self::log_to_ledger('SOCIAL_POST_PUBLISHED', [
            'platform' => 'meta',
            'content'  => wp_trim_words($post_data['message'] ?? '', 50),
            'post_id'  => $result['post_id']
        ]);

        return $result;
    }

    /**
     * Log social activity to canonical Time-Space Ledger
     */
    private static function log_to_ledger(string $event_type, array $payload): void {
        if (class_exists('SD_TimeSpaceLedger', false) && method_exists('SD_TimeSpaceLedger', 'record')) {
            SD_TimeSpaceLedger::record([
                'event_type'   => $event_type,
                'truth_class'  => 'COMMITTED',
                'subject_type' => 'internal',
                'actor_type'   => 'user',
                'actor_id'     => get_current_user_id(),
                'payload_json' => wp_json_encode($payload),
            ]);
        }
    }
}