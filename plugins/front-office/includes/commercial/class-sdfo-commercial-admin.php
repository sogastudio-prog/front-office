<?php
/**
 * SDFO_Commercial_Admin
 *
 * Admin UI for the four commercial CPTs:
 *   sd_commercial_package  — meta boxes, list columns, Stripe re-sync action
 *   sd_commercial_profile  — meta boxes, list columns
 *   sd_authorization_code  — meta boxes, list columns, use counter display
 *   sd_vendor              — meta boxes, list columns
 *
 * Registered by SDFO_Commercial_Admin::register() called from front-office.php.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'SDFO_Commercial_Admin', false ) ) { return; }

final class SDFO_Commercial_Admin {

    private const PKG  = SDFO_Commercial_CPTs::CPT_PACKAGE;
    private const PRF  = SDFO_Commercial_CPTs::CPT_PROFILE;
    private const CODE = SDFO_Commercial_CPTs::CPT_AUTH_CODE;
    private const VND  = SDFO_Commercial_CPTs::CPT_VENDOR;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        if ( ! is_admin() ) { return; }

        add_action( 'add_meta_boxes',    [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post',         [ __CLASS__, 'save_meta_boxes' ], 10, 2 );
        add_action( 'admin_head',        [ __CLASS__, 'inline_styles' ] );

        // List table columns — packages
        add_filter( 'manage_' . self::PKG  . '_posts_columns',       [ __CLASS__, 'pkg_columns' ] );
        add_action( 'manage_' . self::PKG  . '_posts_custom_column',  [ __CLASS__, 'pkg_column_content' ], 10, 2 );

        // List table columns — profiles
        add_filter( 'manage_' . self::PRF  . '_posts_columns',       [ __CLASS__, 'prf_columns' ] );
        add_action( 'manage_' . self::PRF  . '_posts_custom_column',  [ __CLASS__, 'prf_column_content' ], 10, 2 );

        // List table columns — auth codes
        add_filter( 'manage_' . self::CODE . '_posts_columns',       [ __CLASS__, 'code_columns' ] );
        add_action( 'manage_' . self::CODE . '_posts_custom_column',  [ __CLASS__, 'code_column_content' ], 10, 2 );

        // List table columns — vendors
        add_filter( 'manage_' . self::VND  . '_posts_columns',       [ __CLASS__, 'vnd_columns' ] );
        add_action( 'manage_' . self::VND  . '_posts_custom_column',  [ __CLASS__, 'vnd_column_content' ], 10, 2 );

        // Stripe re-sync action (triggered from package edit screen button)
        add_action( 'admin_post_sdfo_stripe_resync', [ __CLASS__, 'handle_stripe_resync' ] );

        // Admin notices (re-sync result)
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
    }

    // -------------------------------------------------------------------------
    // Meta box registration
    // -------------------------------------------------------------------------

    public static function add_meta_boxes(): void {

        add_meta_box( 'sdfo-pkg-details',  'Package Details',        [ __CLASS__, 'render_pkg_details' ],  self::PKG,  'normal', 'high' );
        add_meta_box( 'sdfo-pkg-billing',  'Billing & Stripe',       [ __CLASS__, 'render_pkg_billing' ],  self::PKG,  'normal', 'high' );
        add_meta_box( 'sdfo-pkg-profile',  'Default Profile',        [ __CLASS__, 'render_pkg_profile' ],  self::PKG,  'side',   'default' );

        add_meta_box( 'sdfo-prf-details',  'Profile Details',        [ __CLASS__, 'render_prf_details' ],  self::PRF,  'normal', 'high' );
        add_meta_box( 'sdfo-prf-features', 'Feature Gates',          [ __CLASS__, 'render_prf_features' ], self::PRF,  'normal', 'high' );
        add_meta_box( 'sdfo-prf-fees',     'Application Fee Policy', [ __CLASS__, 'render_prf_fees' ],     self::PRF,  'normal', 'high' );
        add_meta_box( 'sdfo-prf-discount', 'Discount Policy',        [ __CLASS__, 'render_prf_discount' ], self::PRF,  'normal', 'default' );
        add_meta_box( 'sdfo-prf-prov',     'Provisioning Policy',    [ __CLASS__, 'render_prf_prov' ],     self::PRF,  'side',   'default' );

        add_meta_box( 'sdfo-code-details', 'Code Details',           [ __CLASS__, 'render_code_details' ], self::CODE, 'normal', 'high' );
        add_meta_box( 'sdfo-code-usage',   'Usage & Expiry',         [ __CLASS__, 'render_code_usage' ],   self::CODE, 'normal', 'high' );
        add_meta_box( 'sdfo-code-assign',  'Assignment Constraints', [ __CLASS__, 'render_code_assign' ],  self::CODE, 'side',   'default' );

        add_meta_box( 'sdfo-vnd-details',  'Vendor Details',         [ __CLASS__, 'render_vnd_details' ],  self::VND,  'normal', 'high' );
        add_meta_box( 'sdfo-vnd-stripe',   'Stripe & Payouts',       [ __CLASS__, 'render_vnd_stripe' ],   self::VND,  'normal', 'high' );
    }

    // =========================================================================
    // PACKAGE meta boxes
    // =========================================================================

    public static function render_pkg_details( \WP_Post $post ): void {
        wp_nonce_field( 'sdfo_pkg_save_' . $post->ID, 'sdfo_pkg_nonce' );

        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );
        $i = fn( string $k ) => (int) get_post_meta( $post->ID, $k, true );

        self::field_text(    'sd_package_key',  'Package Key',   $m( 'sd_package_key' ),  'Unique slug, e.g. <code>operator</code>. Auto-generated from title if blank.' );
        self::field_textarea('sd_description',  'Description',   $m( 'sd_description' ),  'Shown on the pricing page.' );
        self::field_select(  'sd_status',       'Status',        $m( 'sd_status' ) ?: 'inactive',
            [ 'active' => 'Active', 'inactive' => 'Inactive' ] );
        self::field_checkbox('sd_is_public',    'Public',        $i( 'sd_is_public' ) === 1, 'Show on the public pricing page' );
        self::field_number(  'sd_sort_order',   'Sort Order',    $i( 'sd_sort_order' ) ?: 99, 'Lower numbers appear first.' );
    }

    public static function render_pkg_billing( \WP_Post $post ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );
        $i = fn( string $k ) => (int) get_post_meta( $post->ID, $k, true );

        self::field_select( 'sd_billing_mode', 'Billing Mode', $m( 'sd_billing_mode' ) ?: 'subscription', [
            'subscription' => 'Subscription (recurring Stripe price)',
            'contract'     => 'Contract (custom terms, no Stripe price)',
            'free'         => 'Free (no charge)',
        ] );
        self::field_select( 'sd_billing_interval', 'Billing Interval', $m( 'sd_billing_interval' ) ?: 'month', [
            'month' => 'Monthly',
            'year'  => 'Yearly',
        ] );

        // Show price in dollars for UX; store as cents.
        $cents   = $i( 'sd_display_price_cents' );
        $dollars = $cents > 0 ? number_format( $cents / 100, 2 ) : '';
        echo '<div class="sdfo-field">';
        echo '<label for="sdfo_price_dollars">Display Price (USD)</label>';
        echo '<div class="sdfo-price-wrap">';
        echo '<span class="sdfo-currency">$</span>';
        echo '<input type="number" id="sdfo_price_dollars" name="sdfo_price_dollars" value="' . esc_attr( $dollars ) . '" step="0.01" min="0" style="width:120px">';
        echo '</div>';
        echo '<p class="description">Stored as cents. Changes to this value will trigger a new Stripe price on save.</p>';
        echo '</div>';

        self::field_select( 'sd_currency', 'Currency', $m( 'sd_currency' ) ?: 'usd', [
            'usd' => 'USD ($)',
        ] );

        // Stripe sync status — read-only display + re-sync button.
        $sync_status = $m( 'sd_stripe_sync_status' );
        $sync_at     = $m( 'sd_stripe_sync_at_gmt' );
        $sync_error  = $m( 'sd_stripe_sync_error' );
        $product_id  = $m( 'sd_stripe_product_id' );
        $price_id    = $m( 'sd_stripe_price_id' );

        echo '<div class="sdfo-field sdfo-field--stripe">';
        echo '<label>Stripe Sync</label>';
        echo '<div class="sdfo-stripe-status">';

        if ( $sync_status === 'synced' ) {
            echo '<span class="sdfo-badge sdfo-badge--ok">✓ Synced</span>';
            if ( $sync_at !== '' ) {
                echo ' <span class="sdfo-muted">' . esc_html( self::fmt_gmt( $sync_at ) ) . '</span>';
            }
        } elseif ( $sync_status === 'error' ) {
            echo '<span class="sdfo-badge sdfo-badge--error">✗ Error</span>';
            if ( $sync_error !== '' ) {
                echo '<p class="sdfo-error-msg">' . esc_html( $sync_error ) . '</p>';
            }
        } elseif ( $sync_status === 'pending' || $sync_status === '' ) {
            echo '<span class="sdfo-badge sdfo-badge--pending">⏳ Pending</span>';
        }

        if ( $product_id !== '' ) {
            echo '<div class="sdfo-stripe-ids">';
            echo '<span class="sdfo-label">Product:</span> <code>' . esc_html( $product_id ) . '</code><br>';
            if ( $price_id !== '' ) {
                echo '<span class="sdfo-label">Price:</span> <code>' . esc_html( $price_id ) . '</code>';
            }
            echo '</div>';
        }

        // Re-sync button (only when post is saved).
        if ( $post->ID > 0 && get_post_status( $post->ID ) === 'publish' ) {
            $resync_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=sdfo_stripe_resync&post_id=' . $post->ID ),
                'sdfo_stripe_resync_' . $post->ID
            );
            echo '<p><a href="' . esc_url( $resync_url ) . '" class="button button-secondary sdfo-resync-btn">↻ Force Re-sync to Stripe</a></p>';
        }

        echo '</div></div>';
    }

    public static function render_pkg_profile( \WP_Post $post ): void {
        $current = (int) get_post_meta( $post->ID, 'sd_default_profile_post_id', true );

        $profiles = get_posts( [
            'post_type'      => self::PRF,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        echo '<div class="sdfo-field">';
        echo '<label for="sd_default_profile_post_id">Default Profile</label>';
        echo '<select id="sd_default_profile_post_id" name="sd_default_profile_post_id" style="width:100%">';
        echo '<option value="">— None —</option>';
        foreach ( $profiles as $profile ) {
            $profile_key = (string) get_post_meta( $profile->ID, 'sd_profile_key', true );
            $label       = $profile->post_title . ( $profile_key !== '' ? ' (' . $profile_key . ')' : '' );
            echo '<option value="' . esc_attr( $profile->ID ) . '"' . selected( $current, $profile->ID, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Applied when no authorization code is used at checkout.</p>';
        echo '</div>';
    }

    // =========================================================================
    // PROFILE meta boxes
    // =========================================================================

    public static function render_prf_details( \WP_Post $post ): void {
        wp_nonce_field( 'sdfo_prf_save_' . $post->ID, 'sdfo_prf_nonce' );

        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        self::field_text(   'sd_profile_key',   'Profile Key',   $m( 'sd_profile_key' ),   'Unique slug, e.g. <code>operator_default</code>.' );
        self::field_select( 'sd_profile_type',  'Profile Type',  $m( 'sd_profile_type' ) ?: 'default', [
            'default'  => 'Default (standard package profile)',
            'custom'   => 'Custom (invite-code unlocked)',
            'comp'     => 'Comp (credited / free subscription)',
            'contract' => 'Contract (custom negotiated terms)',
        ] );
        self::field_select( 'sd_status', 'Status', $m( 'sd_status' ) ?: 'inactive', [
            'active'   => 'Active',
            'inactive' => 'Inactive',
        ] );

        // Linked package selector
        $current_pkg = (int) get_post_meta( $post->ID, 'sd_linked_package_post_id', true );
        $packages = get_posts( [
            'post_type'      => self::PKG,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        echo '<div class="sdfo-field">';
        echo '<label for="sd_linked_package_post_id">Linked Package</label>';
        echo '<select id="sd_linked_package_post_id" name="sd_linked_package_post_id" style="width:100%">';
        echo '<option value="">— None —</option>';
        foreach ( $packages as $pkg ) {
            $pkg_key = (string) get_post_meta( $pkg->ID, 'sd_package_key', true );
            echo '<option value="' . esc_attr( $pkg->ID ) . '"' . selected( $current_pkg, $pkg->ID, false ) . '>'
                . esc_html( $pkg->post_title . ( $pkg_key !== '' ? ' (' . $pkg_key . ')' : '' ) ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Which package this profile belongs to. Sets <code>sd_base_package_key</code> automatically on save.</p>';
        echo '</div>';
    }

    public static function render_prf_features( \WP_Post $post ): void {
        $raw      = (string) get_post_meta( $post->ID, 'sd_features_json', true );
        $features = $raw !== '' ? (array) json_decode( $raw, true ) : [];

        $all_features = [
            'tenant_storefront'    => 'Tenant Storefront',
            'custom_domain'        => 'Custom Domain',
            'lead_capture'         => 'Lead Capture',
            'quote_workflow'       => 'Quote Workflow',
            'stripe_authorization' => 'Stripe Authorization',
            'payment_capture'      => 'Payment Capture',
            'operator_console'     => 'Operator Console',
            'driver_portal'        => 'Driver Portal',
            'reservations'         => 'Reservations',
            'stacked_availability' => 'Stacked Availability',
            'advanced_reporting'   => 'Advanced Reporting',
            'white_label'          => 'White Label',
        ];

        echo '<div class="sdfo-feature-grid">';
        foreach ( $all_features as $key => $label ) {
            $checked = ! empty( $features[ $key ] );
            echo '<label class="sdfo-feature-item">';
            echo '<input type="checkbox" name="sdfo_features[' . esc_attr( $key ) . ']" value="1"' . checked( $checked, true, false ) . '>';
            echo ' ' . esc_html( $label );
            echo '</label>';
        }
        echo '</div>';
    }

    public static function render_prf_fees( \WP_Post $post ): void {
        $raw    = (string) get_post_meta( $post->ID, 'sd_application_fee_policy_json', true );
        $policy = $raw !== '' ? (array) json_decode( $raw, true ) : [];

        $mode       = (string) ( $policy['mode']               ?? 'percentage' );
        $pct        = (string) ( $policy['percentage']         ?? '8' );
        $fixed      = (string) ( $policy['fixed_amount_cents'] ?? '' );
        $min        = (string) ( $policy['minimum_fee_cents']  ?? '0' );
        $max        = (string) ( $policy['maximum_fee_cents']  ?? '' );
        $applies    = (array)  ( $policy['applies_to']         ?? [ 'ride_checkout' ] );
        $override   = ! empty( $policy['tenant_override_allowed'] );

        self::field_select( 'sdfo_fee_mode', 'Fee Mode', $mode, [
            'percentage' => 'Percentage of transaction',
            'flat'       => 'Flat fee per transaction',
            'none'       => 'No application fee',
            'contract'   => 'Contract (custom terms)',
        ] );

        echo '<div class="sdfo-field">';
        echo '<label for="sdfo_fee_pct">Percentage (%)</label>';
        echo '<input type="number" id="sdfo_fee_pct" name="sdfo_fee_pct" value="' . esc_attr( $pct ) . '" step="0.1" min="0" max="100" style="width:100px">';
        echo '<p class="description">Used when mode is <code>percentage</code>.</p>';
        echo '</div>';

        echo '<div class="sdfo-field">';
        echo '<label for="sdfo_fee_fixed">Flat Fee (cents)</label>';
        echo '<input type="number" id="sdfo_fee_fixed" name="sdfo_fee_fixed" value="' . esc_attr( $fixed ) . '" min="0" style="width:120px">';
        echo '<p class="description">Used when mode is <code>flat</code>. Enter in cents (e.g. 200 = $2.00).</p>';
        echo '</div>';

        echo '<div class="sdfo-field">';
        echo '<label for="sdfo_fee_min">Minimum Fee (cents)</label>';
        echo '<input type="number" id="sdfo_fee_min" name="sdfo_fee_min" value="' . esc_attr( $min ) . '" min="0" style="width:120px">';
        echo '</div>';

        echo '<div class="sdfo-field">';
        echo '<label for="sdfo_fee_max">Maximum Fee (cents, 0 = no cap)</label>';
        echo '<input type="number" id="sdfo_fee_max" name="sdfo_fee_max" value="' . esc_attr( $max ) . '" min="0" style="width:120px">';
        echo '</div>';

        $charge_types = [
            'ride_checkout'        => 'Ride Checkout',
            'reservation_checkout' => 'Reservation Checkout',
            'manual_capture'       => 'Manual Capture',
        ];

        echo '<div class="sdfo-field">';
        echo '<label>Applies To</label>';
        echo '<div class="sdfo-checkbox-group">';
        foreach ( $charge_types as $val => $lbl ) {
            echo '<label>';
            echo '<input type="checkbox" name="sdfo_fee_applies[]" value="' . esc_attr( $val ) . '"' . checked( in_array( $val, $applies, true ), true, false ) . '>';
            echo ' ' . esc_html( $lbl );
            echo '</label> ';
        }
        echo '</div></div>';

        self::field_checkbox( 'sdfo_fee_override', 'Tenant Override Allowed', $override, 'Allow individual tenants to request a fee override' );
    }

    public static function render_prf_discount( \WP_Post $post ): void {
        $raw    = (string) get_post_meta( $post->ID, 'sd_discount_policy_json', true );
        $policy = $raw !== '' ? (array) json_decode( $raw, true ) : [];

        $mode      = (string) ( $policy['mode']             ?? 'none' );
        $pct       = (string) ( $policy['percent_off']      ?? '' );
        $amount    = (string) ( $policy['amount_off_cents']  ?? '' );
        $duration  = (string) ( $policy['duration']         ?? '' );
        $coupon    = (string) ( $policy['stripe_coupon_id'] ?? '' );
        $credit    = ! empty( $policy['internal_credit'] );
        $label     = (string) ( $policy['label']            ?? '' );

        self::field_select( 'sdfo_disc_mode', 'Discount Mode', $mode, [
            'none'     => 'No discount',
            'percent'  => 'Percentage off',
            'amount'   => 'Amount off (cents)',
            'contract' => 'Contract (custom terms)',
        ] );

        self::field_text(   'sdfo_disc_pct',      'Percent Off',          $pct,     'e.g. 100 for 100% off. Used when mode is <code>percent</code>.' );
        self::field_number( 'sdfo_disc_amount',   'Amount Off (cents)',   (int) $amount,  'Used when mode is <code>amount</code>.' );
        self::field_text(   'sdfo_disc_duration',  'Duration',             $duration, '<code>once</code>, <code>repeating</code>, or <code>forever</code>.' );
        self::field_text(   'sdfo_disc_coupon',   'Stripe Coupon ID',     $coupon,  'If Stripe coupon applies. Leave blank to manage internally.' );
        self::field_text(   'sdfo_disc_label',    'Display Label',        $label,   'Optional label shown in admin, e.g. "Founder credited subscription".' );
        self::field_checkbox( 'sdfo_disc_credit', 'Internal Credit',      $credit,  'Mark as an internally credited subscription (not billed via Stripe)' );
    }

    public static function render_prf_prov( \WP_Post $post ): void {
        $raw    = (string) get_post_meta( $post->ID, 'sd_provisioning_policy_json', true );
        $policy = $raw !== '' ? (array) json_decode( $raw, true ) : [];

        $auto   = isset( $policy['auto_provision'] ) ? (bool) $policy['auto_provision'] : true;
        $review = ! empty( $policy['requires_manual_review'] );
        $status = (string) ( $policy['tenant_status_on_create'] ?? 'active' );

        self::field_checkbox( 'sdfo_prov_auto',   'Auto Provision',        $auto,   'Provision tenant immediately after payment' );
        self::field_checkbox( 'sdfo_prov_review', 'Requires Manual Review', $review, 'Hold for operator review before provisioning' );
        self::field_select(   'sdfo_prov_status', 'Tenant Status on Create', $status, [
            'active'         => 'Active',
            'pending_review' => 'Pending Review',
            'inactive'       => 'Inactive',
        ] );
    }

    // =========================================================================
    // AUTH CODE meta boxes
    // =========================================================================

    public static function render_code_details( \WP_Post $post ): void {
        wp_nonce_field( 'sdfo_code_save_' . $post->ID, 'sdfo_code_nonce' );

        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        // Code string — uppercase display, normalized on save.
        $code = $m( 'sd_code' );
        echo '<div class="sdfo-field">';
        echo '<label for="sd_code">Code String</label>';
        echo '<input type="text" id="sd_code" name="sd_code" value="' . esc_attr( $code ) . '" style="font-family:monospace;font-size:16px;letter-spacing:2px;text-transform:uppercase;width:240px">';
        echo '<p class="description">Stored uppercase. Allowed: A–Z, 0–9, hyphen, underscore. Auto-derived from title if blank.</p>';
        echo '</div>';

        self::field_select( 'sd_status', 'Status', $m( 'sd_status' ) ?: 'active', [
            'active'    => 'Active',
            'inactive'  => 'Inactive',
            'exhausted' => 'Exhausted (max uses reached — auto-set)',
            'expired'   => 'Expired (past expiry date — auto-set)',
        ] );

        // Linked profile selector.
        $current_prf = (int) get_post_meta( $post->ID, 'sd_linked_profile_post_id', true );
        $profiles    = get_posts( [
            'post_type'      => self::PRF,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        echo '<div class="sdfo-field">';
        echo '<label for="sd_linked_profile_post_id">Linked Profile</label>';
        echo '<select id="sd_linked_profile_post_id" name="sd_linked_profile_post_id" style="width:100%">';
        echo '<option value="">— None —</option>';
        foreach ( $profiles as $prf ) {
            $prf_key = (string) get_post_meta( $prf->ID, 'sd_profile_key', true );
            $type    = (string) get_post_meta( $prf->ID, 'sd_profile_type', true );
            echo '<option value="' . esc_attr( $prf->ID ) . '"' . selected( $current_prf, $prf->ID, false ) . '>'
                . esc_html( $prf->post_title . ' (' . $prf_key . ', ' . $type . ')' ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">The commercial profile applied to tenants who redeem this code.</p>';
        echo '</div>';

        // Allowed packages checkboxes.
        $allowed_raw = (string) get_post_meta( $post->ID, 'sd_allowed_package_keys_json', true );
        $allowed     = $allowed_raw !== '' ? (array) json_decode( $allowed_raw, true ) : [];

        $packages = get_posts( [
            'post_type'      => self::PKG,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ] );

        echo '<div class="sdfo-field">';
        echo '<label>Allowed Packages</label>';
        echo '<div class="sdfo-checkbox-group">';
        foreach ( $packages as $pkg ) {
            $pkg_key = (string) get_post_meta( $pkg->ID, 'sd_package_key', true );
            echo '<label>';
            echo '<input type="checkbox" name="sdfo_allowed_packages[]" value="' . esc_attr( $pkg_key ) . '"' . checked( in_array( $pkg_key, $allowed, true ), true, false ) . '>';
            echo ' ' . esc_html( $pkg->post_title );
            echo '</label> ';
        }
        if ( empty( $packages ) ) {
            echo '<span class="sdfo-muted">No packages defined yet.</span>';
        }
        echo '</div>';
        echo '<p class="description">Leave all unchecked to allow any package.</p>';
        echo '</div>';

        self::field_textarea( 'sd_notes', 'Notes', $m( 'sd_notes' ), 'Internal notes — not shown publicly.' );
    }

    public static function render_code_usage( \WP_Post $post ): void {
        $max  = (int) get_post_meta( $post->ID, 'sd_max_uses',     true );
        $used = (int) get_post_meta( $post->ID, 'sd_current_uses', true );
        $exp  = (string) get_post_meta( $post->ID, 'sd_expires_at_gmt', true );

        // Current use counter — read-only display.
        echo '<div class="sdfo-field">';
        echo '<label>Current Uses</label>';
        echo '<div class="sdfo-usage-display">';
        echo '<span class="sdfo-use-count">' . esc_html( $used ) . '</span>';
        if ( $max > 0 ) {
            echo ' <span class="sdfo-muted">/ ' . esc_html( $max ) . ' max</span>';
            $pct = min( 100, round( ( $used / $max ) * 100 ) );
            echo '<div class="sdfo-use-bar"><div class="sdfo-use-bar-fill" style="width:' . (int) $pct . '%"></div></div>';
        } else {
            echo ' <span class="sdfo-muted">/ unlimited</span>';
        }
        echo '</div></div>';

        // Max uses — editable.
        echo '<div class="sdfo-field">';
        echo '<label for="sd_max_uses">Max Uses</label>';
        echo '<input type="number" id="sd_max_uses" name="sd_max_uses" value="' . esc_attr( $max ) . '" min="0" style="width:120px">';
        echo '<p class="description">0 = unlimited. Status auto-changes to <code>exhausted</code> when uses reach max.</p>';
        echo '</div>';

        // Expiry datetime — stored as GMT MySQL datetime.
        $exp_local = '';
        if ( $exp !== '' ) {
            $ts        = strtotime( $exp );
            $exp_local = $ts ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
        }

        echo '<div class="sdfo-field">';
        echo '<label for="sd_expires_at_local">Expiry Date &amp; Time</label>';
        echo '<input type="datetime-local" id="sd_expires_at_local" name="sd_expires_at_local" value="' . esc_attr( $exp_local ) . '">';
        echo '<p class="description">Leave blank for no expiry. Status auto-changes to <code>expired</code> after this date.</p>';
        echo '</div>';
    }

    public static function render_code_assign( \WP_Post $post ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        echo '<p class="description" style="margin-bottom:12px">Optionally restrict this code to a specific email address or domain. Leave blank for unrestricted use.</p>';

        self::field_text( 'sd_assigned_email',  'Assigned Email',  $m( 'sd_assigned_email' ),  'Lock to one specific email address.' );
        self::field_text( 'sd_assigned_domain', 'Assigned Domain', $m( 'sd_assigned_domain' ), 'Lock to an email domain, e.g. <code>acmecorp.com</code>.' );
    }

    // =========================================================================
    // VENDOR meta boxes
    // =========================================================================

    public static function render_vnd_details( \WP_Post $post ): void {
        wp_nonce_field( 'sdfo_vnd_save_' . $post->ID, 'sdfo_vnd_nonce' );

        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        self::field_text(   'sd_vendor_key',    'Vendor Key',     $m( 'sd_vendor_key' ),    'Unique slug, e.g. <code>acme-marketing</code>. Auto-generated from title.' );
        self::field_text(   'sd_contact_name',  'Contact Name',   $m( 'sd_contact_name' ),  '' );
        self::field_text(   'sd_contact_email', 'Contact Email',  $m( 'sd_contact_email' ), '' );
        self::field_select( 'sd_status',        'Status',         $m( 'sd_status' ) ?: 'active', [
            'active'   => 'Active',
            'inactive' => 'Inactive',
        ] );
        self::field_textarea( 'sd_notes', 'Notes', $m( 'sd_notes' ), '' );
    }

    public static function render_vnd_stripe( \WP_Post $post ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        self::field_text(   'sd_stripe_account_id', 'Stripe Account ID', $m( 'sd_stripe_account_id' ), 'The vendor\'s Stripe Connect account ID (e.g. <code>acct_...</code>). Required for automated commission payouts.' );
        self::field_select( 'sd_payout_mode',       'Payout Mode',       $m( 'sd_payout_mode' ) ?: 'manual', [
            'automated' => 'Automated (Stripe Transfer on each charge)',
            'accrual'   => 'Accrual (track owed, pay in batches)',
            'manual'    => 'Manual (tracked only)',
        ] );

        echo '<div class="sdfo-field sdfo-field--info">';
        echo '<p><strong>Automated:</strong> SoloDrive fires a Stripe transfer to the vendor account on every qualifying charge. Requires a valid Stripe account ID.</p>';
        echo '<p><strong>Accrual:</strong> Commission is tracked and accumulated. You initiate batch payouts manually.</p>';
        echo '<p><strong>Manual:</strong> Commission is tracked for reporting purposes only. No Stripe transfers.</p>';
        echo '</div>';
    }

    // =========================================================================
    // Save handler
    // =========================================================================

    public static function save_meta_boxes( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        switch ( $post->post_type ) {
            case self::PKG:
                self::save_package( $post_id );
                break;
            case self::PRF:
                self::save_profile( $post_id );
                break;
            case self::CODE:
                self::save_auth_code( $post_id );
                break;
            case self::VND:
                self::save_vendor( $post_id );
                break;
        }
    }

    private static function save_package( int $post_id ): void {
        if ( ! isset( $_POST['sdfo_pkg_nonce'] ) || ! wp_verify_nonce( $_POST['sdfo_pkg_nonce'], 'sdfo_pkg_save_' . $post_id ) ) { return; }

        // Details
        update_post_meta( $post_id, 'sd_package_key',  sanitize_key( (string) ( $_POST['sd_package_key']  ?? '' ) ) );
        update_post_meta( $post_id, 'sd_description',  sanitize_textarea_field( (string) ( $_POST['sd_description'] ?? '' ) ) );
        update_post_meta( $post_id, 'sd_status',       sanitize_key( (string) ( $_POST['sd_status']       ?? 'inactive' ) ) );
        update_post_meta( $post_id, 'sd_is_public',    isset( $_POST['sd_is_public'] ) ? 1 : 0 );
        update_post_meta( $post_id, 'sd_sort_order',   (int) ( $_POST['sd_sort_order'] ?? 99 ) );

        // Billing
        update_post_meta( $post_id, 'sd_billing_mode',     sanitize_key( (string) ( $_POST['sd_billing_mode']     ?? 'subscription' ) ) );
        update_post_meta( $post_id, 'sd_billing_interval', sanitize_key( (string) ( $_POST['sd_billing_interval'] ?? 'month' ) ) );
        update_post_meta( $post_id, 'sd_currency',         sanitize_key( (string) ( $_POST['sd_currency']         ?? 'usd' ) ) );

        // Convert dollars → cents
        $dollars = (float) ( $_POST['sdfo_price_dollars'] ?? 0 );
        update_post_meta( $post_id, 'sd_display_price_cents', (int) round( $dollars * 100 ) );

        // Default profile
        update_post_meta( $post_id, 'sd_default_profile_post_id', (int) ( $_POST['sd_default_profile_post_id'] ?? 0 ) );
    }

    private static function save_profile( int $post_id ): void {
        if ( ! isset( $_POST['sdfo_prf_nonce'] ) || ! wp_verify_nonce( $_POST['sdfo_prf_nonce'], 'sdfo_prf_save_' . $post_id ) ) { return; }

        update_post_meta( $post_id, 'sd_profile_key',  sanitize_key( (string) ( $_POST['sd_profile_key']  ?? '' ) ) );
        update_post_meta( $post_id, 'sd_profile_type', sanitize_key( (string) ( $_POST['sd_profile_type'] ?? 'default' ) ) );
        update_post_meta( $post_id, 'sd_status',       sanitize_key( (string) ( $_POST['sd_status']       ?? 'inactive' ) ) );

        // Linked package — also write base_package_key for quick lookups.
        $pkg_post_id = (int) ( $_POST['sd_linked_package_post_id'] ?? 0 );
        update_post_meta( $post_id, 'sd_linked_package_post_id', $pkg_post_id );
        if ( $pkg_post_id > 0 ) {
            $pkg_key = (string) get_post_meta( $pkg_post_id, 'sd_package_key', true );
            update_post_meta( $post_id, 'sd_base_package_key', $pkg_key );
        }

        // Features — build JSON from checkboxes.
        $feature_keys = [ 'tenant_storefront', 'custom_domain', 'lead_capture', 'quote_workflow',
            'stripe_authorization', 'payment_capture', 'operator_console', 'driver_portal',
            'reservations', 'stacked_availability', 'advanced_reporting', 'white_label' ];
        $submitted = (array) ( $_POST['sdfo_features'] ?? [] );
        $features  = [];
        foreach ( $feature_keys as $k ) {
            $features[ $k ] = isset( $submitted[ $k ] );
        }
        update_post_meta( $post_id, 'sd_features_json', wp_json_encode( $features ) );

        // Application fee policy.
        $applies = array_map( 'sanitize_key', (array) ( $_POST['sdfo_fee_applies'] ?? [] ) );
        $fee_policy = [
            'mode'                    => sanitize_key( (string) ( $_POST['sdfo_fee_mode']    ?? 'percentage' ) ),
            'percentage'              => (float) ( $_POST['sdfo_fee_pct']    ?? 8 ),
            'fixed_amount_cents'      => (int) ( $_POST['sdfo_fee_fixed']    ?? 0 ) ?: null,
            'minimum_fee_cents'       => (int) ( $_POST['sdfo_fee_min']      ?? 0 ),
            'maximum_fee_cents'       => ( (int) ( $_POST['sdfo_fee_max']    ?? 0 ) ) ?: null,
            'applies_to'              => $applies,
            'tenant_override_allowed' => isset( $_POST['sdfo_fee_override'] ),
        ];
        update_post_meta( $post_id, 'sd_application_fee_policy_json', wp_json_encode( $fee_policy ) );

        // Discount policy.
        $disc_policy = [
            'mode'             => sanitize_key( (string) ( $_POST['sdfo_disc_mode']     ?? 'none' ) ),
            'percent_off'      => ( (string) ( $_POST['sdfo_disc_pct']    ?? '' ) ) !== '' ? (float) $_POST['sdfo_disc_pct'] : null,
            'amount_off_cents' => ( (int) ( $_POST['sdfo_disc_amount'] ?? 0 ) ) ?: null,
            'duration'         => sanitize_text_field( (string) ( $_POST['sdfo_disc_duration'] ?? '' ) ),
            'stripe_coupon_id' => sanitize_text_field( (string) ( $_POST['sdfo_disc_coupon']   ?? '' ) ),
            'label'            => sanitize_text_field( (string) ( $_POST['sdfo_disc_label']    ?? '' ) ),
            'internal_credit'  => isset( $_POST['sdfo_disc_credit'] ),
        ];
        update_post_meta( $post_id, 'sd_discount_policy_json', wp_json_encode( $disc_policy ) );

        // Provisioning policy.
        $prov_policy = [
            'auto_provision'          => isset( $_POST['sdfo_prov_auto'] ),
            'requires_manual_review'  => isset( $_POST['sdfo_prov_review'] ),
            'tenant_status_on_create' => sanitize_key( (string) ( $_POST['sdfo_prov_status'] ?? 'active' ) ),
        ];
        update_post_meta( $post_id, 'sd_provisioning_policy_json', wp_json_encode( $prov_policy ) );
    }

    private static function save_auth_code( int $post_id ): void {
        if ( ! isset( $_POST['sdfo_code_nonce'] ) || ! wp_verify_nonce( $_POST['sdfo_code_nonce'], 'sdfo_code_save_' . $post_id ) ) { return; }

        // Normalize code string.
        $raw_code = (string) ( $_POST['sd_code'] ?? '' );
        update_post_meta( $post_id, 'sd_code',   SDFO_Commercial_CPTs::normalize_code( $raw_code ) );
        update_post_meta( $post_id, 'sd_status', sanitize_key( (string) ( $_POST['sd_status'] ?? 'active' ) ) );
        update_post_meta( $post_id, 'sd_linked_profile_post_id', (int) ( $_POST['sd_linked_profile_post_id'] ?? 0 ) );

        // Allowed packages JSON.
        $allowed = array_map( 'sanitize_key', (array) ( $_POST['sdfo_allowed_packages'] ?? [] ) );
        update_post_meta( $post_id, 'sd_allowed_package_keys_json', wp_json_encode( $allowed ) );

        // Usage.
        update_post_meta( $post_id, 'sd_max_uses', max( 0, (int) ( $_POST['sd_max_uses'] ?? 0 ) ) );

        // Expiry — convert local datetime-local input to GMT MySQL.
        $exp_local = sanitize_text_field( (string) ( $_POST['sd_expires_at_local'] ?? '' ) );
        if ( $exp_local !== '' ) {
            $ts = strtotime( $exp_local );
            if ( $ts ) {
                $offset_seconds = (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
                update_post_meta( $post_id, 'sd_expires_at_gmt', gmdate( 'Y-m-d H:i:s', $ts - $offset_seconds ) );
            }
        } else {
            update_post_meta( $post_id, 'sd_expires_at_gmt', '' );
        }

        // Assignment constraints.
        update_post_meta( $post_id, 'sd_assigned_email',  sanitize_email( (string) ( $_POST['sd_assigned_email']  ?? '' ) ) );
        update_post_meta( $post_id, 'sd_assigned_domain', sanitize_text_field( (string) ( $_POST['sd_assigned_domain'] ?? '' ) ) );
        update_post_meta( $post_id, 'sd_notes',           sanitize_textarea_field( (string) ( $_POST['sd_notes'] ?? '' ) ) );
    }

    private static function save_vendor( int $post_id ): void {
        if ( ! isset( $_POST['sdfo_vnd_nonce'] ) || ! wp_verify_nonce( $_POST['sdfo_vnd_nonce'], 'sdfo_vnd_save_' . $post_id ) ) { return; }

        update_post_meta( $post_id, 'sd_vendor_key',        sanitize_key( (string) ( $_POST['sd_vendor_key']        ?? '' ) ) );
        update_post_meta( $post_id, 'sd_contact_name',      sanitize_text_field( (string) ( $_POST['sd_contact_name']  ?? '' ) ) );
        update_post_meta( $post_id, 'sd_contact_email',     sanitize_email( (string) ( $_POST['sd_contact_email']   ?? '' ) ) );
        update_post_meta( $post_id, 'sd_status',            sanitize_key( (string) ( $_POST['sd_status']            ?? 'active' ) ) );
        update_post_meta( $post_id, 'sd_stripe_account_id', sanitize_text_field( (string) ( $_POST['sd_stripe_account_id'] ?? '' ) ) );
        update_post_meta( $post_id, 'sd_payout_mode',       sanitize_key( (string) ( $_POST['sd_payout_mode']       ?? 'manual' ) ) );
        update_post_meta( $post_id, 'sd_notes',             sanitize_textarea_field( (string) ( $_POST['sd_notes']  ?? '' ) ) );
    }

    // =========================================================================
    // Stripe re-sync action
    // =========================================================================

    public static function handle_stripe_resync(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );

        if ( ! current_user_can( 'manage_options' ) || $post_id <= 0 ) {
            wp_die( 'Access denied.' );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( (string) $_GET['_wpnonce'], 'sdfo_stripe_resync_' . $post_id ) ) {
            wp_die( 'Invalid request.' );
        }

        if ( ! class_exists( 'SDFO_Commercial_Stripe_Sync', false ) ) {
            wp_safe_redirect( add_query_arg( [ 'sdfo_resync' => 'unavailable', 'post' => $post_id, 'action' => 'edit' ], admin_url( 'post.php' ) ) );
            exit;
        }

        $ok = SDFO_Commercial_Stripe_Sync::force_resync( $post_id );

        wp_safe_redirect( add_query_arg( [
            'sdfo_resync' => $ok ? 'ok' : 'error',
            'post'        => $post_id,
            'action'      => 'edit',
        ], admin_url( 'post.php' ) ) );
        exit;
    }

    public static function admin_notices(): void {
        $result = isset( $_GET['sdfo_resync'] ) ? sanitize_key( (string) $_GET['sdfo_resync'] ) : '';
        if ( $result === '' ) { return; }

        if ( $result === 'ok' ) {
            echo '<div class="notice notice-success is-dismissible"><p>✓ Package re-synced to Stripe successfully.</p></div>';
        } elseif ( $result === 'error' ) {
            $error = (string) get_post_meta( (int) ( $_GET['post'] ?? 0 ), 'sd_stripe_sync_error', true );
            echo '<div class="notice notice-error is-dismissible"><p>✗ Stripe sync failed. ' . ( $error !== '' ? esc_html( $error ) : 'Check error logs.' ) . '</p></div>';
        } elseif ( $result === 'unavailable' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Stripe sync module is not loaded.</p></div>';
        }
    }

    // =========================================================================
    // List table columns — packages
    // =========================================================================

    public static function pkg_columns( array $cols ): array {
        return [
            'cb'            => '<input type="checkbox">',
            'title'         => 'Package',
            'sdfo_key'      => 'Key',
            'sdfo_status'   => 'Status',
            'sdfo_public'   => 'Public',
            'sdfo_price'    => 'Price',
            'sdfo_stripe'   => 'Stripe',
            'sdfo_sort'     => 'Order',
        ];
    }

    public static function pkg_column_content( string $col, int $post_id ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post_id, $k, true );
        $i = fn( string $k ) => (int) get_post_meta( $post_id, $k, true );

        switch ( $col ) {
            case 'sdfo_key':
                echo '<code>' . esc_html( $m( 'sd_package_key' ) ) . '</code>';
                break;
            case 'sdfo_status':
                echo self::status_badge( $m( 'sd_status' ) );
                break;
            case 'sdfo_public':
                echo $i( 'sd_is_public' ) ? '<span style="color:#16a34a">✓ Yes</span>' : '<span style="color:#94a3b8">No</span>';
                break;
            case 'sdfo_price':
                $cents = $i( 'sd_display_price_cents' );
                $mode  = $m( 'sd_billing_mode' );
                if ( $mode === 'free' ) {
                    echo '<span style="color:#16a34a">Free</span>';
                } elseif ( $mode === 'contract' ) {
                    echo '<span style="color:#7c3aed">Contract</span>';
                } elseif ( $cents > 0 ) {
                    echo '<strong>$' . esc_html( number_format( $cents / 100, 2 ) ) . '</strong>';
                    echo '<span style="color:#64748b">/' . esc_html( $m( 'sd_billing_interval' ) ) . '</span>';
                } else {
                    echo '<span style="color:#94a3b8">—</span>';
                }
                break;
            case 'sdfo_stripe':
                $sync = $m( 'sd_stripe_sync_status' );
                echo self::sync_badge( $sync );
                break;
            case 'sdfo_sort':
                echo esc_html( $i( 'sd_sort_order' ) ?: '—' );
                break;
        }
    }

    // =========================================================================
    // List table columns — profiles
    // =========================================================================

    public static function prf_columns( array $cols ): array {
        return [
            'cb'           => '<input type="checkbox">',
            'title'        => 'Profile',
            'sdfo_key'     => 'Key',
            'sdfo_type'    => 'Type',
            'sdfo_status'  => 'Status',
            'sdfo_pkg'     => 'Package',
            'sdfo_fee'     => 'App Fee',
        ];
    }

    public static function prf_column_content( string $col, int $post_id ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post_id, $k, true );

        switch ( $col ) {
            case 'sdfo_key':
                echo '<code>' . esc_html( $m( 'sd_profile_key' ) ) . '</code>';
                break;
            case 'sdfo_type':
                echo esc_html( ucfirst( $m( 'sd_profile_type' ) ?: '—' ) );
                break;
            case 'sdfo_status':
                echo self::status_badge( $m( 'sd_status' ) );
                break;
            case 'sdfo_pkg':
                echo esc_html( $m( 'sd_base_package_key' ) ?: '—' );
                break;
            case 'sdfo_fee':
                $raw    = $m( 'sd_application_fee_policy_json' );
                $policy = $raw !== '' ? (array) json_decode( $raw, true ) : [];
                $mode   = $policy['mode'] ?? 'none';
                if ( $mode === 'percentage' ) {
                    echo esc_html( ( $policy['percentage'] ?? '?' ) . '%' );
                } elseif ( $mode === 'flat' ) {
                    $c = (int) ( $policy['fixed_amount_cents'] ?? 0 );
                    echo '$' . esc_html( number_format( $c / 100, 2 ) );
                } elseif ( $mode === 'none' ) {
                    echo '<span style="color:#94a3b8">None</span>';
                } else {
                    echo esc_html( ucfirst( $mode ) );
                }
                break;
        }
    }

    // =========================================================================
    // List table columns — auth codes
    // =========================================================================

    public static function code_columns( array $cols ): array {
        return [
            'cb'           => '<input type="checkbox">',
            'title'        => 'Label',
            'sdfo_code'    => 'Code',
            'sdfo_status'  => 'Status',
            'sdfo_uses'    => 'Uses',
            'sdfo_expires' => 'Expires',
            'sdfo_profile' => 'Profile',
        ];
    }

    public static function code_column_content( string $col, int $post_id ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post_id, $k, true );
        $i = fn( string $k ) => (int) get_post_meta( $post_id, $k, true );

        switch ( $col ) {
            case 'sdfo_code':
                echo '<code style="font-size:13px;letter-spacing:1px">' . esc_html( $m( 'sd_code' ) ) . '</code>';
                break;
            case 'sdfo_status':
                echo self::code_status_badge( $m( 'sd_status' ) );
                break;
            case 'sdfo_uses':
                $used = $i( 'sd_current_uses' );
                $max  = $i( 'sd_max_uses' );
                echo esc_html( $used ) . ( $max > 0 ? ' / ' . esc_html( $max ) : ' / ∞' );
                break;
            case 'sdfo_expires':
                $exp = $m( 'sd_expires_at_gmt' );
                if ( $exp === '' ) {
                    echo '<span style="color:#94a3b8">Never</span>';
                } else {
                    $ts = strtotime( $exp );
                    if ( $ts && $ts < time() ) {
                        echo '<span style="color:#dc2626">' . esc_html( wp_date( 'M j, Y', $ts ) ) . '</span>';
                    } else {
                        echo esc_html( $ts ? wp_date( 'M j, Y', $ts ) : $exp );
                    }
                }
                break;
            case 'sdfo_profile':
                $pid = $i( 'sd_linked_profile_post_id' );
                if ( $pid > 0 ) {
                    $key = (string) get_post_meta( $pid, 'sd_profile_key', true );
                    echo '<code>' . esc_html( $key ?: '#' . $pid ) . '</code>';
                } else {
                    echo '<span style="color:#dc2626">None</span>';
                }
                break;
        }
    }

    // =========================================================================
    // List table columns — vendors
    // =========================================================================

    public static function vnd_columns( array $cols ): array {
        return [
            'cb'            => '<input type="checkbox">',
            'title'         => 'Vendor',
            'sdfo_key'      => 'Key',
            'sdfo_status'   => 'Status',
            'sdfo_stripe'   => 'Stripe Account',
            'sdfo_payout'   => 'Payout Mode',
            'sdfo_email'    => 'Contact',
        ];
    }

    public static function vnd_column_content( string $col, int $post_id ): void {
        $m = fn( string $k ) => (string) get_post_meta( $post_id, $k, true );

        switch ( $col ) {
            case 'sdfo_key':
                echo '<code>' . esc_html( $m( 'sd_vendor_key' ) ) . '</code>';
                break;
            case 'sdfo_status':
                echo self::status_badge( $m( 'sd_status' ) );
                break;
            case 'sdfo_stripe':
                $acct = $m( 'sd_stripe_account_id' );
                if ( $acct !== '' ) {
                    echo '<code>' . esc_html( $acct ) . '</code>';
                } else {
                    echo '<span style="color:#dc2626">Not set</span>';
                }
                break;
            case 'sdfo_payout':
                echo esc_html( ucfirst( $m( 'sd_payout_mode' ) ?: '—' ) );
                break;
            case 'sdfo_email':
                echo esc_html( $m( 'sd_contact_email' ) ?: '—' );
                break;
        }
    }

    // =========================================================================
    // Shared field renderers
    // =========================================================================

    private static function field_text( string $name, string $label, string $value, string $description = '' ): void {
        echo '<div class="sdfo-field">';
        echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%">';
        if ( $description !== '' ) {
            echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
        }
        echo '</div>';
    }

    private static function field_textarea( string $name, string $label, string $value, string $description = '' ): void {
        echo '<div class="sdfo-field">';
        echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3" style="width:100%">' . esc_textarea( $value ) . '</textarea>';
        if ( $description !== '' ) {
            echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
        }
        echo '</div>';
    }

    private static function field_select( string $name, string $label, string $current, array $options, string $description = '' ): void {
        echo '<div class="sdfo-field">';
        echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
        foreach ( $options as $val => $lbl ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select>';
        if ( $description !== '' ) {
            echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
        }
        echo '</div>';
    }

    private static function field_number( string $name, string $label, int $value, string $description = '' ): void {
        echo '<div class="sdfo-field">';
        echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<input type="number" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100px">';
        if ( $description !== '' ) {
            echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
        }
        echo '</div>';
    }

    private static function field_checkbox( string $name, string $label, bool $checked, string $description = '' ): void {
        echo '<div class="sdfo-field sdfo-field--check">';
        echo '<label>';
        echo '<input type="checkbox" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '>';
        echo ' ' . esc_html( $label );
        echo '</label>';
        if ( $description !== '' ) {
            echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
        }
        echo '</div>';
    }

    // =========================================================================
    // Badge renderers
    // =========================================================================

    private static function status_badge( string $status ): string {
        $map = [
            'active'   => [ '#16a34a', 'Active' ],
            'inactive' => [ '#94a3b8', 'Inactive' ],
        ];
        [ $color, $label ] = $map[ $status ] ?? [ '#64748b', ucfirst( $status ) ];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;font-weight:700">' . esc_html( $label ) . '</span>';
    }

    private static function sync_badge( string $status ): string {
        $map = [
            'synced'  => [ '#16a34a', '✓ Synced' ],
            'error'   => [ '#dc2626', '✗ Error' ],
            'pending' => [ '#d97706', '⏳ Pending' ],
        ];
        [ $color, $label ] = $map[ $status ] ?? [ '#94a3b8', '—' ];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;font-weight:700">' . esc_html( $label ) . '</span>';
    }

    private static function code_status_badge( string $status ): string {
        $map = [
            'active'    => [ '#16a34a', 'Active' ],
            'inactive'  => [ '#94a3b8', 'Inactive' ],
            'exhausted' => [ '#d97706', 'Exhausted' ],
            'expired'   => [ '#dc2626', 'Expired' ],
        ];
        [ $color, $label ] = $map[ $status ] ?? [ '#64748b', ucfirst( $status ) ];
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . esc_attr( $color ) . ';color:#fff;font-size:11px;font-weight:700">' . esc_html( $label ) . '</span>';
    }

    // =========================================================================
    // Inline styles
    // =========================================================================

    public static function inline_styles(): void {
        $screen = get_current_screen();
        if ( ! $screen ) { return; }

        $managed_types = [ self::PKG, self::PRF, self::CODE, self::VND ];
        if ( ! in_array( $screen->post_type, $managed_types, true ) ) { return; }

        ?>
        <style>
        /* ---- Field layout ---- */
        .sdfo-field { margin-bottom: 14px; }
        .sdfo-field label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
        .sdfo-field--check label { display: flex; align-items: center; gap: 6px; font-weight: 400; }
        .sdfo-field--check input[type=checkbox] { margin: 0; }
        .sdfo-field--info { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; }
        .sdfo-field--info p { margin: 0 0 8px; font-size: 12px; color: #475569; }
        .sdfo-field--info p:last-child { margin-bottom: 0; }
        .sdfo-field input[type=text],
        .sdfo-field input[type=email],
        .sdfo-field input[type=number],
        .sdfo-field select,
        .sdfo-field textarea { font-size: 13px; }

        /* ---- Feature grid ---- */
        .sdfo-feature-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .sdfo-feature-item { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
        .sdfo-feature-item input { margin: 0; }

        /* ---- Checkbox group ---- */
        .sdfo-checkbox-group { display: flex; flex-wrap: wrap; gap: 10px; }
        .sdfo-checkbox-group label { display: flex; align-items: center; gap: 5px; font-size: 13px; }

        /* ---- Price wrap ---- */
        .sdfo-price-wrap { display: flex; align-items: center; gap: 4px; }
        .sdfo-currency { font-size: 14px; font-weight: 700; color: #475569; }

        /* ---- Stripe status ---- */
        .sdfo-field--stripe { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
        .sdfo-stripe-status { display: flex; flex-direction: column; gap: 8px; }
        .sdfo-stripe-ids { font-size: 12px; color: #475569; margin-top: 4px; }
        .sdfo-stripe-ids code { font-size: 11px; }
        .sdfo-error-msg { color: #dc2626; font-size: 12px; margin: 4px 0 0; }
        .sdfo-label { font-weight: 600; }
        .sdfo-muted { color: #94a3b8; font-size: 12px; }

        /* ---- Badges ---- */
        .sdfo-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .sdfo-badge--ok      { background: #16a34a; color: #fff; }
        .sdfo-badge--error   { background: #dc2626; color: #fff; }
        .sdfo-badge--pending { background: #d97706; color: #fff; }

        /* ---- Usage display ---- */
        .sdfo-usage-display { display: flex; align-items: center; gap: 10px; }
        .sdfo-use-count { font-size: 22px; font-weight: 900; color: #0f172a; }
        .sdfo-use-bar { width: 120px; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .sdfo-use-bar-fill { height: 100%; background: #2563eb; border-radius: 999px; }

        /* ---- Re-sync button ---- */
        .sdfo-resync-btn { margin-top: 4px !important; }
        </style>
        <?php
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    private static function fmt_gmt( string $gmt ): string {
        if ( $gmt === '' || $gmt === '0000-00-00 00:00:00' ) { return '—'; }
        $ts = strtotime( $gmt );
        if ( ! $ts ) { return $gmt; }
        return wp_date( 'M j, Y g:i a', $ts ) . ' (' . human_time_diff( $ts, time() ) . ' ago)';
    }
}
