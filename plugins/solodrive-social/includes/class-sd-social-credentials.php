<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Social_Credentials
 * Handles secure storage and retrieval of platform OAuth tokens (Internal use only)
 */
final class SD_Social_Credentials {

    private const OPTION_GOOGLE = 'sd_social_google_credentials';
    private const OPTION_META   = 'sd_social_meta_credentials';

    public static function init(): void {
        add_action('admin_post_sd_social_connect_google', [__CLASS__, 'handle_google_connect']);
        add_action('admin_post_sd_social_connect_meta', [__CLASS__, 'handle_meta_connect']);
    }

    /**
     * Store encrypted credentials
     */
    public static function save(string $platform, array $data): bool {
        $encrypted = wp_encrypt(json_encode($data));
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        
        return update_option($option_key, $encrypted, false);
    }

    /**
     * Retrieve and decrypt credentials
     */
    public static function get(string $platform): ?array {
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        $encrypted = get_option($option_key);

        if (empty($encrypted)) {
            return null;
        }

        $decrypted = wp_decrypt($encrypted);
        $data = json_decode($decrypted, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Check if platform is connected
     */
    public static function is_connected(string $platform): bool {
        return !empty(self::get($platform));
    }

    /**
     * Delete credentials (disconnect)
     */
    public static function disconnect(string $platform): bool {
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        return delete_option($option_key);
    }

    // TODO: Add token refresh logic (especially for Meta long-lived tokens)
}