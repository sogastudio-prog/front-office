<?php
/**
 * SDCT Post Type Registration
 *
 * Registers sd_authority, sd_conversion, and sd_landing CPTs.
 *
 * These CPTs replace the post_type=page model for all SoloDrive content.
 * post_content is intentionally left empty for all three types.
 * Templates own rendering. Meta fields own content.
 *
 * Rewrite note: after activating this file, visit Settings → Permalinks
 * and click Save to flush rewrite rules.
 *
 * URL structure:
 *   sd_authority  → /page-slug/         (root, same as existing pages)
 *   sd_conversion → /page-slug/         (root)
 *   sd_landing    → /l/campaign-slug/   (prefixed to avoid conflicts)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Post_Types {

	/**
	 * Hook all three CPT registrations into init.
	 * Called once from the plugin entry point.
	 */
	public static function register_all() {
		add_action( 'init', [ __CLASS__, 'register_authority' ], 10 );
		add_action( 'init', [ __CLASS__, 'register_conversion' ], 10 );
		add_action( 'init', [ __CLASS__, 'register_landing' ], 10 );
	}

	// -------------------------------------------------------------------------
	// sd_authority
	// Long-form, machine-first, SEO + AI retrieval pages.
	// 95% of content volume. One topic per page.
	// -------------------------------------------------------------------------
	public static function register_authority() {
		register_post_type(
			'sd_authority',
			[
				'labels'             => [
					'name'               => 'Authority Pages',
					'singular_name'      => 'Authority Page',
					'add_new'            => 'Add New',
					'add_new_item'       => 'Add New Authority Page',
					'edit_item'          => 'Edit Authority Page',
					'new_item'           => 'New Authority Page',
					'view_item'          => 'View Authority Page',
					'search_items'       => 'Search Authority Pages',
					'not_found'          => 'No authority pages found.',
					'not_found_in_trash' => 'No authority pages in trash.',
					'menu_name'          => 'Authority',
					'all_items'          => 'All Authority Pages',
				],
				'description'        => 'Long-form machine-first content for SEO and AI retrieval.',
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false, // Block editor OFF. Template owns rendering.
				'has_archive'        => false,
				'hierarchical'       => false,
				// Rewrite disabled: sd_authority content is absorbed into native WP pages
				// via the repo markdown workflow (Content-Management-System.md).
				// A root slug of '/' generates a broad catch-all rewrite rule that
				// intercepts all single-segment URLs and breaks native page routing.
				'rewrite'            => false,
				'supports'           => [ 'title' ], // Only title. No editor. No excerpt.
				'menu_icon'          => 'dashicons-text-page',
				'menu_position'      => 20,
				'capability_type'    => 'page',
				'map_meta_cap'       => true,
			]
		);
	}

	// -------------------------------------------------------------------------
	// sd_conversion
	// Short funnel pages: /start/, /pricing/, /solution/, /request-access/
	// Action-oriented. Shortcode embeds supported via body blocks.
	// -------------------------------------------------------------------------
	public static function register_conversion() {
		register_post_type(
			'sd_conversion',
			[
				'labels'             => [
					'name'               => 'Conversion Pages',
					'singular_name'      => 'Conversion Page',
					'add_new'            => 'Add New',
					'add_new_item'       => 'Add New Conversion Page',
					'edit_item'          => 'Edit Conversion Page',
					'new_item'           => 'New Conversion Page',
					'view_item'          => 'View Conversion Page',
					'search_items'       => 'Search Conversion Pages',
					'not_found'          => 'No conversion pages found.',
					'not_found_in_trash' => 'No conversion pages in trash.',
					'menu_name'          => 'Conversion',
					'all_items'          => 'All Conversion Pages',
				],
				'description'        => 'Short funnel pages that move prospects toward purchase or activation.',
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				// Rewrite disabled: sd_conversion content is absorbed into native WP pages
				// via the repo markdown workflow (Content-Management-System.md).
				// A root slug of '/' generates a broad catch-all rewrite rule that
				// intercepts all single-segment URLs and breaks native page routing.
				'rewrite'            => false,
				'supports'           => [ 'title' ],
				'menu_icon'          => 'dashicons-megaphone',
				'menu_position'      => 21,
				'capability_type'    => 'page',
				'map_meta_cap'       => true,
			]
		);
	}

	// -------------------------------------------------------------------------
	// sd_landing
	// Campaign-specific pages. Noindex by default. 5% of content volume.
	// More layout flexibility. Hero images, social proof, feature blocks.
	// Lives under /l/ prefix to avoid slug conflicts with authority pages.
	// -------------------------------------------------------------------------
	public static function register_landing() {
		register_post_type(
			'sd_landing',
			[
				'labels'             => [
					'name'               => 'Landing Pages',
					'singular_name'      => 'Landing Page',
					'add_new'            => 'Add New',
					'add_new_item'       => 'Add New Landing Page',
					'edit_item'          => 'Edit Landing Page',
					'new_item'           => 'New Landing Page',
					'view_item'          => 'View Landing Page',
					'search_items'       => 'Search Landing Pages',
					'not_found'          => 'No landing pages found.',
					'not_found_in_trash' => 'No landing pages in trash.',
					'menu_name'          => 'Landing Pages',
					'all_items'          => 'All Landing Pages',
				],
				'description'        => 'Campaign-specific conversion pages. Noindex by default.',
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'rewrite'            => [
					'slug'       => 'l',
					'with_front' => false,
				],
				'supports'           => [ 'title', 'thumbnail' ], // Thumbnail for hero image.
				'menu_icon'          => 'dashicons-flag',
				'menu_position'      => 22,
				'capability_type'    => 'page',
				'map_meta_cap'       => true,
			]
		);
	}
}
