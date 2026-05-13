<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Schema_Builder {

	public function build_article( array $meta, string $site_url = '' ): array {
		if ( $site_url === '' ) {
			$site_url = get_site_url();
		}

		$slug = $meta['slug'] ?? '';
		$url  = rtrim( $site_url, '/' ) . '/' . trim( $slug, '/' ) . '/';

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Article',
			'headline' => $meta['title'] ?? '',
			'url'      => $url,
			'author'   => [
				'@type' => 'Organization',
				'name'  => 'SoloDrive',
				'url'   => $site_url,
			],
		];

		if ( ! empty( $meta['meta_description'] ) ) {
			$schema['description'] = $meta['meta_description'];
		}

		if ( ! empty( $meta['last_reviewed'] ) ) {
			$schema['dateModified'] = $meta['last_reviewed'];
		}

		return $schema;
	}

	public function build_faq( array $items ): array {
		$entities = [];
		foreach ( $items as $item ) {
			if ( empty( $item['question'] ) || empty( $item['answer'] ) ) {
				continue;
			}
			$entities[] = [
				'@type'          => 'Question',
				'name'           => $item['question'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $item['answer'],
				],
			];
		}

		if ( ! $entities ) {
			return [];
		}

		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		];
	}

	public function encode( array $schema ): string {
		return (string) wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
}
