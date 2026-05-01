# SoloDrive Commercial Terms and Fee Engine (LOCKED)

Status: ACTIVE  
Purpose: Define the implementation contract for subscription packages, commercial profiles, tenant-specific fee terms, application-fee calculation, fee sharing, and immutable fee snapshots.

---

## Core Doctrine

SoloDrive commercial policy must resolve in one place before Stripe checkout, Stripe capture, reservation capture, manual capture, reporting, or partner allocation code uses it.

The fee engine is not only a plan calculator. It is the commercial terms authority.

It must answer two separate questions:

```txt
1. Application fee charged
   How much SoloDrive charges on this transaction.

2. Fee allocation / sharing
   How the collected application fee is retained or shared.
```

These must remain separate because a tenant discount changes the fee charged, while a partner/revenue-share agreement changes the destination of the fee already charged.

---

## Boundary Doctrine

Commercial configuration belongs to the SoloDrive control plane.

Runtime enforcement happens during tenant execution.

```txt
solodrive.pro
= packages, pricing display, subscription checkout, tenant onboarding, profile assignment

solodrive.pro/t/{tenant_slug}
= lead, quote, auth, ride, capture, application-fee enforcement
```

Do not let the ride execution storefront become the source of truth for package/profile policy.

Do not let the subscription checkout screen become the source of truth for capture-time fee math.

---

## Object Responsibilities

### Package

A package defines what the tenant buys monthly.

Examples:

```txt
basic
pro
elite
```

Package owns:

```txt
public pricing card
monthly subscription billing
Stripe product/price sync
public sort/highlight behavior
default commercial profile
```

Package does not own detailed capture-time fee math except through its default profile.

---

### Profile

A commercial profile defines default operational/commercial behavior.

Profile owns:

```txt
feature gates
application-fee default policy
monthly included-volume policy
discount policy
provisioning policy
```

Profile policy is the default when no tenant-specific active commercial term overrides it.

---

### Tenant Commercial Term

A tenant commercial term defines a tenant-specific override or contract.

It answers:

```txt
Does this tenant currently have commercial terms different from the profile default?
```

Examples:

```txt
3% application fee for first 90 days, then revert to Pro default
0% launch fee through a fixed end date, then manual review
custom max fee for one tenant contract
custom included monthly volume for one tenant
```

Tenant commercial terms must be date-aware, status-aware, and revert-aware.

---

### Fee Share Rule

A fee share rule defines how collected application fees are allocated.

It answers:

```txt
Who receives what portion of the application fee collected?
```

Examples:

```txt
Partner receives 20% of application fees for 6 months.
Sales rep receives 10% of application fees until $1,000 cap.
Tenant receives a temporary rebate allocation.
SoloDrive retains the remainder.
```

Fee share rules do not decide how much is charged to the transaction. They only allocate the application fee after the fee has been calculated.

---

## Resolver Order

The commercial resolver must determine the active policy in this order:

```txt
1. Active tenant-specific commercial term
2. Active promotional/custom contract term
3. Tenant's assigned profile default policy
4. Package default profile policy
5. Safe failure / manual-review required
```

Expired terms must not silently continue.

When an active term expires, its `revert_policy` controls behavior:

```txt
revert_to_profile_default
revert_to_package_default
manual_review
```

If `manual_review` is required, the engine must return a safe blocked/review result rather than inventing fee math.

---

## Required Records / Meta

### Package meta

```txt
sd_package_key
sd_package_status
sd_package_public
sd_package_sort_order
sd_package_billing_mode
sd_package_billing_interval
sd_package_display_price_cents
sd_package_currency
sd_package_stripe_product_id
sd_package_stripe_price_id
sd_package_default_profile_id

sd_package_public_name
sd_package_public_headline
sd_package_public_subheadline
sd_package_price_line
sd_package_fee_line
sd_package_short_description
sd_package_card_badge
sd_package_cta_label
sd_package_highlighted
sd_package_feature_summary
```

---

### Profile fee policy meta

```txt
sd_fee_mode                         // none | percentage | flat | hybrid
sd_fee_percent
sd_fee_flat_cents
sd_fee_min_cents
sd_fee_max_cents
sd_fee_applies_ride_checkout
sd_fee_applies_reservation_checkout
sd_fee_applies_manual_capture
sd_fee_tenant_override_allowed
```

---

### Monthly allowance meta

```txt
sd_fee_monthly_included_volume_cents
sd_fee_allowance_resets
sd_fee_allowance_period             // monthly
sd_fee_allowance_scope              // processed_volume
sd_fee_allowance_label
```

Pro default:

```txt
sd_fee_monthly_included_volume_cents = 50000
sd_fee_allowance_period = monthly
sd_fee_allowance_resets = true
sd_fee_allowance_scope = processed_volume
sd_fee_allowance_label = First $500 processed monthly included
```

