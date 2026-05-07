# SoloDrive Control Plane — Architecture (LOCKED)

Status: ACTIVE  
Purpose: Define the canonical frontend/control-plane architecture for prospect intake, Stripe onboarding, billing, provision package sales, runtime tenant materialization, and provisioning writeback.

---

## Document Intent

This document replaces split doctrine across older front-office onboarding notes.

It establishes:

- what the control plane owns
- what the runtime owns
- when a prospect becomes a tenant
- Stripe's role in the control-plane lifecycle
- the handoff boundary into SDPRO/runtime

Rule:

> The control plane owns onboarding and commercial readiness. The runtime owns execution and operational tenancy.

---

## System Boundary (LOCKED)

### Control plane

Canonical host:

```txt
solodrive.pro
```

Owns:

- prospect intake
- invitation / qualification workflow
- Stripe Connect onboarding start
- platform subscription checkout
- control-plane Stripe webhooks
- package inventory and invite-code package sales
- provision package creation
- runtime provisioning handoff / pull contract
- activation and runtime tenant writeback tracking

Does not own:

- rider storefront execution
- quote / auth / ride / capture lifecycle
- operator runtime identity creation inside execution flow
- rider payment flow

### Execution plane

Canonical runtime host:

```txt
app.solodrive.pro
```

Owns:

- runtime tenant materialization
- canonical operational `runtime_tenant_id`
- storefront execution
- quote / auth / ride / capture lifecycle
- operator operational surfaces
- rider transaction execution
- tokenized trip surfaces

Does not own:

- prospect acquisition
- control-plane subscription billing
- Stripe Connect onboarding orchestration for acquisition
- tenant commercial promotion rules

---

## Core Lifecycle Model (LOCKED)

WordPress v0 uses Option B:

```txt
front office sells provision packages
Stripe gates customer/subscription truth
runtime creates/materializes the operational tenant
runtime_tenant_id is canonical for operations
runtime_tenant_id is written back to the front office for CRM/views
```

The front office is the commercial gatekeeper. The runtime is the operational source of truth.

### Canonical lifecycle

```txt
Anonymous
→ Prospect
→ Invited Prospect (optional fast lane)
→ Package Selected
→ Checkout / Billing Pending
→ Provision Package Purchased
→ Runtime Provisioning
→ Runtime Tenant Materialized
→ Active / Delivered
```

### Meaning

- **Anonymous** = public traffic only; no system record yet
- **Prospect** = validated onboarding interest captured by control plane
- **Invited Prospect** = prospect with priority-lane validation or custom package access
- **Package Selected** = public or invite-code package chosen
- **Checkout / Billing Pending** = Stripe checkout/customer/subscription path is in progress
- **Provision Package Purchased** = billing truth confirmed and provision package locked
- **Runtime Provisioning** = runtime pulls or receives signed provision package
- **Runtime Tenant Materialized** = runtime created the canonical operational tenant
- **Active / Delivered** = front office has runtime_tenant_id and CRM/support views into operations

---

## Object Model (LOCKED)

### `sd_prospect`

Purpose:

- pre-tenant onboarding record
- qualification record
- Stripe onboarding and billing staging record

This CPT covers:

- Prospect
- Invited Prospect
- Lead

Rule:

> Do not treat a prospect as an operational tenant. Operational tenancy is materialized by runtime after Stripe/package gates and provision package handoff.

### `sd_provision_package`

Purpose:

- locked/versioned commercial handoff artifact
- purchased package snapshot
- runtime provisioning payload source
- bridge from Stripe/front-office sale to runtime materialization

This CPT covers:

- checkout pending package
- purchased provision package
- runtime provisioning status
- delivered/runtime-linked package

### Runtime tenant mirror

Purpose:

- front-office CRM/support reference to the operational tenant
- stores `runtime_tenant_id`, runtime URL/path, activation status, billing status, and health/status snapshots

Rule:

> The same tenant exists commercially in front office and operationally in runtime. Stripe/runtime is the source of truth for operations; front office mirrors runtime_tenant_id for CRM and MRR support.

### Anonymous

No CPT record.
Anonymous exists only as public traffic until validated intake submission.

---

## Minimum Canonical Data Model

### `sd_prospect`

#### Identity

- `sd_prospect_id`
- `sd_lifecycle_stage`
- `sd_source`
- `sd_created_at`
- `sd_updated_at`

#### Intake

- `sd_full_name`
- `sd_phone`
- `sd_email`
- `sd_city`
- `sd_market`
- `sd_current_platform`

