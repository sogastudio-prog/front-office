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
