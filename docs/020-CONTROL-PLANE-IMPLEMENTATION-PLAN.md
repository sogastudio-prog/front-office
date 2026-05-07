# SoloDrive Control Plane — Implementation Plan (LOCKED)

Status: BUILD READY  
Depends on: `010-CONTROL-PLANE-ARCHITECTURE.md`

---

## Purpose

This document translates the locked control-plane onboarding doctrine into an implementation sequence.

It exists to prevent scope creep while building the Stripe-first onboarding path for prospects and tenants.

Canonical principle:

- `solodrive.pro` owns onboarding, billing, tenant promotion, and provisioning handoff
- runtime owns execution and operational lifecycle
- Stripe is the authoritative state middleware for control-plane readiness

---

## Implementation Goal

Produce a canonical onboarding transaction that safely progresses:

```txt
prospect intake
→ invitation/qualification
→ package selection
→ Stripe checkout / billing truth
→ provision package snapshot
→ runtime provisioning pull or signed handoff
→ runtime tenant materialization
→ runtime_tenant_id writeback
→ activation / delivered status
```

Rule:

> The frontend/control-plane build must create one canonical pipeline, not scattered behavior.

---

## Phase 1 — Invitation and Prospect Gate

Use the existing intake flow to create `sd_prospect`.

### Required behavior

- validate request-access form submission
- normalize invitation code
- dedupe by phone/email where appropriate
- create/update `sd_prospect`
- do not create `sd_tenant`

### Output

- valid `sd_prospect`
- lifecycle = `prospect` or `invited_prospect`

### Notes

- keep fast-lane invitation support
- replace hard-coded invitation validation with real lookup logic
- keep comments brief at validation seams so future invite systems remain obvious

---

## Phase 2 — Start Stripe Connect Account

Add a control-plane endpoint to begin Stripe onboarding.

### Recommended endpoint

```txt
POST /wp-json/sd/v1/onboarding/start
```

### Responsibilities

- receive prospect identifier
- verify prospect exists and is eligible
- create Stripe connected account
- persist Stripe account id on `sd_prospect`
- advance prospect to lead stage

### Required writes on success

- `sd_stripe_account_id`
- `sd_stripe_onboarding_started_at_gmt`
- `sd_stripe_onboarding_status = account_created`
- `sd_lifecycle_stage = lead`

### Response

Return:

- prospect id
- Stripe account id
- onboarding start data

### Rule

No tenant is created here.

---

## Phase 3 — Create Stripe Account Link

Use Stripe-hosted onboarding for MVP.

### Recommended endpoint

```txt
POST /wp-json/sd/v1/onboarding/account-link
```

### Responsibilities

- verify prospect has `sd_stripe_account_id`
- create Stripe Account Link
- return hosted onboarding URL

### Browser behavior

- browser redirects to Stripe-hosted onboarding URL

### Do not

- trust completion return alone
- create tenant here
- activate tenant here

---

## Phase 4 — Create Platform Subscription Checkout

After account onboarding has been started, create a platform-side checkout session.

### Recommended endpoint

```txt
POST /wp-json/sd/v1/billing/checkout
```

### Responsibilities

- verify prospect exists
- ensure connected account exists
- create or reuse platform-side Stripe Customer
- create Checkout Session for initial subscription payment
- store billing references on prospect

### Required writes

- `sd_stripe_customer_id`
- `sd_platform_subscription_price_id`
- `sd_last_checkout_session_id`
- `sd_billing_status = checkout_created`

### Recommended billing status enum

- `not_started`
- `checkout_created`
- `checkout_completed`
- `paid`
- `past_due`
- `canceled`
- `failed`

---

## Phase 5 — Control-Plane Webhook Receiver

Add a dedicated webhook endpoint inside the front-office plugin.

### Recommended endpoint

```txt
POST /wp-json/sd/v1/stripe/webhook
```

### Responsibilities

- verify Stripe signature
- parse event type
- map event to prospect and/or tenant
- update control-plane state only
- decide whether promotion/provisioning gates are now satisfied

### Minimum required events

- `account.updated`
- `checkout.session.completed`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

### Critical rules

- keep runtime webhook lane separate
- do not mix rider execution concerns into control-plane webhook handling
- use webhook truth, not redirect returns, for promotion gates

---

## Phase 6 — Provision Package Lock Service

Add a control-plane service that locks `sd_provision_package` only after package/Stripe gates pass.

### Responsibilities

- verify prospect/package is purchase-eligible
- verify Stripe billing truth
- lock package/commercial terms snapshot
- calculate payload version/hash
- attach Stripe identifiers
- mark package ready for runtime provisioning
- keep idempotent linkage back to `sd_prospect`

### Rule

This is the only canonical place where a purchased package becomes a locked runtime handoff artifact.

### Idempotency

Must be safe against:

- repeated webhooks
- repeated checkout confirmation paths
- repeated lock attempts
- duplicate Stripe callbacks

---

## Phase 7 — Runtime Provisioning Bridge

Add the practical bridge from control plane into runtime.

### Trigger

Fire or allow runtime pull after provision package gates pass.

### Recommended behavior

- generate deterministic request id
- sign payload with shared secret or HMAC
- expose/push locked provision package payload
- store request and response on `sd_provision_package`

### Expected runtime route

```txt
POST /wp-json/sd/v1/control-plane/provision-tenant
```

### Minimum payload

