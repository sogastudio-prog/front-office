<?php
/**
 * SDCT Authority Page Meta Boxes
 *
 * Registers and handles all meta for the sd_authority post type.
 *
 * Doctrine:
 *   - post_content remains empty. Template owns rendering.
 *   - All content lives in post meta under the _sdct_* namespace.
 *   - Repeater fields (body sections, FAQ) stored as JSON arrays.
 *   - Admin JS is vanilla — no jQuery dependency.
 *
 * Meta keys registered here:
 *   _sdct_page_title        — H1 display title (separate from WP post title)
 *   _sdct_topic             — primary keyword / topic
 *   _sdct_topic_cluster     — cluster / silo grouping
 *   _sdct_answer            — short direct answer, displayed at top of page
 *   _sdct_body_sections     — JSON: [{heading, heading_level, body}]
 *   _sdct_faq               — JSON: [{question, answer}]
 *   _sdct_meta_description  — meta description (reuses existing key)
 *   _sdct_canonical_url     — canonical URL
 *   _sdct_schema_type       — Article | FAQPage | HowTo (reuses existing key)
 *   _sdct_author_name       — author for schema
 *   _sdct_published_date    — datePublished for schema
 *   _sdct_noindex           — 1 | 0
 *   _sdct_cta_label         — CTA button text
 *   _sdct_cta_url           — CTA button URL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDCT_Meta_Authority {

	const NONCE_ACTION = 'sdct_authority_meta_save';
	const NONCE_FIELD  = 'sdct_authority_meta_nonce';

	/**
	 * Wire hooks. Called once from plugin entry point.
	 */
	public static function register() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
		add_action( 'save_post_sd_authority', [ __CLASS__, 'save_meta' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
	}

	// =========================================================================
	// META BOX REGISTRATION
	// =========================================================================

	public static function add_meta_boxes() {
		// Main content — full width, top
		add_meta_box(
			'sdct_authority_content',
			'Page Content',
			[ __CLASS__, 'render_content_box' ],
			'sd_authority',
			'normal',
			'high'
		);

		// SEO & Schema — full width, below content
		add_meta_box(
			'sdct_authority_seo',
			'SEO & Schema',
			[ __CLASS__, 'render_seo_box' ],
			'sd_authority',
			'normal',
			'default'
		);

		// CTA — sidebar
		add_meta_box(
			'sdct_authority_cta',
			'Call to Action',
			[ __CLASS__, 'render_cta_box' ],
			'sd_authority',
			'side',
			'high'
		);

		// Status panel — sidebar
		add_meta_box(
			'sdct_authority_status',
			'Content Status',
			[ __CLASS__, 'render_status_box' ],
			'sd_authority',
			'side',
			'default'
		);
	}

	// =========================================================================
	// ADMIN ASSETS
	// =========================================================================

	public static function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'sd_authority' ) {
			return;
		}
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Dashicons for handle icon (already loaded in WP admin)
		wp_enqueue_style( 'dashicons' );

		// Admin styles inline on wp-admin handle
		wp_add_inline_style( 'wp-admin', self::admin_css() );

		// Repeater + char count JS — registered as empty handle, script added inline
		wp_register_script( 'sdct-meta-authority', false, [], SDCT_VERSION, true );
		wp_enqueue_script( 'sdct-meta-authority' );
		wp_add_inline_script( 'sdct-meta-authority', self::admin_js() );
	}

	// =========================================================================
	// RENDER: Content Meta Box
	// =========================================================================

	public static function render_content_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$page_title    = get_post_meta( $post->ID, '_sdct_page_title', true );
		$topic         = get_post_meta( $post->ID, '_sdct_topic', true );
		$topic_cluster = get_post_meta( $post->ID, '_sdct_topic_cluster', true );
		$answer        = get_post_meta( $post->ID, '_sdct_answer', true );
		$body_sections = self::get_json_meta( $post->ID, '_sdct_body_sections' );
		$faq           = self::get_json_meta( $post->ID, '_sdct_faq' );

		?>
		<div class="sdct-meta-box">

			<div class="sdct-notice">
				<strong>Option B active.</strong> Content is stored in meta fields below.
				The editor body above is intentionally empty — the template renders everything.
			</div>

			<!-- Page Title + Topic -->
			<div class="sdct-field-row sdct-field-row--two">
				<div class="sdct-field">
					<label for="sdct_page_title">
						Page Title <span class="sdct-required">*</span>
					</label>
					<input
						type="text"
						id="sdct_page_title"
						name="sdct_page_title"
						value="<?php echo esc_attr( $page_title ); ?>"
						placeholder="Exact H1 displayed on page"
					/>
					<p class="sdct-hint">
						Visible H1 on the page. Separate from the WP admin title above,
						which controls the slug only.
					</p>
				</div>
				<div class="sdct-field">
					<label for="sdct_topic">
						Primary Topic / Keyword <span class="sdct-required">*</span>
					</label>
					<input
						type="text"
						id="sdct_topic"
						name="sdct_topic"
						value="<?php echo esc_attr( $topic ); ?>"
						placeholder="how to turn uber riders into repeat customers"
					/>
					<p class="sdct-hint">
						The search query this page is built to answer.
					</p>
				</div>
			</div>

			<!-- Topic Cluster -->
			<div class="sdct-field sdct-field--half">
				<label for="sdct_topic_cluster">Topic Cluster</label>
				<input
					type="text"
					id="sdct_topic_cluster"
					name="sdct_topic_cluster"
					value="<?php echo esc_attr( $topic_cluster ); ?>"
					placeholder="direct bookings"
				/>
				<p class="sdct-hint">
					Silo or cluster this page belongs to. Used for internal linking logic.
				</p>
			</div>

			<!-- Short Answer -->
			<div class="sdct-field">
				<label for="sdct_answer">
					Short Answer <span class="sdct-required">*</span>
				</label>
				<textarea
					id="sdct_answer"
					name="sdct_answer"
					rows="3"
					placeholder="Direct answer to the page topic. 1–3 sentences. This is the first thing visitors and machines read."
				><?php echo esc_textarea( $answer ); ?></textarea>
				<p class="sdct-hint">
					Displayed directly below the H1. Answer the topic immediately.
					Do not save the answer for later in the body.
				</p>
			</div>

			<!-- Body Sections Repeater -->
			<div class="sdct-repeater-section">
				<div class="sdct-repeater-header">
					<h3>Body Sections</h3>
					<button
						type="button"
						class="button sdct-add-row"
						data-target="sdct-body-sections"
						data-type="body-section"
					>
						+ Add Section
					</button>
				</div>
				<p class="sdct-hint" style="margin-bottom:10px;">
					Each section becomes an H2 or H3 heading with body content.
					Rendered in order. HTML allowed in body.
				</p>

				<div id="sdct-body-sections" class="sdct-repeater" data-type="body-section">
					<?php
					if ( ! empty( $body_sections ) ) {
						foreach ( $body_sections as $i => $section ) {
							self::render_body_section_row( $i, $section );
						}
					}
					?>
				</div>

				<template id="sdct-body-section-template">
					<?php self::render_body_section_row( '__INDEX__', [] ); ?>
				</template>
			</div>

			<!-- FAQ Repeater -->
			<div class="sdct-repeater-section">
				<div class="sdct-repeater-header">
					<h3>
						FAQ
						<span class="sdct-badge">Injected into FAQPage schema</span>
					</h3>
					<button
						type="button"
						class="button sdct-add-row"
						data-target="sdct-faq"
						data-type="faq"
					>
						+ Add Question
					</button>
				</div>
				<p class="sdct-hint" style="margin-bottom:10px;">
					FAQ rows are included in JSON-LD when Schema Type is set to FAQPage.
					Keep answers plain text for best schema compatibility.
				</p>

				<div id="sdct-faq" class="sdct-repeater" data-type="faq">
					<?php
					if ( ! empty( $faq ) ) {
						foreach ( $faq as $i => $item ) {
							self::render_faq_row( $i, $item );
						}
					}
					?>
				</div>

				<template id="sdct-faq-template">
					<?php self::render_faq_row( '__INDEX__', [] ); ?>
				</template>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// RENDER: SEO & Schema Meta Box
	// =========================================================================

	public static function render_seo_box( $post ) {
		$meta_description = get_post_meta( $post->ID, '_sdct_meta_description', true );
		$canonical_url    = get_post_meta( $post->ID, '_sdct_canonical_url', true );
		$schema_type      = get_post_meta( $post->ID, '_sdct_schema_type', true );
		$author_name      = get_post_meta( $post->ID, '_sdct_author_name', true );
		$published_date   = get_post_meta( $post->ID, '_sdct_published_date', true );
		$noindex          = get_post_meta( $post->ID, '_sdct_noindex', true );

		if ( ! $author_name ) {
			$author_name = 'SoloDrive';
		}
		if ( ! $schema_type ) {
			$schema_type = 'Article';
		}

		?>
		<div class="sdct-meta-box">

			<!-- Meta Description -->
			<div class="sdct-field">
				<label for="sdct_meta_description">
					Meta Description <span class="sdct-required">*</span>
				</label>
				<textarea
					id="sdct_meta_description"
					name="sdct_meta_description"
					rows="2"
					placeholder="150–160 characters. What the page answers. Appears in search results and AI snippets."
				><?php echo esc_textarea( $meta_description ); ?></textarea>
				<p class="sdct-hint sdct-char-count" data-target="sdct_meta_description">
					<span class="sdct-count">0</span> characters &nbsp;·&nbsp; target: 150–160
				</p>
			</div>

			<!-- Canonical + Schema Type -->
			<div class="sdct-field-row sdct-field-row--two">
				<div class="sdct-field">
					<label for="sdct_canonical_url">
						Canonical URL <span class="sdct-required">*</span>
					</label>
					<input
						type="url"
						id="sdct_canonical_url"
						name="sdct_canonical_url"
						value="<?php echo esc_attr( $canonical_url ); ?>"
						placeholder="https://solodrive.pro/page-slug/"
					/>
				</div>
				<div class="sdct-field">
					<label for="sdct_schema_type">
						Schema Type <span class="sdct-required">*</span>
					</label>
					<select id="sdct_schema_type" name="sdct_schema_type">
						<option value="Article" <?php selected( $schema_type, 'Article' ); ?>>
							Article
						</option>
						<option value="FAQPage" <?php selected( $schema_type, 'FAQPage' ); ?>>
							FAQPage — requires FAQ rows above
						</option>
						<option value="HowTo" <?php selected( $schema_type, 'HowTo' ); ?>>
							HowTo
						</option>
					</select>
				</div>
			</div>

			<!-- Author + Published Date -->
			<div class="sdct-field-row sdct-field-row--two">
				<div class="sdct-field">
					<label for="sdct_author_name">Author Name</label>
					<input
						type="text"
						id="sdct_author_name"
						name="sdct_author_name"
						value="<?php echo esc_attr( $author_name ); ?>"
					/>
					<p class="sdct-hint">Used in schema author field. Default: SoloDrive.</p>
				</div>
				<div class="sdct-field">
					<label for="sdct_published_date">Published Date</label>
					<input
						type="date"
						id="sdct_published_date"
						name="sdct_published_date"
						value="<?php echo esc_attr( $published_date ); ?>"
					/>
					<p class="sdct-hint">Used in schema datePublished field.</p>
				</div>
			</div>

			<!-- Noindex -->
			<div class="sdct-field sdct-field--checkbox">
				<label>
					<input
						type="checkbox"
						name="sdct_noindex"
						value="1"
						<?php checked( $noindex, '1' ); ?>
					/>
					No Index — exclude this page from search engine crawlers
				</label>
				<p class="sdct-hint">
					Leave unchecked for authority pages. Authority pages should be indexed.
				</p>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// RENDER: CTA Meta Box (Sidebar)
	// =========================================================================

	public static function render_cta_box( $post ) {
		$cta_label = get_post_meta( $post->ID, '_sdct_cta_label', true );
		$cta_url   = get_post_meta( $post->ID, '_sdct_cta_url', true );

		if ( ! $cta_label ) {
			$cta_label = 'Get Your Booking Page';
		}
		if ( ! $cta_url ) {
			$cta_url = '/start/';
		}

		?>
		<div class="sdct-meta-box">
			<div class="sdct-field">
				<label for="sdct_cta_label">
					Button Text <span class="sdct-required">*</span>
				</label>
				<input
					type="text"
					id="sdct_cta_label"
					name="sdct_cta_label"
					value="<?php echo esc_attr( $cta_label ); ?>"
				/>
			</div>
			<div class="sdct-field">
				<label for="sdct_cta_url">
					Button URL <span class="sdct-required">*</span>
				</label>
				<input
					type="text"
					id="sdct_cta_url"
					name="sdct_cta_url"
					value="<?php echo esc_attr( $cta_url ); ?>"
				/>
			</div>
			<p class="sdct-hint">
				CTA renders at the bottom of the page and in the related pages card.
				Default destination: <code>/start/</code>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// RENDER: Status Meta Box (Sidebar)
	// =========================================================================

	public static function render_status_box( $post ) {
		$last_reviewed   = get_post_meta( $post->ID, '_sdct_last_reviewed', true );
		$review_required = get_post_meta( $post->ID, '_sdct_review_required', true );

		?>
		<div class="sdct-meta-box">
			<div class="sdct-field">
				<label for="sdct_last_reviewed">Last Reviewed</label>
				<input
					type="date"
					id="sdct_last_reviewed"
					name="sdct_last_reviewed"
					value="<?php echo esc_attr( $last_reviewed ); ?>"
				/>
			</div>
			<div class="sdct-field sdct-field--checkbox">
				<label>
					<input
						type="checkbox"
						name="sdct_review_required"
						value="1"
						<?php checked( $review_required, '1' ); ?>
					/>
					Review required
				</label>
				<p class="sdct-hint">
					Flag this page for content audit.
				</p>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// RENDER: Repeater Row Partials
	// =========================================================================

	private static function render_body_section_row( $index, $data ) {
		$heading       = isset( $data['heading'] ) ? $data['heading'] : '';
		$heading_level = isset( $data['heading_level'] ) ? $data['heading_level'] : 'h2';
		$body          = isset( $data['body'] ) ? $data['body'] : '';
		?>
		<div class="sdct-repeater-row" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="sdct-repeater-row__handle">
				<span class="dashicons dashicons-menu"></span>
			</div>
			<div class="sdct-repeater-row__fields">
				<div class="sdct-field-row sdct-field-row--heading">
					<div class="sdct-field sdct-field--grow">
						<input
							type="text"
							name="sdct_body_sections[<?php echo esc_attr( $index ); ?>][heading]"
							value="<?php echo esc_attr( $heading ); ?>"
							placeholder="Section heading text"
						/>
					</div>
					<div class="sdct-field sdct-field--select-sm">
						<select name="sdct_body_sections[<?php echo esc_attr( $index ); ?>][heading_level]">
							<option value="h2" <?php selected( $heading_level, 'h2' ); ?>>H2</option>
							<option value="h3" <?php selected( $heading_level, 'h3' ); ?>>H3</option>
						</select>
					</div>
				</div>
				<div class="sdct-field">
					<textarea
						name="sdct_body_sections[<?php echo esc_attr( $index ); ?>][body]"
						rows="6"
						placeholder="Section content. HTML allowed: <strong>, <em>, <a>, <ul>, <ol>, <li>, <p>."
					><?php echo esc_textarea( $body ); ?></textarea>
				</div>
			</div>
			<div class="sdct-repeater-row__actions">
				<button
					type="button"
					class="sdct-remove-row"
					title="Remove this section"
				>&times;</button>
			</div>
		</div>
		<?php
	}

	private static function render_faq_row( $index, $data ) {
		$question = isset( $data['question'] ) ? $data['question'] : '';
		$answer   = isset( $data['answer'] ) ? $data['answer'] : '';
		?>
		<div class="sdct-repeater-row" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="sdct-repeater-row__handle">
				<span class="dashicons dashicons-menu"></span>
			</div>
			<div class="sdct-repeater-row__fields">
				<div class="sdct-field">
					<input
						type="text"
						name="sdct_faq[<?php echo esc_attr( $index ); ?>][question]"
						value="<?php echo esc_attr( $question ); ?>"
						placeholder="Question — write as a natural search query"
					/>
				</div>
				<div class="sdct-field">
					<textarea
						name="sdct_faq[<?php echo esc_attr( $index ); ?>][answer]"
						rows="3"
						placeholder="Answer — plain text preferred for schema compatibility. No HTML."
					><?php echo esc_textarea( $answer ); ?></textarea>
				</div>
			</div>
			<div class="sdct-repeater-row__actions">
				<button
					type="button"
					class="sdct-remove-row"
					title="Remove this question"
				>&times;</button>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// SAVE
	// =========================================================================

	public static function save_meta( $post_id, $post ) {
		// Nonce
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST[ self::NONCE_FIELD ], self::NONCE_ACTION ) ) {
			return;
		}

		// Autosave / revision guard
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Capability guard
		if ( ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}

		// ---- Simple text fields ----
		$text_fields = [
			'sdct_page_title'     => '_sdct_page_title',
			'sdct_topic'          => '_sdct_topic',
			'sdct_topic_cluster'  => '_sdct_topic_cluster',
			'sdct_canonical_url'  => '_sdct_canonical_url',
			'sdct_schema_type'    => '_sdct_schema_type',
			'sdct_author_name'    => '_sdct_author_name',
			'sdct_published_date' => '_sdct_published_date',
			'sdct_cta_label'      => '_sdct_cta_label',
			'sdct_cta_url'        => '_sdct_cta_url',
			'sdct_last_reviewed'  => '_sdct_last_reviewed',
		];

		foreach ( $text_fields as $post_key => $meta_key ) {
			if ( array_key_exists( $post_key, $_POST ) ) {
				update_post_meta(
					$post_id,
					$meta_key,
					sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) )
				);
			}
		}

		// ---- Textarea fields ----
		$textarea_fields = [
			'sdct_answer'           => '_sdct_answer',
			'sdct_meta_description' => '_sdct_meta_description',
		];

		foreach ( $textarea_fields as $post_key => $meta_key ) {
			if ( array_key_exists( $post_key, $_POST ) ) {
				update_post_meta(
					$post_id,
					$meta_key,
					sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) )
				);
			}
		}

		// ---- Checkbox fields ----
		update_post_meta( $post_id, '_sdct_noindex', isset( $_POST['sdct_noindex'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_sdct_review_required', isset( $_POST['sdct_review_required'] ) ? '1' : '0' );

		// ---- Body sections repeater ----
		$sections = [];
		if ( isset( $_POST['sdct_body_sections'] ) && is_array( $_POST['sdct_body_sections'] ) ) {
			foreach ( $_POST['sdct_body_sections'] as $row ) {
				$heading = sanitize_text_field( wp_unslash( $row['heading'] ?? '' ) );
				$body    = wp_kses_post( wp_unslash( $row['body'] ?? '' ) );
				$level   = isset( $row['heading_level'] ) && in_array( $row['heading_level'], [ 'h2', 'h3' ], true )
					? $row['heading_level']
					: 'h2';

				// Skip completely empty rows
				if ( '' === $heading && '' === trim( wp_strip_all_tags( $body ) ) ) {
					continue;
				}

				$sections[] = [
					'heading'       => $heading,
					'heading_level' => $level,
					'body'          => $body,
				];
			}
		}
		update_post_meta( $post_id, '_sdct_body_sections', wp_json_encode( $sections ) );

		// ---- FAQ repeater ----
		$faq = [];
		if ( isset( $_POST['sdct_faq'] ) && is_array( $_POST['sdct_faq'] ) ) {
			foreach ( $_POST['sdct_faq'] as $row ) {
				$question = sanitize_text_field( wp_unslash( $row['question'] ?? '' ) );
				$answer   = sanitize_textarea_field( wp_unslash( $row['answer'] ?? '' ) );

				if ( '' === $question && '' === $answer ) {
					continue;
				}

				$faq[] = [
					'question' => $question,
					'answer'   => $answer,
				];
			}
		}
		update_post_meta( $post_id, '_sdct_faq', wp_json_encode( $faq ) );

		// ---- Enforce empty post_content ----
		// Template owns rendering. Clear any residual content without triggering
		// an infinite save_post loop.
		if ( ! empty( $post->post_content ) ) {
			remove_action( 'save_post_sd_authority', [ __CLASS__, 'save_meta' ], 10 );
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => '',
			] );
			add_action( 'save_post_sd_authority', [ __CLASS__, 'save_meta' ], 10, 2 );
		}
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	/**
	 * Get a JSON-encoded meta field and decode it to an array.
	 * Returns empty array on missing or invalid JSON.
	 */
	private static function get_json_meta( $post_id, $key ) {
		$raw = get_post_meta( $post_id, $key, true );
		if ( ! $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// =========================================================================
	// ADMIN CSS (inline on wp-admin handle)
	// =========================================================================

	private static function admin_css() {
		return '
		/* sdct meta boxes */
		#sdct_authority_content .inside,
		#sdct_authority_seo .inside,
		#sdct_authority_cta .inside,
		#sdct_authority_status .inside {
			padding: 12px 16px 16px;
		}

		.sdct-notice {
			background: #eef2ff;
			border-left: 3px solid #4338ca;
			padding: 10px 14px;
			margin-bottom: 20px;
			font-size: 12px;
			color: #312e81;
			border-radius: 3px;
		}

		.sdct-meta-box .sdct-field {
			margin-bottom: 18px;
		}

		.sdct-meta-box .sdct-field label {
			display: block;
			font-weight: 600;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: .04em;
			color: #1e293b;
			margin-bottom: 5px;
		}

		.sdct-meta-box input[type="text"],
		.sdct-meta-box input[type="url"],
		.sdct-meta-box input[type="date"],
		.sdct-meta-box select,
		.sdct-meta-box textarea {
			width: 100%;
			box-sizing: border-box;
			border: 1px solid #d1d5db;
			border-radius: 4px;
			padding: 7px 10px;
			font-size: 13px;
			font-family: inherit;
			background: #fff;
			color: #0f172a;
		}

		.sdct-meta-box textarea {
			resize: vertical;
			line-height: 1.5;
		}

		.sdct-meta-box input:focus,
		.sdct-meta-box select:focus,
		.sdct-meta-box textarea:focus {
			border-color: #287fae;
			outline: none;
			box-shadow: 0 0 0 2px rgba(40, 127, 174, 0.15);
		}

		.sdct-required { color: #b32d2e; margin-left: 2px; }

		.sdct-hint {
			color: #64748b;
			font-size: 11px;
			margin: 4px 0 0;
			line-height: 1.5;
		}

		/* Two-column rows */
		.sdct-field-row {
			display: flex;
			gap: 16px;
			align-items: flex-start;
		}

		.sdct-field-row--two .sdct-field { flex: 1; }

		.sdct-field--half { max-width: 50%; }

		.sdct-field--grow { flex: 1 1 auto !important; }

		.sdct-field--select-sm {
			flex: 0 0 72px !important;
		}

		.sdct-field--checkbox label {
			display: flex;
			align-items: center;
			gap: 8px;
			font-weight: normal;
			text-transform: none;
			letter-spacing: 0;
			font-size: 13px;
			cursor: pointer;
		}

		/* Repeater sections */
		.sdct-repeater-section {
			margin-top: 28px;
			padding-top: 20px;
			border-top: 1px solid #e2e8f0;
		}

		.sdct-repeater-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 8px;
		}

		.sdct-repeater-header h3 {
			margin: 0;
			font-size: 13px;
			font-weight: 700;
			color: #0f172a;
		}

		.sdct-badge {
			display: inline-block;
			background: #eef2ff;
			color: #4338ca;
			font-size: 10px;
			font-weight: 700;
			padding: 2px 7px;
			border-radius: 4px;
			letter-spacing: .04em;
			margin-left: 8px;
			vertical-align: middle;
			text-transform: uppercase;
		}

		.sdct-repeater {
			display: flex;
			flex-direction: column;
			gap: 10px;
			margin-top: 10px;
		}

		.sdct-repeater-row {
			display: flex;
			gap: 10px;
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			border-radius: 6px;
			padding: 14px 12px;
		}

		.sdct-repeater-row__handle {
			flex: 0 0 20px;
			display: flex;
			align-items: flex-start;
			padding-top: 5px;
			color: #94a3b8;
			cursor: grab;
			user-select: none;
		}

		.sdct-repeater-row__fields {
			flex: 1;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}

		/* Remove margins inside repeater rows */
		.sdct-repeater-row .sdct-field {
			margin-bottom: 0;
		}

		.sdct-repeater-row__actions {
			flex: 0 0 24px;
			display: flex;
			align-items: flex-start;
			padding-top: 3px;
		}

		.sdct-remove-row {
			background: none;
			border: none;
			color: #94a3b8;
			font-size: 20px;
			cursor: pointer;
			line-height: 1;
			padding: 0;
			transition: color .15s;
		}

		.sdct-remove-row:hover {
			color: #b32d2e;
		}

		.sdct-field-row--heading {
			align-items: center;
			margin-bottom: 0;
		}

		/* Char counter color states */
		.sdct-count { font-weight: 700; transition: color .2s; }
		';
	}

	// =========================================================================
	// ADMIN JS (vanilla — no jQuery dependency)
	// =========================================================================

	private static function admin_js() {
		return '
(function () {
	"use strict";

	// ---- Add Row ----------------------------------------------------------------
	document.querySelectorAll(".sdct-add-row").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var targetId  = btn.getAttribute("data-target");
			var type      = btn.getAttribute("data-type");
			var container = document.getElementById(targetId);
			var template  = document.getElementById("sdct-" + type + "-template");

			if (!container || !template) return;

			var index = container.querySelectorAll(".sdct-repeater-row").length;
			var html  = template.innerHTML.replace(/__INDEX__/g, String(index));
			var wrap  = document.createElement("div");
			wrap.innerHTML = html;

			var row = wrap.firstElementChild;
			if (!row) return;

			container.appendChild(row);
			bindRemoveButton(row, container);

			// Focus first input in new row
			var first = row.querySelector("input, textarea");
			if (first) first.focus();
		});
	});

	// ---- Remove Row -------------------------------------------------------------
	function bindRemoveButton(row, container) {
		var btn = row.querySelector(".sdct-remove-row");
		if (!btn) return;

		btn.addEventListener("click", function () {
			row.remove();
			reindexRows(container);
		});
	}

	// Bind existing rows on page load
	document.querySelectorAll(".sdct-repeater").forEach(function (container) {
		container.querySelectorAll(".sdct-repeater-row").forEach(function (row) {
			bindRemoveButton(row, container);
		});
	});

	// ---- Reindex after remove ---------------------------------------------------
	function reindexRows(container) {
		if (!container) return;

		container.querySelectorAll(".sdct-repeater-row").forEach(function (row, i) {
			row.setAttribute("data-index", String(i));

			row.querySelectorAll("[name]").forEach(function (el) {
				// Replace [N] or [__INDEX__] with the new index
				el.name = el.name.replace(/\[(\d+|__INDEX__)\]/, "[" + i + "]");
			});
		});
	}

	// ---- Meta Description character counter ------------------------------------
	document.querySelectorAll(".sdct-char-count").forEach(function (hint) {
		var targetId = hint.getAttribute("data-target");
		var field    = document.getElementById(targetId);
		var counter  = hint.querySelector(".sdct-count");

		if (!field || !counter) return;

		function update() {
			var len = field.value.length;
			counter.textContent = String(len);

			if (len === 0) {
				counter.style.color = "#94a3b8";
			} else if (len < 140 || len > 165) {
				counter.style.color = "#b32d2e";
			} else {
				counter.style.color = "#15803d";
			}
		}

		field.addEventListener("input", update);
		update();
	});

})();
		';
	}
}
