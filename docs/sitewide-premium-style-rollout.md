# SoloDrive Sitewide Premium Style Rollout

## Purpose

Move the new home and `/start/` visual direction out of page/tool-controlled inline CSS and into the repo-owned SoloDrive content + CSS tools.

This makes the new style reusable sitewide instead of locked inside one edited page.

## What this pack changes

### 1. Adds a premium marketing CSS layer

New file:

```txt
plugins/solodrive-front-css/assets/css/10-premium-marketing.css
```

This file owns the new visual system:

- photo hero with dark gradient
- cream page background
- navy/white high-contrast sections
- large compressed headings
- pill CTAs
- proof cards
- product cards
- dark driver-outcome section
- premium `/start/` layout
- responsive/mobile rules

It intentionally loads after the existing CSS files so it can override the older clean-slate styles.

### 2. Updates CSS enqueue order

Updated file:

```txt
plugins/solodrive-front-css/solodrive-front-css.php
```

Adds:

```php
'09-authority.css',
'10-premium-marketing.css',
```

This also fixes the prior issue where `09-authority.css` existed but was not being loaded.

### 3. Brings `content/home/` under the content tool

Updated file:

```txt
plugins/solodrive-content-tools/includes/class-sdct-content-repository.php
```

Adds:

```php
$this->content_dir . '/home',
```

This lets the homepage candidate be managed by the same markdown sync workflow as conversion, product, legal, utility, and authority content.

### 4. Replaces `/start/` markdown with the premium structure

Updated file:

```txt
content/conversion/start.md
```

The page now uses:

```html
<section class="sd-start-premium">
```

and keeps the important tool-controlled shortcodes:

```txt
[sdfo_package_select heading="Choose your plan"]
[contact-form-7 id="33" title="Start"]
```

### 5. Replaces the homepage candidate with the new premium structure

Updated file:

```txt
content/home/homepage-candidate.md
```

The page uses:

```html
<section class="sd-home-premium">
```

and matches the new homepage direction shown in the screenshots:

- “You already have riders” hero
- SoloDrive loop
- rider-facing product proof
- dark driver outcomes
- final CTA panel

## Deployment order

From the front-office repo root:

```bash
cd ~/domains/solodrive.pro/public_html/git-deploy/front-office
```

Copy or apply the changed files from this pack.

Then validate PHP:

```bash
php -l plugins/solodrive-front-css/solodrive-front-css.php
php -l plugins/solodrive-content-tools/includes/class-sdct-content-repository.php
```

Then run content validation:

```bash
wp solodrive content validate --slug=start
wp solodrive content validate --slug=landing-page
```

Then dry-run sync:

```bash
wp solodrive content sync --slug=start --dry-run
wp solodrive content sync --slug=landing-page --dry-run
```

Then sync:

```bash
wp solodrive content sync --slug=start
wp solodrive content sync --slug=landing-page
```

If the homepage slug is not `landing-page` on production, find the current homepage first:

```bash
wp option get page_on_front
wp post get $(wp option get page_on_front) --fields=ID,post_title,post_name,post_status --format=table
```

If needed, update the front matter slug in `content/home/homepage-candidate.md` before syncing.

## Verification

Check CSS loads:

```bash
curl -s https://solodrive.pro/ | grep -n "10-premium-marketing.css"
curl -s https://solodrive.pro/start/ | grep -n "10-premium-marketing.css"
```

Check wrappers rendered:

```bash
curl -s https://solodrive.pro/ | grep -n "sd-home-premium\|sd-premium-hero"
curl -s https://solodrive.pro/start/ | grep -n "sd-start-premium\|sd-start-card\|sdfo_package_select"
```

Check shortcodes rendered on `/start/`:

```bash
curl -s https://solodrive.pro/start/ | grep -n "Choose your plan\|Get My Booking Page\|wpcf7\|sdfo"
```

## Important notes

- Do not paste the CSS into Gutenberg anymore.
- Do not remove the package selector or Contact Form 7 shortcodes from `/start/`.
- The homepage file assumes the live front-page post slug is `landing-page`. Verify before syncing.
- This is still the native-page sync model, not the future meta-first CPT model. It is safe for home and `/start/` because they are conversion/marketing pages with intentional raw HTML and shortcodes.
