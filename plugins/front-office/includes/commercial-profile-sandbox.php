<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SoloDrive commercial profile sandbox override.
 *
 * Front-office owns tenant acquisition. This binds the public launch package
 * to the Stripe sandbox product/price used by subscription checkout.
 */
add_filter('sdfo_commercial_profiles', function(array $profiles) : array {
  if (!isset($profiles['operator'])) {
    $profiles['operator'] = [];
  }

  $profiles['operator'] = array_replace_recursive($profiles['operator'], [
    'package_key'            => 'operator',
    'commercial_profile_key' => 'operator_default',
    'public'                 => true,
    'label'                  => 'SoloDrive Platform Subscription',
    'billing'                => [
      'mode'                => 'subscription',
      'interval'            => 'month',
      'currency'            => 'usd',
      'stripe_product_id'   => 'prod_UJ9vuFmYJ0bNJK',
      'stripe_price_id'     => 'price_1TKXbTBhqQFxd7YhzSsP66oU',
      'display_price_cents' => 2000,
    ],
  ]);

  return $profiles;
});