#### Invitation

- `sd_invitation_code`
- `sd_invitation_status`
- `sd_invited_by`
- `sd_priority_lane`

#### Staff workflow

- `sd_review_status`
- `sd_staff_notes`
- `sd_last_staff_action_at`
- `sd_owner_user_id`

#### Stripe onboarding / billing

- `sd_stripe_onboarding_started_at`
- `sd_stripe_account_id`
- `sd_stripe_onboarding_status`
- `sd_stripe_customer_id`
- `sd_last_checkout_session_id`
- `sd_platform_subscription_price_id`
- `sd_billing_status`
- `sd_promoted_to_tenant_id`

### `sd_provision_package`

#### Identity

- `sd_provision_package_id`
- `sd_origin_prospect_id`
- `sd_package_key`
- `sd_package_status`
- `sd_reserved_slug`
- `sd_created_at`
- `sd_updated_at`

#### Stripe / billing

- `sd_stripe_customer_id`
- `sd_stripe_subscription_id`
- `sd_resolved_stripe_price_id`
- `sd_billing_status`
- `sd_billing_truth_source`
- `sd_billing_truth_event_id`

#### Snapshot / payload

- `sd_payload_version`
- `sd_payload_hash`
- `sd_payload_locked_at_gmt`
- `sd_commercial_terms_snapshot_json`
- `sd_invitation_code`
- `sd_custom_terms_snapshot_json`

#### Runtime writeback

- `sd_runtime_tenant_id`
- `sd_runtime_tenant_slug`
- `sd_runtime_storefront_url`
- `sd_runtime_operator_url`
- `sd_runtime_provisioning_status`
- `sd_runtime_health_status`
- `sd_last_runtime_response`

---

## Stripe Doctrine (LOCKED)

Stripe is the authoritative state middleware for control-plane onboarding and billing.

### Two Stripe lanes

#### Control-plane lane

Owns:

- connected account onboarding
- platform subscription checkout
- onboarding readiness
- billing lifecycle for tenant acquisition

Owned by:

- `solodrive.pro`

#### Runtime lane

Owns:

- rider/tenant execution payments
- execution-plane webhooks
- ride-related payment state

Owned by:

- runtime / SDPRO

### Critical rule

There is no browser-trust onboarding system.

- frontend redirects are UX only
- Stripe webhooks are truth
- no redirect alone may create or activate a tenant

---

## Canonical Onboarding Flow (LOCKED)

### Step 1 — Prospect entry

User submits onboarding form.

System:

- create `sd_prospect`
- store invite code and intake details

State:

- `prospect`

### Step 2 — Invitation validation

System validates invite or staff qualifies the record.

State:

- `invited_prospect` or retained `prospect`

### Step 3 — Start Stripe Connect account

System:

- creates Stripe connected account
- stores `sd_stripe_account_id`
- marks onboarding started

State:

- `lead`
- `account_created`

### Step 4 — Stripe hosted onboarding

User completes Stripe-hosted onboarding.

Stripe collects:

- identity
- business details
- payout details

State:

- onboarding in progress until confirmed by webhook

### Step 5 — Platform subscription checkout

System creates Stripe Checkout session for platform billing.

User completes:

- initial subscription payment

State:

- billing in progress until confirmed by webhook

### Step 6 — Webhook confirmation

System receives and validates control-plane Stripe events.

Typical truth events:

- `account.updated`
- `checkout.session.completed`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

State becomes eligible for promotion only when required gates pass.

### Step 7 — Provision package lock

Only after required Stripe/package gates are confirmed:

System:

- locks the `sd_provision_package`
- snapshots package/commercial terms
- records billing truth source/event
- marks package ready for runtime provisioning

State:

- `READY_FOR_PROVISIONING`

### Step 8 — Runtime provisioning handoff / pull

Runtime receives or pulls the signed provision package.

Runtime:

- creates/materializes canonical operational tenant
- configures storefront/runtime identity
- returns provisioning result and `runtime_tenant_id`

State:

- `PROVISIONING_REQUESTED` / `PROVISIONED`

### Step 9 — Activation / delivered status

After successful runtime materialization:

System:

- stores `runtime_tenant_id`
- marks delivered/activation-ready
- exposes CRM/support views into runtime status

State:

- `DELIVERED`

---

## Promotion & Activation Gates (LOCKED)

A provision package may only be locked/provisioned and a runtime tenant may only be activated when required gates are satisfied.

### Gate A — Stripe account ready

- connected account exists
- onboarding complete to required readiness threshold

### Gate B — Subscription paid

