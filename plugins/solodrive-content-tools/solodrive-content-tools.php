<?php
/**
 * Plugin Name: SoloDrive Content Tools
 * Description: WP-CLI tools for syncing SoloDrive website content from repository files into WordPress.
 * Version: 0.1.0
 * Author: SoloDrive
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SDCT_VERSION', '0.1.0');
define('SDCT_PLUGIN_FILE', __FILE__);
define('SDCT_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-content-repository.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-markdown.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-validator.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-cli.php';

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('solodrive content', 'SDCT_CLI_Command');
}

/**
 * Add frontend body classes for source-controlled managed pages.
 *
 * Doctrine:
 * - WordPress post_title remains real for admin/search/routing.
 * - Managed pages control visible titles inside source content.
 * - Theme/Astra default page titles are hidden only for managed pages.
 */
add_filter('body_class', function ($classes) {
    if (!is_page()) {
        return $classes;
    }

    $post = get_post();

    if (!$post) {
        return $classes;
    }

    $content = (string) $post->post_content;

    if (strpos($content, 'sd-managed-page') === false) {
        return $classes;
    }

    $classes[] = 'sd-has-managed-page';
    $classes[] = 'sd-hide-theme-title';

    if (preg_match('/sd-managed-page--([a-z0-9-]+)/', $content, $match)) {
        $managed_type = sanitize_html_class($match[1]);
        $classes[] = 'sd-managed-type-' . $managed_type;

        if ($managed_type === 'utility') {
            $classes[] = 'sd-hide-theme-title';
        }
    }

    return array_unique($classes);
});
