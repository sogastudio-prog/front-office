# Stripe Onboarding System — SoloDrive

Status: LOCKED  
Purpose: Define the onboarding architecture for tenant acquisition using Stripe as the middleware between the control plane and execution plane.

---

## 1. Core Principle

SoloDrive onboarding is a **control-plane responsibility**.

- `solodrive.pro` = acquisition, onboarding, billing, tenant creation
- runtime (`/t/{tenant_slug}` + backend app) = ride execution

These systems MUST remain isolated.

Stripe is used as the **middleware layer** that:
- validates payment readiness
- validates billing state
- signals activation eligibility

---

## 2. System Architecture

### Two Systems

#### Control Plane (solodrive.pro)
Handles:
- prospect lifecycle
- invitation validation
- Stripe onboarding (Connect)
- platform subscription (Billing)
- tenant creation
- provisioning handoff

#### Execution Plane (Runtime App)
Handles:
- ride lifecycle (lead → quote → auth → ride → capture)
- rider payments
- application fees
- operational execution

---

## 3. Stripe as Middleware

Stripe acts as the **authoritative state engine** between systems.

### Stripe Responsibilities

1. Connected Account (tenant identity)
   - KYC / compliance
   - payouts capability
   - payment processing capability

2. Subscription Billing (platform revenue)
   - monthly SaaS fee
   - payment method storage
   - invoice lifecycle

3. Event Signaling (webhooks)
   - onboarding status
   - billing status
   - payment success/failure

---

## 4. Two Stripe Event Lanes (LOCKED)

### 4.1 Runtime Stripe Lane (EXISTING)

Used for:
- rider payments
- authorization / capture
- trip-related financial events

Owned by:
- backend runtime app

---

### 4.2 Control Plane Stripe Lane (NEW)

Used for:
- connected account onboarding
- subscription checkout
- billing lifecycle
- tenant activation readiness

Owned by:
- solodrive.pro

---

### Critical Rule

There is **NO frontend webhook system**.

- Frontend → initiates Stripe flows only
- Stripe → redirects user (UX only)
- Stripe → sends webhooks to backend (SOURCE OF TRUTH)

---

## 5. Onboarding Flow (Canonical)

### Step 1 — Prospect Entry

User submits onboarding form.

System:
- create `sd_prospect`
- store invite code

State:
- `prospect`

---

### Step 2 — Invitation Validation

System validates invite.

State:
- `invited_prospect`

---

### Step 3 — Create Stripe Connected Account

System:
- create Stripe account
- store `sd_stripe_account_id`

State:
- `account_created`

---

### Step 4 — Stripe Hosted Onboarding

User completes Stripe onboarding.

Stripe collects:
- identity
- business details
- payout information

State:
- `onboarding_in_progress`

---

### Step 5 — Subscription Checkout

System creates Stripe Checkout session.

User completes:
- first month payment

State:
- `subscription_pending`

---

### Step 6 — Webhook Confirmation (CRITICAL)

System listens for:
- `account.updated`
- `checkout.session.completed`
- `invoice.paid`

State becomes:
- `subscription_paid`
- `account_ready`

---

### Step 7 — Tenant Creation

ONLY after webhook confirmation:

System:
- create `sd_tenant`
- assign slug
- attach Stripe IDs

State:
- `inactive`

---

### Step 8 — Backend Provisioning

Control plane sends payload to runtime system.

Runtime:
- installs tenant
- configures storefront
- binds user

State:
- `provisioning`

---

### Step 9 — Activation

After successful provisioning:

System:
- activate tenant
- expose storefront

State:
- `active`

---

## 6. Activation Gates (LOCKED)

A tenant may ONLY be activated if:

### Gate A — Stripe Account Ready
- connected account exists
- onboarding complete to required threshold

### Gate B — Subscription Paid
- first invoice successfully paid

### Gate C — Provisioning Complete
- backend install successful
- storefront resolves

---

## 7. Data Model (Minimum Required)

### Prospect
- `sd_prospect_id`
- `email`
- `phone`
- `invite_code`
- `sd_stripe_account_id`

### Tenant
- `sd_tenant_id`
- `sd_slug`
- `sd_stripe_account_id`
- `sd_stripe_customer_id`
- `sd_stripe_subscription_id`
- `status`

---

## 8. Webhook Doctrine (LOCKED)

### Source of Truth Rule

**Redirects are UX. Webhooks are truth.**

System MUST NOT:
- activate tenants on redirect
- trust client-side state

System MUST:
- wait for webhook confirmation

---

### Required Events

- `account.updated`
- `checkout.session.completed`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

---

## 9. Provisioning Contract

Payload sent to runtime:

```json
{
  "tenant_slug": "example",
  "stripe_account_id": "acct_...",
  "stripe_customer_id": "cus_...",
  "stripe_subscription_id": "sub_...",
  "billing_status": "paid"
}
```

---

## 10. Hard Rules (LOCKED)

1. Control plane never executes rides
2. Runtime never handles onboarding
3. Stripe is the state authority for onboarding + billing
4. No webhook-confirmed Stripe state = no tenant activation
5. Tenant is created only after Stripe gates pass
6. Activation requires provisioning success

---

## 11. Strategic Trade-off (ACKNOWLEDGED)

We are intentionally:
- centralizing onboarding + billing in Stripe
- reducing custom infrastructure
- prioritizing speed to market

Trade-off:
- increased dependency on Stripe

Decision:
- ACCEPTED

---

## 12. Summary

**Invite → Stripe onboarding → Stripe billing → webhook confirmation → tenant creation → provisioning → activation**

Stripe is the middleware.

SoloDrive remains the control layer.

Runtime remains the execution engine.

This separation is mandatory and permanent.

---

END OF DOCUMENT

