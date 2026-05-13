# SoloDrive — CEO Build List
Last Updated: May 11, 2026

Single source of truth for all tracked build items across CEO, Systems, Marketing, and Finance sessions.

---

## Priority Key

🔴 Pre-live — must be resolved before Stripe live keys flip  
🟡 Pre-marketing — must be resolved before any marketing push  
🟢 Roadmap — scheduled but not blocking  

---

## Status Key

`Open` — not started  
`In Progress` — actively being worked  
`Blocked` — waiting on dependency  
`Done` — complete and verified  

---

## 🔴 Pre-Live (Blocks Stripe Live Key Flip)

| # | Item | Area | Status | Notes |
|---|---|---|---|---|
| 1 | Fix `sd_commercial_calculate_application_fee()` — fee validation is currently a silent no-op | Systems | ✅ Done | Was already working. Function lives in `commercial/030-commercial-fee-engine.php`, loaded at position 65 before checkout at 98. First audit missed this file. |
| 2 | Fix `_sd_quote_amount_cents` write gap — capture module may compute $0 application fee | Systems | ✅ Done | Was already covered. Written in `048-lead-to-quote-trigger.php:138` and `quote-workflow.php:423`. Capture reads at line 215 with fallback to `sd_quote_total_cents`. |
| 3 | Resolve HMAC onboarding expiry mismatch between front-office and SDPRO | Systems | ✅ Done | Fixed both repos. Neither side had expiry logic — links were valid forever. Front-office now adds `issued_at` to HMAC payload. SDPRO enforces 30-minute expiry window. Clear error shown for expired links. |
| 10 | Flip Stripe sandbox → live keys | Finance + Systems | 🟢 Unblocked | Finance confirms Stripe account + Connect readiness first. Systems flips keys, updates webhook signing secret, runs smoke test before opening to real prospects. |

---

## 🟡 Pre-Marketing

| # | Item | Area | Status | Notes |
|---|---|---|---|---|
| 4 | Align all Commercial Profiles to Option B gate doctrine | Systems | ✅ Done | All three profiles (basic, pro, elite) aligned. Identical gate configuration. Advanced Reporting/Custom Domain/White Label OFF. All working features ON including Stacked Availability. |
| 5 | Lock `/provision-operator` REST route | Systems | ✅ Done | HMAC permission_callback added. Route was previously wide open with no auth. |
| 6 | Resolve dual quote creation path (048 vs 155) | Systems | ✅ Done | 155-quote-engine.php did not exist (audit false positive). 075-quote-service.php was dead code with wrong initial state — removed from module loader. 048 confirmed sole canonical path. |
| 7 | Fix CTA URL parameter mismatch `?package=` vs `?sd_package=` | Systems | ✅ Done | Canonical name: sd_package_key. Fallback chain: sd_package_key → sd_package → package. Both JS URL reader and PHP form handler updated. |
| 8 | Wire auth code use tracking — `current_uses` never incremented | Systems | ✅ Done | current_uses increment wired in both repos. Config-file codes get live WP options counter. max_uses enforcement reads live count. |
| 16 | Rebuild `sdfo_package_select` from system properties | Systems | Open | Currently renders Description field. Must derive display from Feature Gates, Fee Policy, Billing, and Provisioning Policy. Description becomes optional tagline only. Feature gates need display label map. |
| 17 | Elevate conversion pages to premium homepage standard | Marketing | Open | Pages: `start`, `pricing`, `solution`, `request-access`. Must match premium CSS standard (`10-premium-marketing.css`). Holds until Systems confirms shortcode/form layer is stable. |

---

## 🟢 V1 Roadmap

| # | Item | Area | Status | Notes |
|---|---|---|---|---|
| 9 | Waybill — generate at ride start, accessible from Drive surface | Systems | Open | Legal/regulatory document. Driver presents to authorities mid-trip. Generates when ride status → active. Immutable after generation. Printable/shareable link. No feature gate — baseline all plans. Minimum fields: operator name, vehicle, rider name, pickup/dropoff, ride ID, booking timestamp, authorized fare, trip start time. |
| 11 | Stacked Availability — complete build | Systems | Open | Included in all plans. Gate is ON. Feature not yet complete. Target: day one. |
| 12 | Reservations — complete build | Systems | Open | Included in all plans. Gate is ON. Partially built. Calendar surface shown as "Coming Next" in operator app. |

---

## 🟢 Post-V1 / Future

