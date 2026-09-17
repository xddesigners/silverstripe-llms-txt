<?php

namespace XD\LLMsTxt\Extensions;

use League\HTMLToMarkdown\HtmlConverter;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\Requirements;
use XD\LLMsTxt\Controllers\LLMsTxtController;

/**
 * Serves a clean Markdown version of each page — and of a sub-record shown under a page (e.g. a
 * catalogue item served by the page's controller) — and advertises it in the page head.
 *
 * Applied to ContentController, so on every front-end page:
 *  - if the request is for the `.md` variant (URL suffix `foo/bar.md`) OR the client sends
 *    `Accept: text/markdown`, respond with the subject rendered as Markdown instead of HTML;
 *  - otherwise inject `<link rel="alternate" type="text/markdown">` (the current URL + `.md`) and
 *    `<link rel="describedby" href="/llms.txt">` into <head>.
 *
 * The "subject" is normally `$controller->data()` (the page). A controller can override it for a
 * detail view by implementing `getLLMsRecord(): ?DataObject` (e.g. resolving the occasion/model
 * from the URL), so `<page>/<action>/<slug>.md` returns that record's Markdown. The record can be
 * any DataObject with a Title and a Content or Description field; a getLLMsSummary() facts line and
 * an updateLLMsMarkdown() hook are included when present.
 *
 * No static-publish/queue dependency — Markdown is built on the fly and cached per subject +
 * locale, keyed on LastEdited so it invalidates itself on edit.
 *
 * @property \SilverStripe\CMS\Controllers\ContentController $owner
 */
class MarkdownPageExtension extends Extension
{
    public function onAfterInit(): void
    {
        $owner = $this->getOwner();
        $request = $owner->getRequest();

        if ($this->markdownWanted($request)) {
            $subject = $this->resolveSubject();
            if (!$subject || !$subject->exists()) {
                return; // nothing to render as Markdown; let the normal flow continue
            }
            if (!$subject->canView()) {
                return; // let the normal flow handle permission (login/403)
            }
            $response = HTTPResponse::create($this->subjectMarkdown($subject));
            $response->addHeader('Content-Type', 'text/markdown; charset=utf-8');
            $ttl = (int) Config::inst()->get(LLMsTxtController::class, 'cache_ttl');
            if ($ttl > 0) {
                $response->addHeader('Cache-Control', 'public, max-age=' . $ttl);
            }
            // Short-circuit the HTML render and return the Markdown response.
            throw new HTTPResponse_Exception($response);
        }

        // Normal HTML render: advertise the Markdown alternate (the current URL + .md) + the index.
        $page = $owner->data();
        if (!$page instanceof SiteTree || !$page->exists()) {
            return;
        }
        $tags = '<link rel="describedby" href="' . Convert::raw2att(Director::absoluteURL('llms.txt')) . '">';
        if ($mdUrl = $this->markdownUrl($request)) {
            $tags = '<link rel="alternate" type="text/markdown" href="' . Convert::raw2att($mdUrl) . '">'
                . "\n" . $tags;
        }
        Requirements::insertHeadTags($tags);
    }

    /**
     * The record to render: a controller-supplied detail record (getLLMsRecord()) when present,
     * otherwise the page itself.
     */
    private function resolveSubject(): ?DataObject
    {
        $owner = $this->getOwner();
        if ($owner->hasMethod('getLLMsRecord')) {
            $record = $owner->getLLMsRecord();
            if ($record instanceof DataObject && $record->exists()) {
                return $record;
            }
        }
        $page = $owner->data();
        return $page instanceof DataObject ? $page : null;
    }

