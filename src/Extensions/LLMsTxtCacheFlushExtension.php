<?php

namespace XD\LLMsTxt\Extensions;

use SilverStripe\Core\Extension;
use XD\LLMsTxt\Controllers\LLMsTxtController;

/**
 * Clears the llms.txt cache when a page is published or unpublished, so the index (which is cached
 * per locale and does not key on content versions) reflects the change immediately instead of
 * waiting for the TTL. Applied to SiteTree.
 *
 * @property \SilverStripe\CMS\Model\SiteTree $owner
 */
class LLMsTxtCacheFlushExtension extends Extension
{
    public function onAfterPublish(): void
    {
        LLMsTxtController::flush();
    }

    public function onAfterUnpublish(): void
    {
        LLMsTxtController::flush();
    }
}
