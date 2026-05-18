<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Service;

use CacheBoost\Warmer\Model\Config;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Psr\Log\LoggerInterface;

/**
 * Collects cache tags during the request and resolves them to URLs at flush time.
 * Acts as a per-request buffer: multiple flush events are batched into a single API call.
 * Shared as a singleton by the DI container.
 */
class UrlCollector
{
    /** @var array<string, true> */
    private array $pendingTags = [];
    private bool $fullFlushPending = false;
    private bool $flushed = false;

    public function __construct(
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {}

    public function markFullFlush(): void
    {
        $this->fullFlushPending = true;
    }

    public function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $this->pendingTags[$tag] = true;
            }
        }
    }

    /**
     * Called once at the end of the request (controller_front_send_response_before).
     * Sends a single API call regardless of how many flush events fired.
     */
    public function flush(): void
    {
        if ($this->flushed || !$this->config->isConfigured()) {
            return;
        }
        $this->flushed = true;

        // Full flush takes priority: trigger the configured scheduled Boost.
        if ($this->fullFlushPending) {
            $boostId = $this->config->getBoostId();
            if ($boostId > 0) {
                $this->apiClient->triggerBoostRun($boostId);
                $this->logger->info("CacheBoost: triggered boost run #{$boostId} (full flush).");
            } else {
                $this->logger->info(
                    'CacheBoost: full flush event detected but no Boost ID is configured — skipped. ' .
                    'Configure a Boost ID under Stores → Configuration → CacheBoost → Flush total.'
                );
            }
            return;
        }

        if (empty($this->pendingTags)) {
            return;
        }

        // In full_only mode, tag events also trigger the scheduled Boost.
        if ($this->config->getMode() === 'full_only') {
            $boostId = $this->config->getBoostId();
            if ($boostId > 0) {
                $this->apiClient->triggerBoostRun($boostId);
                $this->logger->info("CacheBoost: triggered boost run #{$boostId} (full_only mode).");
            }
            return;
        }

        // Smart mode: resolve tags to URLs and trigger a targeted inline warm.
        $urls = $this->resolveTagsToUrls(array_keys($this->pendingTags));
        if (!empty($urls)) {
            $this->apiClient->triggerWarm($urls);
            $this->logger->info(sprintf(
                'CacheBoost: triggered inline warm for %d URL(s) from %d tag(s).',
                count($urls),
                count($this->pendingTags)
            ));
        }
    }

    /**
     * Resolves Magento cache tags to absolute URLs across all active stores.
     *
     * Supported tag patterns:
     *   cat_p_{id}  → product
     *   cat_c_{id}  → category
     *   cms_p_{id}  → CMS page
     *   cms_b_{id}  → CMS block (no URL, skipped)
     */
    private function resolveTagsToUrls(array $tags): array
    {
        $entities = [];
        foreach ($tags as $tag) {
            if (preg_match('/^cat_p_(\d+)$/', $tag, $m)) {
                $entities[] = [UrlRewrite::ENTITY_TYPE => 'product',  UrlRewrite::ENTITY_ID => (int) $m[1]];
            } elseif (preg_match('/^cat_c_(\d+)$/', $tag, $m)) {
                $entities[] = [UrlRewrite::ENTITY_TYPE => 'category', UrlRewrite::ENTITY_ID => (int) $m[1]];
            } elseif (preg_match('/^cms_p_(\d+)$/', $tag, $m)) {
                $entities[] = [UrlRewrite::ENTITY_TYPE => 'cms-page', UrlRewrite::ENTITY_ID => (int) $m[1]];
            }
            // cms_b_{id} (blocks) have no URL — intentionally skipped.
        }

        if (empty($entities)) {
            return [];
        }

        $urls = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $baseUrl = rtrim($store->getBaseUrl(), '/');

            foreach ($entities as $criterion) {
                try {
                    $rewrites = $this->urlFinder->findAllByData(array_merge($criterion, [
                        UrlRewrite::STORE_ID      => (int) $store->getId(),
                        UrlRewrite::REDIRECT_TYPE => 0, // canonical only, not redirects
                    ]));
                    foreach ($rewrites as $rewrite) {
                        $urls[] = $baseUrl . '/' . ltrim($rewrite->getRequestPath(), '/');
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'CacheBoost: URL resolution failed for ' .
                        json_encode($criterion) . ' — ' . $e->getMessage()
                    );
                }
            }
        }

        return array_values(array_unique($urls));
    }
}