---

### Tenant commercial term record

Preferred record type:

```txt
sd_commercial_term
```

Required fields/meta:

```txt
sd_tenant_id
sd_profile_id
sd_package_key
sd_term_type                 // application_fee_override | promo_fee | custom_contract
sd_fee_mode
sd_fee_percent
sd_fee_flat_cents
sd_fee_min_cents
sd_fee_max_cents
sd_monthly_included_volume_cents
sd_effective_start
sd_effective_end
sd_revert_policy             // revert_to_profile_default | revert_to_package_default | manual_review
sd_revert_profile_id
sd_status                    // active | scheduled | expired | cancelled
sd_priority
sd_internal_note
```

---

### Fee share rule record

Preferred record type:

```txt
sd_fee_share_rule
```

Required fields/meta:

```txt
sd_tenant_id
sd_beneficiary_type          // platform | partner | sales_rep | affiliate | tenant | other
sd_beneficiary_id
sd_source                    // application_fee | gross_charge | net_after_stripe | platform_retained
sd_share_mode                // percent | flat | tiered
sd_share_percent
sd_share_flat_cents
sd_cap_cents
sd_starts_at
sd_ends_at
sd_duration_type             // fixed_dates | months_after_activation | until_cap | indefinite
sd_duration_value
sd_revert_policy             // expire | manual_review | renew
sd_priority
sd_status
sd_label
```

---

## Public Package Defaults

### Basic

```txt
Monthly subscription: $10
Application fee: 6.5%
Minimum application fee: $1
Allowance: none
```

### Pro

```txt
Monthly subscription: $25
Included processed volume: first $500 per month
Application fee after allowance: 6.5%
Minimum application fee: $1, only when fee is actually due after allowance
Allowance resets monthly
```

### Elite

```txt
Monthly subscription: $100
Application fee: none
Allowance: unlimited / not applicable
```

---

## Required Public Pricing Cards

Public cards must not depend on the package description field.

Each public card renders from dedicated package public-display fields:

```txt
Plan name
Badge, optional
Headline
Monthly price
Transaction fee line
Short description
Feature bullets
CTA button
```

Public language must avoid internal terms such as tenant, CPT, runtime, control plane, provision package, or application-fee ledger.

Use customer-facing language:

```txt
booking page
direct bookings
payments
operations
ride requests
```

---

## Required Engine Entrypoint

Canonical function:

```php
sd_commercial_calculate_application_fee(array $args): array
```

Input:

```php
[
  'tenant_id'        => 123,
  'profile_id'       => 456,
  'package_key'      => 'pro',
  'transaction_type' => 'ride_checkout', // ride_checkout | reservation_checkout | manual_capture
  'amount_cents'     => 4200,
  'currency'         => 'usd',
  'occurred_at'      => current_time('mysql'),
]
```

Output:

```php
[
  'ok' => true,

  'application_fee_cents' => 273,

  'resolved_policy' => [
    'source'          => 'profile_default', // profile_default | package_default | tenant_override | promo | custom_contract
    'term_id'         => 0,
    'profile_id'      => 456,
    'package_key'     => 'pro',
    'fee_mode'        => 'percentage',
    'fee_percent'     => 6.5,
    'fee_flat_cents'  => 0,
    'fee_min_cents'   => 100,
    'fee_max_cents'   => 0,
    'effective_start' => '',
    'effective_end'   => '',
    'revert_policy'   => '',
  ],

  'allowance' => [
    'applied' => true,
    'scope' => 'processed_volume',
    'period' => 'monthly',
    'included_volume_cents' => 50000,
    'remaining_before_cents' => 12000,
    'remaining_after_cents' => 7800,
    'billable_amount_cents' => 0,
  ],

  'allocations' => [
    [
      'beneficiary_type' => 'platform',
      'beneficiary_id'   => 0,
      'amount_cents'     => 273,
      'source'           => 'application_fee',
      'rule_id'          => 0,
    ],
  ],

  'platform_retained_cents' => 273,
  'shared_out_cents'        => 0,

  'explanation' => 'Profile default fee policy applied.',
]
```

If the full transaction is inside allowance, the return must be:

```php
'application_fee_cents' => 0,
'allowance' => [
  'billable_amount_cents' => 0,
],
'explanation' => 'Within monthly included processing allowance.',
```

---

## Calculation Rules

### Percentage fee

```txt
raw_fee = round(billable_amount_cents * fee_percent / 100)
```

### Flat fee

```txt
raw_fee = fee_flat_cents
```

### None

```txt
raw_fee = 0
```

### Minimum fee

Minimum fee applies only when a fee is actually due.