| # | Item | Area | Status | Notes |
|---|---|---|---|---|
| 13 | Advanced Reporting — spec + build | Systems | Open | No work done. Gate OFF all plans until built. Waybill is NOT this — waybill is operational output, not reporting. When built, likely premium gate candidate. |
| 14 | Custom Domain — feasibility + spec | Systems | Open | Not built. No feasibility or requirements report exists. Gate OFF. |
| 15 | White Label — feasibility + spec | Systems | Open | Not built. No feasibility or requirements report exists. Gate OFF. |
| 18 | ~~Fix authority/ directory~~ — CLOSED | Systems | ✅ Done | Authority pages live in content/pages/ — already scanned. Non-issue. |
| 19 | Build SDCT_Cluster_Reader | Systems | ✅ Done | Reads authority-cluster.yml. Exposes get_hub_slug(), get_pages(), get_standard_cta(), get_publish_gates(), is_authority_auto_publishable(), is_conversion_gated(), get_page_role(). |
| 20 | Build SDCT_Markdown_Renderer — Path A (authority) | Systems | ✅ Done | Renders to sd-authority__* HTML. Strips trailing inline HTML. Routes related pages to sd-related-pages__list. Appends standard CTA from cluster reader. |
| 21 | Build SDCT_HTML_Processor — Path B (conversion) | Systems | ✅ Done | Validates sd- class structure, injects schema + meta. Never auto-publishes. |
| 22 | Build SDCT_Schema_Builder | Systems | ✅ Done | Article + FAQPage JSON-LD for authority. Service/Product/WebPage for conversion. Writes to _sdct_schema_json. |
| 23 | Build SDCT_Meta_Builder | Systems | ✅ Done | Writes _sdct_meta_title, _sdct_meta_description, _sdct_canonical_url, _sdct_schema_type, _sdct_noindex, _sdct_og_image, _sdct_last_reviewed. |
| 24 | Build SDCT_WordPress_Sync | Systems | ✅ Done | Post ID resolution: wp_post_id frontmatter → get_page_by_path → _sdct_slug meta → create new. Gate enforcement: cluster gate beats frontmatter status. |
| 25 | Build WP-CLI command: wp sdct sync | Systems | ✅ Done | sync --all, sync --file, status, gates subcommands registered. |
| 26 | Build blocks/ library — conversion page components | Systems | ✅ Done | hero-image.html, feature-card.html, social-proof.html, cta-banner.html, how-it-works.html, comparison.html — all sd- class vocabulary with {{variable}} substitution. |
| 27 | Update authority-cluster.yml publish gate | Systems | ✅ Done | authority_cluster flipped from draft_until_visual_review → auto_publish. |
| 28 | Standardize frontmatter across all .md files | Marketing | Open | Add wp_post_id, description, canonical, schema, og_image, tags to all existing .md files. FAQ frontmatter for authority pages. |
| 29 | Visual elevation of conversion pages | Marketing | In Progress | Layout brief approved. Pending: solution.md consolidation proposal + consolidated icon list before copy writing begins. |
| 30 | Consolidated SVG icon brief → Systems | Marketing | ✅ Done | 11 icons delivered to solodrive-front-css/assets/icons/. Single weight line, var(--sd-ink), 24px grid. |
| 31 | Pricing model analysis — margin + break-even | Finance | ✅ Done | Pro plan structural flaw identified. Recommendation: $45/mo + 4% flat. CEO implemented $45/mo + 4.5%. Crossovers: Basic→Pro ~$1,400/mo, Pro→Elite ~$2,400/mo. |
| 34 | APPLICATION FEE NOT CAPTURED — platform collected $0 on Ride #1865 | Systems | ✅ By Design | Test tenant was Elite plan — no per-ride fee. $0 is correct. Verify fee fires correctly on Basic/Pro test ride before live key flip. |
| 35 | Map anchor wrong — showing operator base location instead of pickup | Systems | ✅ Invalid Test | Operator left same screen open at home. Two devices competing. Not a code bug. |
| 36 | Rider trip metrics (ETA, Distance) showing — | Systems | ✅ Likely Test Artifact | Two devices active simultaneously. Not confirmed as code issue. |
| 37 | Operator action buttons buried — requires scroll to bottom | Systems | ✅ Done | Action row moved before status lines in 140, 148, and operator-workspace.php. Status lines now show only current relevant reference. Enum audit trail removed from display. |
| 38 | Deploy solodrive-content-tools + run wp sdct sync --all | Systems | ✅ Done | 14 authority pages live at publish. Astra H1 suppressed via DB on all pages. Browser title, meta description, canonical, OG tags, JSON-LD injected on all. solodrive-pro-gig-driver-to-ride-service-owner held — needs product renderer. |
| 33 | Investigate "SoloDrive Operator Subscription" $20/mo product | CEO/Systems | ✅ Done | Archived. Legacy testing artifact confirmed. |