    private function markdownWanted(HTTPRequest $request): bool
    {
        if (strtolower((string) $request->getExtension()) === 'md') {
            return true;
        }
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'text/markdown');
    }

    /**
     * Absolute URL of the current request's .md variant, or null for the home page (no clean .md).
     * Uses the request URL so detail views (…/presentation/<slug>) advertise their own .md.
     */
    private function markdownUrl(HTTPRequest $request): ?string
    {
        $url = trim((string) $request->getURL(), '/');
        if ($url === '') {
            return null;
        }
        return Director::absoluteURL($url . '.md');
    }

    private function subjectMarkdown(DataObject $subject): string
    {
        $cache = $this->cache();
        $key = 'md_' . i18n::get_locale() . '_' . str_replace('\\', '-', get_class($subject))
            . '_' . $subject->ID . '_' . strtotime((string) $subject->LastEdited);
        if ($cache) {
            $hit = $cache->get($key);
            if ($hit !== null) {
                return $hit;
            }
        }

        $md = '# ' . $subject->Title . "\n";
        // Prefer the site's resolved meta description (convention) so it matches the rendered <meta>
        // and inherits the site's fallbacks; else the raw field (records without one get nothing here).
        $metaDescription = $subject->hasMethod('getResolvedMetaDescription')
            ? (string) $subject->getResolvedMetaDescription()
            : ($subject->hasField('MetaDescription') ? (string) $subject->MetaDescription : '');
        if (trim($metaDescription) !== '') {
            $md .= "\n> " . trim(preg_replace('/\s+/', ' ', $metaDescription)) . "\n";
        }

        // A record's own one-line facts (brand/price/specs). Pages/products contribute via the
        // updateLLMsMarkdown() hook below instead, so this is skipped for them.
        if ($subject->hasMethod('getLLMsSummary')) {
            $summary = $subject->getLLMsSummary();
            if (is_string($summary) && trim($summary) !== '') {
                $md .= "\n" . trim($summary) . "\n";
            }
        }

        // Let the subject contribute structured Markdown (e.g. a product's brand/price/SKU or a
        // series page listing its models), after the title/summary and before the body.
        // invokeWithExtensions() (not extend()) so a method on the class ITSELF is called too.
        // Guarded so a buggy hook can't turn the .md into a 500 (any Markdown already appended stays).
        try {
            $subject->invokeWithExtensions('updateLLMsMarkdown', $md);
        } catch (\Throwable $e) {
            // ignore — render the .md without the hook's contribution
        }

        // Prefer Elemental block content: on block-based pages the blocks ARE the page content, while the
        // Content field is often a vestigial default.
        $html = $this->blocksForMarkdown($subject);
        // Fall back to the Content / Description field when the record has no (renderable) blocks.
        if (trim(strip_tags($html)) === '' && $subject->hasField('Content')) {
            $html = (string) $subject->dbObject('Content');
        }
        if (trim(strip_tags($html)) === '' && $subject->hasField('Description')) {
            $html = (string) $subject->dbObject('Description');
        }
        if (trim(strip_tags($html)) !== '') {
            try {
                $converter = new HtmlConverter([
                    'strip_tags' => true,
                    'hard_break' => true,
                    'remove_nodes' => 'script style',
                ]);
                $md .= "\n" . trim($converter->convert($html)) . "\n";
            } catch (\Throwable $e) {
                // leave the body out rather than fail the response
            }
        }

        // Decode HTML entities left over from the source HTML (e.g. &amp;, &nbsp;, &#039;) so the
        // Markdown output is clean UTF-8 text rather than carrying entity references.
        $md = html_entity_decode(trim($md), ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n";

        if ($cache) {
            $ttl = (int) Config::inst()->get(LLMsTxtController::class, 'cache_ttl');
            if ($ttl > 0) {
                $cache->set($key, $md, $ttl);
            }
        }
        return $md;
    }

    /**
     * Collect the page's Elemental block content as HTML for the Markdown body. Each block is rendered with
     * its own guard, so a single block that assumes full page scope can't blank the whole page (which would
     * otherwise silently fall back to the vestigial Content field). Respects each block's search-indexable
     * flag. Returns '' for records without an Elemental area, so the caller falls back to Content/Description.
     */
    private function blocksForMarkdown(DataObject $subject): string
    {
        if (!$subject->hasMethod('getElementsForSearch')) {
            return '';
        }
        try {
            $area = $subject->ElementalArea();
            if (!$area || !$area->exists()) {
                return '';
            }
            $parts = [];
            foreach ($area->Elements() as $element) {
                if ($element->hasMethod('getSearchIndexable') && !$element->getSearchIndexable()) {
                    continue;
                }
                try {
                    $parts[] = trim((string) $element->getContentForSearchIndex());
                } catch (\Throwable $e) {
                    // Skip a block that can't render in this context rather than losing the whole page.
                }
            }
            return trim(implode("\n\n", array_filter($parts)));
        } catch (\Throwable $e) {
            // Last resort: the aggregate method (all-or-nothing, but guarded).
            try {
                return (string) $subject->getElementsForSearch();
            } catch (\Throwable $e2) {
                return '';
            }
        }
    }

    private function cache(): ?CacheInterface
    {
        try {
            return Injector::inst()->get(CacheInterface::class . '.llmsTxt');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
