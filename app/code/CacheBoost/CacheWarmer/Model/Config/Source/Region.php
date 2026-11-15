<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Config\Source;

use CacheBoost\CacheWarmer\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Regions offered by the CacheBoost account (GET /v1/me, refreshed by Connect and Test connection),
 * labelled like the CacheBoost catalogue (ISO 3166-1 alpha-2). A code missing from the labels is
 * shown upper-cased, so a new CacheBoost region still appears. Before the first connection the
 * currently saved regions are offered, so saving the page never drops them.
 */
class Region implements OptionSourceInterface
{
    /** 'uk' and 'eu' are legacy codes still found in older configurations. */
    private const LABELS = [
        'fr' => 'France',
        'de' => 'Germany',
        'gb' => 'United Kingdom',
        'uk' => 'United Kingdom',
        'us' => 'United States',
        'es' => 'Spain',
        'it' => 'Italy',
        'ch' => 'Switzerland',
        'sg' => 'Singapore',
        'in' => 'India',
        'au' => 'Australia',
        'br' => 'Brazil',
        'ca' => 'Canada',
        'eu' => 'Europe',
    ];

    public function __construct(private readonly Config $config) {}

    public function toOptionArray(): array
    {
        $codes = $this->config->getAvailableRegions();
        if ($codes === []) {
            $codes = $this->config->getRegions();
        }

        $options = [];
        foreach (array_unique($codes) as $code) {
            $options[] = ['value' => $code, 'label' => self::label($code)];
        }
        return $options;
    }

    public static function label(string $code): string
    {
        return self::LABELS[strtolower($code)] ?? strtoupper($code);
    }
}
