# Stripe Control-Plane Implementation Plan

Status: BUILD READY  
Depends on: `Front-Office-Implementation-Spec.md`, `SoloDrive Platform Model`, `Stripe Onboarding System`

---

## 1. Purpose

This document translates the locked onboarding doctrine into an implementation sequence for the SoloDrive control plane.

It exists to prevent scope creep while building the Stripe-first onboarding path for tenants.

Canonical principle:

- `solodrive.pro` owns onboarding, billing, tenant creation, and provisioning handoff
- runtime owns ride execution and rider transaction flow
- Stripe is the authoritative state middleware for control-plane onboarding and platform billing

---

## 2. Locked Architecture

### 2.1 Two systems

#### Control plane
- WordPress front-office plugin on `solodrive.pro`
- owns `sd_prospect`
- owns `sd_tenant`
- starts Stripe Connect onboarding
- starts Stripe subscription checkout
- receives control-plane Stripe webhooks
- decides whether tenant can be created and/or activated
- sends provisioning payload to SDPRO

#### Execution plane
- SDPRO runtime app
- owns storefront execution
- owns rider payment flow
- owns quote/auth/ride/capture lifecycle
- receives provisioning request from control plane

### 2.2 Two Stripe lanes

#### Existing runtime lane
- tenant/rider transactions
- execution-plane webhooks

#### New control-plane lane
- connected account onboarding
- platform subscription
- activation readiness
- control-plane webhooks

---

## 3. Non-Negotiable Rules

1. No browser redirect creates or activates a tenant.
2. Redirects are UX only.
3. Webhooks are truth.
4. `sd_prospect` remains the record until Stripe gates pass.
5. `sd_tenant` is created only after required Stripe conditions are confirmed.
6. `sd_tenant` becomes active only after provisioning success.
7. Runtime never handles onboarding.
8. Control plane never handles ride execution.

---

## 4. Build Sequence

### Phase 1 — Invitation and Prospect Gate

Use the existing CF7 + `sd_prospect` intake path.

Current plugin already supports:
- `prospect`
- `invited_prospect`
- `lead`
- invitation code normalization
- prospect dedupe by email/phone

Required refinement:
- lock invitation validation behind a real invitation lookup instead of hard-coded values
- do not create `sd_tenant` here

Output of Phase 1:
- valid `sd_prospect`
- lifecycle is `prospect` or `invited_prospect`

---

### Phase 2 — Start Stripe Connect Account

Add a control-plane endpoint that:
- receives a prospect identifier
- verifies prospect exists and is eligible
- creates Stripe connected account
- stores Stripe account id on `sd_prospect`
- advances prospect to `lead`

Recommended endpoint:
- `POST /wp-json/sd/v1/onboarding/start`

Required write-back on success:
- `sd_stripe_account_id`
- `sd_stripe_onboarding_started_at_gmt`
- `sd_stripe_onboarding_status = account_created`
- `sd_lifecycle_stage = lead`

Response should include:
- onboarding url or account link start data
- prospect id
- stripe account id

---

### Phase 3 — Redirect to Stripe Hosted Onboarding

Use Stripe-hosted onboarding for MVP.

Recommended endpoint:
- `POST /wp-json/sd/v1/onboarding/account-link`

Responsibilities:
- verify prospect has `sd_stripe_account_id`
- create Stripe Account Link
- return hosted onboarding URL

Browser behavior:
- frontend redirects user to Stripe-hosted onboarding

Do not:
- trust completion return alone
- create tenant here

---

### Phase 4 — Platform Subscription Checkout

After account onboarding has been started, create a Stripe Checkout session for the platform subscription.

Recommended endpoint:
- `POST /wp-json/sd/v1/billing/checkout`

Responsibilities:
- verify prospect exists
- ensure a Stripe connected account exists
- create or reuse platform-side Stripe Customer
- create Checkout Session for first-month subscription payment
- store billing references on prospect

Add prospect meta:
- `sd_stripe_customer_id`
- `sd_platform_subscription_price_id`
- `sd_last_checkout_session_id`
- `sd_billing_status`

