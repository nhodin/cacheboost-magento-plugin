<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Observer;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\UrlCollector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Handles full-cache flush events:
 *   - adminhtml_cache_flush_all   (Flush All Cache from admin)
 *   - adminhtml_cache_flush_system (Flush Storage from admin)
 *   - clean_cache_after_reindex   (post-reindex)
 *
 * Marks the collector for a full flush. The actual API call is deferred to
 * SendBufferedUrls so multiple events in the same request produce one call.
 */
class FlushAll implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlCollector $collector
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $this->collector->markFullFlush();
    }
}
