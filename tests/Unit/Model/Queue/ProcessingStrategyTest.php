<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Queue;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Queue\ProcessingStrategy;
use CacheBoost\CacheWarmer\Model\Queue\QueueInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessingStrategyTest extends TestCase
{
    private function strategy(
        string $mode,
        bool $runnerActive = true,
        ?array $stats = ['count' => 0, 'oldest_age' => null],
        bool $configured = true
    ): ProcessingStrategy {
        $config = $this->createMock(Config::class);
        $config->method('getProcessingMode')->willReturn($mode);
        $config->method('isConfigured')->willReturn($configured);

        $inspector = $this->createMock(QueueInspector::class);
        $inspector->method('isConsumerRunByCron')->willReturn($runnerActive);
        $inspector->method('getPendingStats')->willReturn($stats);

        return new ProcessingStrategy($config, $inspector);
    }

    // ── resolve ──────────────────────────────────────────────────────────────

    public static function resolveCases(): array
    {
        return [
            'auto, runner on'   => [Config::PROCESSING_AUTO, true, ProcessingStrategy::CONSUMER],
            'auto, runner off'  => [Config::PROCESSING_AUTO, false, ProcessingStrategy::CRON],
            'queue, runner off' => [Config::PROCESSING_QUEUE, false, ProcessingStrategy::CONSUMER],
            'cron, runner on'   => [Config::PROCESSING_CRON, true, ProcessingStrategy::CRON],
            'sync, runner on'   => [Config::PROCESSING_SYNC, true, ProcessingStrategy::SYNC],
        ];
    }

    #[DataProvider('resolveCases')]
    public function testResolve(string $mode, bool $runnerActive, string $expected): void
    {
        self::assertSame($expected, $this->strategy($mode, $runnerActive)->resolve());
    }

    // ── cron drain threshold ─────────────────────────────────────────────────

    public function testThresholds(): void
    {
        self::assertNull($this->strategy(Config::PROCESSING_QUEUE)->getCronDrainThreshold());
        self::assertSame(0, $this->strategy(Config::PROCESSING_CRON)->getCronDrainThreshold());
        self::assertSame(0, $this->strategy(Config::PROCESSING_SYNC)->getCronDrainThreshold());
        self::assertSame(
            ProcessingStrategy::GRACE_WITH_RUNNER,
            $this->strategy(Config::PROCESSING_AUTO, true)->getCronDrainThreshold()
        );
        self::assertSame(
            ProcessingStrategy::GRACE_WITHOUT_RUNNER,
            $this->strategy(Config::PROCESSING_AUTO, false)->getCronDrainThreshold()
        );
    }

    // ── shouldCronDrain ──────────────────────────────────────────────────────

    public function testNeverDrainsWhenModuleNotConfigured(): void
    {
        $stats = ['count' => 5, 'oldest_age' => 9999];
        self::assertFalse($this->strategy(Config::PROCESSING_CRON, true, $stats, false)->shouldCronDrain());
    }

    public function testQueueModeNeverDrains(): void
    {
        $stats = ['count' => 5, 'oldest_age' => 9999];
        self::assertFalse($this->strategy(Config::PROCESSING_QUEUE, false, $stats)->shouldCronDrain());
    }

    public function testCronModeDrainsAnyPendingMessage(): void
    {
        $stats = ['count' => 1, 'oldest_age' => 0];
        self::assertTrue($this->strategy(Config::PROCESSING_CRON, true, $stats)->shouldCronDrain());
    }

    public function testNothingToDrainWhenQueueIsEmpty(): void
    {
        self::assertFalse($this->strategy(Config::PROCESSING_CRON)->shouldCronDrain());
    }

    public function testSyncModeDrainsLeftoverMessages(): void
    {
        $stats = ['count' => 3, 'oldest_age' => 5];
        self::assertTrue($this->strategy(Config::PROCESSING_SYNC, true, $stats)->shouldCronDrain());
    }

    public function testAutoWithoutRunnerGivesASupervisedConsumerAGracePeriod(): void
    {
        $fresh = ['count' => 2, 'oldest_age' => ProcessingStrategy::GRACE_WITHOUT_RUNNER - 1];
        $stale = ['count' => 2, 'oldest_age' => ProcessingStrategy::GRACE_WITHOUT_RUNNER];

        self::assertFalse($this->strategy(Config::PROCESSING_AUTO, false, $fresh)->shouldCronDrain());
        self::assertTrue($this->strategy(Config::PROCESSING_AUTO, false, $stale)->shouldCronDrain());
    }

    public function testAutoWithRunnerOnlyDrainsAStuckQueue(): void
    {
        $recent = ['count' => 2, 'oldest_age' => ProcessingStrategy::GRACE_WITHOUT_RUNNER];
        $stuck  = ['count' => 2, 'oldest_age' => ProcessingStrategy::GRACE_WITH_RUNNER];

        self::assertFalse($this->strategy(Config::PROCESSING_AUTO, true, $recent)->shouldCronDrain());
        self::assertTrue($this->strategy(Config::PROCESSING_AUTO, true, $stuck)->shouldCronDrain());
    }

    public function testUninspectableQueueDrainsOnlyWhenNoConsumerIsExpected(): void
    {
        // AMQP: no stats available.
        self::assertFalse($this->strategy(Config::PROCESSING_AUTO, true, null)->shouldCronDrain());
        self::assertTrue($this->strategy(Config::PROCESSING_AUTO, false, null)->shouldCronDrain());
        self::assertTrue($this->strategy(Config::PROCESSING_CRON, true, null)->shouldCronDrain());
    }
}
