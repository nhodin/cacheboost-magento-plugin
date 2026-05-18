<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Observer;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\UrlCollector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Handles granular cache invalidation: clean_cache_by_tags.
 *
 * Magento dispatches this event in two forms:
 *   1. $event->getObject() is a model implementing IdentityInterface (e.g. product save)
 *   2. $event->getObject() is a plain array of tag strings (e.g. from TagScope::clean)
 *
 * Tags are buffered in UrlCollector and resolved to URLs only once at
 * controller_front_send_response_before, avoiding redundant lookups when multiple
 * clean_cache_by_tags events fire in cascade during a single request.
 */
class FlushByTags implements ObserverInterface
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

        $object = $observer->getEvent()->getObject();

        if (is_array($object)) {
            $tags = $object;
        } elseif ($object !== null && method_exists($object, 'getIdentities')) {
            $tags = $object->getIdentities();
        } else {
            return;
        }

        if (!empty($tags)) {
            $this->collector->collectTags($tags);
        }
    }
}
