<?php

namespace XD\LLMsTxt\Controllers;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
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
class LLMsTxtController extends Controller
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

        return trim(preg_replace('/\s+/', ' ', $summary));
    }
}
