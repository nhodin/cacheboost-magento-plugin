<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Cron;

use CacheBoost\Warmer\Cron\DrainWarmQueue;
use CacheBoost\Warmer\Model\Queue\ProcessingStrategy;
use CacheBoost\Warmer\Model\Queue\QueueDrainer;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DrainWarmQueueTest extends TestCase
{
    public function testSkipsWhenStrategySaysNo(): void
    {
        $strategy = $this->createMock(ProcessingStrategy::class);
        $strategy->method('shouldCronDrain')->willReturn(false);
        $drainer = $this->createMock(QueueDrainer::class);
        $drainer->expects(self::never())->method('drain');
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::never())->method('saveFlag');

        (new DrainWarmQueue($strategy, $drainer, $flags, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testDrainsAndRecordsTheRun(): void
    {
        $strategy = $this->createMock(ProcessingStrategy::class);
        $strategy->method('shouldCronDrain')->willReturn(true);
        $drainer = $this->createMock(QueueDrainer::class);
        $drainer->expects(self::once())->method('drain')->willReturn(3);
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')->with(
            DrainWarmQueue::FLAG_LAST_DRAIN,
            self::callback(fn (array $value): bool => $value['processed'] === 3 && $value['at'] > 0)
        );

        (new DrainWarmQueue($strategy, $drainer, $flags, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testNeverThrows(): void
    {
        $strategy = $this->createMock(ProcessingStrategy::class);
        $strategy->method('shouldCronDrain')->willReturn(true);
        $drainer = $this->createMock(QueueDrainer::class);
        $drainer->method('drain')->willThrowException(new \RuntimeException('queue table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        (new DrainWarmQueue($strategy, $drainer, $this->createMock(FlagManager::class), $logger))->execute();
    }
}
