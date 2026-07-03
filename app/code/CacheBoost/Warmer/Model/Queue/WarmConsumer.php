<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Queue;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use CacheBoost\Warmer\Service\TagUrlResolver;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the cacheboost.warm queue (see etc/queue_consumer.xml).
 *
 * All expensive work — resolving tags to URLs (one UrlFinder query per tag per
 * store) and the HTTP calls to the CacheBoost API — happens here, in a
 * background process, so the request that invalidated the cache never waits.
 *
 * Payload contract (JSON, produced by Service\UrlCollector):
 *   full_flush  bool      full-cache flush event → trigger the scheduled Boost
 *   tags        string[]  invalidated cache tags (already deduplicated, capped)
 *   truncated   bool      the tag buffer exceeded the cap and was cut off
 */
class WarmConsumer
{
    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly TagUrlResolver $resolver,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Entry point invoked by the queue framework for each message.
     * Must never throw: a poison message would otherwise be redelivered forever.
     */
    public function process(string $message): void
    {
        try {
            $this->doProcess($message);
        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: warm consumer failed — ' . $e->getMessage());
        }
    }

    private function doProcess(string $message): void
    {
        $payload = $this->json->unserialize($message);
        if (!is_array($payload)) {
            $this->logger->warning('CacheBoost: discarded malformed warm message.');
            return;
        }

        // Re-checked at consume time: the module may have been disabled or
        // reconfigured between publish and consumption.
        if (!$this->config->isConfigured()) {
            return;
        }

        if (!empty($payload['full_flush'])) {
            $this->runFullBoost('full flush');
            return;
        }

        $tags = array_values(array_filter((array) ($payload['tags'] ?? []), 'is_string'));
        if (empty($tags)) {
            return;
        }

        // In full_only mode, tag events also trigger the scheduled Boost.
        if ($this->config->getMode() === 'full_only') {
            $this->runFullBoost('full_only mode');
            return;
        }

        $truncated = !empty($payload['truncated']);
        if ($truncated && $this->config->getBoostId() > 0) {
            // Too many tags were invalidated to warm them individually:
            // a full Boost run covers everything the dropped tags would have.
            $this->runFullBoost('tag buffer exceeded the cap');
            return;
        }
        if ($truncated) {
            $this->logger->warning(
                'CacheBoost: the tag buffer exceeded the cap and no Boost ID is configured — ' .
                'only the first ' . count($tags) . ' tags will be warmed. Configure a Boost ID under ' .
                'Stores → Configuration → CacheBoost → Full Flush to warm the full site instead.'
            );
        }

        $urls = $this->resolver->resolve($tags);
        if (!empty($urls)) {
            $this->apiClient->triggerWarm($urls);
            $this->logger->info(sprintf(
                'CacheBoost: triggered inline warm for %d URL(s) from %d tag(s).',
                count($urls),
                count($tags)
            ));
        }
    }

    private function runFullBoost(string $reason): void
    {
        $boostId = $this->config->getBoostId();
        if ($boostId > 0) {
            $this->apiClient->triggerBoostRun($boostId);
            $this->logger->info("CacheBoost: triggered boost run #{$boostId} ({$reason}).");
            return;
        }

        $this->logger->info(
            "CacheBoost: {$reason} event received but no Boost ID is configured — skipped. " .
            'Configure a Boost ID under Stores → Configuration → CacheBoost → Full Flush.'
        );
    }
}
