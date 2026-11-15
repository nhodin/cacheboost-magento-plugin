<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Model\Environment;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\Environment\SnapshotCollector;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot is built next to env.php, which holds database, Redis and Varnish credentials:
 * only whitelisted facts may leave the server, reduced to versions, enums, booleans and counts.
 */
class SnapshotCollectorTest extends TestCase
{
    /** env.php as found on a real server, secrets included. */
    private const ENV = [
        'cache/frontend/default/backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
        'cache/frontend/page_cache/backend' => 'Cm_Cache_Backend_Redis',
        'session/save' => 'redis',
        'http_cache_hosts' => [['host' => 'varnish.internal', 'port' => '6081'], ['host' => '10.0.0.12', 'port' => '6081']],
        'db/connection/default/password' => 'db-s3cret',
        'cache/frontend/default/backend_options/password' => 'redis-s3cret',
    ];

    private function collector(array $env = self::ENV, array $scope = [], bool $maintenance = false): SnapshotCollector
    {
        $config = $this->createMock(Config::class);
        $config->method('getMode')->willReturn('smart');
        $config->method('getBoostId')->willReturn(7);
        $config->method('getProcessingMode')->willReturn('auto');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scope += [
            'system/full_page_cache/caching_application' => '2',
            'system/full_page_cache/ttl' => '86400',
            'system/full_page_cache/varnish/grace_period' => '300',
            'currency/options/allow' => 'EUR,USD',
        ];
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $scope[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path) => $path === 'web/url/use_store');

        $deployment = $this->createMock(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(fn ($path) => $env[$path] ?? null);

        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.7-p3');
        $metadata->method('getEdition')->willReturn('Community');

        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn('production');
        $maintenanceMode = $this->createMock(MaintenanceMode::class);
        $maintenanceMode->method('isOn')->willReturn($maintenance);

        $cacheState = $this->createMock(StateInterface::class);
        $cacheState->method('isEnabled')->willReturn(true);
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->method('getTypes')->willReturn([
            'config' => new DataObject(['status' => 1]),
            'block_html' => new DataObject(['status' => 0]),
            'full_page' => new DataObject(['status' => 1]),
        ]);
        $typeList->method('getInvalidated')->willReturn(['full_page' => new DataObject()]);

        $stores = [];
        foreach (['https://www.boutique.fr/fr/', 'https://www.boutique.fr/en/', 'https://www.boutique.de/'] as $i => $url) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getBaseUrl')->willReturn($url);
            $store->method('isActive')->willReturn(true);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getDefaultStoreView')->willReturn($stores[0]);
        if (method_exists($storeManager, 'getWebsites')) {
            $storeManager->method('getWebsites')->willReturn([1, 2]);
        }

        $modules = $this->createMock(ModuleListInterface::class);
        $modules->method('has')->willReturnCallback(fn ($n) => $n === 'Amasty_Fpc');
        $fullModules = $this->createMock(FullModuleList::class);
        $fullModules->method('has')->willReturnCallback(fn ($n) => in_array($n, ['Fastly_Cdn', 'Amasty_Fpc'], true));
        $packages = $this->createMock(PackageInfo::class);
        $packages->method('getVersion')->willReturnCallback(fn ($n) => ['CacheBoost_Warmer' => '1.1.0', 'Fastly_Cdn' => '1.2.220'][$n] ?? '');

        return new SnapshotCollector(
            $config, $scopeConfig, $deployment, $metadata, $state, $maintenanceMode, $cacheState, $typeList,
            $storeManager, $modules, $fullModules, $packages
        );
    }

    public function testSnapshotFollowsTheContract(): void
    {
        $snapshot = $this->collector()->collect();

        self::assertSame('magento', $snapshot['platform']);
        self::assertSame('1.1.0', $snapshot['plugin_version']);
        self::assertSame('2.4.7-p3', $snapshot['magento_version']);
        self::assertSame('community', $snapshot['magento_edition']);
        self::assertSame('production', $snapshot['deploy_mode']);
        self::assertSame(
            ['enabled' => true, 'application' => 'varnish', 'ttl_seconds' => 86400, 'varnish_hosts_count' => 2, 'grace_period_seconds' => 300],
            $snapshot['full_page_cache']
        );
        self::assertSame(['total' => 3, 'disabled' => ['block_html'], 'invalidated' => ['full_page']], $snapshot['cache_types']);
        self::assertSame(['default' => 'redis', 'page_cache' => 'redis'], $snapshot['cache_backend']);
        self::assertSame('redis', $snapshot['session_storage']);
        self::assertSame(3, $snapshot['stores']['store_views']);
        self::assertSame(2, $snapshot['stores']['domains']);
        self::assertSame(2, $snapshot['stores']['currencies']);
        self::assertTrue($snapshot['stores']['store_code_in_url']);
        self::assertSame([
            ['name' => 'Fastly_Cdn', 'version' => '1.2.220', 'enabled' => false],
            ['name' => 'Amasty_Fpc', 'version' => null, 'enabled' => true],
        ], $snapshot['cache_modules']);
        self::assertSame(['warm_mode' => 'smart', 'full_warming' => true, 'processing_mode' => 'auto'], $snapshot['cacheboost']);
    }

    public function testNoHostPortOrSecretLeavesTheServer(): void
    {
        $encoded = (string) json_encode($this->collector()->collect());
        foreach (['varnish.internal', '10.0.0.12', '6081', 'db-s3cret', 'redis-s3cret', 'boutique'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testMaintenanceModeIsReported(): void
    {
        self::assertSame('maintenance', $this->collector(self::ENV, [], true)->collect()['deploy_mode']);
    }

    public function testBuiltinCacheAndDefaults(): void
    {
        $snapshot = $this->collector([], ['system/full_page_cache/caching_application' => '1'])->collect();
        self::assertSame('builtin', $snapshot['full_page_cache']['application']);
        self::assertSame(0, $snapshot['full_page_cache']['varnish_hosts_count']);
        self::assertSame(['default' => 'file', 'page_cache' => 'file'], $snapshot['cache_backend']);
        self::assertSame('files', $snapshot['session_storage']);
    }

    public function testFastlyIsRecognized(): void
    {
        self::assertSame('fastly', $this->collector(self::ENV, ['system/full_page_cache/caching_application' => '42'])->collect()['full_page_cache']['application']);
    }

    public function testMappers(): void
    {
        self::assertSame('commerce', SnapshotCollector::edition('Enterprise'));
        self::assertSame('commerce', SnapshotCollector::edition('B2B'));
        self::assertNull(SnapshotCollector::edition('Mage-OS'));
        self::assertSame('valkey', SnapshotCollector::cacheBackend('Vendor\\Cache\\Valkey'));
        self::assertSame('database', SnapshotCollector::cacheBackend('Magento\\Framework\\Cache\\Backend\\Database'));
        self::assertSame('memcached', SnapshotCollector::cacheBackend('Libmemcached'));
        self::assertSame('other', SnapshotCollector::cacheBackend('Vendor\\Custom'));
        self::assertSame('db', SnapshotCollector::sessionStorage('db'));
        self::assertSame('memcached', SnapshotCollector::sessionStorage('memcache'));
        self::assertSame('other', SnapshotCollector::sessionStorage('custom'));
    }
}
