<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Config\Source\Region;
use PHPUnit\Framework\TestCase;

class RegionSourceTest extends TestCase
{
    public function testAccountRegionsAreLabelledLikeTheCatalogue(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getAvailableRegions')->willReturn(['fr', 'gb', 'jp']);

        self::assertSame([
            ['value' => 'fr', 'label' => 'France'],
            ['value' => 'gb', 'label' => 'United Kingdom'],
            ['value' => 'jp', 'label' => 'JP'],
        ], (new Region($config))->toOptionArray());
    }

    public function testSavedRegionsStayOfferedBeforeTheFirstConnection(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getAvailableRegions')->willReturn([]);
        $config->method('getRegions')->willReturn(['fr', 'eu']);

        self::assertSame(['fr', 'eu'], array_column((new Region($config))->toOptionArray(), 'value'));
    }
}
