# SoloDrive — Activation Pipeline (LOCKED)

## Purpose

Define the canonical pipeline that converts a prospect into a live, operational tenant.

This document establishes:
- entity definitions
- lifecycle stages
- cross-system contract
- activation rules

This is the **single source of truth** for tenant activation behavior.

---

## System Boundary (LOCKED)

Two systems, two responsibilities:

### Control Plane (solodrive.pro)
- captures prospects
- prepares commercial offer
- stages provisioning input
- initiates Stripe Checkout

### Runtime / Execution Plane (app.solodrive.pro)
- verifies payment
- provisions tenant
- creates operator identity
- runs operational system

---

## Entities (LOCKED)

### 1. Prospect

Represents an interested business prior to purchase.

- created via frontend intake (CF7)
- contains contact + qualification data
- not authenticated
- not operational

---

### 2. Provision Package (`sd_provision_package`)

Represents a staged, purchase-bound configuration for tenant creation.

**This is NOT a tenant.**

Characteristics:
- created on control plane
- bound to a prospect
- contains:
  - reserved slug
  - pricing snapshot
  - configuration inputs
- tied to a Stripe Checkout session
- used as provisioning input only

Rules:
- not operational
- not used by runtime
- not mutated into a tenant
- consumed during provisioning
- may be archived after activation

---

### 3. Tenant (`sd_tenant`)

Represents a live, operational business entity.

Characteristics:
- created only inside SDPRO (runtime plane)
- owns storefront + runtime configuration
- bound to operator user(s)
- required for all execution flows

Rules:
- canonical runtime entity
- created exactly once
- never created on control plane

---

## Lifecycle (LOCKED)
Prospect
→ Provision Package (staged)
→ Checkout Session (created)
→ Payment Completed (Stripe)
→ SDPRO Onboarding Intercept
→ Tenant Provisioned
→ Operator Logged In
→ Stripe Connect Onboarding
→ Tenant Operational


---

## Canonical Activation Transaction (LOCKED)

Activation occurs entirely inside SDPRO after redirect from Stripe Checkout.

### Required conditions:
- valid HMAC signature
- valid Stripe Checkout session
- `payment_status = paid`

### SDPRO onboarding flow:

1. Intercept request (`template_redirect`, priority 1)
2. Verify HMAC signature
3. Retrieve and verify Stripe session
4. Confirm billing back to control plane
5. Fetch provision package (`get-prospect-package`)
6. Execute provisioning:
   - create `sd_tenant`
   - create/bind operator (WP user)
7. Establish session (`wp_set_auth_cookie`)
8. Initiate Stripe Connect onboarding (if required)

---

## Cross-System Contract (LOCKED)

### Control Plane → SDPRO

Provision Package must provide:

- `prospect_id`
- `provision_package_id`
- `reserved_slug`
- pricing snapshot
- `checkout_session_id`

### SDPRO establishes:

- `runtime_tenant_id`
- `owner_user_id`
- `provisioned_at`
- Stripe identifiers (customer / subscription where applicable)

---

## Identity & Ownership Rules (LOCKED)

- Control plane does **not** create runtime users
- SDPRO exclusively creates or binds operator identity
- Tenant is created **only once**
- Tenant identity exists only in runtime

---

## Slug & Storefront Rules (LOCKED)

- slug is reserved in provision package
- SDPRO enforces slug at provisioning
- slug must be globally unique
- storefront is owned by runtime tenant

---

## Idempotency & Safety (LOCKED)

Provisioning must be safe against:

- repeated Stripe redirects
- duplicate webhook events
- repeated onboarding hits
- partial failures

### Required behavior:

- tenant creation must be idempotent
- provisioning must not duplicate:
  - tenants
  - users
  - billing records

Checkout session is the primary activation key.

---

## Failure Handling (LOCKED)

System must handle:

### 1. Payment verified, provisioning fails
- allow safe retry via same onboarding URL
- do not create duplicate tenant

### 2. Provisioning partial (tenant exists, user missing)
- resume and complete binding

### 3. Connect onboarding incomplete
- allow re-entry via refresh URL
- do not block operator shell access

---

## Critical Rules (LOCKED)

- Provision Package is **not a tenant**
- Tenant is **not created on the front**
- Tenant is **born exactly once in SDPRO**
- Browser redirects are **not trusted alone**
- Stripe verification is **mandatory**
- Provision Package is **consumed, not evolved**

---

## Mental Model (LOCKED)


Prospect = interest
Provision Package = intent + configuration
Tenant = operational business


---

## Final Doctrine

You are not creating tenants during onboarding.

You are:

**capturing intent → packaging it → selling it → materializing it into a live business**

This pipeline must remain clean, singular, and idempotent.