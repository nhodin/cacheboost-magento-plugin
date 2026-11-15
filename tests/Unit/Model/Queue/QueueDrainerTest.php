<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Queue;

use CacheBoost\CacheWarmer\Model\Queue\QueueDrainer;
use CacheBoost\CacheWarmer\Model\Queue\WarmConsumer;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItemInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\MessageEncoder;
use Magento\Framework\MessageQueue\QueueFactoryInterface;
use Magento\Framework\MessageQueue\QueueInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueDrainerTest extends TestCase
{
    private QueueInterface $queue;
    private WarmConsumer $consumer;
    private QueueDrainer $drainer;

    protected function setUp(): void
    {
        $item = $this->createMock(ConsumerConfigItemInterface::class);
        $item->method('getQueue')->willReturn('cacheboost.warm');
        $item->method('getConnection')->willReturn('db');
        $consumerConfig = $this->createMock(ConsumerConfig::class);
        $consumerConfig->method('getConsumer')->with('cacheboostWarmConsumer')->willReturn($item);

        $this->queue = $this->createMock(QueueInterface::class);
        $queueFactory = $this->createMock(QueueFactoryInterface::class);
        $queueFactory->method('create')->with('cacheboost.warm', 'db')->willReturn($this->queue);

        $this->consumer = $this->createMock(WarmConsumer::class);

        $this->drainer = new QueueDrainer(
            $consumerConfig,
            $queueFactory,
            new MessageEncoder(),
            $this->consumer,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** Envelope as stored by the db connection: the string payload, JSON-encoded. */
    private function envelope(string $payload): EnvelopeInterface
    {
        $envelope = $this->createMock(EnvelopeInterface::class);
        $envelope->method('getBody')->willReturn(json_encode($payload));
        return $envelope;
    }

    public function testProcessesAndAcknowledgesUntilQueueIsEmpty(): void
    {
        $first = $this->envelope('{"full_flush":true,"tags":[],"truncated":false}');
        $second = $this->envelope('{"full_flush":false,"tags":["cat_p_1"],"truncated":false}');
        $this->queue->method('dequeue')->willReturnOnConsecutiveCalls($first, $second, null);

        $processed = [];
        $this->consumer->expects(self::exactly(2))->method('process')
            ->willReturnCallback(function (string $message) use (&$processed): void {
                $processed[] = $message;
            });
        $this->queue->expects(self::exactly(2))->method('acknowledge');
        $this->queue->expects(self::never())->method('reject');

        self::assertSame(2, $this->drainer->drain(100, 45));
        self::assertSame([
            '{"full_flush":true,"tags":[],"truncated":false}',
            '{"full_flush":false,"tags":["cat_p_1"],"truncated":false}',
        ], $processed);
    }

    public function testStopsAtTheMessageBudget(): void
    {
        $this->queue->expects(self::exactly(2))->method('dequeue')
            ->willReturn($this->envelope('{"full_flush":true}'));
        $this->consumer->expects(self::exactly(2))->method('process');

        self::assertSame(2, $this->drainer->drain(2, 45));
    }

    public function testEmptyQueueProcessesNothing(): void
    {
        $this->queue->method('dequeue')->willReturn(null);
        $this->consumer->expects(self::never())->method('process');

        self::assertSame(0, $this->drainer->drain(100, 45));
    }

    public function testFailingMessageIsRejectedWithoutRequeue(): void
    {
        $bad = $this->envelope('{"full_flush":true}');
        $this->queue->method('dequeue')->willReturnOnConsecutiveCalls($bad, null);
        $this->consumer->method('process')->willThrowException(new \RuntimeException('boom'));

        $this->queue->expects(self::never())->method('acknowledge');
        $this->queue->expects(self::once())->method('reject')->with($bad, false, 'boom');

        self::assertSame(1, $this->drainer->drain(100, 45));
    }
}
