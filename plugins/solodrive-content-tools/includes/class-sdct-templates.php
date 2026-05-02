<?php
/**
 * SDCT Template Routing and Schema Injection
 *
 * Handles:
 *   - template_include filter → routes CPT singles to plugin templates
 *   - body_class filter → adds managed page classes for CPTs
 *   - wp_head actions → injects JSON-LD schema and meta tags from post meta
 *
 * This class is intentionally minimal. It does not render content.
 * Templates in /templates/ own rendering.
 *
 * Title suppression:
 * The .sd-hide-theme-title body class triggers CSS rules already defined in
 * 03-shell.css which hide Astra's .entry-title and .entry-header on managed pages.
 * No PHP-level title manipulation required.
 *
 * Covered CPTs:
 *   sd_authority — long-form machine-first pages
 *   sd_conversion — funnel pages (template stub, to be built)
 *   sd_landing    — campaign pages (template stub, to be built)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Templates {

	public static function register() {
		add_filter( 'template_include', [ __CLASS__, 'template_include' ] );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
		add_filter( 'pre_get_document_title', [ __CLASS__, 'document_title' ] );
		add_action( 'wp_head', [ __CLASS__, 'inject_meta_tags' ], 1 );
		add_action( 'wp_head', [ __CLASS__, 'inject_schema' ], 2 );
	}

	// =========================================================================
	// TEMPLATE ROUTING
	// =========================================================================

	public static function template_include( $template ) {
		if ( is_singular( 'sd_authority' ) ) {
			return self::locate( 'sd-authority.php', $template );
		}

		// Stubs — uncomment when templates are built
		// if ( is_singular( 'sd_conversion' ) ) {
		// 	return self::locate( 'sd-conversion.php', $template );
		// }
		// if ( is_singular( 'sd_landing' ) ) {
		// 	return self::locate( 'sd-landing.php', $template );
		// }

		return $template;
	}

	/**
	 * Locate a template file in the plugin's /templates/ directory.
	 * Falls back to the original WP template if not found.
	 */
	private static function locate( $filename, $fallback ) {
		$path = SDCT_PLUGIN_DIR . 'templates/' . $filename;
		return file_exists( $path ) ? $path : $fallback;
	}

	// =========================================================================
	// BODY CLASSES
	// =========================================================================

	public static function body_class( $classes ) {
		if ( is_singular( 'sd_authority' ) ) {
			$classes[] = 'sd-has-managed-page';
			$classes[] = 'sd-hide-theme-title';
			$classes[] = 'sd-managed-type-authority';
		}

		if ( is_singular( 'sd_conversion' ) ) {
			$classes[] = 'sd-has-managed-page';
			$classes[] = 'sd-hide-theme-title';
			$classes[] = 'sd-managed-type-conversion';
		}

		if ( is_singular( 'sd_landing' ) ) {
			$classes[] = 'sd-has-managed-page';
			$classes[] = 'sd-hide-theme-title';
			$classes[] = 'sd-managed-type-landing';
		}

		return array_unique( $classes );
	}


	/**
	 * Override the browser title for managed CPT pages early enough for wp_get_document_title().
	 */
	public static function document_title( $title ) {
		if ( ! is_singular( [ 'sd_authority', 'sd_conversion', 'sd_landing' ] ) ) {
			return $title;
		}

		$post_id    = get_the_ID();
		$page_title = get_post_meta( $post_id, '_sdct_page_title', true ) ?: get_the_title( $post_id );

		if ( ! $page_title ) {
			return $title;
		}

		return esc_html( $page_title ) . ' | SoloDrive';
	}

	// =========================================================================
	// META TAGS (wp_head priority 1)
	// =========================================================================

	public static function inject_meta_tags() {
		if ( ! is_singular( [ 'sd_authority', 'sd_conversion', 'sd_landing' ] ) ) {
			return;
		}

		$post_id     = get_the_ID();
		$description = get_post_meta( $post_id, '_sdct_meta_description', true );
		$canonical   = get_post_meta( $post_id, '_sdct_canonical_url', true );
		$noindex     = get_post_meta( $post_id, '_sdct_noindex', true );
		// Meta description
		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}

		// Canonical
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}

		// Robots
		if ( $noindex === '1' ) {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		}
	}

	// =========================================================================
	// JSON-LD SCHEMA (wp_head priority 2)
	// =========================================================================

	public static function inject_schema() {
		if ( ! is_singular( 'sd_authority' ) ) {
			return;
		}

		$post_id     = get_the_ID();
		$page_title  = get_post_meta( $post_id, '_sdct_page_title', true );
		$description = get_post_meta( $post_id, '_sdct_meta_description', true );
		$canonical   = get_post_meta( $post_id, '_sdct_canonical_url', true );
		$schema_type = get_post_meta( $post_id, '_sdct_schema_type', true ) ?: 'Article';
		$author      = get_post_meta( $post_id, '_sdct_author_name', true ) ?: 'SoloDrive';
		$pub_date    = get_post_meta( $post_id, '_sdct_published_date', true );
		$mod_date    = get_the_modified_date( 'Y-m-d' );
		$faq_raw     = get_post_meta( $post_id, '_sdct_faq', true );
		$faq         = $faq_raw ? json_decode( $faq_raw, true ) : [];
		$faq         = is_array( $faq ) ? $faq : [];

		// Require minimum viable fields before emitting schema
		if ( ! $page_title || ! $canonical ) {
			return;
		}

		// ---- Article schema (always emitted for sd_authority) ----
		$article = [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => $page_title,
			'description'   => $description,
			'url'           => $canonical,
			'author'        => [
				'@type' => 'Organization',
				'name'  => $author,
				'url'   => 'https://solodrive.pro',
			],
			'publisher'     => [
				'@type' => 'Organization',
				'name'  => 'SoloDrive',
				'url'   => 'https://solodrive.pro',
			],
		];

		if ( $pub_date ) {
			$article['datePublished'] = $pub_date;
		}
		if ( $mod_date ) {
			$article['dateModified'] = $mod_date;
		}

		self::emit_schema( $article );

		// ---- FAQPage schema (appended when type is FAQPage and rows exist) ----
		if ( $schema_type === 'FAQPage' && ! empty( $faq ) ) {
			$entities = [];

			foreach ( $faq as $item ) {
				$question = $item['question'] ?? '';
				$answer   = $item['answer'] ?? '';

				if ( ! $question || ! $answer ) {
					continue;
				}

				$entities[] = [
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text'  => $answer,
					],
				];
			}

			if ( ! empty( $entities ) ) {
				self::emit_schema( [
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				] );
			}
		}
	}

	/**
	 * Emit a single JSON-LD block.
	 */
	private static function emit_schema( array $schema ) {
		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}
}
