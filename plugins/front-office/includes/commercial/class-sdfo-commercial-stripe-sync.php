<?php
/**
 * SDFO_Commercial_Stripe_Sync
 *
 * Stripe sync layer for the commercial package CPT.
 *
 * Doctrine:
 *   SoloDrive is the source of truth. Stripe is the execution layer.
 *   This class pushes package definitions TO Stripe — products and prices
 *   are created and updated from what is stored in sd_commercial_package
 *   post meta. We never read from Stripe to determine what a package is.
 *
 * Behavior on package save:
 *   1. Resolve the platform Stripe secret key. Abort silently if missing.
 *   2. Skip non-subscription billing modes (contract, free have no Stripe price).
 *   3. If no stripe_product_id on this package → create a Stripe product.
 *      If stripe_product_id exists → update its name/description/metadata.
 *   4. Compute a billing hash over (price_cents, interval, currency).
 *      If the hash has changed since last sync (or no price exists yet):
 *        a. Archive the old Stripe price if one exists (prices are immutable).
 *        b. Create a new Stripe price under the product.
 *        c. Store the new price id and billing hash.
 *   5. Write sd_stripe_sync_status = 'synced' | 'error' and sd_stripe_sync_at_gmt.
 *
 * Fires on:
 *   sdfo_commercial_package_saved (action registered by SDFO_Commercial_CPTs::on_save_package)
 *
 * Config required (wp-config.php on solodrive.pro):
 *   define('SD_FRONT_STRIPE_SECRET_KEY', 'sk_...');
 *   // or: option 'sd_stripe_secret_key'
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'SDFO_Commercial_Stripe_Sync', false ) ) { return; }

final class SDFO_Commercial_Stripe_Sync {

    // Meta key used to detect billing config changes between saves.
    // Avoids creating a new Stripe price on every save when nothing changed.
    private const META_BILLING_HASH = 'sd_stripe_billing_hash';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'sdfo_commercial_package_saved', [ __CLASS__, 'sync_package' ], 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Main sync entry point
    // -------------------------------------------------------------------------

    public static function sync_package( int $post_id, \WP_Post $post, bool $update ): void {

        // ------------------------------------------------------------------
        // 1. Resolve Stripe secret key — abort silently if not configured.
        // ------------------------------------------------------------------
        $secret = self::stripe_secret();
        if ( $secret === '' ) {
            error_log( '[SDFO Stripe Sync] Skipped: Stripe secret key not configured. post_id=' . $post_id );
            return;
        }

        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            self::mark_error( $post_id, 'stripe_sdk_missing' );
            error_log( '[SDFO Stripe Sync] Stripe PHP SDK not loaded. post_id=' . $post_id );
            return;
        }

        // ------------------------------------------------------------------
        // 2. Load package meta.
        // ------------------------------------------------------------------
        $billing_mode   = (string) get_post_meta( $post_id, 'sd_billing_mode',        true );
        $billing_interval = (string) get_post_meta( $post_id, 'sd_billing_interval',  true ) ?: 'month';
        $price_cents    = (int)    get_post_meta( $post_id, 'sd_display_price_cents',   true );
        $currency       = (string) get_post_meta( $post_id, 'sd_currency',             true ) ?: 'usd';
        $package_key    = (string) get_post_meta( $post_id, 'sd_package_key',          true );
        $description    = (string) get_post_meta( $post_id, 'sd_description',          true );
        $product_id     = (string) get_post_meta( $post_id, 'sd_stripe_product_id',    true );
        $price_id       = (string) get_post_meta( $post_id, 'sd_stripe_price_id',      true );

        // Only subscription mode has a Stripe product + recurring price.
        // Contract and free modes are managed outside Stripe's standard flow.
        if ( ! in_array( $billing_mode, [ 'subscription' ], true ) ) {
            error_log( '[SDFO Stripe Sync] Skipped: billing mode is not subscription. mode=' . $billing_mode . ' post_id=' . $post_id );
            return;
        }

        try {
            \Stripe\Stripe::setApiKey( $secret );

            // ------------------------------------------------------------------
            // 3. Product — create or update.
            // ------------------------------------------------------------------
            $product_meta = [
                'name'        => $post->post_title,
                'description' => $description !== '' ? $description : null,
                'metadata'    => [
                    'package_key'  => $package_key,
                    'package_post_id' => (string) $post_id,
                    'managed_by'   => 'solodrive',
                ],
                'statement_descriptor' => self::safe_statement_descriptor( $post->post_title ),
            ];

            if ( $product_id === '' ) {
                // Create new product.
                $product = \Stripe\Product::create( array_filter( $product_meta, fn( $v ) => $v !== null ) );
                $product_id = (string) $product->id;
                update_post_meta( $post_id, 'sd_stripe_product_id', $product_id );
                error_log( '[SDFO Stripe Sync] Stripe product created. product_id=' . $product_id . ' post_id=' . $post_id );
            } else {
                // Update existing product — name, description, metadata.
                \Stripe\Product::update( $product_id, array_filter( $product_meta, fn( $v ) => $v !== null ) );
                error_log( '[SDFO Stripe Sync] Stripe product updated. product_id=' . $product_id . ' post_id=' . $post_id );
            }

            // ------------------------------------------------------------------
            // 4. Price — create only when billing config has changed.
            //    Stripe prices are immutable; we archive the old one and create
            //    a new one whenever price_cents, interval, or currency changes.
            // ------------------------------------------------------------------
            $billing_hash = self::billing_hash( $price_cents, $billing_interval, $currency );
            $stored_hash  = (string) get_post_meta( $post_id, self::META_BILLING_HASH, true );

            $price_needs_sync = ( $billing_hash !== $stored_hash || $price_id === '' );

            if ( $price_needs_sync ) {

                // Archive the existing price if there is one.
                if ( $price_id !== '' ) {
                    try {
                        \Stripe\Price::update( $price_id, [ 'active' => false ] );
                        error_log( '[SDFO Stripe Sync] Archived old Stripe price. price_id=' . $price_id . ' post_id=' . $post_id );
                    } catch ( \Throwable $archive_err ) {
                        // Non-fatal — log and continue to create the new price.
                        error_log( '[SDFO Stripe Sync] Could not archive old price (non-fatal). price_id=' . $price_id . ' error=' . $archive_err->getMessage() );
                    }
                }

                // Create new price.
                $new_price = \Stripe\Price::create( [
                    'product'    => $product_id,
                    'unit_amount'=> $price_cents,
                    'currency'   => $currency,
                    'recurring'  => [ 'interval' => $billing_interval ],
                    'metadata'   => [
                        'package_key'     => $package_key,
                        'package_post_id' => (string) $post_id,
                        'managed_by'      => 'solodrive',
                    ],
                ] );

                $new_price_id = (string) $new_price->id;
                update_post_meta( $post_id, 'sd_stripe_price_id',  $new_price_id );
                update_post_meta( $post_id, self::META_BILLING_HASH, $billing_hash );

                error_log( '[SDFO Stripe Sync] New Stripe price created. price_id=' . $new_price_id . ' amount=' . $price_cents . ' interval=' . $billing_interval . ' post_id=' . $post_id );
            }

            // ------------------------------------------------------------------
            // 5. Mark sync successful.
            // ------------------------------------------------------------------
            self::mark_synced( $post_id );

        } catch ( \Throwable $e ) {
            self::mark_error( $post_id, $e->getMessage() );
            error_log( '[SDFO Stripe Sync] Sync failed. post_id=' . $post_id . ' error=' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    private static function mark_synced( int $post_id ): void {
        update_post_meta( $post_id, 'sd_stripe_sync_status', 'synced' );
        update_post_meta( $post_id, 'sd_stripe_sync_at_gmt', current_time( 'mysql', true ) );
        delete_post_meta( $post_id, 'sd_stripe_sync_error' );
    }

    private static function mark_error( int $post_id, string $message ): void {
        update_post_meta( $post_id, 'sd_stripe_sync_status', 'error' );
        update_post_meta( $post_id, 'sd_stripe_sync_at_gmt', current_time( 'mysql', true ) );
        update_post_meta( $post_id, 'sd_stripe_sync_error',  $message );
    }

    // -------------------------------------------------------------------------
    // Manual re-sync — callable from admin UI (Phase 4) or WP-CLI
    // -------------------------------------------------------------------------

    /**
     * Force a full re-sync of a package to Stripe regardless of hash state.
     * Clears the stored billing hash so price creation is forced.
     */
    public static function force_resync( int $post_id ): bool {
        if ( get_post_type( $post_id ) !== SDFO_Commercial_CPTs::CPT_PACKAGE ) {
            return false;
        }
        delete_post_meta( $post_id, self::META_BILLING_HASH );
        $post = get_post( $post_id );
        if ( ! $post ) { return false; }
        self::sync_package( $post_id, $post, true );
        return (string) get_post_meta( $post_id, 'sd_stripe_sync_status', true ) === 'synced';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Hash the billing config fields that Stripe cares about.
     * A changed hash means we need a new Stripe price.
     */
    private static function billing_hash( int $price_cents, string $interval, string $currency ): string {
        return md5( $price_cents . '|' . $interval . '|' . $currency );
    }

    /**
     * Stripe statement descriptors: 5–22 uppercase ASCII chars, no special chars.
     */
    private static function safe_statement_descriptor( string $label ): string {
        $clean = strtoupper( preg_replace( '/[^A-Z0-9 ]/i', '', $label ) );
        $clean = trim( substr( $clean, 0, 22 ) );
        if ( strlen( $clean ) < 5 ) {
            $clean = 'SOLODRIVE';
        }
        return $clean;
    }

    /**
     * Resolve the platform Stripe secret key.
     * Mirrors the pattern used in SD_Front_Office_Scaffold.
     */
    private static function stripe_secret(): string {
        if ( defined( 'SD_FRONT_STRIPE_SECRET_KEY' ) && SD_FRONT_STRIPE_SECRET_KEY !== '' ) {
            return SD_FRONT_STRIPE_SECRET_KEY;
        }
        return (string) get_option( 'sd_stripe_secret_key', '' );
    }
}
