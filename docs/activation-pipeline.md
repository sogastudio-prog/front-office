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
