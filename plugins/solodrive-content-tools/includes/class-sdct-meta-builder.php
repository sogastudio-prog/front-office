<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Meta_Builder {

	public function build( array $meta, string $site_url = '' ): array {
		if ( $site_url === '' ) {
			$site_url = get_site_url();
		}

		$slug      = $meta['slug'] ?? '';
		$canonical = rtrim( $site_url, '/' ) . '/' . trim( $slug, '/' ) . '/';

		if ( ! empty( $meta['canonical_url'] ) ) {
			$canonical = $meta['canonical_url'];
		}

		return [
			'_sdct_meta_title'       => $meta['meta_title']       ?? $meta['title'] ?? '',
			'_sdct_meta_description' => $meta['meta_description'] ?? '',
			'_sdct_canonical_url'    => $canonical,
			'_sdct_schema_type'      => $meta['schema_type']      ?? 'Article',
			'_sdct_noindex'          => ! empty( $meta['noindex'] ) ? '1' : '0',
			'_sdct_og_image'         => $meta['og_image']         ?? '',
			'_sdct_last_reviewed'    => $meta['last_reviewed']    ?? '',
		];
	}

	public function write( int $post_id, array $built ): void {
		foreach ( $built as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
