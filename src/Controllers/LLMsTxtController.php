<?php

namespace XD\LLMsTxt\Controllers;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Serves /llms.txt — a concise, spec-shaped Markdown overview of the site for LLMs
 * (https://llmstxt.org): an H1 site name, a blockquote summary, a "## Pages" link
 * list and, optionally, a "## Optional" section with secondary (non-menu) pages
 * that an agent may skip. Each entry is `- [Title](url): short summary`.
 *
 * The per-page summary uses MetaDescription, then the Content field, then a short
 * summary of the Elemental block content (getElementsForSearch()). Because that
 * fallback renders blocks (heavy), the output is cached per locale.
 */
class LLMsTxtController extends Controller implements Flushable
{
    private static array $allowed_actions = ['index'];

    /**
     * Seconds to cache generated output (0 disables). Cleared on ?flush. Also used
     * for the per-page Markdown cache + Cache-Control headers.
     * @config
     */
    private static int $cache_ttl = 3600;

    /**
     * Split secondary (non-menu) pages into a "## Optional" section (per the spec).
     * @config
     */
    private static bool $optional_section = true;

    /**
     * Cap the number of pages listed (0 = unlimited). Bounds size + build time.
     * @config
     */
    private static int $max_pages = 0;

    /**
     * Only list pages up to this depth (0 = unlimited, 1 = top-level only).
     * @config
     */
    private static int $max_depth = 0;

    /**
     * Only list pages shown in menus (ShowInMenus = 1).
     * @config
     */
    private static bool $menu_only = false;

    /**
     * Page classes to leave out of the listing.
     * @config
     */
    private static array $exclude_classes = [
        'SilverStripe\\ErrorPage\\ErrorPage',
        'SilverStripe\\CMS\\Model\\RedirectorPage',
        'SilverStripe\\CMS\\Model\\VirtualPage',
    ];

    /**
     * Page classes whose summary uses the MetaDescription only — no Content/Elemental fallback.
     * For listing pages (e.g. product categories) whose body is filter/count UI rather than prose,
     * so the fallback would otherwise surface noise. Matched with instanceof, so subclasses count.
     * @config
     */
    private static array $metadescription_only_classes = [];

    /**
     * DataObject classes to also list in the index, as [ClassName => 'Section heading']. Use for
     * catalogue items that live as DataObjects under a page (caravan models, occasions, rentables…).
     * Each record needs a Title and a Link()/AbsoluteLink(); its one-line summary comes from an
     * optional getLLMsSummary() on the record (add it via the model or an extension), else its
     * MetaDescription. getLLMsSummary() may return false to exclude the record from the index
     * (e.g. a sold occasion or an inactive rentable). Records failing canView() are skipped.
     * @config
     */
    private static array $dataobject_classes = [];

    /**
     * Max records listed per DataObject class (0 = unlimited).
     * @config
     */
    private static int $dataobject_max = 0;

    public function index(): HTTPResponse
    {
        $ttl = (int) $this->config()->get('cache_ttl');
        // Only cache the anonymous view — a logged-in editor may see restricted
        // pages via canView(), which must never be cached for the public.
        $useCache = $ttl > 0 && !Security::getCurrentUser();
        $cache = $useCache ? $this->cache() : null;
        $key = 'index_' . i18n::get_locale();

        $body = $cache ? $cache->get($key) : null;
        if ($body === null) {
            $body = $this->buildBody();
            if ($cache) {
                $cache->set($key, $body, $ttl);
            }
        }

        $response = HTTPResponse::create($body);
        $response->addHeader('Content-Type', 'text/plain; charset=utf-8');
        if ($ttl > 0) {
            $response->addHeader('Cache-Control', 'public, max-age=' . $ttl);
        }
        return $response;
    }

    private function cache(): ?CacheInterface
    {
        try {
            return Injector::inst()->get(CacheInterface::class . '.llmsTxt');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Clear the shared llms.txt cache pool (index + per-page Markdown) on ?flush / dev/build.
     * CacheFactory pools are not wiped automatically, so clear it explicitly.
     */
    public static function flush(): void
    {
        try {
            Injector::inst()->get(CacheInterface::class . '.llmsTxt')->clear();
        } catch (\Throwable $e) {
            // no cache configured — nothing to clear
        }
    }

    private function buildBody(): string
    {
        $config = SiteConfig::current_site_config();

        $lines = ['# ' . $config->Title];
        if ($config->Tagline) {
            $lines[] = '';
            $lines[] = '> ' . $config->Tagline;
        }

        $pages = SiteTree::get()
            ->exclude('ClassName', $this->config()->get('exclude_classes'));
        if ($this->config()->get('menu_only')) {
            $pages = $pages->filter('ShowInMenus', 1);
        }
        $pages = $pages->sort(['ParentID' => 'ASC', 'Sort' => 'ASC']);

        $maxDepth = (int) $this->config()->get('max_depth');
        $maxPages = (int) $this->config()->get('max_pages');
        $useOptional = (bool) $this->config()->get('optional_section');

        $primary = [];
        $optional = [];
        $count = 0;
        $truncated = false;
        foreach ($pages as $page) {
            if (!$page->canView() || !$this->withinDepth($page, $maxDepth)) {
                continue;
            }
            if ($maxPages > 0 && $count >= $maxPages) {
                $truncated = true;
                break;
            }
            $line = $this->pageLine($page);
            if ($useOptional && !$page->ShowInMenus) {
                $optional[] = $line;
            } else {
                $primary[] = $line;
            }
            $count++;
        }

        $lines[] = '';
        $lines[] = '## Pages';
        foreach ($primary as $line) {
            $lines[] = $line;
        }
        if ($optional !== []) {
            $lines[] = '';
            $lines[] = '## Optional';
            foreach ($optional as $line) {
                $lines[] = $line;
            }
        }
        if ($truncated) {
            $lines[] = '';
            $lines[] = sprintf('> Showing the first %d pages.', $maxPages);
        }

        foreach ($this->dataObjectSections() as $section) {
            $lines[] = '';
            $lines[] = '## ' . $section['heading'];
            foreach ($section['rows'] as $row) {
                $lines[] = $row;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function pageLine(SiteTree $page): string
    {
        $summary = $this->pageSummary($page);
        $line = '- [' . $page->Title . '](' . $page->AbsoluteLink() . ')';
        if ($summary !== '') {
            $line .= ': ' . $summary;
        }
        return $line;
    }

    /**
     * True if the page is at most $maxDepth levels deep (1 = top-level). Walks up
     * at most $maxDepth parents, so it stays cheap on large trees.
     */
    private function withinDepth(SiteTree $page, int $maxDepth): bool
    {
        if ($maxDepth <= 0) {
            return true;
        }
        $depth = 1;
        $current = $page;
        while ($current->ParentID) {
            if (++$depth > $maxDepth) {
                return false;
            }
            $current = $current->Parent();
        }
        return true;
    }

    /**
     * A concise one-line summary: MetaDescription, else the Content field, else a
     * short summary of the Elemental block content (getElementsForSearch()),
     * word-limited to keep llms.txt concise.
     */
    private function pageSummary(SiteTree $page): string
    {
        // Metadescription-only classes: use the raw field alone (no Content/Elemental fallback).
        if ($this->isMetaDescriptionOnly($page)) {
            return $this->clean((string) $page->MetaDescription);
        }

        // Prefer the site's resolved meta description when the page provides one (convention), so the
        // summary matches the rendered <meta name="description">, including the site's own fallbacks.
        if ($page->hasMethod('getResolvedMetaDescription')) {
            return $this->clean((string) $page->getResolvedMetaDescription());
        }

        // Generic fallback: MetaDescription → Content field → Elemental blocks, word-limited.
        $summary = trim((string) $page->MetaDescription);
        if ($summary === '') {
            $text = trim(strip_tags((string) $page->dbObject('Content')));
            if ($text === '' && $page->hasMethod('getElementsForSearch')) {
                try {
                    $text = strip_tags((string) $page->getElementsForSearch());
                } catch (\Throwable $e) {
                    $text = '';
                }
            }
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text !== '') {
                $summary = (string) DBField::create_field('Text', $text)->LimitWordCount(30);
            }
        }

        return $this->clean($summary);
    }

    /**
     * True when the page's class is configured to summarise from MetaDescription only
     * (skipping the Content/Elemental fallback). Matched with instanceof, so subclasses count.
     */
    private function isMetaDescriptionOnly(SiteTree $page): bool
    {
        foreach ((array) $this->config()->get('metadescription_only_classes') as $class) {
            if ($class && $page instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalise a plain-text fragment for the index: decode HTML entities (the source is HTML,
     * the output is plain UTF-8 Markdown) and collapse whitespace.
     */
    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Build "## Heading" sections for the configured DataObject classes (catalogue items that live
     * as DataObjects under a page). Only classes yielding at least one viewable, linkable record are
     * returned.
     *
     * @return array<int, array{heading: string, rows: array<int, string>}>
     */
    private function dataObjectSections(): array
    {
        $sections = [];
        $max = (int) $this->config()->get('dataobject_max');

        foreach ((array) $this->config()->get('dataobject_classes') as $class => $heading) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            $rows = [];
            $count = 0;
            foreach ($class::get() as $record) {
                if ($max > 0 && $count >= $max) {
                    break;
                }
                if (!$record->canView()) {
                    continue;
                }
                $title = trim((string) $record->Title);
                $link = $this->recordLink($record);
                if ($title === '' || $link === null) {
                    continue;
                }
                // getLLMsSummary() may return false to opt the record out of the index entirely
                // (e.g. a sold occasion or an inactive rentable); a string is used as the summary.
                $summaryRaw = $record->hasMethod('getLLMsSummary') ? $record->getLLMsSummary() : null;
                if ($summaryRaw === false) {
                    continue;
                }
                $summary = $this->clean((string) ($summaryRaw ?? $record->MetaDescription));
                $row = '- [' . $title . '](' . $link . ')';
                if ($summary !== '') {
                    $row .= ': ' . $summary;
                }
                $rows[] = $row;
                $count++;
            }
            if ($rows !== []) {
                $sections[] = [
                    'heading' => (string) ($heading ?: $class),
                    'rows' => $rows,
                ];
            }
        }

        return $sections;
    }

    /**
     * Absolute URL for a record, or null when it has none.
     */
    private function recordLink($record): ?string
    {
        foreach (['AbsoluteLink', 'Link', 'getLink'] as $method) {
            if ($record->hasMethod($method)) {
                $url = (string) $record->$method();
                if ($url !== '') {
                    return Director::absoluteURL($url);
                }
            }
        }
        return null;
    }
}
