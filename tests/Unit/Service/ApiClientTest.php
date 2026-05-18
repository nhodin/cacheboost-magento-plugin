<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Service;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiClientTest extends TestCase
{
    private Config $config;
    private Curl $curl;
    private LoggerInterface $logger;
    private ApiClient $client;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->curl   = $this->createMock(Curl::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('getApiEndpoint')->willReturn('https://api.cacheboost.io');
        $this->config->method('getApiKey')->willReturn('test-key');
        $this->config->method('getSiteId')->willReturn(42);
        $this->config->method('getRegions')->willReturn([]);

        $this->client = new ApiClient($this->config, $this->curl, $this->logger);
    }

    // ── triggerWarm ──────────────────────────────────────────────────────────

    public function testTriggerWarmReturnsFalseForEmptyArray(): void
    {
        $this->curl->expects(self::never())->method('post');

        self::assertFalse($this->client->triggerWarm([]));
    }

    public function testTriggerWarmPostsToCorrectEndpoint(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->expects(self::once())
            ->method('post')
            ->with('https://api.cacheboost.io/v1/sites/42/warm', self::anything());

        $this->client->triggerWarm(['https://example.com/']);
    }

    public function testTriggerWarmSendsJsonPayload(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                json_encode(['urls' => ['https://a.com/', 'https://b.com/'], 'region' => []])
            );

        $this->client->triggerWarm(['https://a.com/', 'https://b.com/']);
    }

    public function testTriggerWarmDeduplicatesUrls(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                json_encode(['urls' => ['https://a.com/'], 'region' => []])
            );

        $this->client->triggerWarm(['https://a.com/', 'https://a.com/']);
    }

    public function testTriggerWarmAddsAuthorizationHeader(): void
    {
        $this->curl->method('getStatus')->willReturn(200);

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(
            function (string $name, string $value) use (&$headers): void {
                $headers[$name] = $value;
            }
        );

        $this->client->triggerWarm(['https://example.com/']);

        self::assertArrayHasKey('Authorization', $headers);
        self::assertSame('Bearer test-key', $headers['Authorization']);
    }

    public function testTriggerWarmReturnsTrueOn2xx(): void
    {
        $this->curl->method('getStatus')->willReturn(202);

        self::assertTrue($this->client->triggerWarm(['https://example.com/']));
    }

    public function testTriggerWarmReturnsFalseOn4xx(): void
    {
        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('Unauthorized');
        $this->logger->expects(self::once())->method('warning');

        self::assertFalse($this->client->triggerWarm(['https://example.com/']));
    }

    public function testTriggerWarmReturnsFalseOnException(): void
    {
        $this->curl->method('setTimeout')->willThrowException(new \RuntimeException('Network error'));
        $this->logger->expects(self::once())->method('error');

        self::assertFalse($this->client->triggerWarm(['https://example.com/']));
    }

    // ── getWarmRuns ──────────────────────────────────────────────────────────

    public function testGetWarmRunsReturnsDataArrayOnSuccess(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['data' => [
            ['id' => 1, 'status' => 'done'],
            ['id' => 2, 'status' => 'done'],
        ]]));

        $result = $this->client->getWarmRuns(2);

        self::assertCount(2, $result);
        self::assertSame(1, $result[0]['id']);
    }

    public function testGetWarmRunsUsesLimitParameter(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"data":[]}');
        $this->curl->expects(self::once())
            ->method('get')
            ->with('https://api.cacheboost.io/v1/sites/42/warm-runs?limit=5');

        $this->client->getWarmRuns(5);
    }

    public function testGetWarmRunsReturnsEmptyArrayOnHttpError(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->logger->expects(self::once())->method('warning');

        self::assertSame([], $this->client->getWarmRuns());
    }

    public function testGetWarmRunsReturnsEmptyArrayOnException(): void
    {
        $this->curl->method('setTimeout')->willThrowException(new \RuntimeException('Timeout'));
        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->client->getWarmRuns());
    }

    public function testGetWarmRunsReturnsEmptyArrayForMalformedJson(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('not-json');

        self::assertSame([], $this->client->getWarmRuns());
    }

    // ── ping ─────────────────────────────────────────────────────────────────

    public function testPingReturnsFalseWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);

        $result = $this->client->ping();

        self::assertFalse($result['success']);
        self::assertStringContainsString('not configured', $result['message']);
    }

    /** @dataProvider pingStatusProvider */
    public function testPingMapsHttpStatusToSuccess(int $status, bool $expectedSuccess): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn('');

        $result = $this->client->ping();

        self::assertSame($expectedSuccess, $result['success']);
        self::assertNotEmpty($result['message']);
    }

    public static function pingStatusProvider(): array
    {
        return [
            'ok'           => [200, true],
            'unauthorized' => [401, false],
            'forbidden'    => [403, false],
            'not found'    => [404, false],
            'server error' => [500, false],
        ];
    }

    public function testPingReturnsFalseOnException(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->curl->method('setTimeout')->willThrowException(new \RuntimeException('Refused'));

        $result = $this->client->ping();

        self::assertFalse($result['success']);
        self::assertStringContainsString('Refused', $result['message']);
    }

    // ── getBoostRuns ─────────────────────────────────────────────────────────

    public function testGetBoostRunsUsesCorrectEndpoint(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"data":[]}');
        $this->curl->expects(self::once())
            ->method('get')
            ->with('https://api.cacheboost.io/v1/runs?boost_id=7&limit=10');

        $this->client->getBoostRuns(7);
    }

    public function testGetBoostRunsReturnsDataArrayOnSuccess(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['data' => [
            ['id' => 10, 'status' => 'done'],
        ]]));

        $result = $this->client->getBoostRuns(7);

        self::assertCount(1, $result);
        self::assertSame(10, $result[0]['id']);
    }

    public function testGetBoostRunsReturnsEmptyArrayOnHttpError(): void
    {
        $this->curl->method('getStatus')->willReturn(404);
        $this->logger->expects(self::once())->method('warning');

        self::assertSame([], $this->client->getBoostRuns(99));
    }
}
