<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Account;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\ConfigWriter;
use CacheBoost\Warmer\Model\StoreDomain;
use CacheBoost\Warmer\Model\Validation\SiteValidation;
use CacheBoost\Warmer\Service\AccountApi;
use Psr\Log\LoggerInterface;

/**
 * Everything the WordPress plugin does after "Connect", "Test connection" or saving a new key,
 * so the merchant never copies a Site ID, a region list or a Boost ID by hand:
 *
 *   1. GET /v1/me: the key works, its scopes, the regions of the account;
 *   2. the CacheBoost site of this storefront (given by Connect, else matched by domain);
 *   3. the regions to warm from (kept when still offered, else a sensible default);
 *   4. automatic domain validation (needs sites:write);
 *   5. the full-site Boost: reused when the site has one, created from the Magento sitemap on Connect.
 *
 * Blocking: admin only.
 */
class AccountSetup
{
    /** Scopes without which warming or the admin panels do not work. */
    public const REQUIRED_SCOPES = ['boosts:read', 'boosts:write', 'runs:read'];

    /** Default region when the merchant never chose one and it is offered. */
    private const PREFERRED_REGIONS = ['fr', 'us'];

    public function __construct(
        private readonly Config $config,
        private readonly ConfigWriter $writer,
        private readonly AccountApi $api,
        private readonly StoreDomain $storeDomain,
        private readonly SiteValidation $validation,
        private readonly SitemapLocator $sitemapLocator,
        private readonly AccountStatus $accountStatus,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param int  $siteId      site returned by Connect, 0 to match the storefront domain
     * @param bool $createBoost create the full-site Boost when the site has none (Connect only)
     * @return array{success: bool, message: string, notices: string[], site_id?: int, validated?: bool, boost_id?: int}
     */
    public function run(string $apiKey, int $siteId = 0, bool $createBoost = false): array
    {
        $notices = [];

        $me = $this->api->me($apiKey);
        if ($me['status'] === 401) {
            return $this->failure((string) __('Error 401 — invalid or expired API key.'));
        }
        if ($me['status'] === 403 && ($me['body']['code'] ?? '') === 'email_unverified') {
            return $this->failure((string) __('Cache warming is paused because the email address of your CacheBoost account is not verified.'));
        }
        if ($me['status'] !== 200 || !is_array($me['body'])) {
            return $this->failure($me['status'] === 0
                ? (string) __('Network error: could not reach the CacheBoost API.')
                : (string) __('HTTP error %1', $me['status']));
        }

        $scopes = array_values(array_filter((array) ($me['body']['scopes'] ?? []), 'is_string'));
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));
        if ($missing !== []) {
            return $this->failure((string) __('Connected, but missing required scopes: %1. Please regenerate your API key with the correct permissions.', implode(', ', $missing)));
        }

        // ── Site ────────────────────────────────────────────────────────────
        $site = null;
        if ($siteId <= 0) {
            if (!in_array('sites:read', $scopes, true)) {
                $siteId = $this->config->getSiteId();
                if ($siteId <= 0) {
                    return $this->failure((string) __('Enter the Site ID, or use a key with the sites:read scope so the site is found automatically.'));
                }
            } else {
                $sites = $this->api->sites($apiKey);
                $site = is_array($sites['body']) ? $this->storeDomain->matchSite(array_is_list($sites['body']) ? $sites['body'] : (array) ($sites['body']['data'] ?? [])) : null;
                if ($site === null) {
                    return $this->failure((string) __('Connected, but %1 is not registered in your CacheBoost account. Use "Connect to CacheBoost", or add the domain at app.cache-boost.com.', $this->storeDomain->getDomain()));
                }
                $siteId = (int) $site['id'];
            }
        }
        if ($siteId !== $this->config->getSiteId()) {
            $this->writer->saveSiteId($siteId);
        }

        // ── Regions ─────────────────────────────────────────────────────────
        $available = array_values(array_filter((array) ($me['body']['regions'] ?? []), 'is_string'));
        if ($available !== []) {
            $this->writer->saveRegions($available, self::pickRegions($this->config->getRegions(), $available));
        }

        // ── Domain validation ───────────────────────────────────────────────
        $validated = !empty($site['validated']);
        if (in_array('sites:write', $scopes, true)) {
            $validation = $this->validation->ensure($apiKey, $siteId, $site);
            $validated = $validation['success'];
            if ($validation['attempted'] && $validation['success']) {
                $notices[] = (string) __('Your domain has been validated in CacheBoost.');
            } elseif (!$validation['success']) {
                $notices[] = (string) __('%1 could not be validated automatically (%2). Cache warming requests are rejected until it is: see the validation methods at app.cache-boost.com.', $this->storeDomain->getDomain(), $validation['message'] ?? '');
            }
        } elseif ($site !== null && !$validated) {
            $notices[] = (string) __('Your domain is not validated in CacheBoost. Add the sites:write scope to your API key to validate it automatically.');
        }

