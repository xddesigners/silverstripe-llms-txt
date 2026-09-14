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
use SilverStripe\View\Requirements;
use XD\LLMsTxt\Controllers\LLMsTxtController;

/**
 * Serves a clean Markdown version of each page and advertises it in the page head.
 *
 * Applied to ContentController, so on every front-end page:
 *  - if the request is for the `.md` variant (URL suffix `foo/bar.md`) OR the
 *    client sends `Accept: text/markdown`, respond with the page as Markdown
 *    (Title + MetaDescription + body, incl. Elemental blocks) instead of HTML;
 *  - otherwise inject `<link rel="alternate" type="text/markdown">` (pointing at
 *    the .md variant) and `<link rel="describedby" href="/llms.txt">` into <head>.
 *
 * No static-publish/queue dependency — Markdown is built on the fly and cached
 * per page + locale, keyed on LastEdited so it invalidates itself on edit.
 *
 * @property \SilverStripe\CMS\Controllers\ContentController $owner
 */
class MarkdownPageExtension extends Extension
{
    public function onAfterInit(): void
    {
        $owner = $this->getOwner();
        $request = $owner->getRequest();
        $page = $owner->data();

        if (!$page instanceof SiteTree || !$page->exists()) {
            return;
        }

        if ($this->markdownWanted($request)) {
            if (!$page->canView()) {
                return; // let the normal flow handle permission (login/403)
            }
            $response = HTTPResponse::create($this->pageMarkdown($page));
            $response->addHeader('Content-Type', 'text/markdown; charset=utf-8');
            $ttl = (int) Config::inst()->get(LLMsTxtController::class, 'cache_ttl');
            if ($ttl > 0) {
                $response->addHeader('Cache-Control', 'public, max-age=' . $ttl);
            }
            // Short-circuit the HTML render and return the Markdown response.
            throw new HTTPResponse_Exception($response);
        }

        // Normal HTML render: advertise the Markdown alternate + the llms.txt index.
        $tags = '<link rel="describedby" href="' . Convert::raw2att(Director::absoluteURL('llms.txt')) . '">';
        if ($mdUrl = $this->markdownUrl($page)) {
            $tags = '<link rel="alternate" type="text/markdown" href="' . Convert::raw2att($mdUrl) . '">'
                . "\n" . $tags;
        }
        Requirements::insertHeadTags($tags);
    }

    private function markdownWanted(HTTPRequest $request): bool
    {
        if (strtolower((string) $request->getExtension()) === 'md') {
            return true;
        }
        return str_contains(strtolower((string) $request->getHeader('Accept')), 'text/markdown');
    }

    /**
     * Absolute URL of the page's .md variant, or null for the home page (which has
     * no clean `.md` route).
     */
    private function markdownUrl(SiteTree $page): ?string
    {
        $link = rtrim((string) $page->Link(), '/');
        if ($link === '') {
            return null;
        }
        return Director::absoluteURL($link . '.md');
    }

    private function pageMarkdown(SiteTree $page): string
    {
        $cache = $this->cache();
        $key = 'md_' . i18n::get_locale() . '_' . $page->ID . '_' . strtotime((string) $page->LastEdited);
        if ($cache) {
            $hit = $cache->get($key);
            if ($hit !== null) {
                return $hit;
            }
        }

        $md = '# ' . $page->Title . "\n";
        if (trim((string) $page->MetaDescription) !== '') {
            $md .= "\n> " . trim(preg_replace('/\s+/', ' ', $page->MetaDescription)) . "\n";
        }

        $html = (string) $page->dbObject('Content');
        if (trim(strip_tags($html)) === '' && $page->hasMethod('getElementsForSearch')) {
            // getElementsForSearch() renders blocks and can throw for blocks that
            // assume full page scope; never let that fatal the .md response.
            try {
                $html = (string) $page->getElementsForSearch();
            } catch (\Throwable $e) {
                $html = '';
            }
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

        $md = trim($md) . "\n";

        if ($cache) {
            $ttl = (int) Config::inst()->get(LLMsTxtController::class, 'cache_ttl');
            if ($ttl > 0) {
                $cache->set($key, $md, $ttl);
            }
        }
        return $md;
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
