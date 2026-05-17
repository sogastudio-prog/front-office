# SoloDrive — Claude Context

## What This Is
SoloDrive is a WordPress-based ride-booking platform.
Two plugins. Two planes. One canonical lifecycle.

---

## Two Codebases

| Repo | Plugin | Plane | Host |
|---|---|---|---|
| sdpro-fresh | solodrive-kernel | Runtime / Execution | app.solodrive.pro |
| sdpro-front | front-office | Control / Acquisition | solodrive.pro |

---

## Canonical Lifecycle (LOCKED)

```
lead → quote → auth_attempt → ride → capture
```

- Lead owns engagement lifecycle
- Quote attaches to lead only
- Attempt attaches to lead + quote
- Ride is post-auth execution object — not an intake object
- No phase may pre-create a downstream phase
- No side effects on read (GET requests must never create or mutate)

---

## Tenant Entry (LOCKED)

```
solodrive.pro/t/{tenant_slug}  ← canonical public storefront entry
```

Resolution order: path-first → exact domain match → platform host → legacy subdomain

After record creation: `sd_tenant_id` is the only runtime truth. URL is entry context only.

---

## Storefront Chain (LOCKED)

```
/t/{tenant_slug}
  → 020-tenant-routing.php
  → [sd_storefront] shortcode (owned exclusively by 112-storefront-entry.php)
  → StorefrontEntry → StorefrontGate → StorefrontIntake → LeadService
  → /trip/{token}
```

Storefront creates: lead, token, redirect. Nothing else.

---

## Time Occupancy (LOCKED)

Canonical file: `includes/modules/time-space/080-time-space-ledger.php`

**Time is available by default. Occupancy disproves availability.**

- No seeded availability blocks — that model is rejected
- Availability is a query result over the ledger, not a stored status
- A quote may only be drafted after the ledger confirms the full window is serviceable
- Query/service helpers (conflict detection, timeline, free-at, stack-slot) must read from the ledger — they are not a second source of truth

---

## Module Contract (LOCKED)

`SD_Module_Loader` is the sole registration authority.

| Method | Rule |
|---|---|
| `register()` | Called only by the loader. Attaches WP hooks. No business work. |
| `boot()` | Optional runtime prep. May be called by surfaces. Never calls `register()`. |
| `render_*` `handle_*` `build_*` `get_*` `set_*` | All business logic lives here. |

**Forbidden:**
- Bottom-of-file `Module::register();`
- File-scope hook attachment
- One module calling another module's `register()`
- `boot()` used as a second registration pathway

**Loader auto-registration gate:** Only `SD_Module_*` and `SD_CPT_*` prefixed classes are auto-registered. Other classes loaded by `core.php` require an explicit call in `core.php` or `module-loader.php boot()` after their `require_once`.

---

## Activation Pipeline (LOCKED)

```
Prospect → Provision Package → Stripe Checkout
  → SDPRO onboarding intercept
  → HMAC verify → Stripe verify → fetch provision package
  → create sd_tenant → create/bind operator → issue claim URL
  → Stripe Connect onboarding
```

- Provision Package is NOT a tenant
- Tenant is created exactly once, only in SDPRO
- Tenant is never created on the control plane
- Browser redirects are not trusted alone — Stripe verification is mandatory
- Provisioning must be idempotent (safe against repeated redirects/webhooks)

---

## Known Debt (Current)

| Item | File | Status |
|---|---|---|
| `048-lead-captured-worker.php` | modules/ | Commented out — no disposition note |
| `075-quote-service.php` | modules/ | Not loaded — dead, wrong quote status, delete candidate |
| `trip/040-trip-surface.php` | modules/trip/ | Commented out — direction-b is active replacement |
| `StorefrontSelectorResolver.php` | modules/storefront/ | Not loaded — purpose unclear |
| `_quarantine/` | modules/ | Not loaded — legacy ride-first + seeded availability; safe to archive |
| `front-office.php` | front-office | ~2800-line monolith — split not yet progressed |

---

## What Is Clean (Confirmed)

- Canonical lifecycle: lead → quote → attempt → ride → capture ✅
- Storefront chain and shortcode ownership ✅
- Time occupancy ledger: exists, wired, query capabilities present ✅
- One canonical quote creation path: `048-lead-to-quote-trigger.php` ✅
- Activation pipeline: HMAC, Stripe verify, provision package, tenant, operator ✅
- PII log leak: resolved — 37 error_log() calls removed from front-office.php ✅
- Self-registration violations: resolved — all 3 moved to core.php / loader ✅

---

## Locked Docs (Read Before Touching Core Architecture)

All live in the Claude Project attached to this workspace.

| Doc | Topic |
|---|---|
| 010-ARCHITECTURE.md | Runtime shape, entity hierarchy, hosting model |
| 020-RUNTIME-LIFECYCLE.md | Canonical lifecycle, availability-first doctrine |
| 030-STOREFRONT-SPEC.md | Storefront spec, intake contract, CTA doctrine |
| 040-ACTIVATION-PIPELINE.md | Prospect → tenant activation pipeline |
| 045-TIME-OCCUPANCY-MODEL.md | Occupancy ledger doctrine, quote gating |
| 090-DEVELOPER-GUIDE.md | Module contract, forbidden patterns |

---

## Command Layer

This Claude Project chat is the strategic command layer (CEO).
Claude Code (VSCode) is the Systems execution agent.
Do not invent architecture. Check locked docs before proposing structural changes.
