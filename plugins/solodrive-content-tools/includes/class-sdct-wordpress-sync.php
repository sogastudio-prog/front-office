<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_WordPress_Sync {

	private SDCT_Cluster_Reader     $cluster;
	private SDCT_Markdown_Renderer  $renderer;
	private SDCT_Schema_Builder     $schema;
	private SDCT_Meta_Builder       $meta;
	private SDCT_Content_Repository $repo;

	public function __construct() {
		$this->cluster  = new SDCT_Cluster_Reader();
		$this->renderer = new SDCT_Markdown_Renderer( $this->cluster );
		$this->schema   = new SDCT_Schema_Builder();
		$this->meta     = new SDCT_Meta_Builder();
		$this->repo     = new SDCT_Content_Repository();
	}

	public function sync_file( string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return [ 'slug' => basename( $file_path, '.md' ), 'status' => 'error', 'message' => 'File not found' ];
		}
		return $this->sync_page( $this->repo->load_page( $file_path ) );
	}

	public function sync_all(): array {
		$results      = [];
		$root         = $this->repo->root_dir();
		$content_dirs = [
			'authority' => $root . '/content/pages',
			'conversion' => $root . '/content/conversion',
		];

		foreach ( $this->cluster->get_pages() as $cluster_page ) {
			$slug = $cluster_page['slug'] ?? '';
			if ( $slug === '' ) {
				continue;
			}

			$file = $content_dirs['authority'] . '/' . $slug . '.md';
			if ( ! file_exists( $file ) ) {
				$results[] = [ 'slug' => $slug, 'status' => 'missing', 'message' => 'File not found' ];
				continue;
			}

			$results[] = $this->sync_page( $this->repo->load_page( $file ) );
		}

		return $results;
	}

	public function sync_page( array $page ): array {
		$meta = $page['meta'];
		$slug = $meta['slug'] ?? '';

		if ( $slug === '' ) {
			return [ 'slug' => '', 'status' => 'error', 'message' => 'No slug in frontmatter' ];
		}

		$type        = $meta['type'] ?? 'authority';
		$rendered    = $this->render_page( $page, $type );
		$wp_status   = $this->resolve_publish_status( $meta, $type );
		$built_meta  = $this->meta->build( $meta );
		$schema_blob = $this->schema->encode( $this->schema->build_article( $meta ) );

		$post_id = $this->resolve_post_id( $meta );

		if ( $post_id > 0 ) {
			$result = wp_update_post( [
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( $meta['title'] ?? '' ),
				'post_content' => $rendered,
				'post_status'  => $wp_status,
			], true );

			if ( is_wp_error( $result ) ) {
				return [ 'slug' => $slug, 'status' => 'error', 'message' => $result->get_error_message() ];
			}
		} else {
			$post_id = wp_insert_post( [
				'post_type'    => 'page',
				'post_name'    => $slug,
				'post_title'   => sanitize_text_field( $meta['title'] ?? '' ),
				'post_content' => $rendered,
				'post_status'  => $wp_status,
			], true );

			if ( is_wp_error( $post_id ) ) {
				return [ 'slug' => $slug, 'status' => 'error', 'message' => $post_id->get_error_message() ];
			}
		}

		$this->meta->write( $post_id, $built_meta );
		update_post_meta( $post_id, '_sdct_schema_json', $schema_blob );
		update_post_meta( $post_id, '_sdct_slug', $slug );

		return [
			'slug'      => $slug,
			'post_id'   => $post_id,
			'status'    => 'synced',
			'wp_status' => $wp_status,
			'message'   => 'OK',
		];
	}

	private function render_page( array $page, string $type ): string {
		if ( in_array( $type, [ 'authority', 'page' ], true ) ) {
			return $this->renderer->render_authority( $page );
		}
		return '';
	}

	private function resolve_post_id( array $meta ): int {
		if ( ! empty( $meta['wp_post_id'] ) && is_numeric( $meta['wp_post_id'] ) ) {
			return (int) $meta['wp_post_id'];
		}

		$slug = $meta['slug'] ?? '';

		if ( $slug !== '' ) {
			$post = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $post ) {
				return $post->ID;
			}

			$posts = get_posts( [
				'post_type'      => 'page',
				'post_status'    => 'any',
				'meta_key'       => '_sdct_slug',
				'meta_value'     => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			] );
			if ( $posts ) {
				return (int) $posts[0];
			}
		}

		return 0;
	}

	private function resolve_publish_status( array $meta, string $type ): string {
		$gates = $this->cluster->get_publish_gates();

		if ( in_array( $type, [ 'authority', 'page' ], true ) ) {
			if ( ( $gates['authority_cluster'] ?? '' ) !== 'auto_publish' ) {
				return 'draft';
			}
		} elseif ( $type === 'conversion' ) {
			if ( ( $gates['conversion_pages'] ?? '' ) !== 'auto_publish' ) {
				return 'draft';
			}
		}

		$fm = $meta['status'] ?? 'draft';
		return in_array( $fm, [ 'publish', 'draft', 'private', 'pending' ], true ) ? $fm : 'draft';
	}
}