        // ── Full-site Boost ─────────────────────────────────────────────────
        $boostId = $this->config->getBoostId();
        if ($boostId <= 0) {
            $boostId = $this->ensureFullWarmingBoost($apiKey, $siteId, $this->config->getRegions(), $createBoost);
            if ($boostId > 0) {
                $this->writer->saveBoostId($boostId);
                $notices[] = (string) __('Full flushes will run Boost #%1.', $boostId);
            } elseif ($createBoost) {
                $notices[] = (string) __('The full-site Boost could not be created: full flushes are skipped until a Boost ID is set.');
            }
        }

        $this->accountStatus->clear();

        return [
            'success'   => true,
            'message'   => (string) __('Connection successful. Site #%1 (%2) is set up.', $siteId, $this->storeDomain->getDomain()),
            'notices'   => $notices,
            'site_id'   => $siteId,
            'validated' => $validated,
            'boost_id'  => $boostId,
        ];
    }

    /**
     * Regions kept when the account still offers them; otherwise the preferred default, else every
     * offered region is left to CacheBoost (empty list).
     *
     * @param string[] $current
     * @param string[] $available
     * @return string[]
     */
    public static function pickRegions(array $current, array $available): array
    {
        $kept = array_values(array_intersect($current, $available));
        if ($kept !== []) {
            return $kept;
        }
        foreach (self::PREFERRED_REGIONS as $region) {
            if (in_array($region, $available, true)) {
                return [$region];
            }
        }
        return [];
    }

    /**
     * Full flushes need a Boost built from the sitemap. Reuses the first Boost of the site,
     * otherwise (when allowed) registers the sitemap and creates one. Returns 0 on failure.
     *
     * @param string[] $regions
     */
    public function ensureFullWarmingBoost(string $apiKey, int $siteId, array $regions, bool $create): int
    {
        $boosts = $this->api->boosts($apiKey);
        if ($boosts['status'] === 200 && is_array($boosts['body'])) {
            $list = array_is_list($boosts['body']) ? $boosts['body'] : (array) ($boosts['body']['data'] ?? []);
            foreach ($list as $boost) {
                if ((int) ($boost['site_id'] ?? 0) === $siteId && (int) ($boost['id'] ?? 0) > 0) {
                    return (int) $boost['id'];
                }
            }
        }
        if (!$create) {
            return 0;
        }

        $sitemapUrl = $this->sitemapLocator->getUrl();
        if ($sitemapUrl === '') {
            return 0;
        }
        $sitemapId = $this->registerSitemap($apiKey, $siteId, $sitemapUrl);
        if ($sitemapId === 0) {
            $this->logger->error('CacheBoost: could not register sitemap ' . $sitemapUrl);
            return 0;
        }

        $boost = [
            'name'        => 'Magento — full site',
            'site_id'     => $siteId,
            'source_type' => 'sitemap',
            'sitemap_ids' => [$sitemapId],
        ];
        $regions = $regions ?: $this->config->getAvailableRegions();
        if ($regions !== []) {
            $boost['region'] = [$regions[0]];
        }
        $created = $this->api->createBoost($apiKey, $boost);
        $boostId = (int) ($created['body']['id'] ?? 0);
        if ($created['status'] !== 201 || $boostId <= 0) {
            $this->logger->error(sprintf('CacheBoost: could not create the full-site Boost (HTTP %d)', $created['status']));
            return 0;
        }

        $this->logger->info(sprintf('CacheBoost: full-site Boost #%d created from %s', $boostId, $sitemapUrl));
        return $boostId;
    }

    private function registerSitemap(string $apiKey, int $siteId, string $url): int
    {
        $created = $this->api->createSitemap($apiKey, $siteId, $url);
        if ($created['status'] === 201) {
            return (int) ($created['body']['id'] ?? 0);
        }
        if ($created['status'] === 409) {
            // Already registered (a previous connection): look it up.
            $list = $this->api->sitemaps($apiKey, $siteId);
            $items = is_array($list['body']) ? (array_is_list($list['body']) ? $list['body'] : (array) ($list['body']['data'] ?? [])) : [];
            foreach ($items as $sitemap) {
                if (($sitemap['url'] ?? '') === $url) {
                    return (int) ($sitemap['id'] ?? 0);
                }
            }
        }
        return 0;
    }

    /** @return array{success: false, message: string, notices: string[]} */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'notices' => []];
    }
}
