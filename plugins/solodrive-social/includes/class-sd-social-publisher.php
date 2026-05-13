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
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');

        check_admin_referer('sd_social_quick_publish');

        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $link    = esc_url_raw($_POST['link'] ?? '');
        $target  = sanitize_text_field($_POST['target'] ?? 'google');

        if (empty($message)) {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_error=Message+is+required'));
            exit;
        }

        $post_data = ['message' => $message, 'link' => $link];
        $success = true;
        $errors = [];

        if (in_array($target, ['google', 'both'])) {
            $result = self::publish_to_google($post_data);
            if (!$result['success']) {
                $success = false;
                $errors[] = 'Google: ' . $result['error'];
            }
        }

        if (in_array($target, ['meta', 'both'])) {
            $result = self::publish_to_meta($post_data);
            if (!$result['success']) {
                $success = false;
                $errors[] = 'Meta: ' . $result['error'];
            }
        }

        if ($success) {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_success=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=solodrive-social&publish_error=' . urlencode(implode(', ', $errors))));
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
            $accountId = 'accounts/11276864927781004781';

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
     * Log social activity to canonical Time-Space Ledger
     */
    public static function log_to_ledger(string $event_type, array $payload): void {
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

    /**
     * Placeholder for future Meta publish
     */
    public static function publish_to_meta(array $post_data): array {
        $creds = SD_Social_Credentials::get('meta');
        if (!$creds || empty($creds['default_page']['access_token'])) {
            return ['success' => false, 'error' => 'Meta not connected or no Page selected'];
        }

        $page_id = $creds['default_page']['id'];
        $page_token = $creds['default_page']['access_token'];
        $message = trim($post_data['message'] ?? '');

        if (empty($message)) {
            return ['success' => false, 'error' => 'Message is required'];
        }

        $response = wp_remote_post("https://graph.facebook.com/v20.0/{$page_id}/feed", [
            'body' => [
                'message'      => $message,
                'access_token' => $page_token
            ]
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        self::log_to_ledger('SOCIAL_POST_PUBLISHED', [
            'platform' => 'meta',
            'page'     => $creds['default_page']['name'],
            'content'  => wp_trim_words($message, 80),
        ]);

        return ['success' => true];
    }
}