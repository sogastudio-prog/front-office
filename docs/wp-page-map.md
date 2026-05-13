# WP Page Map — Authority Cluster + Conversion Pages

Last updated: 2026-05-13  
Last sync: 2026-05-13 (full sync via `wp sdct sync --all --skip=solodrive-pro-gig-driver-to-ride-service-owner`)

Hub: `what-should-uber-drivers-do-next`

---

## Authority Pages

| Slug | Role | WP Post ID | Status | Last Synced |
|---|---|---|---|---|
| what-should-uber-drivers-do-next | hub | 751 | publish | 2026-05-13 |
| own-your-riders | core-thesis | 1275 | publish | 2026-05-13 |
| apps-are-for-the-first-ride | core-thesis | 1278 | publish | 2026-05-13 |
| do-uber-drivers-own-their-customers | diagnosis | 757 | publish | 2026-05-13 |
| why-uber-and-lyft-drivers-struggle-to-make-more-money | pain | 756 | publish | 2026-05-13 |
| best-alternatives-to-uber-for-drivers | search-entry | 758 | publish | 2026-05-13 |
| can-uber-drivers-start-their-own-business | bridge | 753 | publish | 2026-05-13 |
| uber-vs-owning-your-own-business | comparison | 754 | publish | 2026-05-13 |
| is-uber-worth-it-long-term | urgency | 750 | publish | 2026-05-13 |
| is-it-legal-to-take-riders-off-uber | objection | 834 | publish | 2026-05-13 |
| how-to-turn-uber-riders-into-repeat-customers | execution | 761 | publish | 2026-05-13 |
| how-to-get-repeat-riders | execution | 752 | publish | 2026-05-13 |
| how-do-private-ride-drivers-get-clients | acquisition | 848 | publish | 2026-05-13 |
| how-to-start-a-transportation-business-in-a-small-town | small-market | 755 | publish | 2026-05-13 |

---

## Conversion Pages

| Slug | WP Post ID | Status | Last Synced | Notes |
|---|---|---|---|---|
| request-access | 136 | draft | — | Pre-existing. Contact Form 7 intake. |
| pricing | 413 | draft | 2026-05-13 | Social proof block placeholder present. |
| solution | 1413 | draft | 2026-05-13 | Cluster layout. Loop visual pending design asset. Social proof placeholder present. |

---

## Held Pages

| Slug | Role | WP Post ID | Status | Reason |
|---|---|---|---|---|
| solodrive-pro-gig-driver-to-ride-service-owner | product-bridge | — | SKIP | Needs product renderer before sync. Type: `product` — not handled by `render_authority()`. |

---

## Notes

- All published pages have `site-post-title = disabled` (Astra H1 suppression via DB meta)
- Browser title tag sourced from `_sdct_meta_title` via `pre_get_document_title` filter
- Meta description, canonical, OG tags, JSON-LD injected via `wp_head` (priority 5)
- Publish gate: `authority_cluster: auto_publish` in `content/data/authority-cluster.yml`
