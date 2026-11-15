<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Queue;

use CacheBoost\CacheWarmer\Model\Queue\QueueInspector;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItemInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueInspectorTest extends TestCase
{
    private function inspector(array $env, string $connection = 'db', ?ResourceConnection $resource = null): QueueInspector
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn ($key, $default = null) => array_key_exists($key, $env) ? $env[$key] : $default
        );

        $item = $this->createMock(ConsumerConfigItemInterface::class);
        $item->method('getConnection')->willReturn($connection);
        $item->method('getQueue')->willReturn('cacheboost.warm');
        $consumerConfig = $this->createMock(ConsumerConfig::class);
        $consumerConfig->method('getConsumer')->willReturn($item);

        return new QueueInspector(
            $deploymentConfig,
            $consumerConfig,
            $resource ?? $this->createMock(ResourceConnection::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testRunnerIsActiveByDefault(): void
    {
        // Magento's default when cron_consumers_runner is absent from env.php.
        self::assertTrue($this->inspector([])->isConsumerRunByCron());
    }

    public function testRunnerDisabledInEnvPhp(): void
    {
        self::assertFalse($this->inspector(['cron_consumers_runner/cron_run' => false])->isConsumerRunByCron());
    }

    public function testAllowListWithoutOurConsumer(): void
    {
        $env = ['cron_consumers_runner/consumers' => ['product_action_attribute.update', 'async.operations.all']];
        self::assertFalse($this->inspector($env)->isConsumerRunByCron());
    }

    public function testAllowListIncludingOurConsumer(): void
    {
        $env = ['cron_consumers_runner/consumers' => ['async.operations.all', 'cacheboostWarmConsumer']];
        self::assertTrue($this->inspector($env)->isConsumerRunByCron());
    }

    public function testPendingStatsUnavailableForAmqp(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        self::assertNull($this->inspector([], 'amqp', $resource)->getPendingStats());
    }

    public function testDatabaseErrorsNeverEscape(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('table missing'));
        $inspector = $this->inspector([], 'db', $resource);

        self::assertNull($inspector->getPendingStats());
        self::assertNull($inspector->getLastCronRunAge());
    }
}
