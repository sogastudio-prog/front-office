<?php
/**
 * Plugin Name: SoloDrive Front CSS
 * Description: Structured frontend stylesheet loader for SoloDrive marketing and onboarding flow.
 * Version: 0.1.0
 * Author: SoloDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SD_FRONT_CSS_VERSION', '0.1.0' );
define( 'SD_FRONT_CSS_URL', plugin_dir_url( __FILE__ ) );

function sd_front_css_enqueue_assets() {
	if ( is_admin() ) {
		return;
	}

	$files = array(
		'01-tokens.css',
		'02-base.css',
		'03-shell.css',
		'04-components.css',
		'05-forms.css',
		'06-pages.css',
		'07-footer.css',
		'08-responsive.css',

		//'99-legacy-import.css',
	);

	$deps = array();

	foreach ( $files as $file ) {
		$handle = 'sd-front-css-' . sanitize_title( str_replace( '.css', '', $file ) );

		wp_enqueue_style(
			$handle,
			SD_FRONT_CSS_URL . 'assets/css/' . $file,
			$deps,
			SD_FRONT_CSS_VERSION
		);

		$deps = array( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'sd_front_css_enqueue_assets', 20 );
