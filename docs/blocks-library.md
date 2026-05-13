# Blocks Library — Conversion Page Components

Last updated: 2026-05-13

Source: `content/blocks/`

Blocks are reusable HTML fragments for hand-authored conversion pages (Path B). All variables use `{{double_brace}}` token syntax. Blocks use the sd- class vocabulary from `plugins/solodrive-front-css/`.

---

## `hero-image.html`

Full-width hero with headline, body copy, CTA button, and image.

**Variables**

| Variable | Notes |
|---|---|
| `{{eyebrow}}` | Small label above the headline (e.g. "For rideshare drivers") |
| `{{heading}}` | Primary H1 — only one per page |
| `{{body}}` | Supporting paragraph |
| `{{cta_url}}` | Button destination |
| `{{cta_text}}` | Button label |
| `{{image_url}}` | Hero image path or URL |
| `{{image_alt}}` | Image alt text |
| `{{width}}` | Image width in px |
| `{{height}}` | Image height in px |

**Notes**
- `loading="eager"` is hardcoded — above-the-fold, no lazy load
- Only one hero per page
- Use on: `start`, `pricing`, `solution`, `request-access`

---

## `feature-card.html`

Single feature highlight card. Intended to be used in multiples inside an `sd-grid`.

**Variables**

| Variable | Notes |
|---|---|
| `{{eyebrow}}` | Category label (e.g. "Payments") |
| `{{heading}}` | Feature name |
| `{{body}}` | One-sentence benefit statement |

**Notes**
- No CTA — cards link as a group or not at all
- Use inside `<div class="sd-grid sd-grid--3">` or `--2` for layout
- Use on: `solution`, `pricing` feature sections

---

## `social-proof.html`

Single testimonial quote block. Use one or several in sequence.

**Variables**

| Variable | Notes |
|---|---|
| `{{quote}}` | Full quote text, no quotation marks needed |
| `{{attribution}}` | Name, city, or role of the person quoted |

**Notes**
- Uses `sd-panel--soft` background variant (light off-white)
- Do not use `<q>` tags — the `<blockquote>/<p>` structure handles this
- Use on: `start`, `request-access`, any page needing trust signals

---

## `cta-banner.html`

Full-width call-to-action banner with blue background.

**Variables**

| Variable | Notes |
|---|---|
| `{{heading}}` | Banner headline |
| `{{body}}` | Supporting sentence |
| `{{cta_url}}` | Button destination |
| `{{cta_text}}` | Button label |

**Notes**
- Uses `sd-cta--blue` — hardcoded color variant, do not override inline
- Button uses `sd-button--light` (white on blue)
- Typically placed at page bottom as the primary conversion action
- Use on: all conversion pages

---

## `how-it-works.html`

Three-step numbered process section.

**Variables**

| Variable | Notes |
|---|---|
| `{{eyebrow}}` | Section label (e.g. "Setup") |
| `{{heading}}` | Section headline |
| `{{step_1_heading}}` | Step 1 title |
| `{{step_1_body}}` | Step 1 description |
| `{{step_2_heading}}` | Step 2 title |
| `{{step_2_body}}` | Step 2 description |
| `{{step_3_heading}}` | Step 3 title |
| `{{step_3_body}}` | Step 3 description |

**Notes**
- Step numbers (1, 2, 3) are hardcoded in `sd-loop-visual` spans — not variables
- Always exactly 3 steps — do not add or remove step cards from the template
- Uses `sd-grid--3` — collapses to single column on mobile
- Use on: `start`, `solution`

---

## `comparison.html`

Two-column side-by-side comparison (Option A vs Option B layout).

**Variables**

| Variable | Notes |
|---|---|
| `{{eyebrow}}` | Section label |
| `{{heading}}` | Section headline |
| `{{option_a_heading}}` | Left card heading |
| `{{option_a_body}}` | Left card description |
| `{{option_b_heading}}` | Right card heading |
| `{{option_b_body}}` | Right card description |

**Notes**
- Uses `sd-grid--2` — two equal columns
- Cards use `sd-path-card` — no background color differentiation built in; add modifier if needed
- Intended for "Uber vs SoloDrive" or "platform vs ownership" framing
- Use on: `solution`, `pricing`
