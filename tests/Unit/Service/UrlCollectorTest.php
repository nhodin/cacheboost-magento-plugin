<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Service;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use CacheBoost\Warmer\Service\UrlCollector;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlCollectorTest extends TestCase
{
    private Config $config;
    private ApiClient $apiClient;
    private UrlFinderInterface $urlFinder;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private UrlCollector $collector;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(Config::class);
        $this->apiClient    = $this->createMock(ApiClient::class);
        $this->urlFinder    = $this->createMock(UrlFinderInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->collector = new UrlCollector(
            $this->config,
            $this->apiClient,
            $this->urlFinder,
            $this->storeManager,
            $this->logger
        );
    }

    // ── collectTags ──────────────────────────────────────────────────────────

    public function testCollectTagsIgnoresNonStringValues(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->storeManager->method('getStores')->willReturn([]);
        $this->apiClient->expects(self::never())->method('triggerWarm');

        // Should not crash on mixed types; only the string 'cat_p_1' is kept.
        $this->collector->collectTags([123, null, 'cat_p_1', true]);
        $this->collector->flush();
    }

    // ── flush – guard conditions ─────────────────────────────────────────────

    public function testFlushDoesNothingWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->collector->flush();
    }

    public function testFlushIsIdempotent(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(3);
        // triggerBoostRun must be called exactly once despite two flush() calls.
        $this->apiClient->expects(self::once())->method('triggerBoostRun');

        $this->collector->markFullFlush();
        $this->collector->flush();
        $this->collector->flush();
    }

    public function testFlushSkipsWarmWhenNoTagsAndNoFullFlush(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->collector->flush();
    }

    // ── flush – full flush ───────────────────────────────────────────────────

    public function testFlushTriggersBoostRunOnFullFlush(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(7);
        $this->apiClient->expects(self::once())
            ->method('triggerBoostRun')
            ->with(7);

        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    public function testFlushLogsInfoWhenFullFlushHasNoBoostId(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(0);
        $this->apiClient->expects(self::never())->method('triggerBoostRun');
        $this->logger->expects(self::once())->method('info');

        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    public function testFullFlushTakesPriorityOverPendingTags(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(7);
        // Even with pending tags, a full flush must NOT call triggerWarm.
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::once())->method('triggerBoostRun');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    // ── flush – full_only mode ───────────────────────────────────────────────

    public function testFlushTriggersBoostRunInFullOnlyMode(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('full_only');
        $this->config->method('getBoostId')->willReturn(9);
        $this->apiClient->expects(self::once())->method('triggerBoostRun')->with(9);
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->flush();
    }

    public function testFlushSkipsApiCallInFullOnlyModeWhenNoBoostId(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('full_only');
        $this->config->method('getBoostId')->willReturn(0);
        $this->apiClient->expects(self::never())->method('triggerBoostRun');
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->flush();
    }

    // ── flush – smart mode (URL resolution) ─────────────────────────────────

    public function testFlushTriggersWarmWithResolvedUrls(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('product-slug.html');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        $this->apiClient->expects(self::once())
            ->method('triggerWarm')
            ->with(['https://example.com/product-slug.html']);

        $this->collector->collectTags(['cat_p_42']);
        $this->collector->flush();
    }

    public function testFlushSkipsInactiveStores(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->createMock(StoreInterface::class);
        $store->method('isActive')->willReturn(false);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->flush();
    }

    public function testFlushDeduplicatesResolvedUrls(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        // Both tags resolve to the same URL via the finder.
        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('page.html');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        $this->apiClient->expects(self::once())
            ->method('triggerWarm')
            ->with(['https://example.com/page.html']);

        $this->collector->collectTags(['cat_p_1', 'cat_c_2']);
        $this->collector->flush();
    }

    public function testFlushSkipsWarmWhenNoUrlsResolved(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->urlFinder->method('findAllByData')->willReturn([]);

        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cat_p_99']);
        $this->collector->flush();
    }

    // ── tag pattern routing ──────────────────────────────────────────────────

    /** @dataProvider tagPatternProvider */
    public function testTagPatternsRouteToCorrectEntityType(
        string $tag,
        string $expectedEntityType,
        int $expectedEntityId
    ): void {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('path.html');

        $this->urlFinder->expects(self::once())
            ->method('findAllByData')
            ->with(self::callback(function (array $data) use ($expectedEntityType, $expectedEntityId): bool {
                return $data[UrlRewrite::ENTITY_TYPE] === $expectedEntityType
                    && $data[UrlRewrite::ENTITY_ID]   === $expectedEntityId;
            }))
            ->willReturn([$rewrite]);

        $this->collector->collectTags([$tag]);
        $this->collector->flush();
    }

    public static function tagPatternProvider(): array
    {
        return [
            'product tag'  => ['cat_p_42', 'product',  42],
            'category tag' => ['cat_c_10', 'category', 10],
            'cms page tag' => ['cms_p_5',  'cms-page', 5],
        ];
    }

    public function testCmsBlockTagProducesNoUrlLookup(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        // No stores needed — code returns before reaching getStores when entities is empty.
        $this->urlFinder->expects(self::never())->method('findAllByData');
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cms_b_3']);
        $this->collector->flush();
    }

    public function testUnknownTagsProduceNoUrlLookup(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->urlFinder->expects(self::never())->method('findAllByData');
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['FPC', 'CONFIG', 'some_random_tag']);
        $this->collector->flush();
    }

    public function testUrlFinderExceptionIsHandledGracefully(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->urlFinder->method('findAllByData')->willThrowException(new \RuntimeException('DB error'));
        $this->logger->expects(self::once())->method('warning');
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->flush();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeActiveStore(string $baseUrl, int $id): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('isActive')->willReturn(true);
        $store->method('getBaseUrl')->willReturn(rtrim($baseUrl, '/') . '/');
        $store->method('getId')->willReturn($id);
        return $store;
    }
}
