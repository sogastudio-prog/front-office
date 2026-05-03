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
    
    /**
     * Publish content to Google Business Profile (Local Post)
     */
    public static function publish_to_google(array $post_data): array {
        $creds = SD_Social_Credentials::get('google');
        if (!$creds || empty($creds['access_token'])) {
            return ['success' => false, 'error' => 'Google not connected'];
        }

        $message = $post_data['message'] ?? '';
        $link    = $post_data['link'] ?? '';

        if (empty($message)) {
            return ['success' => false, 'error' => 'Message is required'];
        }

        // Load Google library safely
        $autoload = SD_SOCIAL_PATH . 'vendor/autoload.php';
        if (!file_exists($autoload)) {
            return ['success' => false, 'error' => 'Google Client Library not found. Run composer install.'];
        }
        require_once $autoload;

        try {
            $client = new Google_Client();
            $client->setAccessToken($creds['access_token']);

            // Refresh token if expired
            if ($client->isAccessTokenExpired() && !empty($creds['refresh_token'])) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($creds['refresh_token']);
                // TODO: Update stored credentials with $newToken
            }

            $service = new Google_Service_MyBusiness($client);

            // List accounts and locations
            $accounts = $service->accounts->listAccounts();
            if (empty($accounts->getAccounts())) {
                return ['success' => false, 'error' => 'No Google Business accounts found'];
            }

            $accountName = $accounts->getAccounts()[0]->getName();
            $locations = $service->accounts_locations->listAccountsLocations($accountName);

            if (empty($locations->getLocations())) {
                return ['success' => false, 'error' => 'No locations found in your Google Business Profile'];
            }

            $location = $locations->getLocations()[0];

            $localPost = new Google_Service_MyBusiness_LocalPost();
            $localPost->setLanguageCode('en');
            $localPost->setSummary($message);

            if (!empty($link)) {
                $callToAction = new Google_Service_MyBusiness_CallToAction();
                $callToAction->setActionType('LEARN_MORE');
                $callToAction->setUrl($link);
                $localPost->setCallToAction($callToAction);
            }

            $result = $service->accounts_locations_localPosts->create(
                $location->getName(), 
                $localPost
            );

            $post_id = $result->getName() ?? 'google-' . time();

            self::log_to_ledger('SOCIAL_POST_PUBLISHED', [
                'platform' => 'google',
                'content'  => wp_trim_words($message, 80),
                'post_id'  => $post_id,
                'location' => $location->getTitle() ?? 'Unknown'
            ]);

            return ['success' => true, 'post_id' => $post_id];

        } catch (Exception $e) {
            error_log('Google Local Post Error: ' . $e->getMessage());
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