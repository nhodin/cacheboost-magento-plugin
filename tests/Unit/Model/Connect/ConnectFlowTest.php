<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Connect;

use CacheBoost\CacheWarmer\Model\Connect\ConnectFlow;
use CacheBoost\CacheWarmer\Model\StoreDomain;
use CacheBoost\CacheWarmer\Service\AccountApi;
use CacheBoost\CacheWarmer\Test\Unit\Support\MemoryCache;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Connect to CacheBoost": the code comes back in a URL (storefront callback) and may leak
 * (logs, history). It must only become an API key for the admin who started the flow, once,
 * with the PKCE verifier that never left the server.
 */
class ConnectFlowTest extends TestCase
{
    // RFC 7636 appendix B test vector
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const CODE      = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const COMPLETE  = 'https://admin.boutique.fr/admin/cacheboost/connect/complete/state/__STATE__/key/abc/';

    private MemoryCache $cache;
    private AccountApi $api;
    private StoreDomain $storeDomain;
    private ConnectFlow $flow;

    protected function setUp(): void
    {
        $this->cache = new MemoryCache();
        $this->api = $this->createMock(AccountApi::class);
        $this->storeDomain = $this->createMock(StoreDomain::class);
        $this->storeDomain->method('getBaseUrl')->willReturn('https://www.boutique.fr/');
        $this->flow = new ConnectFlow($this->cache, new Json(), $this->storeDomain, $this->api, $this->createMock(LoggerInterface::class));
    }

    /** @return array{0: string, 1: array<string, string>} state and query of the consent URL */
    private function start(int $adminId = 1): array
    {
        $url = $this->flow->start($adminId, self::COMPLETE);
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return [$query['state'], $query];
    }

    public function testChallengeMatchesRfc7636(): void
    {
        // The app checks the challenge: an encoding slip (base64 vs base64url, padding) breaks every connection.
        self::assertSame(self::CHALLENGE, ConnectFlow::challenge(self::VERIFIER));
    }

    public function testStartSendsTheAdminToTheMagentoConsentPageWithAStorefrontReturnUrl(): void
    {
        $url = (string) $this->flow->start(1, self::COMPLETE);
        self::assertStringStartsWith('https://app.cache-boost.com/connect/magento?', $url);

        [, $query] = $this->start();
        self::assertSame('https://www.boutique.fr/', $query['site_url']);
        // The app only redirects to the site's own host: the admin may live elsewhere.
        self::assertSame('https://www.boutique.fr/cacheboost/connect/callback/', $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,128}$/', $query['state']);
    }

    public function testVerifierNeverLeavesTheServer(): void
    {
        $url = (string) $this->flow->start(1, self::COMPLETE);
        $stored = json_decode((string) array_values($this->cache->data)[0], true);

        self::assertStringNotContainsString($stored['verifier'], $url);
        self::assertSame(900, array_values($this->cache->lifetimes)[0]);
    }

    public function testStartFailsWithoutAStorefrontUrl(): void
    {
        $storeDomain = $this->createMock(StoreDomain::class);
        $storeDomain->method('getBaseUrl')->willReturn('');
        $flow = new ConnectFlow($this->cache, new Json(), $storeDomain, $this->api, $this->createMock(LoggerInterface::class));

        self::assertNull($flow->start(1, self::COMPLETE));
    }

    public function testCallbackParksTheCodeAndContinuesOnTheAdminUrlOfTheFlow(): void
    {
        [$state] = $this->start();

        $next = $this->flow->receiveCallback($state, self::CODE, '');
        self::assertSame(str_replace('__STATE__', $state, self::COMPLETE), $next);
        self::assertStringNotContainsString(self::CODE, (string) $next);
    }

    public function testCallbackIsUsableOnce(): void
    {
        [$state] = $this->start();
        $this->flow->receiveCallback($state, self::CODE, '');

        self::assertNull($this->flow->receiveCallback($state, 'b' . substr(self::CODE, 1), ''));
    }

    public function testCallbackRejectsUnknownOrMalformedStates(): void
    {
        self::assertNull($this->flow->receiveCallback('unknown-state-123456', self::CODE, ''));
        self::assertNull($this->flow->receiveCallback('../../etc', self::CODE, ''));
        [$state] = $this->start();
        self::assertNull($this->flow->receiveCallback($state, 'not a code!', ''));
    }

    public function testCompleteExchangesTheCodeWithTheVerifier(): void
    {
        [$state] = $this->start(7);
        $this->flow->receiveCallback($state, self::CODE, '');

        $stored = json_decode((string) array_values($this->cache->data)[0], true);
        $this->api->expects(self::once())->method('exchangeConnectCode')
            ->with(self::CODE, $stored['verifier'])
            ->willReturn(['status' => 200, 'body' => ['api_key' => 'cb_live_abc123', 'site_id' => 42, 'domain' => 'www.boutique.fr']]);

        self::assertSame(['success' => true, 'api_key' => 'cb_live_abc123', 'site_id' => 42], $this->flow->complete($state, 7));
        // One-time: the pending flow is gone.
        self::assertSame(['success' => false, 'reason' => 'invalid_state'], $this->flow->complete($state, 7));
    }

    public function testAnotherAdminCannotCompleteTheFlow(): void
    {
        [$state] = $this->start(7);
        $this->flow->receiveCallback($state, self::CODE, '');
        $this->api->expects(self::never())->method('exchangeConnectCode');

        self::assertSame(['success' => false, 'reason' => 'invalid_state'], $this->flow->complete($state, 8));
    }

    public function testRefusalOnTheConsentPageIsReported(): void
    {
        [$state] = $this->start();
        self::assertNotNull($this->flow->receiveCallback($state, '', 'access_denied'));
        $this->api->expects(self::never())->method('exchangeConnectCode');

        self::assertSame(['success' => false, 'reason' => 'denied'], $this->flow->complete($state, 1));
    }

    public function testCompleteWithoutCallbackIsInvalid(): void
    {
        [$state] = $this->start();
        self::assertSame(['success' => false, 'reason' => 'invalid_state'], $this->flow->complete($state, 1));
    }

    public function testFailedExchangeOrMalformedKeyIsAFailure(): void
    {
        foreach ([
            ['status' => 400, 'body' => ['error' => 'Invalid or expired code.']],
            ['status' => 200, 'body' => ['api_key' => 'sk_other_format', 'site_id' => 42]],
            ['status' => 200, 'body' => ['api_key' => 'cb_live_abc', 'site_id' => 0]],
            ['status' => 0, 'body' => null],
        ] as $response) {
            $api = $this->createMock(AccountApi::class);
            $api->method('exchangeConnectCode')->willReturn($response);
            $flow = new ConnectFlow($this->cache, new Json(), $this->storeDomain, $api, $this->createMock(LoggerInterface::class));

            $url = (string) $flow->start(1, self::COMPLETE);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $flow->receiveCallback($query['state'], self::CODE, '');

            self::assertSame(['success' => false, 'reason' => 'exchange_failed'], $flow->complete($query['state'], 1));
        }
    }
}
