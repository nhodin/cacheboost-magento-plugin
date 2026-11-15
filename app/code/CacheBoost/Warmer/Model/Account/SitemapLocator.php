<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Account;

use CacheBoost\Warmer\Model\StoreDomain;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * URL of the storefront sitemap the full-site Boost is built from: the Google Sitemap generated
 * for the default store view (Marketing → SEO & Search → Site Map), else /sitemap.xml.
 */
class SitemapLocator
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreDomain $storeDomain
    ) {}

    public function getUrl(): string
    {
        $base = $this->storeDomain->getBaseUrl();
        if ($base === '') {
            return '';
        }

        try {
            $storeId = (int) $this->storeManager->getDefaultStoreView()?->getId();
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('sitemap');
            if ($storeId > 0 && $connection->isTableExists($table)) {
                $row = $connection->fetchRow(
                    $connection->select()
                        ->from($table, ['sitemap_path', 'sitemap_filename'])
                        ->where('store_id = ?', $storeId)
                        ->order('sitemap_id ASC')
                        ->limit(1)
                );
                if (is_array($row) && !empty($row['sitemap_filename'])) {
                    $path = trim((string) $row['sitemap_path'], '/');
                    return $base . ($path !== '' ? $path . '/' : '') . ltrim((string) $row['sitemap_filename'], '/');
                }
            }
        } catch (\Throwable) {
            // Magento_Sitemap disabled or table unreadable: fall back to the conventional location.
        }

        return $base . 'sitemap.xml';
    }
}
