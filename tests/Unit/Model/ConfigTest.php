<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Model;

use CacheBoost\Warmer\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private EncryptorInterface $encryptor;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor   = $this->createMock(EncryptorInterface::class);
        $this->config      = new Config($this->scopeConfig, $this->encryptor);
    }

    // ── getRegions ───────────────────────────────────────────────────────────

    public function testGetRegionsParsesCommaSeparatedValues(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('us, eu, asia');

        self::assertSame(['us', 'eu', 'asia'], $this->config->getRegions());
    }

    public function testGetRegionsReturnsEmptyArrayForEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        self::assertSame([], $this->config->getRegions());
    }

    public function testGetRegionsFiltersEmptySegments(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('us,,eu');

        self::assertSame(['us', 'eu'], $this->config->getRegions());
    }

    // ── getMode ──────────────────────────────────────────────────────────────

    public function testGetModeDefaultsToSmartWhenNotSet(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('smart', $this->config->getMode());
    }

    public function testGetModeReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('full_only');

        self::assertSame('full_only', $this->config->getMode());
    }

    // ── getApiEndpoint ───────────────────────────────────────────────────────

    public function testGetApiEndpointStripsTrailingSlash(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://api.cacheboost.io/');

        self::assertSame('https://api.cacheboost.io', $this->config->getApiEndpoint());
    }

    public function testGetApiEndpointReturnsCleanUrlWithoutSlash(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://api.cacheboost.io');

        self::assertSame('https://api.cacheboost.io', $this->config->getApiEndpoint());
    }

    // ── getApiKey ────────────────────────────────────────────────────────────

    public function testGetApiKeyDecryptsStoredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('encrypted_blob');
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('encrypted_blob')
            ->willReturn('plain_key');

        self::assertSame('plain_key', $this->config->getApiKey());
    }

    public function testGetApiKeySkipsDecryptionWhenEmpty(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->encryptor->expects(self::never())->method('decrypt');

        self::assertSame('', $this->config->getApiKey());
    }

    // ── isConfigured ─────────────────────────────────────────────────────────

    public function testIsConfiguredReturnsTrueWhenAllSet(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn($path) => match ($path) {
                'cacheboost/general/api_key' => 'enc',
                'cacheboost/general/site_id' => '5',
                default                      => null,
            }
        );
        $this->encryptor->method('decrypt')->willReturn('my-api-key');

        self::assertTrue($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        self::assertFalse($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenApiKeyEmpty(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn($path) => match ($path) {
                'cacheboost/general/api_key' => '',
                'cacheboost/general/site_id' => '5',
                default                      => null,
            }
        );

        self::assertFalse($this->config->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenSiteIdIsZero(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn($path) => match ($path) {
                'cacheboost/general/api_key' => 'enc',
                'cacheboost/general/site_id' => '0',
                default                      => null,
            }
        );
        $this->encryptor->method('decrypt')->willReturn('my-api-key');

        self::assertFalse($this->config->isConfigured());
    }
}
