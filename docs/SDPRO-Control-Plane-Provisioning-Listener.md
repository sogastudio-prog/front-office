# SDPRO Control-Plane Provisioning Listener

Status: BUILD READY  
Purpose: Accept provisioning requests from the SoloDrive control plane and materialize them inside SDPRO.

## Important correction

`sd_control_plane_tenant_provisioning_requested` is a WordPress action inside the control-plane app. By itself, that hook cannot cross from `solodrive.pro` into SDPRO because these are separate systems. The practical bridge is:

1. front-office fires the hook internally
2. front-office hook callback sends a signed HTTP POST to SDPRO
3. SDPRO listens on a REST route and provisions runtime state

This build provides the SDPRO listener.

## Route

`POST /wp-json/sd/v1/control-plane/provision-tenant`

## Auth

Accepted auth methods:

- `X-SD-Provisioning-Secret: <shared secret>`
- `X-SD-Signature: <hex hmac sha256 of raw body using shared secret>`
- fallback body field: `provisioning_secret`

Recommended config on SDPRO:

- constant: `SD_CONTROL_PLANE_PROVISIONING_SECRET`
- or option: `sd_control_plane_provisioning_secret`
- or env var: `SD_CONTROL_PLANE_PROVISIONING_SECRET`

## Required payload

```json
{
  "tenant_id": "ten_...",
  "tenant_slug": "mike",
  "prospect_id": "prs_...",
  "prospect_post_id": 123,
  "full_name": "Mike Example",
  "email": "mike@example.com",
  "phone": "15551234567",
  "stripe_account_id": "acct_...",
  "stripe_customer_id": "cus_...",
  "stripe_subscription_id": "sub_...",
  "billing_status": "paid",
  "activation_mode": "inactive_until_provisioned"
}
```

## What the listener does

- validates secret/signature
- enforces `billing_status = paid`
- creates or updates the owner user by email
- prefers role `sd_owner`, then falls back to `administrator`, then `subscriber`
- creates or updates runtime `sd_tenant` post if that post type exists
- writes a runtime tenant manifest option for slug/path resolution support
- marks provisioning status as `provisioned`
- marks storefront status as `ready`
- marks activation readiness as `true`
- dedupes repeated requests via request id / deterministic hash
- fires runtime hook: `sdpro_control_plane_tenant_provisioned`

## Response

```json
{
  "ok": true,
  "request_id": "sdpro_req_...",
  "tenant_id": "ten_...",
  "tenant_slug": "mike",
  "runtime_tenant_post_id": 456,
  "owner_user_id": 22,
  "provisioning_status": "provisioned",
  "activation_ready": true,
  "storefront_status": "ready",
  "runtime_storefront_path": "/t/mike"
}
```

## Next bridge to build in front-office

Front-office should attach a callback to:

`sd_control_plane_tenant_provisioning_requested`

That callback should `wp_remote_post()` the payload to SDPRO using the shared secret or HMAC signature, then store the response back onto the control-plane tenant record.
