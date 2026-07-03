<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Service;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\UrlCollector;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlCollectorTest extends TestCase
{
    private Config $config;
    private PublisherInterface $publisher;
    private LoggerInterface $logger;
    private UrlCollector $collector;

    protected function setUp(): void
    {
        $this->config    = $this->createMock(Config::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->collector = new UrlCollector(
            $this->config,
            $this->publisher,
            new Json(),
            $this->logger
        );
    }

    /** Asserts the next publish() call carries exactly this payload. */
    private function expectPublishedPayload(array $expected): void
    {
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(
                UrlCollector::TOPIC,
                self::callback(function (string $message) use ($expected): bool {
                    self::assertSame($expected, json_decode($message, true));
                    return true;
                })
            );
    }

    // ── flush – guard conditions ─────────────────────────────────────────────

    public function testFlushDoesNothingWhenNotConfigured(): void
    {
        $this->config->method('isConfigured')->willReturn(false);
        $this->publisher->expects(self::never())->method('publish');

        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    public function testFlushIsIdempotent(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        // publish must be called exactly once despite two flush() calls.
        $this->publisher->expects(self::once())->method('publish');

        $this->collector->markFullFlush();
        $this->collector->flush();
        $this->collector->flush();
    }

    public function testFlushPublishesNothingWhenNoTagsAndNoFullFlush(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->publisher->expects(self::never())->method('publish');

        $this->collector->flush();
    }

    // ── flush – payloads ─────────────────────────────────────────────────────

    public function testFullFlushPublishesFullFlushPayload(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->expectPublishedPayload(['full_flush' => true, 'tags' => [], 'truncated' => false]);

        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    public function testFullFlushTakesPriorityOverPendingTags(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->expectPublishedPayload(['full_flush' => true, 'tags' => [], 'truncated' => false]);

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->markFullFlush();
        $this->collector->flush();
    }

    public function testTagsArePublishedDeduplicated(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->expectPublishedPayload([
            'full_flush' => false,
            'tags'       => ['cat_p_1', 'cat_c_2'],
            'truncated'  => false,
        ]);

        $this->collector->collectTags(['cat_p_1', 'cat_c_2']);
        $this->collector->collectTags(['cat_p_1']); // duplicate, ignored
        $this->collector->flush();
    }

    public function testCollectTagsIgnoresNonStringValues(): void
    {
        $this->config->method('isConfigured')->willReturn(true);
        $this->expectPublishedPayload([
            'full_flush' => false,
            'tags'       => ['cat_p_1'],
            'truncated'  => false,
        ]);

        // Should not crash on mixed types; only the string 'cat_p_1' is kept.
        $this->collector->collectTags([123, null, 'cat_p_1', true]);
        $this->collector->flush();
    }

    // ── flush – MAX_TAGS cap ─────────────────────────────────────────────────

    public function testFlushTruncatesTagsAboveTheCap(): void
    {
        // 501 tags collected → message carries the first 500 with truncated=true,
        // so the consumer can decide to fall back to a full Boost run.
        $this->config->method('isConfigured')->willReturn(true);
        $this->logger->expects(self::atLeastOnce())->method('info');

        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(
                UrlCollector::TOPIC,
                self::callback(function (string $message): bool {
                    $payload = json_decode($message, true);
                    self::assertFalse($payload['full_flush']);
                    self::assertTrue($payload['truncated']);
                    self::assertCount(500, $payload['tags']);
                    self::assertSame('cat_p_1', $payload['tags'][0]);
                    self::assertSame('cat_p_500', $payload['tags'][499]);
                    return true;
                })
            );

        $tags = array_map(static fn(int $i) => "cat_p_{$i}", range(1, 501));
        $this->collector->collectTags($tags);
        $this->collector->flush();
    }

    // ── flush – publish failures ─────────────────────────────────────────────

    public function testPublishFailureIsLoggedAndNeverThrows(): void
    {
        // A broken queue must never break the request that flushed the cache.
        $this->config->method('isConfigured')->willReturn(true);
        $this->publisher->method('publish')
            ->willThrowException(new \RuntimeException('queue is down'));
        $this->logger->expects(self::once())->method('error');

        $this->collector->collectTags(['cat_p_1']);
        $this->collector->flush();
    }
}
