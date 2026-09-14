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
- **Summaries** fall back MetaDescription → Content field → Elemental blocks
  (`getElementsForSearch()`), word-limited to keep the index concise.
- **Catalogue DataObjects** — also list DataObjects that live *under* a page
  (products, caravan models, listings…) in the index with their own URL + summary,
  via `dataobject_classes`. Each record needs a `Title` and a `Link()`/`AbsoluteLink()`.
- **Per-record Markdown** — a page controller can expose a detail record with
  `getLLMsRecord(): ?DataObject`, so `…/<action>/<slug>.md` returns *that record's*
  Markdown (Title + summary + Content/Description body), not the page's.
- **Extension hooks** — `updateLLMsMarkdown(&$md)` (append structured Markdown to a
  page or record) and `getLLMsSummary()` (a one-line index summary; return `false`
  to exclude the record).
- **`<head>` discovery tags** — every page advertises its Markdown alternate
  (the current URL + `.md`) and the index (`<link rel="describedby">`).
- **Cached** per locale (index) and per subject + `LastEdited` (Markdown), with
  HTTP `Cache-Control`; cleared on `?flush` **and** on page publish/unpublish.
- **Scales**: `max_pages`, `max_depth`, `menu_only`, `exclude_classes`,
  `metadescription_only_classes`, `dataobject_max`, and a `canView()` guard.

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
  cache_ttl: 3600         # seconds to cache output (0 = off; also drives .md cache + Cache-Control)
  optional_section: true  # split non-menu pages into "## Optional"
  max_pages: 300          # cap listed pages (0 = unlimited)
  max_depth: 2            # only pages up to N levels deep (0 = unlimited, 1 = top-level)
  menu_only: false        # only list pages shown in menus
  exclude_classes:        # page classes to leave out
    - Path\To\SomePage
  metadescription_only_classes:  # summarise these from MetaDescription only (matched via instanceof)
    - Path\To\ListingPage
  dataobject_classes:     # DataObjects to also list, as [ClassName: 'Section heading']
    'App\Model\Product': 'Products'
  dataobject_max: 0       # cap records per DataObject class (0 = unlimited)
```

## Extending

Add richer output from your own page types and models:

- **`updateLLMsMarkdown(&$md)`** — on a page/record (or an extension of it), append
  structured Markdown after the title. Good for a product's price/brand/SKU, or a
  listing page enumerating its child DataObjects.
- **`getLLMsSummary(): string|false`** — on a DataObject listed via `dataobject_classes`,
  return its one-line index summary. Return `false` to exclude the record (e.g.
  sold/inactive items).
- **`getLLMsRecord(): ?DataObject`** — on a **page controller**, return the detail
  record currently being viewed (resolved from the request), so `…/<action>/<slug>.md`
  renders that record instead of the page. It runs at `onAfterInit`, before action
  params are set, so parse the URL rather than reading `param()`.

Records rendered as `.md` use their `Content` field, else `Description`.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
