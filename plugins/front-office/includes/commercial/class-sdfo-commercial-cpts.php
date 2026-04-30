<?php
/**
 * SDFO_Commercial_CPTs
 *
 * Registers the four commercial post types that govern per-tenant Stripe
 * Connect commercial terms on the SoloDrive platform:
 *
 *   sd_commercial_package   — subscription products a prospect selects
 *   sd_commercial_profile   — business rules resolved per tenant
 *   sd_authorization_code   — tracked invite codes with use count + expiry
 *   sd_vendor               — referrer/marketer entity with Stripe account
 *
 * Doctrine:
 *   - The front-office is the commercial source of truth.
 *   - Stripe is the execution layer. Products and prices are synced TO Stripe,
 *     not sourced from it.
 *   - The config file (commercial-profiles.php) remains a seed/fallback only.
 *     Phase 5 will point the kernel API adapter functions at these CPTs.
 *
 * Phase scope:
 *   Phase 2 — CPT + meta registration, defaults on save, static API methods.
 *   Phase 3 — Stripe sync layer (added to this class via sdfo_commercial_package_saved hook).
 *   Phase 4 — Admin UI (separate class, loaded alongside this one).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'SDFO_Commercial_CPTs', false ) ) { return; }

final class SDFO_Commercial_CPTs {

    // -------------------------------------------------------------------------
    // CPT slugs
    // -------------------------------------------------------------------------

    const CPT_PACKAGE   = 'sd_comm_package';  // 15 chars — WP max is 20
    const CPT_PROFILE   = 'sd_comm_profile';  // 15 chars
    const CPT_AUTH_CODE = 'sd_auth_code';     // 12 chars
    const CPT_VENDOR    = 'sd_vendor';        //  9 chars

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'init', [ __CLASS__, 'register_post_types' ] );
        add_action( 'init', [ __CLASS__, 'register_meta_keys' ] );

        add_action( 'save_post_' . self::CPT_PACKAGE,   [ __CLASS__, 'on_save_package' ],   10, 3 );
        add_action( 'save_post_' . self::CPT_PROFILE,   [ __CLASS__, 'on_save_profile' ],   10, 3 );
        add_action( 'save_post_' . self::CPT_AUTH_CODE, [ __CLASS__, 'on_save_auth_code' ], 10, 3 );
        add_action( 'save_post_' . self::CPT_VENDOR,    [ __CLASS__, 'on_save_vendor' ],    10, 3 );
    }

    // -------------------------------------------------------------------------
    // CPT registration
    // -------------------------------------------------------------------------

    public static function register_post_types(): void {

        // -- sd_commercial_package --------------------------------------------
        register_post_type( self::CPT_PACKAGE, [
            'labels' => [
                'name'               => 'Commercial Packages',
                'singular_name'      => 'Commercial Package',
                'menu_name'          => 'Packages',
                'add_new'            => 'Add Package',
                'add_new_item'       => 'Add New Package',
                'edit_item'          => 'Edit Package',
                'new_item'           => 'New Package',
                'view_item'          => 'View Package',
                'search_items'       => 'Search Packages',
                'not_found'          => 'No packages found',
                'not_found_in_trash' => 'No packages found in Trash',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'sd-commercial',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'hierarchical'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'query_var'           => false,
            'rewrite'             => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // -- sd_commercial_profile --------------------------------------------
        register_post_type( self::CPT_PROFILE, [
            'labels' => [
                'name'               => 'Commercial Profiles',
                'singular_name'      => 'Commercial Profile',
                'menu_name'          => 'Profiles',
                'add_new'            => 'Add Profile',
                'add_new_item'       => 'Add New Profile',
                'edit_item'          => 'Edit Profile',
                'new_item'           => 'New Profile',
                'view_item'          => 'View Profile',
                'search_items'       => 'Search Profiles',
                'not_found'          => 'No profiles found',
                'not_found_in_trash' => 'No profiles found in Trash',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'sd-commercial',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'hierarchical'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'query_var'           => false,
            'rewrite'             => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // -- sd_authorization_code --------------------------------------------
        register_post_type( self::CPT_AUTH_CODE, [
            'labels' => [
                'name'               => 'Authorization Codes',
                'singular_name'      => 'Authorization Code',
                'menu_name'          => 'Auth Codes',
                'add_new'            => 'Add Code',
                'add_new_item'       => 'Add New Code',
                'edit_item'          => 'Edit Code',
                'new_item'           => 'New Code',
                'view_item'          => 'View Code',
                'search_items'       => 'Search Codes',
                'not_found'          => 'No codes found',
                'not_found_in_trash' => 'No codes found in Trash',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'sd-commercial',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'hierarchical'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'query_var'           => false,
            'rewrite'             => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // -- sd_vendor --------------------------------------------------------
        register_post_type( self::CPT_VENDOR, [
            'labels' => [
                'name'               => 'Vendors',
                'singular_name'      => 'Vendor',
                'menu_name'          => 'Vendors',
                'add_new'            => 'Add Vendor',
                'add_new_item'       => 'Add New Vendor',
                'edit_item'          => 'Edit Vendor',
                'new_item'           => 'New Vendor',
                'view_item'          => 'View Vendor',
                'search_items'       => 'Search Vendors',
                'not_found'          => 'No vendors found',
                'not_found_in_trash' => 'No vendors found in Trash',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'sd-commercial',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'hierarchical'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'query_var'           => false,
            'rewrite'             => false,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // Register the top-level admin menu that groups all four CPTs.
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
    }

    public static function register_admin_menu(): void {
        add_menu_page(
            'Commercial',
            'Commercial',
            'manage_options',
            'sd-commercial',
            [ __CLASS__, 'render_commercial_overview' ],
            'dashicons-chart-bar',
            30
        );

        add_submenu_page( 'sd-commercial', 'Packages',   'Packages',   'manage_options', 'edit.php?post_type=' . self::CPT_PACKAGE );
        add_submenu_page( 'sd-commercial', 'Profiles',   'Profiles',   'manage_options', 'edit.php?post_type=' . self::CPT_PROFILE );
        add_submenu_page( 'sd-commercial', 'Auth Codes', 'Auth Codes', 'manage_options', 'edit.php?post_type=' . self::CPT_AUTH_CODE );
        add_submenu_page( 'sd-commercial', 'Vendors',    'Vendors',    'manage_options', 'edit.php?post_type=' . self::CPT_VENDOR );
    }

    public static function render_commercial_overview(): void {
        echo '<div class="wrap"><h1>Commercial</h1>';
        echo '<p>Manage subscription packages, commercial profiles, authorization codes, and vendor arrangements.</p>';
        echo '<ul style="list-style:disc;margin-left:20px;line-height:2">';
        echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_PACKAGE ) ) . '">Packages</a> — subscription products prospects select at signup. Synced to Stripe.</li>';
        echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_PROFILE ) ) . '">Profiles</a> — application fee, revenue sharing, and feature rules applied per tenant.</li>';
        echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_AUTH_CODE ) ) . '">Auth Codes</a> — tracked invite codes that unlock non-default profiles. Campaign-safe with use limits and expiry.</li>';
        echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_VENDOR ) ) . '">Vendors</a> — referrer/marketer entities. Stripe account required for automated commission payouts.</li>';
        echo '</ul></div>';
    }

    // -------------------------------------------------------------------------
    // Meta key registration
    // -------------------------------------------------------------------------

    public static function register_meta_keys(): void {
        self::register_package_meta();
        self::register_profile_meta();
        self::register_auth_code_meta();
        self::register_vendor_meta();
    }

    private static function register_package_meta(): void {
        $keys = [
            // Identity
            'sd_package_key'             => 'string',  // unique slug, e.g. 'operator'
            'sd_description'             => 'string',
            'sd_is_public'               => 'integer', // 1 = visible on pricing page
            'sd_status'                  => 'string',  // active | inactive
            'sd_sort_order'              => 'integer',
            // Billing
            'sd_billing_mode'            => 'string',  // subscription | contract | free
            'sd_billing_interval'        => 'string',  // month | year
            'sd_display_price_cents'     => 'integer',
            'sd_currency'                => 'string',  // usd
            // Stripe — synced from this record; Stripe is the execution layer
            'sd_stripe_product_id'       => 'string',
            'sd_stripe_price_id'         => 'string',
            'sd_stripe_sync_status'      => 'string',  // synced | pending | error
            'sd_stripe_sync_at_gmt'      => 'string',
            'sd_stripe_sync_error'       => 'string',
            // Relations
            'sd_default_profile_post_id' => 'integer', // → sd_commercial_profile post ID
            // Timestamps
            'sd_created_at_gmt'          => 'string',
            'sd_updated_at_gmt'          => 'string',
        ];

        foreach ( $keys as $key => $type ) {
            register_post_meta( self::CPT_PACKAGE, $key, self::meta_args( $type ) );
        }
    }

    private static function register_profile_meta(): void {
        $keys = [
            // Identity
            'sd_profile_key'                 => 'string',  // unique slug, e.g. 'operator_default'
            'sd_profile_type'                => 'string',  // default | custom | comp | contract
            'sd_base_package_key'            => 'string',  // which package this profile belongs to
            'sd_linked_package_post_id'      => 'integer', // → sd_commercial_package post ID
            'sd_status'                      => 'string',  // active | inactive
            // Policy JSON fields — see default_*() methods for shape documentation
            'sd_features_json'               => 'string',
            'sd_discount_policy_json'        => 'string',
            'sd_application_fee_policy_json' => 'string',
            'sd_provisioning_policy_json'    => 'string',
            // Timestamps
            'sd_created_at_gmt'              => 'string',
            'sd_updated_at_gmt'              => 'string',
        ];

        foreach ( $keys as $key => $type ) {
            register_post_meta( self::CPT_PROFILE, $key, self::meta_args( $type ) );
        }
    }

    private static function register_auth_code_meta(): void {
        $keys = [
            // The code string — stored uppercase, normalized on save
            'sd_code'                      => 'string',
            'sd_status'                    => 'string',  // active | inactive | exhausted | expired
            // Relations
            'sd_linked_profile_post_id'    => 'integer', // → sd_commercial_profile post ID
            'sd_allowed_package_keys_json' => 'string',  // JSON array e.g. ["operator","growth"]
            // Usage tracking — campaign-safe
            'sd_max_uses'                  => 'integer', // 0 = unlimited
            'sd_current_uses'              => 'integer', // auto-incremented on redemption
            // Expiry
            'sd_expires_at_gmt'            => 'string',  // empty = never expires
            // Optional assignment constraints
            'sd_assigned_email'            => 'string',  // lock to a specific email if set
            'sd_assigned_domain'           => 'string',  // lock to an email domain if set
            // Notes
            'sd_notes'                     => 'string',
            // Timestamps
            'sd_created_at_gmt'            => 'string',
            'sd_updated_at_gmt'            => 'string',
        ];

        foreach ( $keys as $key => $type ) {
            register_post_meta( self::CPT_AUTH_CODE, $key, self::meta_args( $type ) );
        }
    }

    private static function register_vendor_meta(): void {
        $keys = [
            // Identity
            'sd_vendor_key'        => 'string',  // unique slug, e.g. 'acme-marketing'
            'sd_contact_name'      => 'string',
            'sd_contact_email'     => 'string',
            'sd_status'            => 'string',  // active | inactive
            // Stripe — vendor receives commission transfers to this account
            'sd_stripe_account_id' => 'string',
            // Payout mode
            'sd_payout_mode'       => 'string',  // automated | manual | accrual
            // Notes
            'sd_notes'             => 'string',
            // Timestamps
            'sd_created_at_gmt'    => 'string',
            'sd_updated_at_gmt'    => 'string',
        ];

        foreach ( $keys as $key => $type ) {
            register_post_meta( self::CPT_VENDOR, $key, self::meta_args( $type ) );
        }
    }

    private static function meta_args( string $type ): array {
        return [
            'type'              => $type,
            'single'            => true,
            'show_in_rest'      => false,
            'auth_callback'     => static fn() => current_user_can( 'manage_options' ),
            'sanitize_callback' => [ __CLASS__, 'sanitize_meta' ],
        ];
    }

    public static function sanitize_meta( mixed $value ): mixed {
        if ( is_array( $value ) || is_object( $value ) ) {
            return wp_json_encode( $value );
        }
        if ( is_int( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) && (string) (int) $value === (string) $value ) {
            return (int) $value;
        }
        return sanitize_text_field( (string) $value );
    }

    // -------------------------------------------------------------------------
    // Save hooks — enforce defaults and normalization
    // -------------------------------------------------------------------------

    public static function on_save_package( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }

        $now = current_time( 'mysql', true );

        $key = (string) get_post_meta( $post_id, 'sd_package_key', true );
        if ( $key === '' ) {
            update_post_meta( $post_id, 'sd_package_key', sanitize_title( $post->post_title ) );
        }

        if ( (string) get_post_meta( $post_id, 'sd_status', true ) === '' ) {
            update_post_meta( $post_id, 'sd_status', 'inactive' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_billing_mode', true ) === '' ) {
            update_post_meta( $post_id, 'sd_billing_mode', 'subscription' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_billing_interval', true ) === '' ) {
            update_post_meta( $post_id, 'sd_billing_interval', 'month' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_currency', true ) === '' ) {
            update_post_meta( $post_id, 'sd_currency', 'usd' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_stripe_sync_status', true ) === '' ) {
            update_post_meta( $post_id, 'sd_stripe_sync_status', 'pending' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_created_at_gmt', true ) === '' ) {
            update_post_meta( $post_id, 'sd_created_at_gmt', $now );
        }

        update_post_meta( $post_id, 'sd_updated_at_gmt', $now );

        // Phase 3 hook — Stripe sync layer attaches here.
        do_action( 'sdfo_commercial_package_saved', $post_id, $post, $update );
    }

    public static function on_save_profile( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }

        $now = current_time( 'mysql', true );

        if ( (string) get_post_meta( $post_id, 'sd_profile_key', true ) === '' ) {
            update_post_meta( $post_id, 'sd_profile_key', sanitize_title( $post->post_title ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_status', true ) === '' ) {
            update_post_meta( $post_id, 'sd_status', 'inactive' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_profile_type', true ) === '' ) {
            update_post_meta( $post_id, 'sd_profile_type', 'default' );
        }

        // Seed default JSON policy fields if absent.
        if ( (string) get_post_meta( $post_id, 'sd_features_json', true ) === '' ) {
            update_post_meta( $post_id, 'sd_features_json', wp_json_encode( self::default_features() ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_application_fee_policy_json', true ) === '' ) {
            update_post_meta( $post_id, 'sd_application_fee_policy_json', wp_json_encode( self::default_application_fee_policy() ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_discount_policy_json', true ) === '' ) {
            update_post_meta( $post_id, 'sd_discount_policy_json', wp_json_encode( self::default_discount_policy() ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_provisioning_policy_json', true ) === '' ) {
            update_post_meta( $post_id, 'sd_provisioning_policy_json', wp_json_encode( self::default_provisioning_policy() ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_created_at_gmt', true ) === '' ) {
            update_post_meta( $post_id, 'sd_created_at_gmt', $now );
        }

        update_post_meta( $post_id, 'sd_updated_at_gmt', $now );
    }

    public static function on_save_auth_code( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }

        $now = current_time( 'mysql', true );

        // Normalize code string — uppercase, alphanumeric/hyphen/underscore only.
        $code = (string) get_post_meta( $post_id, 'sd_code', true );
        if ( $code === '' ) {
            $code = strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', $post->post_title ) );
        }
        update_post_meta( $post_id, 'sd_code', self::normalize_code( $code ) );

        if ( (string) get_post_meta( $post_id, 'sd_status', true ) === '' ) {
            update_post_meta( $post_id, 'sd_status', 'active' );
        }

        $uses = get_post_meta( $post_id, 'sd_current_uses', true );
        if ( $uses === '' || $uses === false ) {
            update_post_meta( $post_id, 'sd_current_uses', 0 );
        }
        $max = get_post_meta( $post_id, 'sd_max_uses', true );
        if ( $max === '' || $max === false ) {
            update_post_meta( $post_id, 'sd_max_uses', 0 );
        }
        if ( (string) get_post_meta( $post_id, 'sd_allowed_package_keys_json', true ) === '' ) {
            update_post_meta( $post_id, 'sd_allowed_package_keys_json', '[]' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_created_at_gmt', true ) === '' ) {
            update_post_meta( $post_id, 'sd_created_at_gmt', $now );
        }

        update_post_meta( $post_id, 'sd_updated_at_gmt', $now );

        // Auto-expire based on date or use count.
        self::maybe_expire_auth_code( $post_id );
    }

    public static function on_save_vendor( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }

        $now = current_time( 'mysql', true );

        if ( (string) get_post_meta( $post_id, 'sd_vendor_key', true ) === '' ) {
            update_post_meta( $post_id, 'sd_vendor_key', sanitize_title( $post->post_title ) );
        }
        if ( (string) get_post_meta( $post_id, 'sd_status', true ) === '' ) {
            update_post_meta( $post_id, 'sd_status', 'active' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_payout_mode', true ) === '' ) {
            update_post_meta( $post_id, 'sd_payout_mode', 'manual' );
        }
        if ( (string) get_post_meta( $post_id, 'sd_created_at_gmt', true ) === '' ) {
            update_post_meta( $post_id, 'sd_created_at_gmt', $now );
        }

        update_post_meta( $post_id, 'sd_updated_at_gmt', $now );
    }

    // -------------------------------------------------------------------------
    // Auth code lifecycle
    // -------------------------------------------------------------------------

    /**
     * Check expiry and use count; flip status to exhausted/expired if warranted.
     * Safe to call on every save — only writes if status actually needs to change.
     */
    private static function maybe_expire_auth_code( int $post_id ): void {
        $status = (string) get_post_meta( $post_id, 'sd_status', true );

        if ( in_array( $status, [ 'exhausted', 'expired', 'inactive' ], true ) ) {
            return;
        }

        $expires = (string) get_post_meta( $post_id, 'sd_expires_at_gmt', true );
        if ( $expires !== '' ) {
            $ts = strtotime( $expires );
            if ( $ts && $ts < time() ) {
                update_post_meta( $post_id, 'sd_status', 'expired' );
                return;
            }
        }

        $max  = (int) get_post_meta( $post_id, 'sd_max_uses', true );
        $used = (int) get_post_meta( $post_id, 'sd_current_uses', true );
        if ( $max > 0 && $used >= $max ) {
            update_post_meta( $post_id, 'sd_status', 'exhausted' );
        }
    }

    /**
     * Increment the use counter for an authorization code.
     * Called when a prospect successfully redeems a code at checkout.
     * Returns true on success, false if code is already terminal.
     */
    public static function increment_auth_code_uses( int $post_id ): bool {
        if ( get_post_type( $post_id ) !== self::CPT_AUTH_CODE ) {
            return false;
        }

        $status = (string) get_post_meta( $post_id, 'sd_status', true );
        if ( $status !== 'active' ) {
            return false;
        }

        $current = (int) get_post_meta( $post_id, 'sd_current_uses', true );
        update_post_meta( $post_id, 'sd_current_uses', $current + 1 );
        update_post_meta( $post_id, 'sd_updated_at_gmt', current_time( 'mysql', true ) );
        self::maybe_expire_auth_code( $post_id );

        return true;
    }

    // -------------------------------------------------------------------------
    // Static API
    //
    // These mirror the shape of the config-file functions in
    // 020-commercial-profiles.php. Phase 5 will point the kernel adapter
    // functions here, making these CPTs the live source of truth.
    // -------------------------------------------------------------------------

    /**
     * All active public packages, sorted by sort_order.
     * Returns array keyed by package_key.
     */
    public static function get_public_packages(): array {
        $posts = get_posts( [
            'post_type'      => self::CPT_PACKAGE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => 'sd_is_public', 'value' => '1',     'compare' => '=' ],
                [ 'key' => 'sd_status',    'value' => 'active', 'compare' => '=' ],
            ],
        ] );

        $packages = [];
        foreach ( $posts as $post ) {
            $pkg = self::hydrate_package( $post );
            $packages[ $pkg['package_key'] ] = $pkg;
        }

        uasort( $packages, static fn( $a, $b ) =>
            (int) $a['sort_order'] <=> (int) $b['sort_order']
        );

        return $packages;
    }

    /** Get a single package by key. */
    public static function get_package_by_key( string $package_key ): ?array {
        $package_key = sanitize_key( $package_key );
        if ( $package_key === '' ) { return null; }

        $posts = get_posts( [
            'post_type'      => self::CPT_PACKAGE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [ [ 'key' => 'sd_package_key', 'value' => $package_key ] ],
        ] );

        return ! empty( $posts ) ? self::hydrate_package( $posts[0] ) : null;
    }

    /** Get a single profile by key. */
    public static function get_profile_by_key( string $profile_key ): ?array {
        $profile_key = sanitize_key( $profile_key );
        if ( $profile_key === '' ) { return null; }

        $posts = get_posts( [
            'post_type'      => self::CPT_PROFILE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [ [ 'key' => 'sd_profile_key', 'value' => $profile_key ] ],
        ] );

        return ! empty( $posts ) ? self::hydrate_profile( $posts[0] ) : null;
    }

    /** Get a single authorization code record by code string. */
    public static function get_auth_code_by_code( string $code ): ?array {
        $code = self::normalize_code( $code );
        if ( $code === '' ) { return null; }

        $posts = get_posts( [
            'post_type'      => self::CPT_AUTH_CODE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'meta_query'     => [ [ 'key' => 'sd_code', 'value' => $code ] ],
        ] );

        return ! empty( $posts ) ? self::hydrate_auth_code( $posts[0] ) : null;
    }

    /** Get a vendor by post ID. */
    public static function get_vendor( int $post_id ): ?array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::CPT_VENDOR ) { return null; }
        return self::hydrate_vendor( $post );
    }

    /**
     * Validate an authorization code against a package key.
     * Returns the same shape as sd_commercial_validate_authorization_code()
     * in the config-file adapter.
     */
    public static function validate_auth_code( string $code, string $package_key ): array {
        $code        = self::normalize_code( $code );
        $package_key = sanitize_key( $package_key );

        if ( $code === '' ) {
            return [ 'valid' => false, 'reason' => 'empty_code', 'record' => null ];
        }

        $record = self::get_auth_code_by_code( $code );
        if ( ! $record ) {
            return [ 'valid' => false, 'reason' => 'code_not_found', 'record' => null ];
        }

        if ( $record['status'] !== 'active' ) {
            return [ 'valid' => false, 'reason' => 'code_' . $record['status'], 'record' => $record ];
        }

        $allowed = $record['allowed_package_keys'];
        if ( ! empty( $allowed ) && ! in_array( $package_key, $allowed, true ) ) {
            return [ 'valid' => false, 'reason' => 'package_not_allowed', 'record' => $record ];
        }

        $max  = (int) $record['max_uses'];
        $used = (int) $record['current_uses'];
        if ( $max > 0 && $used >= $max ) {
            return [ 'valid' => false, 'reason' => 'max_uses_reached', 'record' => $record ];
        }

        $expires = $record['expires_at_gmt'];
        if ( $expires !== '' ) {
            $ts = strtotime( $expires );
            if ( $ts && $ts < time() ) {
                return [ 'valid' => false, 'reason' => 'code_expired', 'record' => $record ];
            }
        }

        if ( (int) $record['linked_profile_post_id'] <= 0 ) {
            return [ 'valid' => false, 'reason' => 'missing_linked_profile', 'record' => $record ];
        }

        $profile = self::hydrate_profile( get_post( (int) $record['linked_profile_post_id'] ) );
        if ( ! $profile || $profile['status'] !== 'active' ) {
            return [ 'valid' => false, 'reason' => 'linked_profile_invalid', 'record' => $record ];
        }

        return [
            'valid'   => true,
            'reason'  => 'valid',
            'record'  => $record,
            'profile' => $profile,
        ];
    }

    // -------------------------------------------------------------------------
    // Hydration — WP_Post → clean array
    // -------------------------------------------------------------------------

    private static function hydrate_package( \WP_Post $post ): array {
        $m = static fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );
        $i = static fn( string $k ) => (int) get_post_meta( $post->ID, $k, true );

        return [
            'post_id'                 => $post->ID,
            'package_key'             => $m( 'sd_package_key' ) ?: sanitize_title( $post->post_title ),
            'label'                   => $post->post_title,
            'description'             => $m( 'sd_description' ),
            'is_public'               => (bool) $i( 'sd_is_public' ),
            'status'                  => $m( 'sd_status' ) ?: 'inactive',
            'sort_order'              => $i( 'sd_sort_order' ) ?: 99,
            'billing_mode'            => $m( 'sd_billing_mode' ) ?: 'subscription',
            'billing_interval'        => $m( 'sd_billing_interval' ) ?: 'month',
            'display_price_cents'     => $i( 'sd_display_price_cents' ),
            'currency'                => $m( 'sd_currency' ) ?: 'usd',
            'stripe_product_id'       => $m( 'sd_stripe_product_id' ),
            'stripe_price_id'         => $m( 'sd_stripe_price_id' ),
            'stripe_sync_status'      => $m( 'sd_stripe_sync_status' ) ?: 'pending',
            'stripe_sync_at_gmt'      => $m( 'sd_stripe_sync_at_gmt' ),
            'default_profile_post_id' => $i( 'sd_default_profile_post_id' ),
            // Kernel compatibility: sd_resolve_commercial_profile() reads
            // default_profile_key to fall back when no auth code is provided.
            'default_profile_key'     => self::resolve_profile_key_for_post( $i( 'sd_default_profile_post_id' ) ),
            'created_at_gmt'          => $m( 'sd_created_at_gmt' ),
            'updated_at_gmt'          => $m( 'sd_updated_at_gmt' ),
            // Compatibility shape for sd_commercial_build_terms_snapshot()
            'billing' => [
                'mode'                => $m( 'sd_billing_mode' ) ?: 'subscription',
                'interval'            => $m( 'sd_billing_interval' ) ?: 'month',
                'currency'            => $m( 'sd_currency' ) ?: 'usd',
                'stripe_product_id'   => $m( 'sd_stripe_product_id' ),
                'stripe_price_id'     => $m( 'sd_stripe_price_id' ),
                'display_price_cents' => $i( 'sd_display_price_cents' ),
            ],
        ];
    }

    private static function hydrate_profile( ?\WP_Post $post ): ?array {
        if ( ! $post || $post->post_type !== self::CPT_PROFILE ) { return null; }

        $m = static fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );
        $i = static fn( string $k ) => (int) get_post_meta( $post->ID, $k, true );

        $decode = static function ( string $json, array $default = [] ): array {
            if ( $json === '' ) { return $default; }
            $decoded = json_decode( $json, true );
            return is_array( $decoded ) ? $decoded : $default;
        };

        return [
            'post_id'                => $post->ID,
            'profile_key'            => $m( 'sd_profile_key' ) ?: sanitize_title( $post->post_title ),
            'label'                  => $post->post_title,
            'profile_type'           => $m( 'sd_profile_type' ) ?: 'default',
            'base_package_key'       => $m( 'sd_base_package_key' ),
            'linked_package_post_id' => $i( 'sd_linked_package_post_id' ),
            'status'                 => $m( 'sd_status' ) ?: 'inactive',
            'features'               => $decode( $m( 'sd_features_json' ),               self::default_features() ),
            'discount_policy'        => $decode( $m( 'sd_discount_policy_json' ),        self::default_discount_policy() ),
            'application_fee_policy' => $decode( $m( 'sd_application_fee_policy_json' ), self::default_application_fee_policy() ),
            'provisioning_policy'    => $decode( $m( 'sd_provisioning_policy_json' ),    self::default_provisioning_policy() ),
            'created_at_gmt'         => $m( 'sd_created_at_gmt' ),
            'updated_at_gmt'         => $m( 'sd_updated_at_gmt' ),
        ];
    }

    private static function hydrate_auth_code( \WP_Post $post ): array {
        $m = static fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );
        $i = static fn( string $k ) => (int) get_post_meta( $post->ID, $k, true );

        $allowed_raw = $m( 'sd_allowed_package_keys_json' );
        $allowed     = [];
        if ( $allowed_raw !== '' ) {
            $decoded = json_decode( $allowed_raw, true );
            $allowed = is_array( $decoded ) ? $decoded : [];
        }

        $linked_profile_post_id = $i( 'sd_linked_profile_post_id' );

        return [
            'post_id'                => $post->ID,
            'code'                   => $m( 'sd_code' ),
            'label'                  => $post->post_title,
            'status'                 => $m( 'sd_status' ) ?: 'inactive',
            'linked_profile_post_id' => $linked_profile_post_id,
            // assigned_profile_key: kernel compatibility field.
            // sd_commercial_validate_authorization_code() calls sd_commercial_get_profile()
            // using this key, so it must resolve to the linked profile's sd_profile_key.
            'assigned_profile_key'   => self::resolve_profile_key_for_post( $linked_profile_post_id ),
            'allowed_package_keys'   => $allowed,
            'max_uses'               => $i( 'sd_max_uses' ),
            'current_uses'           => $i( 'sd_current_uses' ),
            'expires_at_gmt'         => $m( 'sd_expires_at_gmt' ),
            // expires_at: alias for kernel compatibility.
            // sd_commercial_validate_authorization_code() reads this key.
            'expires_at'             => $m( 'sd_expires_at_gmt' ),
            'assigned_email'         => $m( 'sd_assigned_email' ),
            'assigned_domain'        => $m( 'sd_assigned_domain' ),
            'notes'                  => $m( 'sd_notes' ),
            'created_at_gmt'         => $m( 'sd_created_at_gmt' ),
            'updated_at_gmt'         => $m( 'sd_updated_at_gmt' ),
        ];
    }

    private static function hydrate_vendor( \WP_Post $post ): array {
        $m = static fn( string $k ) => (string) get_post_meta( $post->ID, $k, true );

        return [
            'post_id'           => $post->ID,
            'vendor_key'        => $m( 'sd_vendor_key' ) ?: sanitize_title( $post->post_title ),
            'display_name'      => $post->post_title,
            'contact_name'      => $m( 'sd_contact_name' ),
            'contact_email'     => $m( 'sd_contact_email' ),
            'stripe_account_id' => $m( 'sd_stripe_account_id' ),
            'payout_mode'       => $m( 'sd_payout_mode' ) ?: 'manual',
            'status'            => $m( 'sd_status' ) ?: 'inactive',
            'notes'             => $m( 'sd_notes' ),
            'created_at_gmt'    => $m( 'sd_created_at_gmt' ),
            'updated_at_gmt'    => $m( 'sd_updated_at_gmt' ),
        ];
    }

    // -------------------------------------------------------------------------
    // Default policy shapes
    // -------------------------------------------------------------------------

    private static function default_features(): array {
        return [
            'tenant_storefront'    => true,
            'custom_domain'        => false,
            'lead_capture'         => true,
            'quote_workflow'       => true,
            'stripe_authorization' => true,
            'payment_capture'      => true,
            'operator_console'     => true,
            'driver_portal'        => false,
            'reservations'         => false,
            'stacked_availability' => false,
            'advanced_reporting'   => false,
            'white_label'          => false,
        ];
    }

    private static function default_application_fee_policy(): array {
        return [
            'mode'                    => 'percentage',
            'percentage'              => 8.0,
            'fixed_amount_cents'      => null,
            'minimum_fee_cents'       => 0,
            'maximum_fee_cents'       => null,
            'applies_to'              => [ 'ride_checkout', 'reservation_checkout', 'manual_capture' ],
            'tenant_override_allowed' => false,
        ];
    }

    private static function default_discount_policy(): array {
        return [
            'mode'             => 'none',
            'percent_off'      => null,
            'amount_off_cents' => null,
            'duration'         => null,
            'stripe_coupon_id' => '',
            'internal_credit'  => false,
            'label'            => '',
        ];
    }

    private static function default_provisioning_policy(): array {
        return [
            'auto_provision'          => true,
            'requires_manual_review'  => false,
            'tenant_status_on_create' => 'active',
        ];
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    /**
     * Look up the sd_profile_key for a given profile post ID.
     *
     * Used to populate assigned_profile_key and default_profile_key — the
     * compatibility fields that sd_commercial_validate_authorization_code() and
     * sd_resolve_commercial_profile() in the kernel expect on their data shapes.
     */
    private static function resolve_profile_key_for_post( int $post_id ): string {
        if ( $post_id <= 0 ) { return ''; }
        $key = (string) get_post_meta( $post_id, 'sd_profile_key', true );
        if ( $key !== '' ) { return $key; }
        $post = get_post( $post_id );
        return $post ? sanitize_title( $post->post_title ) : '';
    }

    public static function normalize_code( string $code ): string {
        return strtoupper( preg_replace( '/[^A-Z0-9_-]/i', '', trim( $code ) ) );
    }
}
