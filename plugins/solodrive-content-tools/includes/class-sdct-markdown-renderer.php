<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Markdown_Renderer {

	private SDCT_Cluster_Reader $cluster;

	public function __construct( SDCT_Cluster_Reader $cluster ) {
		$this->cluster = $cluster;
	}

	public function render_authority( array $page ): string {
		$meta    = $page['meta'];
		$body    = $this->strip_trailing_html( $page['body'] );
		$title   = $meta['title']   ?? '';
		$summary = $meta['summary'] ?? '';

		$sections   = $this->parse_sections( $body );
		$body_html  = $this->render_body_sections( $sections );
		$rel_html   = $this->render_related( $sections );
		$cta_html   = $this->render_cta();

		$html  = '<main class="sd-managed-page sd-managed-page--authority">' . "\n";
		$html .= '  <header class="sd-authority__header">' . "\n";
		$html .= '    <h1 class="sd-authority__title">' . esc_html( $title ) . '</h1>' . "\n";
		if ( $summary !== '' ) {
			$html .= '    <p class="sd-authority__answer">' . esc_html( $summary ) . '</p>' . "\n";
		}
		$html .= '  </header>' . "\n\n";
		$html .= '  <div class="sd-authority__body">' . "\n";
		$html .= $body_html;
		$html .= $rel_html;
		$html .= $cta_html;
		$html .= '  </div>' . "\n";
		$html .= '</main>';

		return $html;
	}

	private function strip_trailing_html( string $body ): string {
		return trim( preg_replace( '/<section\b[^>]*>.*?<\/section>/si', '', $body ) );
	}

	private function parse_sections( string $body ): array {
		$lines    = preg_split( '/\r?\n/', $body );
		$sections = [];
		$current  = null;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^##\s+(.+)$/', $line, $m ) ) {
				if ( $current !== null ) {
					$sections[] = $current;
				}
				$heading = trim( $m[1] );
				$lower   = strtolower( $heading );
				$type    = 'normal';
				if ( $lower === 'related solodrive pages' ) {
					$type = 'related';
				} elseif ( $lower === 'next step' ) {
					$type = 'skip';
				}
				$current = [ 'heading' => $heading, 'content' => '', 'type' => $type ];
			} elseif ( $current !== null ) {
				$current['content'] .= $line . "\n";
			}
		}

		if ( $current !== null ) {
			$sections[] = $current;
		}

		return $sections;
	}

	private function render_body_sections( array $sections ): string {
		$html = '';
		foreach ( $sections as $section ) {
			if ( $section['type'] !== 'normal' ) {
				continue;
			}
			$html .= '    <section class="sd-authority__section">' . "\n";
			$html .= '      <h2 class="sd-authority__section-heading">' . esc_html( $section['heading'] ) . '</h2>' . "\n";
			$html .= '      <div class="sd-authority__section-body">' . "\n";
			$html .= $this->render_inline_content( $section['content'] );
			$html .= '      </div>' . "\n";
			$html .= '    </section>' . "\n\n";
		}
		return $html;
	}

	private function render_inline_content( string $content ): string {
		$lines   = preg_split( '/\r?\n/', trim( $content ) );
		$html    = '';
		$in_list = false;
		$para    = [];

		foreach ( $lines as $line ) {
			$trim = trim( $line );

			if ( $trim === '' ) {
				if ( $para ) {
					$html .= '        <p>' . $this->inline( implode( ' ', $para ) ) . '</p>' . "\n";
					$para = [];
				}
				if ( $in_list ) {
					$html .= '        </ul>' . "\n";
					$in_list = false;
				}
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $trim, $m ) ) {
				if ( $para ) {
					$html .= '        <p>' . $this->inline( implode( ' ', $para ) ) . '</p>' . "\n";
					$para = [];
				}
				if ( ! $in_list ) {
					$html .= '        <ul>' . "\n";
					$in_list = true;
				}
				$html .= '          <li>' . $this->inline( $m[1] ) . '</li>' . "\n";
				continue;
			}

			if ( $in_list ) {
				$html .= '        </ul>' . "\n";
				$in_list = false;
			}

			$para[] = $trim;
		}

		if ( $para ) {
			$html .= '        <p>' . $this->inline( implode( ' ', $para ) ) . '</p>' . "\n";
		}
		if ( $in_list ) {
			$html .= '        </ul>' . "\n";
		}

		return $html;
	}

	private function render_related( array $sections ): string {
		$related = null;
		foreach ( $sections as $section ) {
			if ( $section['type'] === 'related' ) {
				$related = $section;
				break;
			}
		}

		if ( ! $related ) {
			return '';
		}

		$links = [];
		foreach ( preg_split( '/\r?\n/', trim( $related['content'] ) ) as $line ) {
			$trim = trim( $line );
			if ( preg_match( '/^[-*]\s+\[(.+?)\]\((.+?)\)$/', $trim, $m ) ) {
				$links[] = [ 'text' => $m[1], 'href' => $m[2] ];
			}
		}

		if ( ! $links ) {
			return '';
		}

		$html  = '    <section class="sd-related-pages">' . "\n";
		$html .= '      <p class="sd-eyebrow">Related SoloDrive pages</p>' . "\n";
		$html .= '      <ul class="sd-related-pages__list">' . "\n";
		foreach ( $links as $link ) {
			$html .= '        <li><a href="' . esc_url( $link['href'] ) . '">' . esc_html( $link['text'] ) . '</a></li>' . "\n";
		}
		$html .= '      </ul>' . "\n";
		$html .= '    </section>' . "\n\n";

		return $html;
	}

	private function render_cta(): string {
		$cta = $this->cluster->get_standard_cta();
		if ( empty( $cta ) ) {
			return '';
		}

		$heading   = esc_html( $cta['heading']   ?? 'Next step' );
		$text      = esc_html( $cta['text']      ?? '' );
		$link_text = esc_html( $cta['link_text'] ?? 'Learn more' );
		$link_url  = esc_url( $cta['link_url']   ?? '/' );

		$html  = '    <section class="sd-final-cta">' . "\n";
		$html .= '      <h2 class="sd-final-cta__heading">' . $heading . '</h2>' . "\n";
		if ( $text !== '' ) {
			$html .= '      <p class="sd-final-cta__body">' . $text . '</p>' . "\n";
		}
		$html .= '      <a class="sd-button" href="' . $link_url . '">' . $link_text . '</a>' . "\n";
		$html .= '    </section>' . "\n";

		return $html;
	}

	private function inline( string $text ): string {
		$pattern = '/\*\*(.*?)\*\*|\*(.*?)\*|\[(.+?)\]\((.+?)\)/';
		$result   = '';
		$last_end = 0;

		if ( preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$start = $match[0][1];
				$len   = strlen( $match[0][0] );

				$result .= esc_html( substr( $text, $last_end, $start - $last_end ) );

				if ( $match[1][0] !== '' ) {
					$result .= '<strong>' . esc_html( $match[1][0] ) . '</strong>';
				} elseif ( $match[2][0] !== '' ) {
					$result .= '<em>' . esc_html( $match[2][0] ) . '</em>';
				} elseif ( $match[3][0] !== '' ) {
					$result .= '<a href="' . esc_url( $match[4][0] ) . '">' . esc_html( $match[3][0] ) . '</a>';
				}

				$last_end = $start + $len;
			}
		}

		$result .= esc_html( substr( $text, $last_end ) );
		return $result;
	}
}