---

## Feature Gate Map — V1 Doctrine (Option B)

All plans identical until intentional premium divergence.

| Gate | All Plans | Reason |
|---|---|---|
| `Tenant Storefront` | ✅ ON | Working |
| `Lead Capture` | ✅ ON | Working |
| `Stripe Authorization` | ✅ ON | Working |
| `Payment Capture` | ✅ ON | Working |
| `Operator Console` | ✅ ON | Working |
| `Quote Workflow` | ✅ ON | Working |
| `Driver Portal` | ✅ ON | Working — operator home at app.solodrive.pro/operator |
| `Reservations` | ✅ ON | Included all plans — partially built |
| `Stacked Availability` | ✅ ON | Included all plans — not yet complete |
| `Advanced Reporting` | ❌ OFF | Not built |
| `Custom Domain` | ❌ OFF | Not built, no spec |
| `White Label` | ❌ OFF | Not built, no spec |

---

## Package Display Label Map (Draft — V1)

Used by `sdfo_package_select` rebuild (item #16). Labels evolve as features are named publicly.

| Gate Key | Display Label |
|---|---|
| `Tenant Storefront` | Your booking page |
| `Lead Capture` | Ride requests |
| `Stripe Authorization` | Secure checkout |
| `Payment Capture` | Automatic payment collection |
| `Operator Console` | Your dashboard |
| `Driver Portal` | Operator app |
| `Reservations` | Scheduled rides |
| `Quote Workflow` | Custom quote approval |
| `Stacked Availability` | Back-to-back ride scheduling |
| `Advanced Reporting` | Advanced reporting *(off — not built)* |
| `Custom Domain` | Custom web address *(off — not built)* |
| `White Label` | Remove SoloDrive branding *(off — not built)* |

---

## Architecture Decisions Locked This Session

- **Time model:** Single canonical occupancy ledger. Availability inferred, not stored. (`045-TIME-OCCUPANCY-MODEL.md`)
- **V1 operator model:** Driver = Operator. Single owner/operator. No distinction.
- **Package doctrine (Option B):** All working features ON for all plans. Gates diverge only on intentional premium decision.
- **Description field:** Becomes optional marketing tagline only. Package display derived from system properties.
- **Waybill:** Operational output, not a reporting feature. No feature gate. Generates at ride start.
- **Stacked Availability + Reservations:** Included in all plans. Gate ON. Build completion is roadmap, not a gate condition.

---

## Session Log

| Date | Session | Key Decisions |
|---|---|---|
| May 11, 2026 | CEO | Audit completed. Priority triage. Option B gate doctrine locked. Waybill spec defined. Build list established. |
| May 11, 2026 | Systems | Sprint 1 complete. Items 1+2 were already working (first audit incomplete). Item 3 fixed — HMAC expiry now enforced, 30-min window, both repos. Stripe live key flip unblocked. |
| May 12, 2026 | CEO | Content pipeline architecture defined. SDCT_Content_Repository reviewed — solid foundation, four layers needed on top. Frontmatter standard defined. Items 18-24 added to build list. |
| May 12, 2026 | CEO | Two-audience doctrine locked. Authority pages = machine audience, auto-publish, markdown-first. Conversion pages = human audience (drivers on phones), sizzle-first, HTML-first, never auto-publish. Blocks library scoped. Items 18-29 updated. |
| May 12, 2026 | Marketing | start.md copy approved pending rate confirm + item #16. Icons delivered (item #30 done). request-access.md in progress. |
| May 12, 2026 | Systems | Icons complete. Item #16 moved to top of queue. |
| May 12, 2026 | Finance | Session opened. First task: pricing model analysis — margin, break-even, structural validation. |
| May 12, 2026 | Systems | Sprint 2 complete. Items 5-8 closed. Notable: audit had two false positives (155 didn't exist, 075 was dead code). Canonical quote path is 048. sd_package_key is canonical parameter. Auth code tracking wired via WP options. |
| May 13, 2026 | Systems | Content pipeline complete (items 19-27). 14 authority pages live. H1 suppressed via Astra DB meta. Browser title, meta, canonical, OG, JSON-LD all injected. Operator UX fixed (item 37). Live test completed — full lifecycle end-to-end on Ride #1865. |
| May 13, 2026 | Systems | Documentation complete. 5 files written: content-pipeline.md, blocks-library.md, wp-page-map.md (sdpro-front/docs), architecture-decisions.md, sprint-log.md (sdpro-fresh/docs). |