# SoloDrive Control Plane — Architecture (LOCKED)

Status: ACTIVE  
Purpose: Define the canonical frontend/control-plane architecture for prospect intake, Stripe onboarding, billing, tenant promotion, and provisioning handoff.

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
- tenant promotion decision
- provisioning handoff to runtime
- activation status tracking

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

A tenant is not a prospect.
A prospect is not a tenant.
A tenant is a Stripe-linked, promotion-approved, provisionable business entity.

### Canonical lifecycle

```txt
Anonymous
→ Prospect
→ Invited Prospect (optional fast lane)
→ Lead (Stripe started)
→ Tenant (Inactive)
→ Tenant (Active)
```

### Meaning

- **Anonymous** = public traffic only; no system record yet
- **Prospect** = validated onboarding interest captured by control plane
- **Invited Prospect** = prospect with priority-lane validation
- **Lead** = Stripe onboarding has started; still not a tenant
- **Tenant (Inactive)** = promoted business entity exists, but is not yet fully activated/live
- **Tenant (Active)** = provisioned and activation-approved

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

> Do not create `sd_tenant` until required Stripe and promotion gates are satisfied.

### `sd_tenant`

Purpose:

- actual tenant business entity
- control-plane tenant identity
- provisioning source for runtime handoff

This CPT covers:

- Tenant (Inactive)
- Tenant (Active)

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

### `sd_tenant`

#### Identity

- `sd_tenant_id`
- `sd_slug`
- `sd_domain`
- `sd_status`
- `sd_created_at`
- `sd_updated_at`

#### Relationship back to prospect

- `sd_origin_prospect_id`

#### Stripe

- `sd_connected_account_id`
- `sd_stripe_customer_id`
- `sd_stripe_subscription_id`
- `sd_charges_enabled`
- `sd_payouts_enabled`
- `sd_stripe_status_snapshot`

#### Activation / provisioning

- `sd_storefront_status`
- `sd_activation_ready`
- `sd_activation_status`
- `sd_activated_at`
- `sd_last_provisioning_response`

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

### Step 7 — Tenant promotion

Only after required Stripe gates are confirmed:

System:

- creates `sd_tenant`
- links prospect via `sd_origin_prospect_id`
- attaches Stripe identifiers
- marks tenant inactive pending provisioning completion

State:

- `inactive`

### Step 8 — Provisioning handoff

Control plane sends signed provisioning payload to runtime.

Runtime:

- provisions tenant runtime state
- configures storefront/runtime identity
- returns provisioning result

State:

- `provisioning`

### Step 9 — Activation

After successful provisioning:

System:

- marks tenant activation-ready
- activates tenant/storefront when approved

State:

- `active`

---

## Promotion & Activation Gates (LOCKED)

A tenant may only be promoted/activated when required gates are satisfied.

### Gate A — Stripe account ready

- connected account exists
- onboarding complete to required readiness threshold

### Gate B — Subscription paid

- required initial billing event confirmed

### Gate C — Provisioning complete

- runtime accepted provisioning request
- storefront/runtime path is returned as valid

### Gate D — Activation approved

- control-plane activation rules satisfied
- no blocking support/compliance flags

---

## Non-Negotiable Rules

1. No browser redirect creates or activates a tenant.
2. Redirects are UX only.
3. Webhooks are truth.
4. `sd_prospect` remains the record until Stripe/promotion gates pass.
5. `sd_tenant` is created only after required Stripe conditions are confirmed.
6. `sd_tenant` becomes active only after provisioning success.
7. Runtime never handles control-plane onboarding.
8. Control plane never handles ride execution.
9. Prospect and tenant are different objects and must not be conflated.
10. Email is not the source of truth.
11. Form submission must create/update records first; notifications are side effects.

---

## Provisioning Boundary (LOCKED)

The control plane does not directly execute runtime internals.

The practical bridge is:

1. control-plane workflow decides tenant is promotion-ready
2. control plane sends signed HTTP request to runtime
3. runtime provisions tenant-side resources
4. runtime responds with provisioning result
5. control plane updates tenant activation/provisioning state

Rule:

> Provisioning is a cross-system contract, not a local WordPress hook illusion.

---

## Identity & Ownership Rules

- control plane owns onboarding identity as `sd_prospect`
- control plane owns commercial tenant identity as `sd_tenant`
- runtime may create or bind runtime-side user/operator identity
- runtime does not decide commercial promotion from prospect to tenant
- promotion decision belongs to control plane

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

- `solodrive.pro` owns prospect onboarding, Stripe readiness, billing, tenant promotion, and provisioning handoff
- runtime owns tenant materialization consumption, storefront execution, and ride operations
- Stripe webhooks, not redirects, are the source of truth
- `sd_prospect` remains the pre-tenant object until required gates pass
- `sd_tenant` is the promoted business entity and activation source for runtime provisioning