Recommended `sd_billing_status` enum:
- `not_started`
- `checkout_created`
- `checkout_completed`
- `paid`
- `past_due`
- `canceled`
- `failed`

---

### Phase 5 — Control-Plane Webhook Receiver

Add a dedicated webhook endpoint inside the front-office plugin.

Recommended endpoint:
- `POST /wp-json/sd/v1/stripe/webhook`

Responsibilities:
- verify Stripe signature
- parse event type
- map event to prospect and/or tenant
- update control-plane state only
- enqueue tenant promotion/provisioning decision when gates pass

Minimum required events:
- `account.updated`
- `checkout.session.completed`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

Important:
- keep runtime webhook lane separate
- do not combine both business concerns into one endpoint unless event routing is explicit and stable

---

### Phase 6 — Tenant Promotion Service

Build promotion as a server-side service function, not as a browser action.

Recommended internal function:
- `sd_promote_prospect_to_tenant( int $prospect_post_id ): int|WP_Error`

Promotion gate:
- connected account exists
- account is ready enough for launch policy
- first invoice is paid
- prospect has not already been promoted

Actions:
- create `sd_tenant`
- set `sd_status = inactive`
- set `sd_storefront_status = not_live`
- copy Stripe references
- set relationship back to prospect
- mark prospect promoted

Required additional tenant meta:
- `sd_stripe_customer_id`
- `sd_stripe_subscription_id`
- `sd_billing_status`
- `sd_provisioning_status`

Recommended `sd_provisioning_status` enum:
- `not_started`
- `queued`
- `sent`
- `succeeded`
- `failed`

---

### Phase 7 — Provisioning Handoff to SDPRO

Once tenant exists as inactive, send provisioning payload to SDPRO.

Recommended control-plane endpoint/service:
- internal service function or queued action
- optional admin retry endpoint: `POST /wp-json/sd/v1/tenants/{id}/provision`

Provisioning payload:

```json
{
  "tenant_id": "tnt_...",
  "tenant_slug": "example",
  "origin_prospect_id": "prs_...",
  "stripe_account_id": "acct_...",
  "stripe_customer_id": "cus_...",
  "stripe_subscription_id": "sub_...",
  "billing_status": "paid",
  "activation_mode": "inactive_until_provisioned"
}
```

Expected SDPRO response:
- tenant runtime created
- storefront configured
- admin binding complete if applicable
- healthcheck result

---

### Phase 8 — Activation Gate

Activation occurs only after:
- account readiness confirmed
- first month paid
- provisioning succeeded
- storefront resolves

Activation action:
- `sd_status = active`
- `sd_storefront_status = live`
- `sd_activation_ready = 1`
- `sd_activated_at_gmt = now`

---

## 5. WordPress Plugin Additions

### 5.1 New prospect meta to add
- `sd_stripe_customer_id`
- `sd_last_checkout_session_id`
- `sd_platform_subscription_price_id`
- `sd_billing_status`
- `sd_subscription_paid_at_gmt`
- `sd_stripe_account_snapshot_json`
- `sd_last_stripe_event_id`
- `sd_last_stripe_event_type`
- `sd_last_stripe_event_at_gmt`

### 5.2 New tenant meta to add
- `sd_stripe_customer_id`
- `sd_stripe_subscription_id`
- `sd_billing_status`
- `sd_subscription_paid_at_gmt`
- `sd_provisioning_status`
- `sd_provisioning_attempts`
- `sd_provisioned_at_gmt`
- `sd_runtime_install_ref`
- `sd_runtime_base_url`

### 5.3 Recommended utility services
- prospect lookup service
- invitation validation service
- stripe control-plane client
- webhook event router
- tenant promotion service
- provisioning client for SDPRO
- slug generator / uniqueness checker

---

## 6. REST Endpoint Map

### Public / browser-started
- `POST /wp-json/sd/v1/onboarding/start`
- `POST /wp-json/sd/v1/onboarding/account-link`
- `POST /wp-json/sd/v1/billing/checkout`

### Stripe inbound
- `POST /wp-json/sd/v1/stripe/webhook`

