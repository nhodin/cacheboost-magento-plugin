<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    public const XML_ENABLED           = 'cacheboost/general/enabled';
    public const XML_API_KEY           = 'cacheboost/general/api_key';
    public const XML_SITE_ID           = 'cacheboost/general/site_id';
    public const XML_REGION            = 'cacheboost/general/region';
    public const XML_AVAILABLE_REGIONS = 'cacheboost/general/available_regions';
    private const XML_MODE             = 'cacheboost/general/mode';
    private const XML_API_ENDPOINT     = 'cacheboost/general/api_endpoint';
    public const XML_BOOST_ID          = 'cacheboost/flush_all/boost_id';
    public const XML_SHARE_ENVIRONMENT = 'cacheboost/diagnostics/share_environment';
    private const XML_PROCESSING   = 'cacheboost/processing/mode';

    /** How queued warm requests are processed (see Model\Queue\ProcessingStrategy). */
    public const PROCESSING_AUTO  = 'auto';
    public const PROCESSING_QUEUE = 'queue';
    public const PROCESSING_CRON  = 'cron';
    public const PROCESSING_SYNC  = 'sync';

    private const PROCESSING_MODES = [
        self::PROCESSING_AUTO,
        self::PROCESSING_QUEUE,
        self::PROCESSING_CRON,
        self::PROCESSING_SYNC,
    ];

    private const DEFAULT_API_ENDPOINT = 'https://api.cache-boost.com';

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

    public function getApiEndpoint(): string
    {
        $value = rtrim((string) $this->scopeConfig->getValue(self::XML_API_ENDPOINT), '/');
        return $value !== '' ? $value : self::DEFAULT_API_ENDPOINT;
    }

    /**
     * Regions offered by the CacheBoost account (GET /v1/me), saved by Connect and Test connection.
     *
     * @return string[]
     */
    public function getAvailableRegions(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_AVAILABLE_REGIONS);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Share the cache configuration with CacheBoost for the cache diagnostics (on by default). */
    public function isEnvironmentSharingEnabled(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_SHARE_ENVIRONMENT);
        return $value === null || $value === '' ? true : (bool) (int) $value;
    }

    public function getBoostId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_BOOST_ID);
    }

    /**
     * Configured processing mode; unknown values fall back to auto.
     */
    public function getProcessingMode(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PROCESSING);
        return in_array($value, self::PROCESSING_MODES, true) ? $value : self::PROCESSING_AUTO;
    }

    /**
     * True when API credentials are present, regardless of the Enable flag.
     * Used by "Test connection" so merchants can validate their key before
     * switching the module on.
     */
    public function hasCredentials(): bool
    {
        return $this->getApiKey() !== ''
            && $this->getSiteId() > 0;
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasCredentials();
    }
}
