<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Social_Publisher
 * Base class for publishing to social platforms (Internal use)
 */
final class SD_Social_Publisher {

    public static function init(): void {
        // Register admin-post handlers for publishing later
    }

    /**
     * Publish content to Google Business Profile (Local Post)
     */
    public static function publish_to_google(array $post_data): array {
        $creds = SD_Social_Credentials::get('google');
        if (!$creds) {
            return ['success' => false, 'error' => 'Google not connected'];
        }

        // TODO: Implement Google Business Profile Local Posts API call
        // Use Google_Client or HTTP request with access token

        $result = ['success' => true, 'platform' => 'google', 'post_id' => 'temp-' . time()];

        self::log_to_ledger('SOCIAL_POST_PUBLISHED', [
            'platform' => 'google',
            'content'  => wp_trim_words($post_data['message'] ?? '', 50),
            'post_id'  => $result['post_id']
        ]);

        return $result;
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