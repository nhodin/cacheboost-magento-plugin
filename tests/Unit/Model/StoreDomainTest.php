<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model;

use CacheBoost\CacheWarmer\Model\StoreDomain;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StoreDomainTest extends TestCase
{
    private function domain(string $baseUrl): StoreDomain
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getDefaultStoreView')->willReturn($store);
        return new StoreDomain($manager);
    }

    public function testBaseUrlAndDomainOfTheDefaultStoreView(): void
    {
        $domain = $this->domain('https://WWW.Boutique.fr/fr');
        self::assertSame('https://WWW.Boutique.fr/fr/', $domain->getBaseUrl());
        self::assertSame('www.boutique.fr', $domain->getDomain());
    }

    public function testExactDomainWinsOverTheWwwVariant(): void
    {
        $sites = [['id' => 1, 'domain' => 'boutique.fr'], ['id' => 2, 'domain' => 'https://www.boutique.fr/']];
        self::assertSame(2, $this->domain('https://www.boutique.fr/')->matchSite($sites)['id']);
    }

    public function testWwwVariantIsAccepted(): void
    {
        self::assertSame(1, $this->domain('https://www.boutique.fr/')->matchSite([['id' => 1, 'domain' => 'boutique.fr']])['id']);
        self::assertSame(3, $this->domain('https://boutique.fr/')->matchSite([['id' => 3, 'domain' => 'www.boutique.fr']])['id']);
    }

    public function testOtherDomainsDoNotMatch(): void
    {
        self::assertNull($this->domain('https://www.boutique.fr/')->matchSite([['id' => 1, 'domain' => 'boutique.com'], ['id' => 2, 'domain' => 'shop.boutique.fr']]));
    }
}
