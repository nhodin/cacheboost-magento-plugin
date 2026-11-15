<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Environment;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use CacheBoost\CacheWarmer\Model\Environment\SnapshotCollector;
use CacheBoost\CacheWarmer\Service\AccountApi;
use CacheBoost\CacheWarmer\Test\Unit\Support\MemoryFlags;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnvironmentReporterTest extends TestCase
{
    private Config $config;
    private SnapshotCollector $collector;
    private AccountApi $api;
    private MemoryFlags $flags;
    private array $snapshot = ['platform' => 'magento', 'plugin_version' => '1.1.0'];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->collector = $this->createMock(SnapshotCollector::class);
        $this->collector->method('collect')->willReturnCallback(fn () => $this->snapshot);
        $this->api = $this->createMock(AccountApi::class);
        $this->flags = new MemoryFlags();
    }

    private function reporter(bool $connected = true, bool $sharing = true): EnvironmentReporter
    {
        $this->config->method('getApiKey')->willReturn($connected ? 'cb_live_k' : '');
        $this->config->method('getSiteId')->willReturn($connected ? 42 : 0);
        $this->config->method('isEnvironmentSharingEnabled')->willReturn($sharing);
        return new EnvironmentReporter($this->config, $this->collector, $this->api, $this->flags, new Json(), $this->createMock(LoggerInterface::class));
    }

    public function testSnapshotIsSentOnlyWhenItChanged(): void
    {
        $this->api->expects(self::exactly(2))->method('sendEnvironment')
            ->with('cb_live_k', 42, self::anything())
            ->willReturn(['status' => 200, 'body' => ['stored' => true]]);
        $reporter = $this->reporter();

        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->send(false));
        self::assertSame(EnvironmentReporter::STATUS_UNCHANGED, $reporter->send(false));
        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->state()['status'], 'an unchanged check keeps the last outcome');

        $this->snapshot['plugin_version'] = '1.1.1';
        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->send(false));
    }

    public function testSendNowResendsAnUnchangedSnapshot(): void
    {
        $this->api->expects(self::exactly(2))->method('sendEnvironment')->willReturn(['status' => 200, 'body' => []]);
        $reporter = $this->reporter();
        $reporter->send(false);
        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->send(true));
    }

    public function testNothingIsSentWhenSharingIsOffOrNotConnected(): void
    {
        $this->api->expects(self::never())->method('sendEnvironment');
        self::assertSame(EnvironmentReporter::STATUS_DISABLED, $this->reporter(true, false)->send(true));
        $this->setUp();
        $this->api->expects(self::never())->method('sendEnvironment');
        self::assertSame(EnvironmentReporter::STATUS_SKIPPED, $this->reporter(false)->send(true));
    }

    public function testRefusalsAreRecordedAndRetried(): void
    {
        $this->api->method('sendEnvironment')->willReturnOnConsecutiveCalls(
            ['status' => 403, 'body' => ['error' => 'Insufficient scope']],
            ['status' => 422, 'body' => ['field' => 'deploy_mode', 'reason' => 'unexpected_value']],
            ['status' => 0, 'body' => null],
            ['status' => 200, 'body' => []]
        );
        $reporter = $this->reporter();

        self::assertSame(EnvironmentReporter::STATUS_FORBIDDEN, $reporter->send(false));
        // A failed send stores no hash: the next check sends again.
        self::assertSame(EnvironmentReporter::STATUS_REJECTED, $reporter->send(false));
        self::assertSame(EnvironmentReporter::STATUS_FAILED, $reporter->send(false));
        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->send(false));
    }

    public function testScheduledSendRunsOnTheNextCronAndHourly(): void
    {
        $this->api->expects(self::exactly(2))->method('sendEnvironment')->willReturn(['status' => 200, 'body' => []]);
        $reporter = $this->reporter();

        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->runScheduled(), 'never checked: due');
        self::assertNull($reporter->runScheduled(), 'checked a moment ago');

        $reporter->schedule();
        $this->snapshot['plugin_version'] = '2.0.0';
        self::assertSame(EnvironmentReporter::STATUS_SENT, $reporter->runScheduled());

        $state = $this->flags->flags[EnvironmentReporter::FLAG_STATE];
        $state['checked_at'] = time() - 3601;
        $this->flags->flags[EnvironmentReporter::FLAG_STATE] = $state;
        self::assertSame(EnvironmentReporter::STATUS_UNCHANGED, $reporter->runScheduled());
    }

    public function testStoppingSharingDeletesWhatCacheBoostReceived(): void
    {
        $this->api->expects(self::once())->method('deleteEnvironment')->with('cb_live_k', 42)->willReturn(['status' => 200, 'body' => ['deleted' => true]]);
        $reporter = $this->reporter(true, false);

        $reporter->stopSharing();
        self::assertSame(EnvironmentReporter::STATUS_DISABLED, $reporter->state()['status']);
        self::assertArrayNotHasKey('hash', $reporter->state(), 'turning sharing back on sends again');
    }
}
