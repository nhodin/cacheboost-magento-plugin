<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Writes the settings filled in automatically (Connect, Test connection, saving a new key):
 * default scope only, like every CacheBoost field, then reloads the config so the same
 * request reads the new values.
 */
class ConfigWriter
{
    public function __construct(
        private readonly WriterInterface $writer,
        private readonly EncryptorInterface $encryptor,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {}

    public function saveConnection(string $apiKey, int $siteId): void
    {
        $this->writer->save(Config::XML_API_KEY, $this->encryptor->encrypt($apiKey));
        $this->writer->save(Config::XML_SITE_ID, (string) $siteId);
        $this->writer->save(Config::XML_ENABLED, '1');
        $this->reinitableConfig->reinit();
    }

    public function saveSiteId(int $siteId): void
    {
        $this->writer->save(Config::XML_SITE_ID, (string) $siteId);
        $this->reinitableConfig->reinit();
    }

    /**
     * @param string[] $available regions returned by GET /v1/me
     * @param string[] $selected  regions to warm from
     */
    public function saveRegions(array $available, array $selected): void
    {
        $this->writer->save(Config::XML_AVAILABLE_REGIONS, implode(',', $available));
        $this->writer->save(Config::XML_REGION, implode(',', $selected));
        $this->reinitableConfig->reinit();
    }

    public function saveBoostId(int $boostId): void
    {
        $this->writer->save(Config::XML_BOOST_ID, (string) $boostId);
        $this->reinitableConfig->reinit();
    }
}
