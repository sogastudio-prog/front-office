<?php
/**
 * Plugin Name: SoloDrive Content Tools
 * Description: WP-CLI sync tools and structured meta-first content architecture for SoloDrive.
 * Version: 0.2.0
 * Author: SoloDrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SDCT_VERSION', '0.2.0' );
define( 'SDCT_PLUGIN_FILE', __FILE__ );
define( 'SDCT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// ---- Core sync classes (existing) ----
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-content-repository.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-markdown.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-validator.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-cli.php';

// ---- Option B: Meta-first content architecture ----
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-post-types.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-meta-authority.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-templates.php';

// ---- Content pipeline (Path A) ----
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-cluster-reader.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-markdown-renderer.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-schema-builder.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-meta-builder.php';
require_once SDCT_PLUGIN_DIR . 'includes/class-sdct-wordpress-sync.php';

// ---- WP-CLI commands ----
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'solodrive content', 'SDCT_CLI_Command' );
	WP_CLI::add_command( 'sdct', 'SDCT_Sync_CLI_Command' );
}

// ---- Register CPTs ----
SDCT_Post_Types::register_all();

// ---- Register authority meta boxes ----
SDCT_Meta_Authority::register();

// ---- Register template routing + schema injection ----
SDCT_Templates::register();

/**
 * Add frontend body classes for source-controlled managed pages.
 *
 * Doctrine:
 * - WordPress post_title remains real for admin/search/routing.
 * - Managed pages control visible titles inside source content.
 * - Theme/Astra default page titles are hidden only for managed pages.
 */
add_filter( 'body_class', function ( $classes ) {
	if ( ! is_page() ) {
		return $classes;
	}

	$post = get_post();

	if ( ! $post ) {
		return $classes;
	}

	$content = (string) $post->post_content;

	if ( strpos( $content, 'sd-managed-page' ) === false ) {
		return $classes;
	}

	$classes[] = 'sd-has-managed-page';
	$classes[] = 'sd-hide-theme-title';

	if ( preg_match( '/sd-managed-page--([a-z0-9-]+)/', $content, $match ) ) {
		$managed_type = sanitize_html_class( $match[1] );
		$classes[]    = 'sd-managed-type-' . $managed_type;

		if ( $managed_type === 'utility' ) {
			$classes[] = 'sd-hide-theme-title';
		}
	}

	return array_unique( $classes );
} );

/**
 * Path A — inject meta tags and schema for pipeline-synced WP pages.
 *
 * Only fires for regular WP pages whose post_content contains sd-managed-page
 * (written by SDCT_Markdown_Renderer). sd_authority CPTs are handled by
 * SDCT_Templates::inject_meta_tags() and SDCT_Templates::inject_schema().
 */
add_action( 'wp_head', function () {
	if ( ! is_page() ) {
		return;
	}

	$post = get_post();
	if ( ! $post || strpos( (string) $post->post_content, 'sd-managed-page' ) === false ) {
		return;
	}

	$post_id     = $post->ID;
	$meta_title  = get_post_meta( $post_id, '_sdct_meta_title', true );
	$meta_desc   = get_post_meta( $post_id, '_sdct_meta_description', true );
	$canonical   = get_post_meta( $post_id, '_sdct_canonical_url', true );
	$og_image    = get_post_meta( $post_id, '_sdct_og_image', true );
	$schema_json = get_post_meta( $post_id, '_sdct_schema_json', true );

	if ( $meta_desc ) {
		echo '<meta name="description" content="' . esc_attr( $meta_desc ) . '">' . "\n";
	}

	if ( $canonical ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
	}

	if ( $meta_title ) {
		echo '<meta property="og:title" content="' . esc_attr( $meta_title ) . '">' . "\n";
	}

	if ( $og_image ) {
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
	}

	if ( $schema_json ) {
		echo '<script type="application/ld+json">' . "\n" . wp_kses( $schema_json, [] ) . "\n</script>\n";
	}
}, 5 );
