<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Service;

use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Psr\Log\LoggerInterface;

/**
 * Resolves Magento cache tags to absolute storefront URLs.
 *
 * Runs inside the queue consumer (bin/magento queue:consumers:start), never on
 * the request path: each tag costs one UrlFinder query per active store.
 */
class TagUrlResolver
{
    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Resolves Magento cache tags to absolute URLs across all active stores.
     *
     * Supported tag patterns:
     *   cat_p_{id}  → product
     *   cat_c_{id}  → category
     *   cms_p_{id}  → CMS page
     *   cms_b_{id}  → CMS block (no URL, skipped)
     *
     * @param string[] $tags
     * @return string[]
     */
    public function resolve(array $tags): array
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
