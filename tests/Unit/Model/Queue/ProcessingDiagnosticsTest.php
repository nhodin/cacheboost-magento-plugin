<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Model\Queue;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\Queue\ProcessingDiagnostics;
use CacheBoost\Warmer\Model\Queue\ProcessingStrategy;
use CacheBoost\Warmer\Model\Queue\QueueInspector;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

class ProcessingDiagnosticsTest extends TestCase
{
    private function collect(
        string $mode,
        string $strategy,
        bool $runnerActive,
        ?array $pending,
        ?int $cronAge,
        mixed $lastDrain = null
    ): array {
        $config = $this->createMock(Config::class);
        $config->method('getProcessingMode')->willReturn($mode);

        $strategyMock = $this->createMock(ProcessingStrategy::class);
        $strategyMock->method('resolve')->willReturn($strategy);

        $inspector = $this->createMock(QueueInspector::class);
        $inspector->method('isConsumerRunByCron')->willReturn($runnerActive);
        $inspector->method('getPendingStats')->willReturn($pending);
        $inspector->method('getLastCronRunAge')->willReturn($cronAge);
        $inspector->method('getConnectionName')->willReturn('db');

        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn($lastDrain);

        return (new ProcessingDiagnostics($config, $strategyMock, $inspector, $flags))->collect();
    }

    public function testHealthySetupReportsOk(): void
    {
        $d = $this->collect(Config::PROCESSING_AUTO, ProcessingStrategy::CONSUMER, true, ['count' => 0, 'oldest_age' => null], 30);

        self::assertSame(ProcessingDiagnostics::LEVEL_OK, $d['level']);
        self::assertSame([], $d['messages']);
        self::assertNull($d['last_drain']);
    }

    public function testCronNotRunningIsAnError(): void
    {
        $d = $this->collect(Config::PROCESSING_AUTO, ProcessingStrategy::CRON, false, ['count' => 4, 'oldest_age' => 60], null);

        self::assertSame(ProcessingDiagnostics::LEVEL_ERROR, $d['level']);
        self::assertStringContainsString('Synchronous', $d['messages'][0]);
    }

    public function testCronNotRunningIsFineInSyncMode(): void
    {
        $d = $this->collect(Config::PROCESSING_SYNC, ProcessingStrategy::SYNC, false, ['count' => 0, 'oldest_age' => null], null);

        self::assertSame(ProcessingDiagnostics::LEVEL_OK, $d['level']);
    }

    public function testQueueModeWithoutRunnerWarnsAboutSupervisor(): void
    {
        $d = $this->collect(Config::PROCESSING_QUEUE, ProcessingStrategy::CONSUMER, false, ['count' => 0, 'oldest_age' => null], 30);

        self::assertSame(ProcessingDiagnostics::LEVEL_WARNING, $d['level']);
        self::assertStringContainsString('queue:consumers:start cacheboostWarmConsumer', $d['messages'][0]);
    }

    public function testOldPendingMessagesWarn(): void
    {
        $d = $this->collect(Config::PROCESSING_AUTO, ProcessingStrategy::CONSUMER, true, ['count' => 12, 'oldest_age' => 1800], 30);

        self::assertSame(ProcessingDiagnostics::LEVEL_WARNING, $d['level']);
        self::assertSame('12 warm request(s) have been waiting for more than 30 minutes.', $d['messages'][0]);
    }

    public function testErrorIsNotDowngradedByALaterWarning(): void
    {
        $d = $this->collect(Config::PROCESSING_QUEUE, ProcessingStrategy::CONSUMER, false, ['count' => 12, 'oldest_age' => 1800], 5000);

        self::assertSame(ProcessingDiagnostics::LEVEL_ERROR, $d['level']);
        self::assertCount(3, $d['messages']);
    }

    public function testLastDrainIsExposed(): void
    {
        $d = $this->collect(Config::PROCESSING_CRON, ProcessingStrategy::CRON, false, ['count' => 0, 'oldest_age' => null], 30, ['at' => 1700000000, 'processed' => 7]);

        self::assertSame(['at' => 1700000000, 'processed' => 7], $d['last_drain']);
    }
}
