<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Social_Credentials
 * Secure storage for Google & Meta OAuth tokens (Internal)
 */
final class SD_Social_Credentials {

    private const OPTION_GOOGLE = 'sd_social_google_credentials';
    private const OPTION_META   = 'sd_social_meta_credentials';

    public static function init(): void {
        // No legacy connect handler needed
        add_action('admin_post_sd_social_disconnect', [__CLASS__, 'handle_disconnect']);
    }

    /**
     * Store credentials securely
     */
    public static function save(string $platform, array $data): bool {
        $json = wp_json_encode($data);
        $encrypted = self::encrypt($json);
        
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        return update_option($option_key, $encrypted, false);
    }

    public static function get(string $platform): ?array {
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        $encrypted = get_option($option_key);

        if (empty($encrypted)) {
            return null;
        }

        $decrypted = self::decrypt($encrypted);
        $data = json_decode($decrypted, true);

        return is_array($data) ? $data : null;
    }

    private static function encrypt(string $data): string {
        $key = defined('SD_SOCIAL_ENCRYPTION_KEY') ? SD_SOCIAL_ENCRYPTION_KEY : wp_salt('auth');
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private static function decrypt(string $data): string {
        $key = defined('SD_SOCIAL_ENCRYPTION_KEY') ? SD_SOCIAL_ENCRYPTION_KEY : wp_salt('auth');
        $decoded = base64_decode($data);
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    public static function is_connected(string $platform): bool {
        return !empty(self::get($platform));
    }

    public static function disconnect(string $platform): bool {
        $option_key = $platform === 'google' ? self::OPTION_GOOGLE : self::OPTION_META;
        return delete_option($option_key);
    }

    public static function handle_disconnect(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $platform = isset($_GET['platform']) ? sanitize_text_field($_GET['platform']) : '';
        if (!in_array($platform, ['google', 'meta'], true)) {
            wp_die('Invalid platform.');
        }

        self::disconnect($platform);

        wp_redirect(admin_url('admin.php?page=solodrive-social&' . $platform . '_disconnected=1'));
        exit;
    }
}