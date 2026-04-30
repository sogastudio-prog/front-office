# SoloDrive Content Management Process

**Purpose:** Add and edit SoloDrive website content through the repo-based markdown workflow, then absorb that content into WordPress pages.

This process keeps SoloDrive content structured, versionable, and easier to manage than editing directly inside Gutenberg.

---

## 1. Source of truth

The working content source is the deployed front-office repo:

```bash
~/domains/solodrive.pro/public_html/git-deploy/front-office/
```

Markdown content lives inside:

```bash
git-deploy/front-office/content/
```

Primary conversion pages currently live here:

```bash
git-deploy/front-office/content/conversion/
```

Example:

```bash
git-deploy/front-office/content/conversion/start.md
```

This matters because `/public_html/content/...` is **not** the active source path. The repo path is.

---

## 2. Content categories

Use content folders by purpose.

```txt
content/conversion/
```

For short conversion pages such as:

```txt
start.md
pricing.md
request-access.md
solution.md
```

Use these for pages that move prospects toward the tenant acquisition/onboarding funnel.

```txt
content/authority/
```

For long-form SEO / AI retrieval pages such as:

```txt
own-your-riders.md
apps-first-ride-second.md
future-of-uber-lyft-drivers.md
start-transportation-business-small-town.md
```

Authority pages should follow the SoloDrive knowledge-engine model: one topic per page, one clear answer at the top, strong internal links, and a CTA into the conversion flow. 

---

## 3. Editing an existing page

### Step 1 — Navigate to the site root

```bash
cd ~/domains/solodrive.pro/public_html
```

### Step 2 — Edit the markdown file

Example for `/start/`:

```bash
nano git-deploy/front-office/content/conversion/start.md
```

Or use the hosting file manager:

```txt
public_html
→ git-deploy
→ front-office
→ content
→ conversion
→ start.md
```

### Step 3 — Confirm the edit exists in the markdown file

Example:

```bash
grep -n "sdfo_package_select" git-deploy/front-office/content/conversion/start.md
```

---

## 4. Absorbing markdown into WordPress

After editing the markdown file, WordPress must absorb that file into the actual WP page/post content.

For `/start/`, the current WordPress page is:

```txt
Post ID: 880
Slug: start
Title: Start
```

Absorb the markdown into WP:

```bash
wp post update 880 --post_content="$(cat git-deploy/front-office/content/conversion/start.md)"
```

Then verify WordPress now contains the update:

```bash
wp post get 880 --field=post_content | grep -n "sdfo_package_select"
```

Check the public page:

```bash
curl -s https://solodrive.pro/start/ \
  | grep -n "Choose your plan\|sdfo_package_select\|sd-package"
```

---

## 5. Finding the correct WordPress page ID

Before absorbing content, confirm the target page.

Example:

```bash
wp post list --post_type=page --name=start --fields=ID,post_title,post_name,post_status --format=table
```

Or inspect a known page:

```bash
wp post get 880 --field=post_title
wp post get 880 --field=post_name
```

Never assume the markdown filename and WordPress post ID are automatically connected. The absorption command must target the correct post ID.

---

## 6. Adding a new page

### Step 1 — Create the markdown file

Example:

```bash
nano git-deploy/front-office/content/authority/own-your-riders.md
```

### Step 2 — Create the WordPress page

Example:

```bash
wp post create \
  --post_type=page \
  --post_status=publish \
  --post_title="Own Your Riders" \
  --post_name="own-your-riders" \
  --post_content="$(cat git-deploy/front-office/content/authority/own-your-riders.md)"
```

### Step 3 — Record the new page ID

WP-CLI will return a created post ID. Save that mapping.

Example mapping format:

```txt
/content/authority/own-your-riders.md → /own-your-riders/ → post ID 1234
```

---

## 7. Recommended page mapping registry

Maintain a simple mapping file in the repo so we do not have to rediscover IDs every time.

Suggested file:

```bash
git-deploy/front-office/docs/wp-page-map.md
```

Suggested format:

```md
# SoloDrive WordPress Page Map

| Page | URL | Markdown Source | WP Post ID |
|---|---|---|---|
| Start | /start/ | content/conversion/start.md | 880 |
| Pricing | /pricing/ | content/conversion/pricing.md | TBD |
| Request Access | /request-access/ | content/conversion/request-access.md | TBD |
| Solution | /solution/ | content/conversion/solution.md | TBD |
```

---

## 8. Content doctrine

Content must respect the platform model:

```txt
solodrive.pro
= acquisition + onboarding + support + platform control

solodrive.pro/t/{tenant_slug}
= hosted tenant storefront / ride execution
```

Do not mix the tenant acquisition funnel with the ride execution funnel. 

Marketing pages should focus on driver/tenant demand, product definition, and conversion. Technical implementation belongs in the Systems project. 

---

## 9. Language rules

Prefer concrete customer-facing language:

```txt
booking page
business link
direct bookings
your riders
your transportation business
payments
operations
live page
```

Avoid inside-baseball language on public pages:

```txt
tenant
control plane
execution plane
platform kernel
provision package
runtime
CPT
```

The public promise is:

```txt
We turn your transportation business on with bookings, payments, and operations.
```

That aligns with the locked platform model. 

---

## 10. Standard workflow summary

```txt
Edit markdown in repo
        ↓
Confirm file contains change
        ↓
Absorb markdown into correct WP post ID
        ↓
Verify WP post_content
        ↓
Verify public URL
        ↓
Record/update page mapping
```

For `/start/`, that means:

```bash
cd ~/domains/solodrive.pro/public_html

grep -n "sdfo_package_select" git-deploy/front-office/content/conversion/start.md

wp post update 880 --post_content="$(cat git-deploy/front-office/content/conversion/start.md)"

wp post get 880 --field=post_content | grep -n "sdfo_package_select"

curl -s https://solodrive.pro/start/ \
  | grep -n "Choose your plan\|sdfo_package_select\|sd-package"
```

This is now the working SoloDrive content management process.
