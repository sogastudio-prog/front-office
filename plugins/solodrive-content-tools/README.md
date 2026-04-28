# SoloDrive Content Tools

WP-CLI tools for managing SoloDrive website content from terminal-managed files.

## Commands

```bash
wp solodrive content list
wp solodrive content validate
wp solodrive content diff
wp solodrive content sync
wp solodrive content export
wp solodrive content links
```

From the repository root, use:

```bash
make content-validate
make content-diff
make content-sync
```

## Content files

Markdown pages live in:

```txt
content/pages/*.md
```

Each page should include front matter:

```yaml
title: Own Your Riders
slug: own-your-riders
status: draft
meta_title: Own Your Riders | SoloDrive
meta_description: SoloDrive helps rideshare drivers turn one-time passengers into repeat direct-booking customers.
summary: Drivers do not need more random rides.
audience: Uber and Lyft drivers
primary_topic: driver-owned rider relationships
cta: request-access
schema_type: Article
last_reviewed: 2026-04-27
```

## First safe test

```bash
make content-validate
wp solodrive content sync --dry-run --root=/path/to/front-office-main
```

Then publish one page at a time:

```bash
wp solodrive content sync --slug=own-your-riders --root=/path/to/front-office-main
```
