# CTO Alignment — Front Office Roadmap to Live (LOCKED)

Status: ACTIVE  
Purpose: Capture the front-office launch doctrine for package sales, provisioning, runtime tenant writeback, and marketing-safe product scope.

---

## Product Promise

Marketing may promise:

```txt
direct booking page
automated quote builder for operator review
Stripe-powered payment flow
professional trip surface
operator-managed fulfillment
single owner/operator launch path
```

Marketing must not promise:

```txt
fully automated dispatch
automatic quote approval
autonomous reservations
fleet dispatch
multi-driver scheduling
fully autonomous reauthorization/capture decisions
```

---

## Front Office / Runtime Doctrine

WordPress v0 uses Option B:

```txt
solodrive.pro front office sells provision packages
Stripe gates customer/subscription truth
runtime creates/materializes the operational tenant
runtime_tenant_id is canonical for operations
runtime_tenant_id is written back to front office
front office uses writeback for CRM, MRR, support, and views into runtime state
```

The front office is commercial gatekeeper. Runtime is operational source of truth.

---

## Package Inventory vs Delivered Package

Inventory package:

```txt
what is displayed or made available for purchase
public package, invite-code package, or custom package
```

Delivered package:

```txt
what was purchased, snapshotted, provisioned, and linked to runtime_tenant_id
```

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

---

## Provision Package Doctrine

After purchase, `sd_provision_package` is a locked handoff artifact.

Required snapshot ideas:

```txt
package_key
display_name
stripe_price_id
billing_interval
included_features
application_fee_policy
invite_code
custom_terms
payload_version
payload_hash
runtime_tenant_id
```

Runtime writes back:

```txt
runtime_tenant_id
runtime_tenant_slug
runtime_storefront_url
runtime_operator_url
runtime_provisioning_status
runtime_health_status
runtime_last_seen_at
```

---

## Runtime Product Scope Reflection

The front-office product copy must reflect runtime v1 truth:

```txt
all quotes require operator approval
reservations are requestable but not automatically confirmed
operator time is not committed without operator approval
offline storefront still captures demand but apologizes that the driver is unavailable
reservations more than seven days out require scheduled authorization inside the valid window
```
