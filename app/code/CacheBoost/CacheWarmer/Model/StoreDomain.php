<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The storefront a CacheBoost site stands for: the default store view, whose host is the
 * domain registered in CacheBoost (the module supports one Site ID per installation).
 */
class StoreDomain
{
    public function __construct(private readonly StoreManagerInterface $storeManager) {}

    /** Secure base URL of the default store view, with a trailing slash; '' if unavailable. */
    public function getBaseUrl(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            if ($store === null) {
                return '';
            }
            return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/') . '/';
        } catch (\Throwable) {
            return '';
        }
    }

    /** Lower-case host of the default store view ("www.boutique.fr"); '' if unavailable. */
    public function getDomain(): string
    {
        return strtolower((string) parse_url($this->getBaseUrl(), PHP_URL_HOST));
    }

    /**
     * The CacheBoost site matching this storefront, among GET /v1/sites. A registered domain may
     * carry a scheme or a trailing slash, or omit "www.".
     *
     * @param array<int, array<string, mixed>> $sites
     */
    public function matchSite(array $sites): ?array
    {
        $domain = $this->getDomain();
        if ($domain === '') {
            return null;
        }
        $bare = preg_replace('/^www\./', '', $domain);
        $fallback = null;
        foreach ($sites as $site) {
            $registered = strtolower(rtrim((string) preg_replace('#^https?://#i', '', (string) ($site['domain'] ?? '')), '/'));
            if ($registered === $domain) {
                return $site;
            }
            if ($fallback === null && preg_replace('/^www\./', '', $registered) === $bare) {
                $fallback = $site;
            }
        }
        return $fallback;
    }
}
