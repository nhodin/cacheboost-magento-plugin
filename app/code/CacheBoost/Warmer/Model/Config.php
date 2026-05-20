<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    public const  API_ENDPOINT = 'https://api.cache-boost.com';

    private const XML_ENABLED  = 'cacheboost/general/enabled';
    private const XML_API_KEY  = 'cacheboost/general/api_key';
    private const XML_SITE_ID  = 'cacheboost/general/site_id';
    private const XML_REGION   = 'cacheboost/general/region';
    private const XML_MODE     = 'cacheboost/general/mode';
    private const XML_BOOST_ID = 'cacheboost/flush_all/boost_id';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    public function getApiKey(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_API_KEY);
        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    public function getSiteId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_SITE_ID);
    }

    public function getRegions(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_REGION);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getMode(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_MODE) ?: 'smart');
    }

    public function getBoostId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_BOOST_ID);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && $this->getApiKey() !== ''
            && $this->getSiteId() > 0;
    }
}
