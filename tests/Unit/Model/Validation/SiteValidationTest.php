<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Validation;

use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use CacheBoost\CacheWarmer\Service\AccountApi;
use CacheBoost\CacheWarmer\Test\Unit\Support\MemoryFlags;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Automatic domain validation: the storefront answers /{token}.html only while CacheBoost checks
 * it, so an API key alone never validates a domain, and regular pages never pay for it.
 */
class SiteValidationTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private AccountApi $api;
    private MemoryFlags $flags;
    private SiteValidation $validation;

    protected function setUp(): void
    {
        $this->api = $this->createMock(AccountApi::class);
        $this->flags = new MemoryFlags();
        $this->validation = new SiteValidation($this->api, $this->flags, $this->createMock(LoggerInterface::class));
    }

    public function testAlreadyValidatedSiteIsNotValidatedAgain(): void
    {
        $this->api->expects(self::never())->method('validateSite');

        $result = $this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validated' => true]);

        self::assertSame(['success' => true, 'attempted' => false], $result);
        self::assertFalse($this->validation->isPending());
    }

    public function testTokenIsServedOnlyDuringTheValidationCall(): void
    {
        $servedDuringCall = null;
        $this->api->method('validateSite')->willReturnCallback(function () use (&$servedDuringCall): array {
            $servedDuringCall = $this->validation->tokenForPath('/' . self::TOKEN . '.html');
            return ['status' => 200, 'body' => ['validated' => true]];
        });

        $result = $this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validated' => false, 'validation_token' => self::TOKEN]);

        self::assertSame(self::TOKEN, $servedDuringCall);
        self::assertSame(['success' => true, 'attempted' => true], $result);
        self::assertNull($this->validation->tokenForPath('/' . self::TOKEN . '.html'), 'the token must not stay public');
        self::assertArrayNotHasKey(SiteValidation::FLAG_TOKEN, $this->flags->flags);
    }

    public function testTokenIsRemovedEvenWhenTheCallThrows(): void
    {
        $this->api->method('validateSite')->willThrowException(new \RuntimeException('timeout'));

        try {
            $this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validation_token' => self::TOKEN]);
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        self::assertArrayNotHasKey(SiteValidation::FLAG_TOKEN, $this->flags->flags);
    }

    public function testFailureIsRecordedForTheAdminWarning(): void
    {
        $this->api->method('validateSite')->willReturn(['status' => 422, 'body' => ['error' => 'Token not found at https://www.boutique.fr/x.html']]);

        $result = $this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validation_token' => self::TOKEN]);

        self::assertFalse($result['success']);
        self::assertTrue($result['attempted']);
        self::assertStringContainsString('Token not found', $result['message']);
        self::assertTrue($this->validation->isPending());

        $this->validation->reset();
        self::assertFalse($this->validation->isPending());
    }

    public function testMissingSitesWriteScopeIsExplained(): void
    {
        $this->api->method('validateSite')->willReturn(['status' => 403, 'body' => ['error' => 'Insufficient scope']]);

        $result = $this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validation_token' => self::TOKEN]);

        self::assertStringContainsString('sites:write', $result['message']);
    }

    public function testTokenIsReadFromTheSiteWhenTheListDoesNotCarryIt(): void
    {
        $this->api->expects(self::once())->method('site')->with('cb_live_k', 42)
            ->willReturn(['status' => 200, 'body' => ['id' => 42, 'validation_token' => self::TOKEN]]);
        $this->api->method('validateSite')->willReturn(['status' => 200, 'body' => ['validated' => true]]);

        self::assertTrue($this->validation->ensure('cb_live_k', 42, ['id' => 42, 'validated' => false])['success']);
    }

    public function testSiteIsFetchedWhenNotGiven(): void
    {
        $this->api->method('site')->willReturn(['status' => 200, 'body' => ['id' => 42, 'validated' => true]]);
        self::assertTrue($this->validation->ensure('cb_live_k', 42)['success']);
    }

    public function testRegularStorefrontPagesNeverReadTheFlagTable(): void
    {
        foreach (['/', '/women/tops.html', '/shoes.html', '/checkout/cart/', '/' . self::TOKEN . '.htm', '/a/' . self::TOKEN . '.html', '/ABCDEF0123456789ABCDEF.html'] as $path) {
            self::assertNull($this->validation->tokenForPath($path), $path);
        }
        self::assertSame(0, $this->flags->reads);
    }

    public function testWrongTokenIsNotServed(): void
    {
        $this->flags->flags[SiteValidation::FLAG_TOKEN] = self::TOKEN;
        self::assertNull($this->validation->tokenForPath('/ffffffffffffffffffffffffffffffff.html'));
        self::assertSame(self::TOKEN, $this->validation->tokenForPath('/' . self::TOKEN . '.html'));
    }
}
