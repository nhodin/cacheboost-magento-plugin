<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Service;

use CacheBoost\Warmer\Service\TagUrlResolver;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagUrlResolverTest extends TestCase
{
    private UrlFinderInterface $urlFinder;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private TagUrlResolver $resolver;

    protected function setUp(): void
    {
        $this->urlFinder    = $this->createMock(UrlFinderInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->resolver = new TagUrlResolver(
            $this->urlFinder,
            $this->storeManager,
            $this->logger
        );
    }

    public function testResolvesTagToAbsoluteUrl(): void
    {
        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('product-slug.html');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        self::assertSame(
            ['https://example.com/product-slug.html'],
            $this->resolver->resolve(['cat_p_42'])
        );
    }

    public function testSkipsInactiveStores(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('isActive')->willReturn(false);
        $this->storeManager->method('getStores')->willReturn([$store]);

        self::assertSame([], $this->resolver->resolve(['cat_p_1']));
    }

    public function testDeduplicatesResolvedUrls(): void
    {
        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);

        // Both tags resolve to the same URL via the finder.
        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('page.html');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        self::assertSame(
            ['https://example.com/page.html'],
            $this->resolver->resolve(['cat_p_1', 'cat_c_2'])
        );
    }

    public function testSkipsStoresOnOtherDomains(): void
    {
        // The API rejects the whole batch if any URL is outside the registered
        // site domain: stores served on another domain must be excluded.
        $mainStore  = $this->makeActiveStore('https://example.com', 1);
        $otherStore = $this->makeActiveStore('https://other-domain.com', 2);
        $this->storeManager->method('getStores')->willReturn([$mainStore, $otherStore]);
        $this->storeManager->method('getDefaultStoreView')->willReturn($mainStore);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('page.html');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        self::assertSame(
            ['https://example.com/page.html'],
            $this->resolver->resolve(['cat_p_1'])
        );
    }

    public function testReturnsEmptyWhenNoUrlsResolved(): void
    {
        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->urlFinder->method('findAllByData')->willReturn([]);

        self::assertSame([], $this->resolver->resolve(['cat_p_99']));
    }

    /** @dataProvider tagPatternProvider */
    public function testTagPatternsRouteToCorrectEntityType(
        string $tag,
        string $expectedEntityType,
        int $expectedEntityId
    ): void {
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

        $this->resolver->resolve([$tag]);
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
        // No stores needed — code returns before reaching getStores when entities is empty.
        $this->urlFinder->expects(self::never())->method('findAllByData');

        self::assertSame([], $this->resolver->resolve(['cms_b_3']));
    }

    public function testUnknownTagsProduceNoUrlLookup(): void
    {
        $this->urlFinder->expects(self::never())->method('findAllByData');

        self::assertSame([], $this->resolver->resolve(['FPC', 'CONFIG', 'some_random_tag']));
    }

    public function testUrlFinderExceptionIsHandledGracefully(): void
    {
        $store = $this->makeActiveStore('https://example.com', 1);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $this->urlFinder->method('findAllByData')->willThrowException(new \RuntimeException('DB error'));
        $this->logger->expects(self::once())->method('warning');

        self::assertSame([], $this->resolver->resolve(['cat_p_1']));
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
