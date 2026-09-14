# Silverstripe llms.txt

Generate an [`/llms.txt`](https://llmstxt.org) index of your site **and** clean
per-page Markdown (`.md`) versions of pages, so LLMs / AI agents can read your
content efficiently. No static-publish-queue dependency — everything is generated
on the fly and cached.

## Features

- **`/llms.txt`** — a spec-shaped Markdown index: `# Site name`, a `> tagline`
  blockquote, a `## Pages` link list and an optional `## Optional` section for
  secondary (non-menu) pages. Each entry is `- [Title](url): short summary`.
- **Per-page Markdown** — request `…/a-page.md` **or** send
  `Accept: text/markdown` to any page URL to get a clean Markdown rendering
  (Title + meta description + body, including Elemental block content).
- **`<head>` discovery tags** — every page advertises its Markdown alternate
  (`<link rel="alternate" type="text/markdown">`) and the index
  (`<link rel="describedby" href="/llms.txt">`).
- **Summaries** fall back MetaDescription → Content field → Elemental blocks
  (`getElementsForSearch()`), word-limited to keep the index concise.
- **Cached** per locale (index) and per page + `LastEdited` (Markdown), with
  HTTP `Cache-Control`; cleared on `?flush`.
- **Scales**: `max_pages`, `max_depth`, `menu_only`, `exclude_classes`, and a
  `canView()` guard.

## Requirements

- Silverstripe CMS ^6
- `league/html-to-markdown` ^5.1 (installed automatically)

## Installation

```bash
composer require xddesigners/silverstripe-llms-txt
dev/build flush=all
```

## Configuration

```yaml
XD\LLMsTxt\Controllers\LLMsTxtController:
  cache_ttl: 3600         # seconds to cache output (0 = off)
  optional_section: true  # split non-menu pages into "## Optional"
  max_pages: 300          # cap listed pages (0 = unlimited)
  max_depth: 2            # only pages up to N levels deep (0 = unlimited, 1 = top-level)
  menu_only: false        # only list pages shown in menus
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
