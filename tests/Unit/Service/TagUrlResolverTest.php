<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Service;

use CacheBoost\CacheWarmer\Service\TagUrlResolver;
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

    public function testWithoutRewritesTheCliScriptNameIsReplacedByIndexPhp(): void
    {
        // bin/magento (queue consumer): Magento builds link URLs with "magento/" instead of "index.php/".
        $previous = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/bin/magento';
        try {
            $store = $this->makeRealisticStore('https://example.com/', 'https://example.com/magento/', 'home');
            $this->storeManager->method('getStores')->willReturn([$store]);

            $rewrite = $this->createMock(UrlRewrite::class);
            $rewrite->method('getRequestPath')->willReturn('product-slug.html');
            $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

            self::assertSame(
                ['https://example.com/index.php/product-slug.html'],
                $this->resolver->resolve(['cat_p_42'])
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $previous;
            }
        }
    }

    public function testHomeCmsPageAlsoWarmsTheStoreRoot(): void
    {
        $store = $this->makeRealisticStore('https://example.com/', 'https://example.com/', 'home|2');
        $this->storeManager->method('getStores')->willReturn([$store]);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('home');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        self::assertSame(
            ['https://example.com/home', 'https://example.com/'],
            $this->resolver->resolve(['cms_p_2'])
        );
    }

    public function testOtherCmsPagesDoNotWarmTheStoreRoot(): void
    {
        $store = $this->makeRealisticStore('https://example.com/', 'https://example.com/', 'home');
        $this->storeManager->method('getStores')->willReturn([$store]);

        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getRequestPath')->willReturn('about-us');
        $this->urlFinder->method('findAllByData')->willReturn([$rewrite]);

        self::assertSame(['https://example.com/about-us'], $this->resolver->resolve(['cms_p_5']));
    }

    /** A store whose link URL differs from its web URL and that exposes its configuration, like Magento's. */
    private function makeRealisticStore(string $webUrl, string $linkUrl, string $homePage): StoreInterface
    {
        return new class ($webUrl, $linkUrl, $homePage) implements StoreInterface {
            public function __construct(private string $webUrl, private string $linkUrl, private string $homePage) {}

            public function isActive(): bool
            {
                return true;
            }

            public function getBaseUrl($type = 'link'): string
            {
                return $type === 'web' ? $this->webUrl : $this->linkUrl;
            }

            public function getId(): mixed
            {
                return 1;
            }

            public function getCode()
            {
                return 'default';
            }

            public function getConfig(string $path): ?string
            {
                return $path === 'web/default/cms_home_page' ? $this->homePage : null;
            }
        };
    }

    private function makeActiveStore(string $baseUrl, int $id): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('isActive')->willReturn(true);
        $store->method('getBaseUrl')->willReturn(rtrim($baseUrl, '/') . '/');
        $store->method('getId')->willReturn($id);
        return $store;
    }
}
