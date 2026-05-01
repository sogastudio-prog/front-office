<?php
/**
 * SD_Front_Office_Admin (v2.0 — Pipeline Monitor)
 *
 * Admin surface for monitoring the full prospect → provision package →
 * checkout → backend tenant pipeline.
 *
 * Surfaces:
 *   1. Enhanced sd_prospect list columns (lifecycle, billing, package, tenant)
 *   2. Pipeline Monitor page  — funnel summary + filterable full table
 *   3. Prospect Detail page   — full chain: prospect → pkg → runtime tenant
 *
 * Canon:
 *   - Read-only. No state writes from this surface.
 *   - sd_provision_package is linked through sd_provision_package_post_id.
 *   - Runtime tenant is confirmed when sd_runtime_tenant_post_id > 0.
 *   - Billing truth is sd_billing_status on the prospect.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'SD_Front_Office_Admin', false ) ) { return; }

final class SD_Front_Office_Admin {

	private const CPT_PROSPECT = 'sd_prospect';
	private const CPT_PACKAGE  = 'sd_provision_package';
	private const PAGE_PIPELINE = 'sd-prospect-pipeline';
	private const PAGE_DETAIL   = 'sd-prospect-detail';

	// Canonical stage order for the funnel
	private const STAGE_ORDER = [
		'INTAKE_CAPTURED'         => [ 'label' => 'Intake',      'color' => '#64748b' ],
		'SLUG_PENDING'            => [ 'label' => 'Slug pending', 'color' => '#d97706' ],
		'SLUG_RESERVED'           => [ 'label' => 'Slug reserved','color' => '#2563eb' ],
		'CHECKOUT_PENDING'        => [ 'label' => 'Checkout',     'color' => '#7c3aed' ],
		'SUBSCRIPTION_PAID'       => [ 'label' => 'Paid',         'color' => '#059669' ],
		'TENANT_PROVISIONING'     => [ 'label' => 'Provisioning', 'color' => '#0891b2' ],
		'PROVISION_PACKAGE_STAGED'=> [ 'label' => 'Staged',       'color' => '#0891b2' ],
		'ACTIVATED'               => [ 'label' => 'Active',       'color' => '#16a34a' ],
	];

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function bootstrap() : void {
		add_action( 'admin_menu',    [ __CLASS__, 'register_menus' ] );
		add_action( 'admin_head',    [ __CLASS__, 'inline_styles' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );

		// List table enhancements
		add_filter( 'manage_' . self::CPT_PROSPECT . '_posts_columns',        [ __CLASS__, 'list_columns' ] );
		add_action( 'manage_' . self::CPT_PROSPECT . '_posts_custom_column',  [ __CLASS__, 'list_column_content' ], 10, 2 );
		add_filter( 'manage_edit-' . self::CPT_PROSPECT . '_sortable_columns',[ __CLASS__, 'sortable_columns' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'handle_sort_and_filter' ] );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'list_stage_filter_dropdown' ] );
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	public static function register_menus() : void {
		$parent = 'edit.php?post_type=' . self::CPT_PROSPECT;

		add_submenu_page(
			$parent,
			'Pipeline Monitor',
			'Pipeline',
			'manage_options',
			self::PAGE_PIPELINE,
			[ __CLASS__, 'render_pipeline_page' ]
		);

		// Hidden detail page — linked via query arg
		add_submenu_page(
			null,
			'Prospect Detail',
			'Prospect Detail',
			'manage_options',
			self::PAGE_DETAIL,
			[ __CLASS__, 'render_detail_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// List table: columns
	// -------------------------------------------------------------------------

	public static function list_columns( array $cols ) : array {
		return [
			'cb'         => '<input type="checkbox">',
			'title'      => 'Name / Contact',
			'sdf_stage'  => 'Stage',
			'sdf_billing'=> 'Billing',
			'sdf_slug'   => 'Slug',
			'sdf_pkg'    => 'Package',
			'sdf_tenant' => 'Tenant',
			'sdf_age'    => 'Age',
		];
	}

	public static function sortable_columns( array $cols ) : array {
		$cols['sdf_stage']   = 'sd_lifecycle_stage';
		$cols['sdf_billing'] = 'sd_billing_status';
		$cols['sdf_age']     = 'date';
		return $cols;
	}

	public static function handle_sort_and_filter( \WP_Query $q ) : void {
		if ( ! is_admin() || ! $q->is_main_query() ) { return; }
		if ( $q->get( 'post_type' ) !== self::CPT_PROSPECT ) { return; }

		$orderby = $q->get( 'orderby' );
		if ( in_array( $orderby, [ 'sd_lifecycle_stage', 'sd_billing_status' ], true ) ) {
			$q->set( 'meta_key', $orderby );
			$q->set( 'orderby', 'meta_value' );
		}

		// Stage filter from dropdown
		$stage = isset( $_GET['sdf_stage'] ) ? sanitize_key( wp_unslash( $_GET['sdf_stage'] ) ) : '';
		if ( $stage !== '' ) {
			$existing = (array) $q->get( 'meta_query' );
			$existing[] = [ 'key' => 'sd_lifecycle_stage', 'value' => strtoupper( $stage ), 'compare' => '=' ];
			$q->set( 'meta_query', $existing );
		}
	}

	public static function list_stage_filter_dropdown() : void {
		global $pagenow;
		if ( $pagenow !== 'edit.php' ) { return; }
		if ( ( $_GET['post_type'] ?? '' ) !== self::CPT_PROSPECT ) { return; }

		$current = isset( $_GET['sdf_stage'] ) ? sanitize_key( wp_unslash( $_GET['sdf_stage'] ) ) : '';
		echo '<select name="sdf_stage" style="max-width:160px;">';
		echo '<option value="">All stages</option>';
		foreach ( self::STAGE_ORDER as $key => $meta ) {
			echo '<option value="' . esc_attr( strtolower( $key ) ) . '"'
				. selected( $current, strtolower( $key ), false ) . '>'
				. esc_html( $meta['label'] ) . '</option>';
		}
		echo '</select>';
	}

	public static function list_column_content( string $col, int $post_id ) : void {
		$m = fn( $k ) => (string) get_post_meta( $post_id, $k, true );

		switch ( $col ) {

			case 'title':
				$name  = $m( 'sd_full_name' );
				$email = $m( 'sd_email_normalized' ) ?: $m( 'sd_email_raw' );
				$phone = $m( 'sd_phone_normalized' ) ?: $m( 'sd_phone_raw' );
				$token = $m( 'sd_prospect_token' );

				$detail_url = add_query_arg( [
					'page'        => self::PAGE_DETAIL,
					'prospect_id' => $post_id,
				], admin_url( 'edit.php?post_type=' . self::CPT_PROSPECT ) );

				echo '<strong><a href="' . esc_url( $detail_url ) . '">' . esc_html( $name !== '' ? $name : '(unnamed)' ) . '</a></strong>';
				if ( $email !== '' ) { echo '<div class="sdf-sub">' . esc_html( $email ) . '</div>'; }
				if ( $phone !== '' ) { echo '<div class="sdf-sub">' . esc_html( $phone ) . '</div>'; }
				if ( $token !== '' ) {
					$prospect_url = home_url( '/prospect/' . rawurlencode( $token ) . '/' );
					echo '<div class="sdf-sub"><a href="' . esc_url( $prospect_url ) . '" target="_blank" rel="noopener">↗ Prospect page</a></div>';
				}
				break;

			case 'sdf_stage':
				$stage = $m( 'sd_lifecycle_stage' );
				echo self::stage_badge( $stage );
				$state = $m( 'sd_activation_state' );
				if ( $state !== '' && $state !== $stage ) {
					echo '<div class="sdf-sub">' . esc_html( $state ) . '</div>';
				}
				break;

			case 'sdf_billing':
				$billing = $m( 'sd_billing_status' );
				echo self::billing_badge( $billing );
				$paid_at = $m( 'sd_subscription_paid_at_gmt' );
				if ( $paid_at !== '' ) {
					$ts = strtotime( $paid_at );
					echo '<div class="sdf-sub">' . esc_html( human_time_diff( $ts, time() ) . ' ago' ) . '</div>';
				}
				break;

			case 'sdf_slug':
				$slug   = $m( 'sd_reserved_slug' );
				$status = $m( 'sd_slug_status' );
				if ( $slug !== '' ) {
					echo '<code class="sdf-code">' . esc_html( $slug ) . '</code>';
					if ( $status !== '' ) {
						echo '<div class="sdf-sub">' . esc_html( $status ) . '</div>';
					}
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				break;

			case 'sdf_pkg':
				$pkg_post_id = (int) $m( 'sd_provision_package_post_id' );
				$pkg_id      = $m( 'sd_provision_package_id' );
				if ( $pkg_post_id > 0 ) {
					$pkg_status = (string) get_post_meta( $pkg_post_id, 'sd_package_status', true );
					$prov_status = (string) get_post_meta( $pkg_post_id, 'sd_provisioning_status', true );
					$pkg_url = get_edit_post_link( $pkg_post_id, '' );
					if ( $pkg_url ) {
						echo '<a href="' . esc_url( $pkg_url ) . '" class="sdf-pkg-link">' . esc_html( substr( $pkg_id, 0, 14 ) . '…' ) . '</a>';
					} else {
						echo '<code class="sdf-code">' . esc_html( substr( $pkg_id, 0, 14 ) . '…' ) . '</code>';
					}
					if ( $pkg_status !== '' ) { echo '<div class="sdf-sub">' . esc_html( $pkg_status ) . '</div>'; }
					if ( $prov_status !== '' ) { echo '<div class="sdf-sub sdf-sub-em">' . esc_html( $prov_status ) . '</div>'; }
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				break;

			case 'sdf_tenant':
				$tenant_post_id = (int) $m( 'sd_runtime_tenant_post_id' );
				$tenant_id      = $m( 'sd_runtime_tenant_id' );
				$ops_url        = $m( 'sd_operations_entry_url' );
				if ( $tenant_post_id > 0 || $tenant_id !== '' ) {
					echo '<span class="sdf-badge sdf-badge-ok">✓ Provisioned</span>';
					if ( $ops_url !== '' ) {
						echo '<div class="sdf-sub"><a href="' . esc_url( $ops_url ) . '" target="_blank" rel="noopener">↗ Ops</a></div>';
					}
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				break;

			case 'sdf_age':
				$created = $m( 'sd_created_at_gmt' );
				$ts      = $created !== '' ? strtotime( $created ) : get_post_timestamp( $post_id );
				if ( $ts > 0 ) {
					$days = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
					$cls  = $days >= 14 ? 'sdf-age-old' : ( $days >= 7 ? 'sdf-age-mid' : '' );
					echo '<span class="' . esc_attr( $cls ) . '">' . (int) $days . 'd</span>';
					echo '<div class="sdf-sub">' . esc_html( wp_date( 'M j', $ts ) ) . '</div>';
				}
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Pipeline Monitor page
	// -------------------------------------------------------------------------

	public static function render_pipeline_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }

		$prospects = get_posts( [
			'post_type'      => self::CPT_PROSPECT,
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		// Build stage counts and segment lists
		$stage_counts = array_fill_keys( array_keys( self::STAGE_ORDER ), 0 );
		$stage_counts['_other'] = 0;
		$rows = [];

		foreach ( $prospects as $p ) {
			$stage   = strtoupper( (string) get_post_meta( $p->ID, 'sd_lifecycle_stage', true ) );
			$billing = (string) get_post_meta( $p->ID, 'sd_billing_status', true );
			$slug    = (string) get_post_meta( $p->ID, 'sd_reserved_slug', true );
			$pkg_pid = (int)    get_post_meta( $p->ID, 'sd_provision_package_post_id', true );
			$tenant  = (int)    get_post_meta( $p->ID, 'sd_runtime_tenant_post_id', true );
			$email   = (string) get_post_meta( $p->ID, 'sd_email_normalized', true );
			$name    = (string) get_post_meta( $p->ID, 'sd_full_name', true );
			$token   = (string) get_post_meta( $p->ID, 'sd_prospect_token', true );
			$created = (string) get_post_meta( $p->ID, 'sd_created_at_gmt', true );
			$created_ts = $created !== '' ? (int) strtotime( $created ) : (int) get_post_timestamp( $p->ID );

			$pkg_status   = $pkg_pid > 0 ? (string) get_post_meta( $pkg_pid, 'sd_package_status',      true ) : '';
			$prov_status  = $pkg_pid > 0 ? (string) get_post_meta( $pkg_pid, 'sd_provisioning_status', true ) : '';
			$pkg_billing  = $pkg_pid > 0 ? (string) get_post_meta( $pkg_pid, 'sd_billing_status',      true ) : '';

			if ( isset( $stage_counts[ $stage ] ) ) {
				$stage_counts[ $stage ]++;
			} else {
				$stage_counts['_other']++;
			}

			$rows[] = compact(
				'p', 'stage', 'billing', 'slug', 'pkg_pid', 'tenant',
				'email', 'name', 'token', 'created_ts',
				'pkg_status', 'prov_status', 'pkg_billing'
			);
		}

		// Filter by stage
		$filter_stage = isset( $_GET['filter_stage'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['filter_stage'] ) ) ) : '';
		if ( $filter_stage !== '' ) {
			$rows = array_filter( $rows, fn( $r ) => $r['stage'] === $filter_stage );
		}

		$base_url = admin_url( 'edit.php?post_type=' . self::CPT_PROSPECT . '&page=' . self::PAGE_PIPELINE );

		// -------------------------------------------------------------------------
		echo '<div class="wrap sdf-wrap">';
		echo '<h1 class="sdf-page-title">Prospect Pipeline</h1>';

		// -- Funnel strip --
		echo '<div class="sdf-funnel">';
		$total = count( $prospects );
		foreach ( self::STAGE_ORDER as $key => $meta ) {
			$count   = $stage_counts[ $key ] ?? 0;
			$is_filt = ( $filter_stage === $key );
			$href    = $is_filt
				? $base_url
				: add_query_arg( 'filter_stage', strtolower( $key ), $base_url );
			$pct     = $total > 0 ? round( $count / $total * 100 ) : 0;

			echo '<a href="' . esc_url( $href ) . '" class="sdf-funnel-step' . ( $is_filt ? ' is-active' : '' ) . '">';
			echo '<div class="sdf-funnel-count" style="color:' . esc_attr( $meta['color'] ) . '">' . (int) $count . '</div>';
			echo '<div class="sdf-funnel-label">' . esc_html( $meta['label'] ) . '</div>';
			if ( $total > 0 ) {
				echo '<div class="sdf-funnel-pct">' . (int) $pct . '%</div>';
			}
			echo '</a>';
		}
		echo '</div>';

		// -- Filter notice --
		if ( $filter_stage !== '' ) {
			$label = self::STAGE_ORDER[ $filter_stage ]['label'] ?? $filter_stage;
			echo '<div class="sdf-filter-notice">Showing stage: <strong>' . esc_html( $label ) . '</strong> · <a href="' . esc_url( $base_url ) . '">Clear filter ✕</a></div>';
		}

		// -- Summary counts --
		$paid_count   = count( array_filter( $rows, fn( $r ) => $r['billing'] === 'SUBSCRIPTION_PAID' ) );
		$active_count = count( array_filter( $rows, fn( $r ) => $r['tenant'] > 0 ) );
		$pending_count = count( array_filter( $rows, fn( $r ) => $r['tenant'] <= 0 && $r['billing'] === 'SUBSCRIPTION_PAID' ) );

		echo '<div class="sdf-kpi-row">';
		echo '<div class="sdf-kpi"><span class="sdf-kpi-n">' . (int) $total . '</span><span class="sdf-kpi-l">Total prospects</span></div>';
		echo '<div class="sdf-kpi sdf-kpi-ok"><span class="sdf-kpi-n">' . (int) $paid_count . '</span><span class="sdf-kpi-l">Paid</span></div>';
		echo '<div class="sdf-kpi sdf-kpi-live"><span class="sdf-kpi-n">' . (int) $active_count . '</span><span class="sdf-kpi-l">Tenants live</span></div>';
		if ( $pending_count > 0 ) {
			echo '<div class="sdf-kpi sdf-kpi-warn"><span class="sdf-kpi-n">' . (int) $pending_count . '</span><span class="sdf-kpi-l">Paid, not provisioned</span></div>';
		}
		echo '</div>';

		// -- Table --
		if ( empty( $rows ) ) {
			echo '<div class="sdf-empty">No prospects found for this filter.</div>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped sdf-table">';
			echo '<thead><tr>';
			foreach ( [
				'Name / Contact', 'Stage', 'Billing', 'Slug', 'Provision Package',
				'Prov. status', 'Tenant', 'Age',
			] as $th ) {
				echo '<th>' . esc_html( $th ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $rows as $r ) {
				$pid        = (int) $r['p']->ID;
				$detail_url = add_query_arg( [
					'page'        => self::PAGE_DETAIL,
					'prospect_id' => $pid,
				], admin_url( 'edit.php?post_type=' . self::CPT_PROSPECT ) );

				$days = $r['created_ts'] > 0 ? (int) floor( ( time() - $r['created_ts'] ) / DAY_IN_SECONDS ) : 0;
				$age_cls = $days >= 14 ? 'sdf-age-old' : ( $days >= 7 ? 'sdf-age-mid' : '' );

				echo '<tr>';

				// Name / contact
				echo '<td>';
				echo '<a href="' . esc_url( $detail_url ) . '" class="sdf-name-link">'
					. esc_html( $r['name'] !== '' ? $r['name'] : '(unnamed)' ) . '</a>';
				if ( $r['email'] !== '' ) { echo '<div class="sdf-sub">' . esc_html( $r['email'] ) . '</div>'; }
				echo '</td>';

				// Stage
				echo '<td>' . self::stage_badge( $r['stage'] ) . '</td>';

				// Billing
				echo '<td>' . self::billing_badge( $r['billing'] ) . '</td>';

				// Slug
				echo '<td>';
				if ( $r['slug'] !== '' ) {
					echo '<code class="sdf-code">' . esc_html( $r['slug'] ) . '</code>';
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				echo '</td>';

				// Package
				echo '<td>';
				if ( $r['pkg_pid'] > 0 ) {
					$pkg_edit = get_edit_post_link( $r['pkg_pid'], '' );
					$pkg_id   = (string) get_post_meta( $r['pkg_pid'], 'sd_provision_package_id', true );
					if ( $pkg_edit ) {
						echo '<a href="' . esc_url( $pkg_edit ) . '" class="sdf-sub">' . esc_html( substr( $pkg_id, 0, 16 ) . '…' ) . '</a>';
					} else {
						echo '<span class="sdf-sub">' . esc_html( substr( $pkg_id, 0, 16 ) . '…' ) . '</span>';
					}
					if ( $r['pkg_status'] !== '' ) {
						echo '<div class="sdf-sub">' . esc_html( $r['pkg_status'] ) . '</div>';
					}
					echo '</td>';
				} else {
					echo '<span class="sdf-muted">—</span></td>';
				}

				// Prov status
				echo '<td>';
				if ( $r['prov_status'] !== '' ) {
					echo self::prov_status_badge( $r['prov_status'] );
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				echo '</td>';

				// Tenant
				echo '<td>';
				if ( $r['tenant'] > 0 ) {
					echo '<span class="sdf-badge sdf-badge-ok">✓ Live</span>';
				} else {
					echo '<span class="sdf-muted">—</span>';
				}
				echo '</td>';

				// Age
				echo '<td><span class="' . esc_attr( $age_cls ) . '">' . (int) $days . 'd</span></td>';

				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>'; // .sdf-wrap
	}

	// -------------------------------------------------------------------------
	// Prospect Detail page
	// -------------------------------------------------------------------------

	public static function render_detail_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }

		$prospect_id = isset( $_GET['prospect_id'] ) ? absint( wp_unslash( $_GET['prospect_id'] ) ) : 0;
		if ( $prospect_id <= 0 || get_post_type( $prospect_id ) !== self::CPT_PROSPECT ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>Invalid prospect.</p></div></div>';
			return;
		}

		$m = fn( $k ) => (string) get_post_meta( $prospect_id, $k, true );

		$pkg_post_id    = (int) $m( 'sd_provision_package_post_id' );
		$tenant_post_id = (int) $m( 'sd_runtime_tenant_post_id' );

		$pipeline_url = admin_url( 'edit.php?post_type=' . self::CPT_PROSPECT . '&page=' . self::PAGE_PIPELINE );
		$edit_url     = get_edit_post_link( $prospect_id, '' );
		$token        = $m( 'sd_prospect_token' );
		$prospect_url = $token !== '' ? home_url( '/prospect/' . rawurlencode( $token ) . '/' ) : '';

		$name    = $m( 'sd_full_name' );
		$email   = $m( 'sd_email_normalized' ) ?: $m( 'sd_email_raw' );
		$phone   = $m( 'sd_phone_normalized' ) ?: $m( 'sd_phone_raw' );
		$stage   = $m( 'sd_lifecycle_stage' );
		$billing = $m( 'sd_billing_status' );

		$pm              = $pkg_post_id > 0 ? fn( $k ) => (string) get_post_meta( $pkg_post_id, $k, true ) : fn( $k ) => '';
		$pkg_prov_status = $pm( 'sd_provisioning_status' );
		$runtime_tid     = $m( 'sd_runtime_tenant_id' );
		$has_tenant      = $tenant_post_id > 0 || $runtime_tid !== '';
		$is_paid         = $billing === 'SUBSCRIPTION_PAID';

		// Avatar initials
		$initials = '';
		if ( $name !== '' ) {
			$parts    = preg_split( '/\s+/', trim( $name ) );
			$initials = strtoupper( substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
		}

		echo '<div class="wrap sdf-wrap">';

		// Breadcrumb
		echo '<div class="sdf-breadcrumb">';
		echo '<a href="' . esc_url( $pipeline_url ) . '">← Pipeline</a>';
		echo ' <span class="sdf-breadcrumb-sep">·</span> ';
		echo esc_html( $name ?: 'Prospect #' . $prospect_id );
		echo '</div>';

		// ---- Hero card ----
		echo '<div class="sdf-hero-card">';
		echo '<div class="sdf-hero-left">';
		echo '<div class="sdf-hero-avatar">' . esc_html( $initials ?: '#' ) . '</div>';
		echo '<div class="sdf-hero-info">';
		echo '<div class="sdf-hero-name">' . esc_html( $name ?: '(unnamed)' ) . '</div>';
		if ( $email !== '' ) { echo '<div class="sdf-hero-contact">' . esc_html( $email ) . '</div>'; }
		if ( $phone !== '' ) { echo '<div class="sdf-hero-contact">' . esc_html( $phone ) . '</div>'; }
		echo '<div class="sdf-hero-badges">';
		echo self::stage_badge( $stage );
		echo self::billing_badge( $billing );
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div class="sdf-hero-actions">';
		if ( $edit_url ) {
			echo '<a href="' . esc_url( $edit_url ) . '" class="button">Edit record</a>';
		}
		if ( $prospect_url !== '' ) {
			echo '<a href="' . esc_url( $prospect_url ) . '" class="button" target="_blank" rel="noopener">↗ Prospect page</a>';
		}
		echo '</div>';
		echo '</div>';

		// ---- Pipeline strip ----
		self::render_pipeline_strip( $stage );

		// ---- Vitals strip ----
		$prov_done = $pkg_prov_status === 'provisioned';
		$prov_cls  = $prov_done ? 'ok' : ( $pkg_prov_status !== '' ? 'warn' : 'neutral' );
		$prov_val  = $prov_done ? 'Provisioned' : ( $pkg_prov_status !== '' ? ucwords( strtolower( str_replace( '_', ' ', $pkg_prov_status ) ) ) : 'Not started' );

		echo '<div class="sdf-vitals-row">';
		self::vital_chip(
			'Billing',
			$is_paid ? 'Paid' : ( $billing !== '' ? ucwords( strtolower( str_replace( '_', ' ', $billing ) ) ) : 'Unpaid' ),
			$is_paid ? 'ok' : ( $billing === 'SUBSCRIPTION_FAILED' ? 'err' : 'neutral' )
		);
		self::vital_chip( 'Package', $pkg_post_id > 0 ? 'Linked' : 'None', $pkg_post_id > 0 ? 'ok' : 'neutral' );
		self::vital_chip( 'Provisioning', $prov_val, $prov_cls );
		self::vital_chip(
			'Tenant',
			$has_tenant ? 'Live' : ( $is_paid ? 'Pending' : 'Not provisioned' ),
			$has_tenant ? 'ok' : ( $is_paid ? 'warn' : 'neutral' )
		);
		echo '</div>';

		// ---- Two-panel grid ----
		echo '<div class="sdf-detail-grid">';

		// Panel 1: Prospect identity
		echo '<div class="sdf-detail-panel">';
		echo '<div class="sdf-panel-head">Prospect</div>';
		self::detail_row( 'Post ID', (string) $prospect_id );
		self::detail_row( 'Prospect ID', $m( 'sd_prospect_id' ) );
		self::detail_row( 'Stage', self::stage_badge( $stage ), false );
		self::detail_row( 'Activation state', $m( 'sd_activation_state' ) );
		self::detail_row( 'Name', $name );
		self::detail_row( 'Email', $email );
		self::detail_row( 'Phone', $phone );
		self::detail_row( 'Source', $m( 'sd_source' ) );
		self::detail_row( 'Review', $m( 'sd_review_status' ) );
		self::detail_row( 'Invitation code', $m( 'sd_invitation_code' ) );
		self::detail_row( 'Invite status', $m( 'sd_invitation_status' ) );
		self::detail_row( 'Created', self::fmt_gmt( $m( 'sd_created_at_gmt' ) ) );
		self::detail_row( 'Updated', self::fmt_gmt( $m( 'sd_updated_at_gmt' ) ) );
		self::detail_row( 'Submissions', $m( 'sd_submission_count' ) );
		if ( $prospect_url !== '' ) {
			self::detail_row( 'Prospect URL', '<a href="' . esc_url( $prospect_url ) . '" target="_blank" rel="noopener">' . esc_html( $prospect_url ) . '</a>', false );
		}
		echo '</div>';

		// Panel 2: Billing & Stripe
		echo '<div class="sdf-detail-panel">';
		echo '<div class="sdf-panel-head">Billing &amp; Stripe</div>';
		self::detail_row( 'Billing status', self::billing_badge( $billing ), false );
		self::detail_row( 'Paid at', self::fmt_gmt( $m( 'sd_subscription_paid_at_gmt' ) ) );
		self::detail_row( 'Plan', $m( 'sd_resolved_plan_label' ) );
		self::detail_row( 'Price ID', self::id_chip( $m( 'sd_resolved_stripe_price_id' ) ), false );
		self::detail_row( 'Checkout session', self::id_chip( self::mask_str( $m( 'sd_stripe_checkout_session_id' ) ) ), false );
		self::detail_row( 'Customer ID', self::id_chip( $m( 'sd_stripe_customer_id' ) ), false );
		self::detail_row( 'Subscription ID', self::id_chip( $m( 'sd_stripe_subscription_id' ) ), false );

		echo '<div class="sdf-panel-sub-head">Connect account</div>';
		self::detail_row( 'Account ID', self::id_chip( $m( 'sd_stripe_account_id' ) ), false );
		self::detail_row( 'Onboarding status', $m( 'sd_stripe_onboarding_status' ) );
		self::detail_row( 'Connect state', $m( 'sd_stripe_state' ) );
		self::detail_row( 'Charges enabled',   self::bool_chip( $m( 'sd_stripe_charges_enabled' )   === '1' ), false );
		self::detail_row( 'Payouts enabled',    self::bool_chip( $m( 'sd_stripe_payouts_enabled' )    === '1' ), false );
		self::detail_row( 'Details submitted',  self::bool_chip( $m( 'sd_stripe_details_submitted' )  === '1' ), false );

		$disabled_reason = $m( 'sd_stripe_disabled_reason' );
		if ( $disabled_reason !== '' ) {
			self::detail_row( 'Disabled reason', '<span class="sdf-alert-text">' . esc_html( $disabled_reason ) . '</span>', false );
		}

		$currently_due = json_decode( $m( 'sd_stripe_requirements_currently_due_json' ), true );
		if ( is_array( $currently_due ) && ! empty( $currently_due ) ) {
			self::detail_row( 'Currently due', '<span class="sdf-alert-text">' . esc_html( implode( ', ', $currently_due ) ) . '</span>', false );
		}

		echo '</div>'; // Panel 2

		echo '</div>'; // .sdf-detail-grid

		// ---- Provision Package block ----
		echo '<div class="sdf-chain-block">';
		echo '<div class="sdf-chain-head">Provision Package</div>';

		if ( $pkg_post_id > 0 ) {
			$pkg_edit = get_edit_post_link( $pkg_post_id, '' );
			$slug     = $pm( 'sd_reserved_slug' );

			echo '<div class="sdf-chain-body">';

			echo '<div class="sdf-chain-section">';
			if ( $slug !== '' ) {
				echo '<div class="sdf-chain-slug">/' . esc_html( $slug ) . '</div>';
			}
			if ( $pkg_edit ) {
				echo '<a href="' . esc_url( $pkg_edit ) . '" class="button button-small sdf-chain-edit-link">Edit package record</a>';
			}
			self::detail_row( 'Post ID', (string) $pkg_post_id );
			self::detail_row( 'Package ID', $pm( 'sd_provision_package_id' ) );
			self::detail_row( 'Package status', $pm( 'sd_package_status' ) );
			self::detail_row( 'Billing', self::billing_badge( $pm( 'sd_billing_status' ) ), false );
			self::detail_row( 'Paid at', self::fmt_gmt( $pm( 'sd_subscription_paid_at_gmt' ) ) );
			echo '</div>';

			echo '<div class="sdf-chain-section">';
			echo '<div class="sdf-panel-sub-head">Provisioning</div>';
			self::detail_row( 'Status', self::prov_status_badge( $pkg_prov_status ), false );
			self::detail_row( 'Provisioned at', self::fmt_gmt( $pm( 'sd_provisioned_at_gmt' ) ) );
			self::detail_row( 'Runtime tenant ID', $pm( 'sd_runtime_tenant_id' ) );
			self::detail_row( 'Runtime tenant post', $pm( 'sd_runtime_tenant_post_id' ) );
			self::detail_row( 'Health', $pm( 'sd_health_status' ) );
			echo '</div>';

			$prov_payload  = $pm( 'sd_last_provisioning_payload_json' );
			$prov_response = $pm( 'sd_last_provisioning_response_json' );

			if ( $prov_payload !== '' || $prov_response !== '' ) {
				echo '<div class="sdf-chain-section sdf-chain-section-full">';
				echo '<div class="sdf-panel-sub-head">Last provisioning exchange</div>';
				if ( $prov_payload !== '' ) {
					$decoded = json_decode( $prov_payload, true );
					echo '<div class="sdf-json-label">Request</div>';
					echo '<pre class="sdf-json">' . esc_html( is_array( $decoded ) ? json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $prov_payload ) . '</pre>';
				}
				if ( $prov_response !== '' ) {
					$decoded = json_decode( $prov_response, true );
					echo '<div class="sdf-json-label" style="margin-top:10px;">Response</div>';
					echo '<pre class="sdf-json">' . esc_html( is_array( $decoded ) ? json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $prov_response ) . '</pre>';
				}
				echo '</div>';
			}

			echo '</div>'; // .sdf-chain-body
		} else {
			echo '<div class="sdf-chain-empty">No provision package linked yet — created when the prospect reserves their slug.</div>';
		}

		echo '</div>'; // .sdf-chain-block

		// ---- Runtime Tenant block ----
		$ops_url        = $m( 'sd_operations_entry_url' );
		$storefront_url = $m( 'sd_storefront_url' );
		$runtime_tpost  = (int) $m( 'sd_runtime_tenant_post_id' );

		echo '<div class="sdf-chain-block sdf-chain-block-tenant">';
		echo '<div class="sdf-chain-head">Runtime Tenant (SDPRO)</div>';

		if ( $has_tenant ) {
			echo '<div class="sdf-chain-body">';
			echo '<div class="sdf-chain-section">';
			echo '<span class="sdf-badge sdf-badge-ok sdf-tenant-live-badge">&#10003; Tenant live</span>';
			self::detail_row( 'Tenant ID', $runtime_tid );
			self::detail_row( 'Tenant post', (string) $runtime_tpost );
			echo '</div>';
			if ( $storefront_url !== '' || $ops_url !== '' ) {
				echo '<div class="sdf-chain-section">';
				echo '<div class="sdf-panel-sub-head">URLs</div>';
				if ( $storefront_url !== '' ) {
					self::detail_row( 'Storefront', '<a href="' . esc_url( $storefront_url ) . '" target="_blank" rel="noopener">' . esc_html( $storefront_url ) . ' ↗</a>', false );
				}
				if ( $ops_url !== '' ) {
					self::detail_row( 'Operator app', '<a href="' . esc_url( $ops_url ) . '" target="_blank" rel="noopener">' . esc_html( $ops_url ) . ' ↗</a>', false );
				}
				echo '</div>';
			}
			echo '</div>'; // .sdf-chain-body
		} else {
			echo '<div class="sdf-chain-empty">';
			echo 'Tenant not yet provisioned on SDPRO.';
			if ( $is_paid ) {
				echo ' <strong>Payment is confirmed</strong> — provisioning should have been triggered. Check the provision package status above.';
			}
			echo '</div>';
		}

		echo '</div>'; // .sdf-chain-block (tenant)

		echo '</div>'; // .sdf-wrap
	}

	// -------------------------------------------------------------------------
	// Pipeline strip
	// -------------------------------------------------------------------------

	private static function render_pipeline_strip( string $current_stage ) : void {
		$current_stage = strtoupper( trim( $current_stage ) );

		$keys      = array_keys( self::STAGE_ORDER );
		$cur_rank  = array_search( $current_stage, $keys, true );
		if ( $cur_rank === false ) { $cur_rank = -1; }

		echo '<div class="sdf-pipeline-strip">';
		foreach ( self::STAGE_ORDER as $key => $meta ) {
			$rank = (int) array_search( $key, $keys, true );
			$cls  = 'sdf-pip';
			if ( $rank < $cur_rank ) { $cls .= ' is-done'; }
			elseif ( $rank === $cur_rank ) { $cls .= ' is-current'; }

			$style = $rank === $cur_rank ? ' style="--pip-color:' . $meta['color'] . '"' : '';

			echo '<div class="' . esc_attr( $cls ) . '"' . $style . '>';
			echo '<div class="sdf-pip-dot"></div>';
			echo '<div class="sdf-pip-label">' . esc_html( $meta['label'] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	public static function admin_notices() : void {
		$screen = get_current_screen();
		if ( ! $screen ) { return; }
		if ( $screen->id !== 'edit-' . self::CPT_PROSPECT ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// Prospects paid but not provisioned
		$stuck = get_posts( [
			'post_type'      => self::CPT_PROSPECT,
			'post_status'    => [ 'publish', 'private', 'draft' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => 'sd_billing_status', 'value' => 'SUBSCRIPTION_PAID', 'compare' => '=' ],
				[ 'key' => 'sd_runtime_tenant_post_id', 'value' => '0', 'compare' => 'IN' ],
			],
		] );

		// Filter out those that actually have a tenant ID
		$stuck_count = 0;
		foreach ( $stuck as $pid ) {
			$tid = (string) get_post_meta( $pid, 'sd_runtime_tenant_id', true );
			if ( $tid === '' ) { $stuck_count++; }
		}

		if ( $stuck_count > 0 ) {
			$pipeline_url = admin_url( 'edit.php?post_type=' . self::CPT_PROSPECT . '&page=' . self::PAGE_PIPELINE . '&filter_stage=subscription_paid' );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo '<strong>' . (int) $stuck_count . ' paid prospect(s) not yet provisioned.</strong> ';
			echo '<a href="' . esc_url( $pipeline_url ) . '">View in pipeline →</a>';
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Inline styles
	// -------------------------------------------------------------------------

	public static function inline_styles() : void {
		$screen = get_current_screen();
		if ( ! $screen ) { return; }
		if ( ! in_array( $screen->post_type, [ self::CPT_PROSPECT, self::CPT_PACKAGE ], true )
			&& ! in_array( $screen->id, [ 'sd_prospect_page_' . self::PAGE_PIPELINE, 'admin_page_' . self::PAGE_DETAIL, 'sd_prospect_page_' . self::PAGE_DETAIL ], true )
		) { return; }
		?>
		<style>
		/* ---- Shared ---- */
		.sdf-wrap { max-width: 1300px; }
		.sdf-page-title { font-size: 22px; font-weight: 800; color: #0f172a; margin-bottom: 20px; }
		.sdf-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
		.sdf-sub-em { font-style: italic; }
		.sdf-muted { color: #94a3b8; }
		.sdf-code { font-size: 11px; background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
		.sdf-pkg-link { font-size: 11px; }
		.sdf-name-link { font-weight: 700; }
		.sdf-age-old { color: #dc2626; font-weight: 700; }
		.sdf-age-mid { color: #d97706; }
		.sdf-json { font-size: 11px; background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow: auto; max-height: 200px; margin: 0; }
		.sdf-empty { padding: 24px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; color: #94a3b8; }

		/* ---- Badges ---- */
		.sdf-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
		.sdf-badge-ok      { background: #16a34a; color: #fff; }
		.sdf-badge-paid    { background: #059669; color: #fff; }
		.sdf-badge-pending { background: #7c3aed; color: #fff; }
		.sdf-badge-failed  { background: #dc2626; color: #fff; }
		.sdf-badge-stage   { background: #1e293b; color: #f8fafc; }
		.sdf-badge-neutral { background: #64748b; color: #fff; }
		.sdf-badge-warn    { background: #d97706; color: #fff; }
		.sdf-badge-prov-ok     { background: #0891b2; color: #fff; }
		.sdf-badge-prov-ready  { background: #2563eb; color: #fff; }
		.sdf-badge-prov-wait   { background: #94a3b8; color: #fff; }

		/* ---- Funnel strip ---- */
		.sdf-funnel { display: flex; gap: 0; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; margin-bottom: 20px; }
		.sdf-funnel-step { flex: 1 1 80px; padding: 14px 10px 12px; text-align: center; text-decoration: none; color: inherit; border-right: 1px solid #f1f5f9; transition: background .1s; }
		.sdf-funnel-step:last-child { border-right: none; }
		.sdf-funnel-step:hover { background: #f8fafc; }
		.sdf-funnel-step.is-active { background: #f0f9ff; }
		.sdf-funnel-count { font-size: 26px; font-weight: 900; line-height: 1; }
		.sdf-funnel-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-top: 4px; }
		.sdf-funnel-pct { font-size: 10px; color: #94a3b8; margin-top: 2px; }

		/* ---- KPI row ---- */
		.sdf-kpi-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
		.sdf-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; min-width: 120px; }
		.sdf-kpi-ok { border-color: #86efac; }
		.sdf-kpi-live { border-color: #bae6fd; }
		.sdf-kpi-warn { border-color: #fcd34d; background: #fffbeb; }
		.sdf-kpi-n { display: block; font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
		.sdf-kpi-l { display: block; font-size: 11px; color: #64748b; margin-top: 4px; }

		/* ---- Filter notice ---- */
		.sdf-filter-notice { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 14px; font-size: 13px; margin-bottom: 16px; }
		.sdf-filter-notice a { color: #2563eb; }

		/* ---- Pipeline table ---- */
		.sdf-table { font-size: 13px; }

		/* ---- Breadcrumb ---- */
		.sdf-breadcrumb { font-size: 13px; color: #64748b; margin-bottom: 16px; }
		.sdf-breadcrumb a { color: #2563eb; }
		.sdf-breadcrumb-sep { margin: 0 6px; }


/* ---- Pipeline strip ---- */
		.sdf-pipeline-strip { display: flex; align-items: flex-start; gap: 0; overflow-x: auto; padding: 14px 0 10px; margin-bottom: 24px; position: relative; }
		.sdf-pipeline-strip::before { content: ''; position: absolute; top: 22px; left: 8px; right: 8px; height: 2px; background: #e2e8f0; z-index: 0; }
		.sdf-pip { flex: 1 1 0; min-width: 64px; display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1; }
		.sdf-pip-dot { width: 16px; height: 16px; border-radius: 50%; background: #fff; border: 2px solid #cbd5e1; margin-bottom: 5px; flex-shrink: 0; }
		.sdf-pip.is-done .sdf-pip-dot { background: #1e293b; border-color: #1e293b; }
		.sdf-pip.is-current .sdf-pip-dot { background: #fff; border-color: var(--pip-color, #1e293b); border-width: 3px; box-shadow: 0 0 0 4px color-mix(in srgb, var(--pip-color, #1e293b) 15%, transparent); }
		.sdf-pip-label { font-size: 10px; text-align: center; color: #94a3b8; white-space: nowrap; line-height: 1.2; }
		.sdf-pip.is-done .sdf-pip-label { color: #1e293b; font-weight: 700; }
		.sdf-pip.is-current .sdf-pip-label { color: var(--pip-color, #1e293b); font-weight: 800; }

		/* ---- Detail grid ---- */
		.sdf-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
		@media (max-width: 900px) { .sdf-detail-grid { grid-template-columns: 1fr; } }
		.sdf-detail-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 18px; }
		.sdf-panel-head { font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: #64748b; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9; }
		.sdf-panel-sub-head { font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; margin: 14px 0 6px; }
		.sdf-detail-row { display: flex; gap: 10px; padding: 5px 0; border-bottom: 1px solid #f8fafc; font-size: 12px; align-items: flex-start; }
		.sdf-detail-row:last-child { border-bottom: none; }
		.sdf-detail-row-label { width: 130px; flex-shrink: 0; color: #475569; font-weight: 700; padding-top: 1px; }
		.sdf-detail-row-value { color: #0f172a; word-break: break-all; }

		/* ---- Chain blocks ---- */
		.sdf-chain-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 16px; }
		.sdf-chain-block-tenant { border-color: #bae6fd; }
		.sdf-chain-head { font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }
		.sdf-chain-body { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; align-items: start; }
		.sdf-chain-section-full { grid-column: 1 / -1; }
		.sdf-chain-section { }
		.sdf-chain-empty { font-size: 13px; color: #64748b; padding: 10px 0; }
		.sdf-chain-edit-link { margin-bottom: 12px; display: inline-block; }
		.sdf-chain-slug { font-family: ui-monospace, 'SF Mono', monospace; font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: -.01em; margin-bottom: 10px; }
		.sdf-tenant-live-badge { margin-bottom: 12px; display: inline-block; }
		.sdf-json-label { font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; margin-bottom: 4px; }

		/* ---- Hero card ---- */
		.sdf-hero-card { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px 24px; margin-bottom: 20px; flex-wrap: wrap; }
		.sdf-hero-left { display: flex; align-items: flex-start; gap: 16px; }
		.sdf-hero-avatar { width: 52px; height: 52px; border-radius: 50%; background: #1e293b; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; letter-spacing: -.02em; }
		.sdf-hero-name { font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1.2; margin-bottom: 3px; }
		.sdf-hero-contact { font-size: 13px; color: #64748b; margin-bottom: 2px; }
		.sdf-hero-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
		.sdf-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; align-self: center; }

		/* ---- Vitals strip ---- */
		.sdf-vitals-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
		.sdf-vital { display: flex; align-items: center; gap: 7px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 13px; font-size: 12px; }
		.sdf-vital-ok { border-color: #86efac; background: #f0fdf4; }
		.sdf-vital-warn { border-color: #fcd34d; background: #fffbeb; }
		.sdf-vital-err { border-color: #fca5a5; background: #fef2f2; }
		.sdf-vital-label { color: #64748b; font-weight: 600; }
		.sdf-vital-sep { color: #cbd5e1; }
		.sdf-vital-value { color: #0f172a; font-weight: 700; }
		.sdf-vital-ok .sdf-vital-value { color: #15803d; }
		.sdf-vital-warn .sdf-vital-value { color: #b45309; }
		.sdf-vital-err .sdf-vital-value { color: #b91c1c; }

		/* ---- ID chips & booleans ---- */
		.sdf-id-chip { font-family: ui-monospace, 'SF Mono', 'Cascadia Code', monospace; font-size: 11px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 6px; color: #334155; word-break: break-all; }
		.sdf-check-yes { color: #16a34a; font-weight: 700; }
		.sdf-check-no  { color: #94a3b8; font-weight: 600; }
		.sdf-alert-text { color: #dc2626; font-weight: 600; }
		</style>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helper renderers
	// -------------------------------------------------------------------------

	private static function vital_chip( string $label, string $value, string $state ) : void {
		echo '<div class="sdf-vital sdf-vital-' . esc_attr( $state ) . '">';
		echo '<span class="sdf-vital-label">' . esc_html( $label ) . '</span>';
		echo '<span class="sdf-vital-sep">·</span>';
		echo '<span class="sdf-vital-value">' . esc_html( $value ) . '</span>';
		echo '</div>';
	}

	private static function id_chip( string $id ) : string {
		if ( $id === '' || $id === '—' ) { return '—'; }
		return '<code class="sdf-id-chip">' . esc_html( $id ) . '</code>';
	}

	private static function bool_chip( bool $val ) : string {
		return $val
			? '<span class="sdf-check-yes">&#10003; Yes</span>'
			: '<span class="sdf-check-no">&#10007; No</span>';
	}

	private static function detail_row( string $label, string $value, bool $escape = true ) : void {
		$display = $escape ? esc_html( $value !== '' ? $value : '—' ) : ( $value !== '' ? $value : '—' );
		echo '<div class="sdf-detail-row">';
		echo '<div class="sdf-detail-row-label">' . esc_html( $label ) . '</div>';
		echo '<div class="sdf-detail-row-value">' . $display . '</div>';
		echo '</div>';
	}

	private static function stage_badge( string $stage ) : string {
		$stage = strtoupper( trim( $stage ) );
		if ( $stage === '' ) { return '<span class="sdf-muted">—</span>'; }

		$meta  = self::STAGE_ORDER[ $stage ] ?? null;
		$label = $meta ? $meta['label'] : ucwords( strtolower( str_replace( '_', ' ', $stage ) ) );
		$style = $meta ? ' style="background:' . $meta['color'] . ';color:#fff"' : '';

		return '<span class="sdf-badge"' . $style . '>' . esc_html( $label ) . '</span>';
	}

	private static function billing_badge( string $status ) : string {
		if ( $status === '' ) { return '<span class="sdf-muted">—</span>'; }
		$map = [
			'SUBSCRIPTION_PAID'    => [ 'sdf-badge-paid',    'Paid' ],
			'CHECKOUT_PENDING'     => [ 'sdf-badge-pending', 'Checkout pending' ],
			'SUBSCRIPTION_FAILED'  => [ 'sdf-badge-failed',  'Failed' ],
		];
		[ $cls, $label ] = $map[ $status ] ?? [ 'sdf-badge-neutral', $status ];
		return '<span class="sdf-badge ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function prov_status_badge( string $status ) : string {
		if ( $status === '' ) { return '<span class="sdf-muted">—</span>'; }
		$map = [
			'provisioned'                     => [ 'sdf-badge-prov-ok',    '✓ Provisioned' ],
			'ready_for_runtime_provisioning'  => [ 'sdf-badge-prov-ready', 'Ready to provision' ],
			'awaiting_payment'                => [ 'sdf-badge-prov-wait',  'Awaiting payment' ],
			'staged_for_provisioning'         => [ 'sdf-badge-prov-wait',  'Staged' ],
			'staged'                          => [ 'sdf-badge-prov-wait',  'Staged' ],
		];
		[ $cls, $label ] = $map[ $status ] ?? [ 'sdf-badge-neutral', $status ];
		return '<span class="sdf-badge ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function fmt_gmt( string $gmt ) : string {
		if ( $gmt === '' || $gmt === '0000-00-00 00:00:00' ) { return '—'; }
		$ts = strtotime( $gmt );
		if ( ! $ts ) { return $gmt; }
		return wp_date( 'M j, Y g:i a', $ts ) . ' (' . human_time_diff( $ts, time() ) . ' ago)';
	}

	private static function mask_str( string $value, int $show = 14 ) : string {
		if ( $value === '' ) { return '—'; }
		if ( strlen( $value ) <= $show ) { return $value; }
		return substr( $value, 0, $show ) . '…';
	}
}

add_action( 'admin_init', function () {
	if ( class_exists( 'SD_Front_Office_Admin', false ) ) {
		SD_Front_Office_Admin::bootstrap();
	}
} );