- required initial billing event confirmed

### Gate C — Runtime materialization complete

- runtime accepted provisioning request or pull
- `runtime_tenant_id` is returned
- storefront/runtime path is returned as valid

### Gate D — Activation approved

- control-plane activation rules satisfied
- no blocking support/compliance flags

---

## Non-Negotiable Rules

1. No browser redirect creates or activates a tenant.
2. Redirects are UX only.
3. Webhooks are truth.
4. `sd_prospect` remains the intake/commercial lead record until Stripe/package gates pass.
5. `sd_provision_package` becomes the locked handoff artifact after purchase.
6. Runtime creates/materializes the canonical operational tenant and writes back `runtime_tenant_id`.
7. Runtime never handles front-office package sales or control-plane billing decisions.
8. Control plane never handles ride execution.
9. Prospect, provision package, and runtime tenant are different objects and must not be conflated.
10. Email is not the source of truth.
11. Form submission must create/update records first; notifications are side effects.

---

## Provisioning Boundary (LOCKED)

The control plane does not directly execute runtime internals.

The practical bridge is:

1. control-plane workflow decides provision package is purchased/ready
2. runtime pulls or receives signed provision package payload
3. runtime creates/materializes tenant-side resources
4. runtime responds with provisioning result and `runtime_tenant_id`
5. control plane updates provision/delivered status and CRM mirror fields

Rule:

> Provisioning is a cross-system contract, not a local WordPress hook illusion.

---

## Identity & Ownership Rules

- control plane owns onboarding identity as `sd_prospect`
- control plane owns package inventory and purchased `sd_provision_package`
- runtime owns canonical operational tenant identity as `runtime_tenant_id`
- front office mirrors `runtime_tenant_id` for CRM/support/MRR visibility
- runtime may create or bind runtime-side user/operator identity
- package purchase/provision readiness belongs to front office/Stripe
- operational tenant materialization belongs to runtime

---

## Slug & Storefront Rules

- tenant slug must be canonical and stable
- slug collision prevention belongs to control plane before runtime handoff
- runtime enforces final storefront materialization using canonical slug
- storefront readiness is not assumed until provisioning response confirms it

---

## Relationship to Runtime Docs

This document governs frontend/control-plane onboarding only.

It should be read alongside runtime docs that govern:

- storefront execution
- lead/quote/auth/ride/capture lifecycle
- runtime tenant operations
- tokenized public trip surfaces

Rule:

> Control-plane onboarding and runtime ride execution are two different funnels and must not be mixed.

---

## Bottom Line

The locked control-plane architecture is:

- `solodrive.pro` owns prospect onboarding, package inventory, Stripe billing, provision packages, and CRM/support mirrors
- runtime owns operational tenant materialization, storefront execution, and ride operations
- Stripe webhooks, not redirects, are the source of truth for purchase/billing gates
- `sd_prospect` remains the intake/commercial lead object
- `sd_provision_package` is the locked commercial handoff artifact
- `runtime_tenant_id` is the canonical operational tenant identity and is written back to front office
---

## Package Inventory vs Delivered Package Doctrine

The front office must distinguish what is for sale from what was delivered.

Inventory package states:

```txt
DRAFT
ACTIVE_PUBLIC
ACTIVE_INVITE_ONLY
HIDDEN
ARCHIVED
```

Delivered/provision package states:

```txt
DRAFT
CHECKOUT_PENDING
PURCHASED
READY_FOR_PROVISIONING
PROVISIONING_REQUESTED
PROVISIONED
DELIVERED
FAILED_RETRYABLE
FAILED_BLOCKED
CANCELLED
```

Public packages, invite-code packages, and customized packages all resolve into a locked provision package snapshot after purchase.
---

## Runtime Tenant ID Writeback

After runtime materializes the tenant, runtime must write back:

```txt
runtime_tenant_id
runtime_tenant_slug
runtime_storefront_url
runtime_operator_url
runtime_provisioning_status
runtime_health_status
runtime_last_seen_at
```

Front office uses these fields for CRM, support, billing/MRR views, and tenant lifecycle status. Runtime remains authoritative for operational ride execution.
---

## V0 Control-Plane Clarification

Earlier architecture notes may describe a promoted control-plane `sd_tenant` as the tenant birth point. For WordPress v0, the locked doctrine is Option B:

```txt
sd_prospect
→ sd_provision_package
→ runtime tenant materialization
→ runtime_tenant_id writeback
```

A front-office tenant/account mirror may exist for CRM, but it is not the operational tenant authority.
