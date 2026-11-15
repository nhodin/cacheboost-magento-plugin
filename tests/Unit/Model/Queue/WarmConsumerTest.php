<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Model\Queue;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\Queue\WarmConsumer;
use CacheBoost\Warmer\Service\ApiClient;
use CacheBoost\Warmer\Service\TagUrlResolver;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WarmConsumerTest extends TestCase
{
    private Config $config;
    private ApiClient $apiClient;
    private TagUrlResolver $resolver;
    private LoggerInterface $logger;
    private WarmConsumer $consumer;

    protected function setUp(): void
    {
        $this->config    = $this->createMock(Config::class);
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->resolver  = $this->createMock(TagUrlResolver::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->consumer = new WarmConsumer(
            $this->config,
            $this->apiClient,
            $this->resolver,
            new Json(),
            $this->logger
        );
    }

    private function message(array $payload): string
    {
        return json_encode($payload);
    }

    // ── guards ───────────────────────────────────────────────────────────────

    public function testDoesNothingWhenNotConfigured(): void
    {
        // Config re-checked at consume time: module disabled since publish.
        $this->config->method('isConfigured')->willReturn(false);
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->consumer->process($this->message(['full_flush' => true, 'tags' => [], 'truncated' => false]));
    }

    public function testMalformedJsonIsLoggedAndNeverThrows(): void
    {
        // A poison message must be dropped, not redelivered forever.
        $this->logger->expects(self::once())->method('error');
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->consumer->process('{not-json');
    }

    public function testNonArrayPayloadIsDiscardedWithWarning(): void
    {
        $this->logger->expects(self::once())->method('warning');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->consumer->process('"just a string"');
    }

    public function testEmptyTagListDoesNothing(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::never())->method('triggerBoostRun');

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => [], 'truncated' => false]));
    }

    // ── full flush ───────────────────────────────────────────────────────────

    public function testFullFlushTriggersBoostRun(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(7);
        $this->apiClient->expects(self::once())->method('triggerBoostRun')->with(7);
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->consumer->process($this->message(['full_flush' => true, 'tags' => [], 'truncated' => false]));
    }

    public function testFullFlushWithoutBoostIdIsSkippedWithInfoLog(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getBoostId')->willReturn(0);
        $this->apiClient->expects(self::never())->method('triggerBoostRun');
        $this->logger->expects(self::once())->method('info');

        $this->consumer->process($this->message(['full_flush' => true, 'tags' => [], 'truncated' => false]));
    }

    // ── full_only mode ───────────────────────────────────────────────────────

    public function testFullOnlyModeTriggersBoostRunForTags(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('full_only');
        $this->config->method('getBoostId')->willReturn(9);
        $this->apiClient->expects(self::once())->method('triggerBoostRun')->with(9);
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->resolver->expects(self::never())->method('resolve');

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_1'], 'truncated' => false]));
    }

    // ── smart mode ───────────────────────────────────────────────────────────

    public function testSmartModeResolvesTagsAndTriggersWarm(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with(['cat_p_42'])
            ->willReturn(['https://example.com/product-slug.html']);

        $this->apiClient->expects(self::once())
            ->method('triggerWarm')
            ->with(['https://example.com/product-slug.html']);

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_42'], 'truncated' => false]));
    }

    public function testSmartModeSkipsWarmWhenNoUrlsResolved(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->resolver->method('resolve')->willReturn([]);
        $this->apiClient->expects(self::never())->method('triggerWarm');

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_99'], 'truncated' => false]));
    }

    public function testNonStringTagsAreFilteredOut(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with(['cat_p_1'])
            ->willReturn([]);

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => [123, null, 'cat_p_1'], 'truncated' => false]));
    }

    // ── truncated messages (tag cap exceeded at publish time) ────────────────

    public function testTruncatedMessageFallsBackToBoostRun(): void
    {
        // The publisher cut the tag list off at the cap: a full Boost run covers
        // everything the dropped tags would have warmed.
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->config->method('getBoostId')->willReturn(7);

        $this->resolver->expects(self::never())->method('resolve');
        $this->apiClient->expects(self::never())->method('triggerWarm');
        $this->apiClient->expects(self::once())->method('triggerBoostRun')->with(7);

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_1'], 'truncated' => true]));
    }

    public function testTruncatedMessageWithoutBoostIdResolvesRemainingTags(): void
    {
        // No full-run fallback available: warm what survived the cap and warn.
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->config->method('getBoostId')->willReturn(0);
        $this->logger->expects(self::once())->method('warning');

        $this->resolver->expects(self::once())
            ->method('resolve')
            ->with(['cat_p_1'])
            ->willReturn(['https://example.com/page.html']);

        $this->apiClient->expects(self::never())->method('triggerBoostRun');
        $this->apiClient->expects(self::once())
            ->method('triggerWarm')
            ->with(['https://example.com/page.html']);

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_1'], 'truncated' => true]));
    }

    // ── resilience ───────────────────────────────────────────────────────────

    public function testResolverExceptionIsCaughtAndLogged(): void
    {
        // The consumer must never throw: the queue framework would redeliver.
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('getMode')->willReturn('smart');
        $this->resolver->method('resolve')->willThrowException(new \RuntimeException('DB gone'));
        $this->logger->expects(self::once())->method('error');

        $this->consumer->process($this->message(['full_flush' => false, 'tags' => ['cat_p_1'], 'truncated' => false]));
    }
}
