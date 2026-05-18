<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Observer;

use CacheBoost\Warmer\Service\UrlCollector;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Fires on controller_front_send_response_before — once per HTTP request.
 * Flushes all buffered tags/flags to the CacheBoost API in a single call.
 */
class SendBufferedUrls implements ObserverInterface
{
    public function __construct(private readonly UrlCollector $collector) {}

    public function execute(Observer $observer): void
    {
        $this->collector->flush();
    }
}
