<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Cluster_Reader {

	private array $data;

	public function __construct( string $yaml_path = '' ) {
		if ( $yaml_path === '' ) {
			$yaml_path = $this->detect_root_dir() . '/content/data/authority-cluster.yml';
		}
		$raw        = file_exists( $yaml_path ) ? (string) file_get_contents( $yaml_path ) : '';
		$this->data = $raw !== '' ? $this->parse_yaml( $raw ) : [];
	}

	public function get_hub_slug(): string {
		return $this->data['hub'] ?? '';
	}

	public function get_pages(): array {
		$pages = $this->data['pages'] ?? [];
		return is_array( $pages ) ? array_values( $pages ) : [];
	}

	public function get_standard_cta(): array {
		$cta = $this->data['standard_cta'] ?? [];
		return is_array( $cta ) ? $cta : [];
	}

	public function get_publish_gates(): array {
		$gates = $this->data['publish_status'] ?? [];
		return is_array( $gates ) ? $gates : [];
	}

	public function is_authority_auto_publishable(): bool {
		return ( $this->get_publish_gates()['authority_cluster'] ?? '' ) === 'auto_publish';
	}

	public function is_conversion_gated(): bool {
		return ( $this->get_publish_gates()['conversion_pages'] ?? '' ) !== 'auto_publish';
	}

	public function get_page_role( string $slug ): string {
		foreach ( $this->get_pages() as $page ) {
			if ( ( $page['slug'] ?? '' ) === $slug ) {
				return $page['role'] ?? '';
			}
		}
		return '';
	}

	private function detect_root_dir(): string {
		$dir = dirname( SDCT_PLUGIN_DIR, 2 );
		for ( $i = 0; $i < 5; $i++ ) {
			if ( is_dir( $dir . '/content' ) ) {
				return $dir;
			}
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				break;
			}
			$dir = $parent;
		}
		return dirname( SDCT_PLUGIN_DIR, 2 );
	}

	private function parse_yaml( string $yaml ): array {
		$lines      = preg_split( '/\r?\n/', $yaml );
		$result     = [];
		$section    = null;
		$list_index = -1;

		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			$ltrimmed = ltrim( $line );
			if ( $ltrimmed !== '' && $ltrimmed[0] === '#' ) {
				continue;
			}

			$indent  = strlen( $line ) - strlen( $ltrimmed );
			$content = $ltrimmed;

			if ( $indent === 0 ) {
				$list_index = -1;
				if ( substr( $content, -1 ) === ':' && strpos( $content, ': ' ) === false ) {
					$section = rtrim( $content, ':' );
					if ( ! isset( $result[ $section ] ) ) {
						$result[ $section ] = [];
					}
				} elseif ( strpos( $content, ': ' ) !== false ) {
					[ $key, $val ] = explode( ': ', $content, 2 );
					$result[ trim( $key ) ] = trim( $val );
					$section = null;
				}
			} elseif ( $indent === 2 && $section !== null ) {
				if ( strpos( $content, '- ' ) === 0 ) {
					$list_index++;
					$result[ $section ][ $list_index ] = [];
					$rest = substr( $content, 2 );
					if ( strpos( $rest, ': ' ) !== false ) {
						[ $key, $val ] = explode( ': ', $rest, 2 );
						$result[ $section ][ $list_index ][ trim( $key ) ] = trim( $val );
					}
				} else {
					$list_index = -1;
					if ( strpos( $content, ': ' ) !== false ) {
						[ $key, $val ] = explode( ': ', $content, 2 );
						$result[ $section ][ trim( $key ) ] = trim( $val );
					}
				}
			} elseif ( $indent === 4 && $section !== null && $list_index >= 0 ) {
				if ( strpos( $content, ': ' ) !== false ) {
					[ $key, $val ] = explode( ': ', $content, 2 );
					$result[ $section ][ $list_index ][ trim( $key ) ] = trim( $val );
				}
			}
		}

		return $result;
	}
}