- provision package id
- package key
- reserved tenant slug
- prospect id
- prospect post id
- contact info
- Stripe customer/subscription ids
- billing status
- commercial terms snapshot
- activation mode

### Required write-back

- runtime_tenant_id
- runtime tenant slug
- runtime storefront path
- runtime operator path
- last provisioning request id
- last provisioning response
- provisioning status
- activation readiness boolean

### Note

The old listener doc belongs to the runtime side as a companion implementation note, not as a control-plane architecture document.

---

## Phase 8 — Activation State Finalization

After successful runtime provisioning:

### Responsibilities

- mark tenant activation-ready
- optionally mark storefront ready/live according to approval rules
- expose canonical storefront URL
- preserve failure/retry metadata when provisioning is incomplete

### Required rules

- provisioning success precedes active storefront status
- active tenant state is not granted on payment alone
- support/compliance blockers may hold activation even after billing/provisioning succeed

---

## Failure-State Handling

System must explicitly handle:

### 1. Redirect returned, webhook missing

- no promotion
- no activation
- await webhook truth

### 2. Stripe account created, billing incomplete

- remain in prospect/lead state
- do not promote to tenant

### 3. Billing paid, provisioning failed

- keep provision package in failed/retryable or failed/blocked state
- allow safe retry of provisioning handoff
- do not duplicate runtime tenant

### 4. Provisioning partial

- persist response details
- retry safely with same tenant identity
- do not create second tenant record

### 5. Repeated webhook events

- process idempotently
- do not duplicate promotion or provisioning

---

## Idempotency & Duplicate Protection

The full control-plane flow must be safe against:

- double Stripe callbacks
- repeated confirm clicks
- repeated billing endpoint hits
- duplicate runtime tenant creation
- repeated provisioning attempts
- slug collisions

### Canonical keys

Use stable identifiers such as:

- prospect id
- Stripe account id
- Stripe customer id
- Stripe checkout session id
- subscription id
- deterministic provisioning request id

---

## Build Notes for Code Comments

As this is implemented, keep brief comments at important seams so future plans remain obvious.

Comment targets:

- webhook truth boundaries
- promotion gate checks
- idempotency guards
- provisioning bridge seams
- legacy compatibility branches

Style target:

- explain why the stub/guard exists
- tie it to future control-plane/runtime separation
- avoid long prose comments

---

## Deferred / Quarantined Items

These are not part of the active canonical implementation plan:

- any architecture where tenant birth occurs from browser redirect alone
- local WordPress actions pretending to cross systems without HTTP bridge or signed runtime pull
- mixing control-plane Stripe onboarding with runtime ride-payment webhooks
- competing push and pull provisioning contracts without declaring one canonical v0 path

If retained in code temporarily, they should be marked as transitional and non-canonical.

---

## Delivery Checklist

### Control plane must deliver

- request-access prospect intake
- invitation validation
- Stripe Connect start endpoint
- Stripe Account Link endpoint
- subscription checkout endpoint
- dedicated control-plane webhook receiver
- provision package lock service
- runtime provisioning bridge or signed runtime-pull endpoint
- runtime_tenant_id writeback handling
- activation/delivered-state finalization

### Runtime must receive

- signed provisioning request
- canonical provision package payload
- dedupe-safe request id
- runtime_tenant_id writeback

---

## Bottom Line

The next-pass build should produce one canonical control-plane onboarding pipeline:

- prospect captured on `solodrive.pro`
- Stripe readiness and billing confirmed by webhook truth
- provision package locked in control plane
- runtime tenant materialized through signed cross-system handoff or pull
- runtime_tenant_id written back to front office
- delivered/active status granted only after provisioning success

This plan intentionally replaces split or contradictory older notes with one build-ready control-plane sequence.
---

## V0 Option B Implementation Doctrine

WordPress v0 does not require a separate authoritative control-plane `sd_tenant` before runtime provisioning.

Implementation target:

```txt
sd_prospect
→ package selection
→ sd_provision_package
→ Stripe billing truth
→ locked provision payload
→ runtime creates/materializes tenant
→ runtime_tenant_id written back to front office
```

Runtime tenant identity is canonical for operations. Front office remains commercial gatekeeper and CRM/support mirror.
---

## Provision Package Hardening

After purchase, the provision package becomes a locked handoff artifact.

Required fields:

```txt
sd_payload_version
sd_payload_hash
sd_payload_status
sd_payload_locked_at_gmt
sd_origin_prospect_id
sd_package_key
sd_invite_code
sd_custom_terms_snapshot_json
sd_stripe_customer_id
sd_stripe_subscription_id
sd_resolved_stripe_price_id
sd_runtime_tenant_id
sd_runtime_response_code
sd_runtime_response_message
sd_retry_count
```

Commercial changes after purchase require a new version, explicit admin override, or new package snapshot.
---

## Runtime Pull / Writeback Contract

The current preferred v0 contract may be runtime-pull:

```txt
runtime verifies checkout/session context
runtime calls front office for signed provision package
runtime materializes tenant
runtime writes back runtime_tenant_id and provisioning result
```

A future push model may be added, but v0 must not implement conflicting push and pull paths without declaring one canonical path.
---

## Updated Delivery Checklist Additions

Control plane must also deliver:

```txt
public package inventory states
invite-code/custom package support
package health validation before public display
locked provision package snapshot after billing
runtime_tenant_id writeback storage
front-office CRM/support views into runtime tenant status
```
