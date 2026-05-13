# Content Pipeline — Architecture Reference

Last updated: 2026-05-13

---

## Two-Audience Doctrine (Locked)

| Audience | Path | Content type | Publisher |
|---|---|---|---|
| Machines (AI search, Google) | Path A | Authority pages | Pipeline (CLI) |
| Humans (drivers on phones) | Path B | Conversion pages | Manual / reviewed |

Authority pages are written for indexing and AI retrieval. They are source-controlled markdown, machine-synced to WordPress. No human edits directly in WP admin.

Conversion pages are written for conversion. They are hand-authored HTML using the sd- block vocabulary, manually reviewed before publish.

---

## Class Architecture

### `SDCT_Content_Repository`
Reads `.md` files from disk. Parses YAML frontmatter and returns a structured `array { meta, body }`. Owns `detect_root_dir()` — walks up from plugin dir until it finds a `/content` directory.

### `SDCT_Cluster_Reader`
Reads `content/data/authority-cluster.yml`. Custom YAML parser (no external libraries) handles the specific indentation structure: top-level keys, nested maps at 2 spaces, list items with nested keys at 4 spaces.

Key methods:
- `get_hub_slug()` — returns cluster hub slug
- `get_pages()` — returns all cluster page entries
- `get_standard_cta()` — returns CTA block config
- `get_publish_gates()` — returns gate key/value map
- `is_authority_auto_publishable()` — gate check shorthand
- `get_page_role(string $slug)` — role for a given slug

### `SDCT_Markdown_Renderer` (Path A)
Renders authority markdown pages to HTML using the `sd-authority__*` BEM class vocabulary. Always called for type `authority` or `page`.

Rendering rules:
- Strips trailing inline HTML blocks (`<section>`) from body tail — renderer controls all output
- Parses H2 sections; detects `Related` sections and renders them as `sd-related-pages__list`
- Appends standard CTA block from `authority-cluster.yml` (always present, not optional)
- Produces `<main class="sd-managed-page sd-managed-page--authority">` wrapper
- Inline markdown (`**bold**`, `*em*`, `[link](url)`) processed without double-escaping via `PREG_OFFSET_CAPTURE | PREG_SET_ORDER`

### `SDCT_HTML_Processor` (Path B)
Validates sd- class structure on hand-authored HTML conversion pages. Injects schema and meta. **Never auto-publishes.** Conversion pages require manual review.

### `SDCT_Schema_Builder`
Builds JSON-LD schema blocks.
- `build_article(array $meta): array` — Article schema with Organization as author
- `build_faq(array $items): array` — FAQPage schema
- `encode(array $schema): string` — `wp_json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`

### `SDCT_Meta_Builder`
Derives and writes `_sdct_*` post meta from frontmatter.
- `build(array $meta): array` — returns keyed array of all meta values
- `write(int $post_id, array $built): void` — calls `update_post_meta` for each key

Meta keys written: `_sdct_meta_title`, `_sdct_meta_description`, `_sdct_canonical_url`, `_sdct_schema_type`, `_sdct_noindex`, `_sdct_og_image`, `_sdct_last_reviewed`, `_sdct_slug`, `_sdct_schema_json`

### `SDCT_WordPress_Sync`
Orchestrates the full sync pipeline. Called by CLI.
- `sync_all(array $skip_slugs = []): array` — iterates cluster, skips listed slugs
- `sync_file(string $file_path): array` — syncs a single file by path
- `sync_page(array $page): array` — full sync: render → resolve post ID → upsert → write meta

Writes `site-post-title = disabled` for all authority/page types. This is the Astra theme per-page H1 suppression via database meta (not CSS). Required because the renderer writes its own H1 via `sd-authority__title`; the theme default H1 must not also render.

### `SDCT_Sync_CLI_Command`
Registered as `wp sdct`. See CLI Reference below.

---

## CLI Reference

```bash
# Sync all cluster pages
wp sdct sync --all

# Sync a single file
wp sdct sync --file=content/pages/{slug}.md

# Sync all, skip one slug
wp sdct sync --all --skip={slug}

# Dry run (no DB writes)
wp sdct sync --all --dry-run

# Show cluster page status (SLUG / ROLE / WP STATUS / POST ID)
wp sdct status

# Show publish gate values
wp sdct gates
```

---

## Publish Gate Doctrine

Gate values are set in `content/data/authority-cluster.yml` under `publish_status`.

```yaml
publish_status:
  authority_cluster: auto_publish
  conversion_pages: manual_until_migrated
  legal_pages: review_required
```

**Gate is the authoritative safety valve.** If the gate for a content type is not `auto_publish`, the sync forces `draft` regardless of the per-page `status` field in frontmatter. Only when the gate is open does frontmatter status apply.

---

## Frontmatter Standard Fields

| Field | Required | Notes |
|---|---|---|
| `title` | Yes | Used as WP `post_title` and fallback browser title |
| `slug` | Yes | Must match WP page slug |
| `status` | Yes | `publish`, `draft`, `private`, or `pending` — gate overrides this |
| `meta_title` | Recommended | Browser `<title>` tag override |
| `meta_description` | Recommended | `<meta name="description">` |
| `summary` | Optional | Internal context only |
| `audience` | Optional | `machines` or `humans` |
| `primary_topic` | Optional | Topical cluster grouping |
| `cta` | Optional | Override for standard CTA |
| `schema_type` | Optional | `Article`, `FAQPage`, etc. |
| `last_reviewed` | Optional | ISO date — written to `_sdct_last_reviewed` |
| `wp_post_id` | Optional | Forces sync to a specific post ID |

---

## Post ID Resolution Order

1. `wp_post_id` in frontmatter — explicit override
2. `get_page_by_path(slug)` — matches WP page slug
3. `_sdct_slug` meta lookup — matches previously synced post
4. Create new `page` post type

---

## Meta + Head Injection

For pages whose `post_content` contains `sd-managed-page` (written by renderer):

- **Browser title:** `pre_get_document_title` filter (priority 10) — pulls `_sdct_meta_title`
- **Meta tags:** `wp_head` action (priority 5) — outputs description, canonical, OG title, OG image
- **JSON-LD:** `wp_head` action — outputs `<script type="application/ld+json">` from `_sdct_schema_json`
- **Body class:** `body_class` filter — adds `sd-has-managed-page`, `sd-hide-theme-title`, `sd-managed-type-{type}`