### Internal/admin
- `POST /wp-json/sd/v1/tenants/{id}/provision`
- `POST /wp-json/sd/v1/tenants/{id}/activate`
- `POST /wp-json/sd/v1/prospects/{id}/promote`

For MVP, promotion and activation should happen automatically via service functions triggered by webhook-confirmed gates, not by manual UI clicks.

---

## 7. State Mapping

### Prospect lifecycle
- `prospect`
- `invited_prospect`
- `lead`

### Stripe onboarding status
- `not_started`
- `started`
- `account_created`
- `requirements_due`
- `charges_enabled`
- `failed`

### Billing status
- `not_started`
- `checkout_created`
- `checkout_completed`
- `paid`
- `past_due`
- `canceled`
- `failed`

### Tenant status
- `inactive`
- `active`

### Provisioning status
- `not_started`
- `queued`
- `sent`
- `succeeded`
- `failed`

---

## 8. Webhook Event Handling Matrix

### `account.updated`
Use to update:
- `sd_stripe_onboarding_status`
- connected-account readiness snapshot
- charges/payout flags if mirrored later to tenant

Behavior:
- if account not ready, keep prospect as `lead`
- if account ready and billing already paid, evaluate promotion

### `checkout.session.completed`
Use to update:
- `sd_billing_status = checkout_completed`
- save checkout session id

Behavior:
- informational only
- do not activate tenant from this event alone

### `invoice.paid`
Use to update:
- `sd_billing_status = paid`
- `sd_subscription_paid_at_gmt`
- `sd_stripe_subscription_id`

Behavior:
- if account readiness gate already passed, evaluate promotion

### `invoice.payment_failed`
Use to update:
- `sd_billing_status = failed` or `past_due`

Behavior:
- no promotion
- if tenant already exists, flag billing risk

### `customer.subscription.updated`
Use to update:
- recurring subscription state

### `customer.subscription.deleted`
Use to update:
- billing status canceled
- downstream tenant status review workflow

---

## 9. Sequence Diagram

```text
Visitor submits request access form
  -> create/update sd_prospect
  -> invite validated
  -> prospect / invited_prospect

User starts onboarding
  -> control plane creates connected account
  -> sd_prospect becomes lead
  -> browser redirected to Stripe hosted onboarding

User returns from Stripe
  -> no tenant created yet

Control plane creates subscription checkout
  -> browser redirected to Stripe Checkout

Stripe sends webhook events
  -> account.updated
  -> checkout.session.completed
  -> invoice.paid

Control plane evaluates gates
  -> create inactive sd_tenant
  -> send provisioning payload to SDPRO

SDPRO provisions tenant
  -> control plane records success
  -> tenant activated
```

---

## 10. Scope Guardrails

This build does NOT include yet:
- custom embedded onboarding UI
- advanced retry orchestration
- multi-seat tenant staff setup
- tax automation decisions
- CRM automation beyond prospect records
- custom billing portal UX
- subdomain-based tenant launch
- runtime refactor of existing rider transaction Stripe lane

These may come later, but are explicitly out of scope for the first build.

---

## 11. Definition of Done for MVP

MVP is complete when all of the following are true:

1. request-access form creates/updates `sd_prospect`
2. valid invitation can move prospect into fast lane
3. control plane can create Stripe connected account from prospect
4. control plane can send prospect to Stripe hosted onboarding
5. control plane can create subscription checkout session
6. control-plane webhook endpoint receives and verifies Stripe events
7. webhook-confirmed readiness can promote prospect to inactive tenant
8. control plane can send provisioning payload to SDPRO
9. successful provisioning can activate tenant
10. no browser redirect alone can create or activate a tenant

---

## 12. Recommended Immediate Build Order

1. extend plugin meta schema
2. add Stripe control-plane configuration constants/settings
3. add onboarding start endpoint
4. add account-link endpoint
5. add billing checkout endpoint
6. add webhook endpoint with signature verification
7. add tenant promotion service
8. add SDPRO provisioning client
9. add activation service
10. add admin observability columns/flags for billing + provisioning

---

End of Document