```txt
if billable_amount_cents > 0 and raw_fee > 0:
  fee = max(raw_fee, fee_min_cents)
```

Minimum fee must not turn a zero-fee allowance transaction into a charged transaction.

### Maximum fee

```txt
if fee_max_cents > 0:
  fee = min(fee, fee_max_cents)
```

---

## Monthly Allowance Ledger

The engine needs monthly processed-volume state for Pro and future included-volume plans.

Required dimensions:

```txt
tenant_id
profile_id
package_key
period_start
period_end
currency
processed_volume_cents
application_fee_cents
```

Only successful captured transactions count toward processed volume.

Do not count:

```txt
failed authorizations
voided authorizations
uncaptured payment intents
refunded transactions unless refund handling is explicitly implemented
```

The allowance query must return remaining allowance before and after the current transaction.

---

## Fee Sharing / Allocation Rules

Application fee calculation happens before allocation.

Allocation must never increase the application fee charged.

Rules:

```txt
sum(allocations.amount_cents) must equal application_fee_cents
platform_retained_cents + shared_out_cents must equal application_fee_cents
expired sharing rules do not apply
capped sharing rules stop after cap is reached
```

If no sharing rule applies, 100% of the application fee is retained by platform.

---

## Immutable Capture Snapshot

Every successful capture must snapshot the resolved commercial terms on the transaction/capture artifact.

Required snapshot fields:

```txt
sd_fee_policy_source
sd_fee_policy_id
sd_application_fee_cents
sd_platform_retained_cents
sd_fee_shared_out_cents
sd_fee_allocation_json
sd_fee_allowance_json
sd_fee_explanation
```

Do not rely on current package/profile/tenant settings to explain historical captures. Commercial terms change over time.

---

## Stripe Doctrine

Stripe subscription price handles the package monthly charge.

Stripe application fee amount comes from the centralized commercial fee engine at transaction/capture time.

Pro's first `$500/mo` included processing is not a Stripe subscription coupon. It is a SoloDrive application-fee allowance.

Stripe-facing capture code should not contain commercial math. It should ask the fee engine for the application fee and pass the resolved amount to Stripe.

---

## Validation Rules

```txt
package_key must be unique
profile_key must be unique or intentionally reusable
package display price must be >= 0
fee percent must be >= 0
minimum fee must be >= 0
monthly included volume must be >= 0
Elite fee percent must be 0 if fee mode is none
Pro monthly included volume must be 50000 cents
term effective_end must be after effective_start when both are set
active tenant term must have a valid revert_policy
sharing rule percent must be >= 0 and <= 100
sharing cap must be >= 0
```

Warnings:

```txt
Package is public but has no Stripe price
Package is public but has no default profile
Profile is active but has no linked package
Profile fee mode is percentage but percent is empty
Pro package has no monthly allowance rule
Tenant override is active but expired
Tenant override expired with manual_review policy
Sharing rule is active but expired
Sharing rules allocate more than 100% of application fee
```

---

## Acceptance Criteria

### Fee policy

```txt
Basic on a $20 captured ride computes $1.30 SoloDrive fee.
Basic on a $10 captured ride computes $1.00 SoloDrive fee because of the minimum.
Pro on first $500 of monthly processed volume computes $0 SoloDrive application fee.
Pro after $500 monthly processed volume computes 6.5% on the billable amount above allowance.
Pro minimum $1 applies only when billable amount above allowance produces a fee below $1.
Elite always computes $0 SoloDrive application fee.
```

### Tenant override

```txt
Can set tenant-specific app fee override with start/end date.
Can set override to revert to profile default after expiration.
Can set override to revert to package default after expiration.
Can set override to require manual review after expiration.
Can calculate fee from active override instead of default profile.
Can ignore expired override and fall back according to revert policy.
```

### Fee sharing

```txt
Can create app-fee sharing rule by percent.
Can create app-fee sharing rule by flat amount.
Can create app-fee sharing rule with duration.
Can create app-fee sharing rule with cap.
Can return itemized allocation breakdown.
Can preserve platform retained amount and shared-out amount separately.
```

### Snapshot/audit

```txt
Can preserve original fee policy snapshot on transaction/capture record.
Can audit why a fee was calculated the way it was.
Can explain allowance usage before and after the transaction.
Can explain allocation/sharing rule application.
```

---

## Build Order

1. Add commercial fee engine doc and constants.
2. Add package public-display fields.
3. Add profile allowance fields.
4. Add `sd_commercial_calculate_application_fee()`.
5. Add commercial resolver for profile default vs tenant override.
6. Add monthly allowance query helper.
7. Add fee share allocation helper.
8. Snapshot resolved fee result on capture artifacts.
9. Wire Stripe capture to use the fee engine.
10. Update public package selector to use public-display fields.
11. Add admin warnings and validation.

