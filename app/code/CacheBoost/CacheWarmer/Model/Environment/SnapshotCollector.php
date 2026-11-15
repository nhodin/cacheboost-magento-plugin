<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Environment;

use CacheBoost\CacheWarmer\Model\Config;
use Magento\Framework\App\Cache\StateInterface as CacheState;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the cache configuration snapshot sent for CacheBoost's cache diagnostics (API contract
 * §3.1, "platform": "magento"): versions, deploy mode, full page cache, cache types, cache and
 * session backends, store layout, cache-related modules and the CacheBoost settings.
 *
 * Only whitelisted facts are read, and each is reduced to a version, an enum, a boolean or a
 * count: env.php also holds database, Redis and Varnish credentials and hosts, and none of them
 * may leave the server. The API revalidates the same whitelist.
 */
class SnapshotCollector
{
    public const MODULE_NAME = 'CacheBoost_CacheWarmer';

    /** Third-party modules that change how pages are cached or warmed, reported when installed. */
    public const CACHE_MODULES = [
        'Fastly_Cdn',
        'Amasty_Fpc',
        'Mirasvit_CacheWarmer',
        'Litespeed_Litemage',
    ];

    /** system/full_page_cache/caching_application: 1 built-in, 2 Varnish, 42 Fastly (Fastly_Cdn). */
    private const FPC_APPLICATIONS = ['1' => 'builtin', '2' => 'varnish', '42' => 'fastly'];

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly AppState $appState,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly CacheState $cacheState,
        private readonly TypeListInterface $cacheTypeList,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleListInterface $moduleList,
        private readonly FullModuleList $fullModuleList,
        private readonly PackageInfo $packageInfo
    ) {}

    /** @return array<string, mixed> */
    public function collect(): array
    {
        return [
            'platform'             => 'magento',
            'plugin_version'       => $this->moduleVersion(self::MODULE_NAME),
            'magento_version'      => $this->safe(fn () => (string) $this->productMetadata->getVersion()),
            'magento_edition'      => $this->safe(fn () => self::edition((string) $this->productMetadata->getEdition())),
            'php_version'          => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'deploy_mode'          => $this->deployMode(),
            'adobe_commerce_cloud' => getenv('MAGENTO_CLOUD_PROJECT') !== false || getenv('MAGENTO_CLOUD_ENVIRONMENT') !== false,
            'full_page_cache'      => $this->fullPageCache(),
            'cache_types'          => $this->cacheTypes(),
            'cache_backend'        => [
                'default'    => self::cacheBackend($this->deploymentValue('cache/frontend/default/backend')),
                'page_cache' => self::cacheBackend($this->deploymentValue('cache/frontend/page_cache/backend')),
            ],
            'session_storage'      => self::sessionStorage($this->deploymentValue('session/save')),
            'stores'               => $this->stores(),
            'cache_modules'        => $this->cacheModules(),
            'cacheboost'           => [
                'warm_mode'       => $this->config->getMode() === 'full_only' ? 'full_only' : 'smart',
                'full_warming'    => $this->config->getBoostId() > 0,
                'processing_mode' => $this->config->getProcessingMode(),
            ],
        ];
    }

    /** Edition reported by ProductMetadata: Community, Enterprise (Adobe Commerce) or B2B. */
    public static function edition(string $edition): ?string
    {
        return match (strtolower($edition)) {
            'community' => 'community',
            'enterprise', 'b2b' => 'commerce',
            default => null,
        };
    }

    /** Cache backend class from env.php, reduced to its kind; unset means Magento's file backend. */
    public static function cacheBackend(mixed $backend): string
    {
        $backend = is_string($backend) ? strtolower($backend) : '';
        return match (true) {
            $backend === '', str_contains($backend, 'file') => 'file',
            str_contains($backend, 'valkey') => 'valkey',
            str_contains($backend, 'redis') => 'redis',
            str_contains($backend, 'memcache') => 'memcached',
            str_contains($backend, 'database') => 'database',
            default => 'other',
        };
    }

    /** session/save from env.php: files (default), db, redis, memcache(d). */
    public static function sessionStorage(mixed $save): string
    {
        $save = is_string($save) ? strtolower($save) : '';
        return match (true) {
            $save === '', $save === 'files' => 'files',
            $save === 'db' => 'db',
            $save === 'redis' => 'redis',
            str_starts_with($save, 'memcache') => 'memcached',
            default => 'other',
        };
    }

    private function deployMode(): ?string
    {
        if ($this->safe(fn () => $this->maintenanceMode->isOn()) === true) {
            return 'maintenance';
        }
        $mode = $this->safe(fn () => (string) $this->appState->getMode());
        return in_array($mode, ['production', 'developer', 'default'], true) ? $mode : null;
    }

    private function fullPageCache(): array
    {
        $application = (string) $this->scopeConfig->getValue('system/full_page_cache/caching_application');
        $hosts = $this->deploymentValue('http_cache_hosts');

        return [
            'enabled'              => $this->safe(fn () => $this->cacheState->isEnabled('full_page')),
            'application'          => self::FPC_APPLICATIONS[$application] ?? ($application === '' ? 'builtin' : 'other'),
            'ttl_seconds'          => self::intOrNull($this->scopeConfig->getValue('system/full_page_cache/ttl')),
            // Purge targets are only counted: their addresses never leave the server.
            'varnish_hosts_count'  => is_array($hosts) ? count($hosts) : 0,
            'grace_period_seconds' => self::intOrNull($this->scopeConfig->getValue('system/full_page_cache/varnish/grace_period')),
        ];
    }

    private function cacheTypes(): ?array
    {
        return $this->safe(function (): array {
            $disabled = [];
            $total = 0;
            foreach ($this->cacheTypeList->getTypes() as $id => $type) {
                $total++;
                $status = is_object($type) && method_exists($type, 'getData') ? $type->getData('status') : null;
                if (!$status && preg_match('/^[a-z0-9_]{1,64}$/', (string) $id)) {
                    $disabled[] = (string) $id;
                }
            }
            $invalidated = array_values(array_filter(
                array_map('strval', array_keys($this->cacheTypeList->getInvalidated())),
                fn (string $id) => (bool) preg_match('/^[a-z0-9_]{1,64}$/', $id)
            ));
            return ['total' => min($total, 64), 'disabled' => array_slice($disabled, 0, 64), 'invalidated' => array_slice($invalidated, 0, 64)];
        });
    }

    private function stores(): ?array
    {
        return $this->safe(function (): array {
            $domains = [];
            $views = 0;
            foreach ($this->storeManager->getStores() as $store) {
                if (method_exists($store, 'isActive') && !$store->isActive()) {
                    continue;
                }
                $views++;
                $host = parse_url((string) $store->getBaseUrl(), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $domains[strtolower($host)] = true;
                }
            }
            // Currencies offered on the default store view: more than one means a cache variant per currency.
            $defaultStore = $this->storeManager->getDefaultStoreView();
            $currencies = array_filter(array_map('trim', explode(',', (string) $this->scopeConfig->getValue(
                'currency/options/allow',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $defaultStore?->getCode()
            ))));

            return [
                'websites'          => count($this->storeManager->getWebsites()),
                'store_views'       => $views,
                'domains'           => count($domains),
                'store_code_in_url' => (bool) $this->scopeConfig->isSetFlag('web/url/use_store'),
                'currencies'        => count($currencies),
            ];
        });
    }

    private function cacheModules(): array
    {
        $modules = [];
        foreach (self::CACHE_MODULES as $name) {
            $installed = $this->safe(fn () => $this->fullModuleList->has($name)) === true;
            if (!$installed) {
                continue;
            }
            $modules[] = [
                'name'    => $name,
                'version' => $this->moduleVersion($name),
                'enabled' => $this->safe(fn () => $this->moduleList->has($name)) === true,
            ];
        }
        return $modules;
    }

    private function moduleVersion(string $module): ?string
    {
        $version = $this->safe(fn () => (string) $this->packageInfo->getVersion($module));
        return is_string($version) && $version !== '' ? $version : null;
    }

    private function deploymentValue(string $path): mixed
    {
        return $this->safe(fn () => $this->deploymentConfig->get($path));
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /** A snapshot must never break a cron run or a page: an unreadable fact reads as null. */
    private function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            return null;
        }
    }
}
