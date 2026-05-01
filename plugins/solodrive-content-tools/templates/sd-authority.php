<?php
/**
 * Template: sd_authority single post
 *
 * Renders entirely from post meta. post_content is never read.
 *
 * Meta fields consumed:
 *   _sdct_page_title      — H1
 *   _sdct_answer          — Short answer paragraph below H1
 *   _sdct_body_sections   — JSON: [{heading, heading_level, body}]
 *   _sdct_faq             — JSON: [{question, answer}]
 *   _sdct_related_pages   — JSON: [{label, url}]
 *   _sdct_cta_label       — CTA button text
 *   _sdct_cta_url         — CTA button URL
 *   _sdct_cta_heading     — CTA card H2 (optional, falls back to cta_label)
 *   _sdct_cta_body        — CTA card supporting copy (optional)
 *
 * CSS classes used are all defined in solodrive-front-css:
 *   Shell:      .sd-managed-page .sd-managed-page--authority
 *   Components: .sd-section .sd-card .sd-eyebrow .sd-button .sd-stack
 *   Authority:  .sd-authority__* (defined in 06-pages.css authority block)
 */

defined( 'ABSPATH' ) || exit;

get_header();

$post_id = get_the_ID();

// ---- Meta fields ----
$page_title    = get_post_meta( $post_id, '_sdct_page_title', true ) ?: get_the_title();
$answer        = get_post_meta( $post_id, '_sdct_answer', true );
$body_sections = json_decode( get_post_meta( $post_id, '_sdct_body_sections', true ) ?: '[]', true );
$faq           = json_decode( get_post_meta( $post_id, '_sdct_faq', true ) ?: '[]', true );
$related_pages = json_decode( get_post_meta( $post_id, '_sdct_related_pages', true ) ?: '[]', true );
$cta_label     = get_post_meta( $post_id, '_sdct_cta_label', true ) ?: 'Get Your Booking Page';
$cta_url       = get_post_meta( $post_id, '_sdct_cta_url', true ) ?: '/start/';
$cta_heading   = get_post_meta( $post_id, '_sdct_cta_heading', true ) ?: 'Build the direct path.';
$cta_body      = get_post_meta( $post_id, '_sdct_cta_body', true )
	?: 'Start your booking page and give riders a clean link they can use to request you again.';

// ---- Sanitize arrays ----
$body_sections = is_array( $body_sections ) ? $body_sections : [];
$faq           = is_array( $faq ) ? $faq : [];
$related_pages = is_array( $related_pages ) ? $related_pages : [];

?>

<div class="sd-managed-page sd-managed-page--authority">
	<article
		class="sd-authority"
		itemscope
		itemtype="https://schema.org/Article"
	>

		<!-- ================================================================
		     HEADER — H1 + Short Answer
		     ================================================================ -->
		<header class="sd-authority__header">

			<h1
				class="sd-authority__title"
				itemprop="headline"
			>
				<?php echo esc_html( $page_title ); ?>
			</h1>

			<?php if ( $answer ) : ?>
				<p
					class="sd-authority__answer"
					itemprop="description"
				>
					<?php echo esc_html( $answer ); ?>
				</p>
			<?php endif; ?>

		</header>

		<!-- ================================================================
		     BODY SECTIONS
		     ================================================================ -->
		<?php if ( ! empty( $body_sections ) ) : ?>
			<div
				class="sd-authority__body"
				itemprop="articleBody"
			>
				<?php foreach ( $body_sections as $section ) :
					$level   = isset( $section['heading_level'] )
						&& in_array( $section['heading_level'], [ 'h2', 'h3' ], true )
						? $section['heading_level']
						: 'h2';
					$heading = $section['heading'] ?? '';
					$body    = $section['body'] ?? '';

					if ( '' === $heading && '' === trim( wp_strip_all_tags( $body ) ) ) {
						continue;
					}
				?>
					<section class="sd-authority__section">

						<?php if ( $heading ) : ?>
							<<?php echo esc_attr( $level ); ?> class="sd-authority__section-heading">
								<?php echo esc_html( $heading ); ?>
							</<?php echo esc_attr( $level ); ?>>
						<?php endif; ?>

						<?php if ( $body ) : ?>
							<div class="sd-authority__section-body">
								<?php
								// wp_kses_post was applied at save time.
								// Direct echo is safe.
								echo wp_kses_post( $body );
								?>
							</div>
						<?php endif; ?>

					</section>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<!-- ================================================================
		     FAQ — rendered only when rows exist
		     Schema type FAQPage is handled in class-sdct-templates.php
		     ================================================================ -->
		<?php if ( ! empty( $faq ) ) : ?>
			<section class="sd-authority__faq sd-section sd-card">

				<p class="sd-eyebrow">Common Questions</p>

				<div class="sd-faq-list">
					<?php foreach ( $faq as $item ) :
						$question    = $item['question'] ?? '';
						$answer_text = $item['answer'] ?? '';

						if ( ! $question ) {
							continue;
						}
					?>
						<details class="sd-faq-item">
							<summary class="sd-faq-question">
								<?php echo esc_html( $question ); ?>
							</summary>
							<p class="sd-faq-answer">
								<?php echo esc_html( $answer_text ); ?>
							</p>
						</details>
					<?php endforeach; ?>
				</div>

			</section>
		<?php endif; ?>

		<!-- ================================================================
		     RELATED PAGES — rendered only when rows exist
		     Populated from _sdct_related_pages meta (JSON array of {label, url})
		     Migration script will populate this from existing markdown HTML sections.
		     ================================================================ -->
		<?php if ( ! empty( $related_pages ) ) : ?>
			<section class="sd-section sd-card sd-related-pages">

				<p class="sd-eyebrow">Related SoloDrive Pages</p>
				<h2>Keep going</h2>

				<ul class="sd-related-pages__list">
					<?php foreach ( $related_pages as $link ) :
						$label = $link['label'] ?? '';
						$url   = $link['url'] ?? '';

						if ( ! $label || ! $url ) {
							continue;
						}
					?>
						<li>
							<a href="<?php echo esc_url( $url ); ?>">
								<?php echo esc_html( $label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>

			</section>
		<?php endif; ?>

		<!-- ================================================================
		     CTA — always rendered
		     ================================================================ -->
		<section class="sd-section sd-card sd-final-cta">

			<p class="sd-eyebrow">Next step</p>

			<h2 class="sd-final-cta__heading">
				<?php echo esc_html( $cta_heading ); ?>
			</h2>

			<p class="sd-final-cta__body">
				<?php echo esc_html( $cta_body ); ?>
			</p>

			<p>
				<a
					class="sd-button"
					href="<?php echo esc_url( $cta_url ); ?>"
				>
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</p>

		</section>

	</article>
</div>

<?php get_footer(); ?>
