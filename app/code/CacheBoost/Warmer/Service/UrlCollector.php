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
 *
 * Tag resolution is capped at MAX_TAGS: each tag costs one UrlFinder query per
 * store, so an unbounded buffer (e.g. a partial reindex invalidating tens of
 * thousands of tags) would hammer the database at shutdown. Above the cap the
 * collector falls back to the configured scheduled Boost, or truncates when no
 * Boost ID is configured.
 */
class UrlCollector
{
    /**
     * Maximum number of tags resolved to URLs in a single flush. Each tag is
     * one UrlFinder query per active store; beyond this we fall back to a full
     * Boost run (or truncate) instead of flooding the DB.
     */
    private const MAX_TAGS = 500;

    /** @var array<string, true> */
    private array $pendingTags = [];
    private bool $fullFlushPending = false;
    private bool $flushed = false;
    private bool $shutdownRegistered = false;

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
        $this->registerShutdownFallback();
    }

    public function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $this->pendingTags[$tag] = true;
            }
        }
        if (!empty($this->pendingTags)) {
            $this->registerShutdownFallback();
        }
    }

    /**
     * controller_front_send_response_before never fires in CLI/cron contexts
     * (bin/magento indexer:reindex, cron jobs…), so a shutdown function is the
     * only guaranteed flush point there. In HTTP requests the event observer
     * flushes first and this becomes a no-op thanks to the $flushed guard.
     */
    private function registerShutdownFallback(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'flush']);
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
        $tags = array_keys($this->pendingTags);

        // Resolving each tag costs one UrlFinder query per store; above the cap
        // a full Boost run is cheaper for everyone than thousands of DB queries.
        if (count($tags) > self::MAX_TAGS) {
            $boostId = $this->config->getBoostId();
            if ($boostId > 0) {
                $this->apiClient->triggerBoostRun($boostId);
                $this->logger->info(sprintf(
                    'CacheBoost: %d invalidated tags exceed the limit of %d — ' .
                    'falling back to full boost run #%d instead of resolving them individually.',
                    count($tags),
                    self::MAX_TAGS,
                    $boostId
                ));
                return;
            }
            $this->logger->warning(sprintf(
                'CacheBoost: %d invalidated tags exceed the limit of %d and no Boost ID is configured — ' .
                'only the first %d tags will be resolved. Configure a Boost ID under ' .
                'Stores → Configuration → CacheBoost → Flush total to warm the full site instead.',
                count($tags),
                self::MAX_TAGS,
                self::MAX_TAGS
            ));
            $tags = array_slice($tags, 0, self::MAX_TAGS);
        }

        $urls = $this->resolveTagsToUrls($tags);
        if (!empty($urls)) {
            $this->apiClient->triggerWarm($urls);
            $this->logger->info(sprintf(
                'CacheBoost: triggered inline warm for %d URL(s) from %d tag(s).',
                count($urls),
                count($tags)
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

        // The API rejects the whole batch (422) if any URL is outside the domain
        // registered for the CacheBoost site. The default store view's host is our
        // best local proxy for that domain: skip stores served on other domains.
        $referenceHost = null;
        try {
            $referenceHost = parse_url(
                $this->storeManager->getDefaultStoreView()->getBaseUrl(),
                PHP_URL_HOST
            ) ?: null;
        } catch (\Throwable) {
            // No default store view available — skip host filtering.
        }

        $urls = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $baseUrl = rtrim($store->getBaseUrl(), '/');

            $host = parse_url($store->getBaseUrl(), PHP_URL_HOST);
            if ($referenceHost !== null && $host !== $referenceHost) {
                $this->logger->debug(
                    "CacheBoost: store #{$store->getId()} skipped — host {$host} differs from site domain {$referenceHost}."
                );
                continue;
            }

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
