<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Model\Account;

use CacheBoost\Warmer\Model\Account\AccountSetup;
use CacheBoost\Warmer\Model\Account\AccountStatus;
use CacheBoost\Warmer\Model\Account\SitemapLocator;
use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\ConfigWriter;
use CacheBoost\Warmer\Model\StoreDomain;
use CacheBoost\Warmer\Model\Validation\SiteValidation;
use CacheBoost\Warmer\Service\AccountApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * After Connect, Test connection or a new key, the merchant must not have to copy a Site ID,
 * regions or a Boost ID: everything is found or created, as in the WordPress plugin.
 */
class AccountSetupTest extends TestCase
{
    private const KEY = 'cb_live_k';
    private const ALL_SCOPES = ['sites:read', 'sites:write', 'boosts:read', 'boosts:write', 'runs:read'];

    private Config $config;
    private ConfigWriter $writer;
    private AccountApi $api;
    private StoreDomain $storeDomain;
    private SiteValidation $validation;
    private SitemapLocator $sitemaps;

    /** @var array<string, mixed> values saved through ConfigWriter */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->writer = $this->createMock(ConfigWriter::class);
        $this->writer->method('saveSiteId')->willReturnCallback(function (int $id) { $this->saved['site_id'] = $id; });
        $this->writer->method('saveRegions')->willReturnCallback(function (array $a, array $s) { $this->saved['regions'] = [$a, $s]; });
        $this->writer->method('saveBoostId')->willReturnCallback(function (int $id) { $this->saved['boost_id'] = $id; });
        $this->api = $this->createMock(AccountApi::class);
        $this->storeDomain = $this->createMock(StoreDomain::class);
        $this->storeDomain->method('getDomain')->willReturn('www.boutique.fr');
        $this->storeDomain->method('matchSite')->willReturnCallback(
            fn (array $sites) => array_values(array_filter($sites, fn ($s) => $s['domain'] === 'www.boutique.fr'))[0] ?? null
        );
        $this->validation = $this->createMock(SiteValidation::class);
        $this->sitemaps = $this->createMock(SitemapLocator::class);
        $this->sitemaps->method('getUrl')->willReturn('https://www.boutique.fr/sitemap.xml');
    }

    private function subject(): AccountSetup
    {
        return new AccountSetup(
            $this->config, $this->writer, $this->api, $this->storeDomain, $this->validation,
            $this->sitemaps, $this->createMock(AccountStatus::class), $this->createMock(LoggerInterface::class)
        );
    }

    private function me(array $scopes = self::ALL_SCOPES, array $regions = ['fr', 'de', 'us']): void
    {
        $this->api->method('me')->willReturn(['status' => 200, 'body' => ['scopes' => $scopes, 'regions' => $regions]]);
    }

    private function sites(array $sites): void
    {
        $this->api->method('sites')->willReturn(['status' => 200, 'body' => $sites]);
    }

    public function testInvalidKey(): void
    {
        $this->api->method('me')->willReturn(['status' => 401, 'body' => null]);
        $result = $this->subject()->run(self::KEY);
        self::assertFalse($result['success']);
        self::assertStringContainsString('401', $result['message']);
    }

    public function testUnverifiedEmailIsExplained(): void
    {
        $this->api->method('me')->willReturn(['status' => 403, 'body' => ['code' => 'email_unverified']]);
        self::assertStringContainsString('email', $this->subject()->run(self::KEY)['message']);
    }

    public function testMissingRequiredScopes(): void
    {
        $this->me(['boosts:read']);
        $result = $this->subject()->run(self::KEY);
        self::assertFalse($result['success']);
        self::assertStringContainsString('boosts:write, runs:read', $result['message']);
    }

    public function testSiteIsFoundByTheStorefrontDomainAndSaved(): void
    {
        $this->me();
        $this->sites([['id' => 9, 'domain' => 'other.fr'], ['id' => 42, 'domain' => 'www.boutique.fr', 'validated' => true]]);
        $this->validation->method('ensure')->willReturn(['success' => true, 'attempted' => false]);
        $this->config->method('getBoostId')->willReturn(7);

        $result = $this->subject()->run(self::KEY);

        self::assertTrue($result['success']);
        self::assertSame(42, $result['site_id']);
        self::assertSame(42, $this->saved['site_id']);
        self::assertTrue($result['validated']);
    }

    public function testUnregisteredDomainFailsWithTheDomainInTheMessage(): void
    {
        $this->me();
        $this->sites([['id' => 9, 'domain' => 'other.fr']]);
        $result = $this->subject()->run(self::KEY);
        self::assertFalse($result['success']);
        self::assertStringContainsString('www.boutique.fr', $result['message']);
    }

    public function testKeyWithoutSitesReadKeepsTheManualSiteId(): void
    {
        $this->me(['boosts:read', 'boosts:write', 'runs:read']);
        $this->config->method('getSiteId')->willReturn(42);
        $this->config->method('getBoostId')->willReturn(7);
        $this->api->expects(self::never())->method('sites');
        $this->validation->expects(self::never())->method('ensure');

        $result = $this->subject()->run(self::KEY);
        self::assertTrue($result['success']);
        self::assertSame(42, $result['site_id']);
        self::assertArrayNotHasKey('site_id', $this->saved, 'unchanged site id is not rewritten');
    }

    public function testKeyWithoutSitesReadAndNoSiteIdAsksForIt(): void
    {
        $this->me(['boosts:read', 'boosts:write', 'runs:read']);
        $this->config->method('getSiteId')->willReturn(0);
        self::assertFalse($this->subject()->run(self::KEY)['success']);
    }

    public function testConnectSiteIdIsUsedWithoutListingSites(): void
    {
        $this->me();
        $this->api->expects(self::never())->method('sites');
        $this->validation->expects(self::once())->method('ensure')->with(self::KEY, 42, null)
            ->willReturn(['success' => true, 'attempted' => true]);
        $this->config->method('getBoostId')->willReturn(7);

        $result = $this->subject()->run(self::KEY, 42);
        self::assertContains('Your domain has been validated in CacheBoost.', $result['notices']);
    }

    public function testValidationFailureIsANoticeNotAFailure(): void
    {
        $this->me();
        $this->sites([['id' => 42, 'domain' => 'www.boutique.fr', 'validated' => false]]);
        $this->validation->method('ensure')->willReturn(['success' => false, 'attempted' => true, 'message' => 'HTTP 404']);
        $this->config->method('getBoostId')->willReturn(7);

        $result = $this->subject()->run(self::KEY);
        self::assertTrue($result['success']);
        self::assertFalse($result['validated']);
        self::assertStringContainsString('HTTP 404', implode(' ', $result['notices']));
    }

    public function testRegionsAreRefreshedFromTheAccount(): void
    {
        $this->me(self::ALL_SCOPES, ['fr', 'de']);
        $this->config->method('getRegions')->willReturn(['de', 'uk']);
        $this->config->method('getBoostId')->willReturn(7);
        $this->validation->method('ensure')->willReturn(['success' => true, 'attempted' => false]);

        $this->subject()->run(self::KEY, 42);
        self::assertSame([['fr', 'de'], ['de']], $this->saved['regions']);
    }

    public function testPickRegions(): void
    {
        self::assertSame(['de'], AccountSetup::pickRegions(['de', 'xx'], ['fr', 'de']));
        self::assertSame(['fr'], AccountSetup::pickRegions([], ['us', 'fr']));
        self::assertSame(['us'], AccountSetup::pickRegions(['uk'], ['us', 'sg']));
        self::assertSame([], AccountSetup::pickRegions([], ['sg']));
    }

    public function testExistingBoostOfTheSiteIsReused(): void
    {
        $this->api->method('boosts')->willReturn(['status' => 200, 'body' => [['id' => 3, 'site_id' => 9], ['id' => 5, 'site_id' => 42]]]);
        $this->api->expects(self::never())->method('createBoost');

        self::assertSame(5, $this->subject()->ensureFullWarmingBoost(self::KEY, 42, ['fr'], true));
    }

    public function testBoostIsNotCreatedOutsideConnect(): void
    {
        $this->api->method('boosts')->willReturn(['status' => 200, 'body' => []]);
        $this->api->expects(self::never())->method('createSitemap');
        self::assertSame(0, $this->subject()->ensureFullWarmingBoost(self::KEY, 42, ['fr'], false));
    }

    public function testBoostIsCreatedFromTheMagentoSitemap(): void
    {
        $this->api->method('boosts')->willReturn(['status' => 200, 'body' => []]);
        $this->api->expects(self::once())->method('createSitemap')->with(self::KEY, 42, 'https://www.boutique.fr/sitemap.xml')
            ->willReturn(['status' => 201, 'body' => ['id' => 11]]);
        $this->api->expects(self::once())->method('createBoost')->with(self::KEY, [
            'name' => 'Magento — full site', 'site_id' => 42, 'source_type' => 'sitemap', 'sitemap_ids' => [11], 'region' => ['fr'],
        ])->willReturn(['status' => 201, 'body' => ['id' => 77]]);

        self::assertSame(77, $this->subject()->ensureFullWarmingBoost(self::KEY, 42, ['fr', 'de'], true));
    }

    public function testAlreadyRegisteredSitemapIsLookedUp(): void
    {
        $this->api->method('boosts')->willReturn(['status' => 200, 'body' => []]);
        $this->api->method('createSitemap')->willReturn(['status' => 409, 'body' => null]);
        $this->api->method('sitemaps')->willReturn(['status' => 200, 'body' => [['id' => 4, 'url' => 'https://x/sitemap.xml'], ['id' => 12, 'url' => 'https://www.boutique.fr/sitemap.xml']]]);
        $this->api->expects(self::once())->method('createBoost')
            ->with(self::KEY, self::callback(fn (array $b) => $b['sitemap_ids'] === [12]))
            ->willReturn(['status' => 201, 'body' => ['id' => 78]]);

        self::assertSame(78, $this->subject()->ensureFullWarmingBoost(self::KEY, 42, [], true));
    }

    public function testConnectSavesTheCreatedBoost(): void
    {
        $this->me();
        $this->validation->method('ensure')->willReturn(['success' => true, 'attempted' => false]);
        $this->config->method('getBoostId')->willReturn(0);
        $this->api->method('boosts')->willReturn(['status' => 200, 'body' => []]);
        $this->api->method('createSitemap')->willReturn(['status' => 201, 'body' => ['id' => 11]]);
        $this->api->method('createBoost')->willReturn(['status' => 201, 'body' => ['id' => 77]]);

        $result = $this->subject()->run(self::KEY, 42, true);
        self::assertSame(77, $result['boost_id']);
        self::assertSame(77, $this->saved['boost_id']);
    }
}
