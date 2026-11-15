<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Queue;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Psr\Log\LoggerInterface;

/**
 * Read-only view of the environment the warm queue lives in: whether Magento's
 * cron consumers runner will start our consumer, how many messages are waiting,
 * and whether Magento cron runs at all.
 *
 * Every method is cheap and never throws: callers use the answers to pick a
 * processing strategy and to render the admin diagnostic.
 */
class QueueInspector
{
    public const CONSUMER_NAME = 'cacheboostWarmConsumer';

    /** queue_message_status.status values (Magento\MysqlMq\Model\QueueManagement). */
    private const STATUS_NEW            = 2;
    private const STATUS_RETRY_REQUIRED = 5;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ConsumerConfig $consumerConfig,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Mirrors Magento\MessageQueue\Model\Cron\ConsumersRunner: the runner is on
     * unless cron_consumers_runner/cron_run is false in env.php, and a non-empty
     * cron_consumers_runner/consumers list is an allow-list. On Adobe Commerce
     * Cloud, ece-tools writes CRON_CONSUMERS_RUNNER into these same keys.
     *
     * True does not prove Magento cron actually runs: see getLastCronRunAge().
     */
    public function isConsumerRunByCron(): bool
    {
        try {
            if (!$this->deploymentConfig->get('cron_consumers_runner/cron_run', true)) {
                return false;
            }
            $allowed = (array) $this->deploymentConfig->get('cron_consumers_runner/consumers', []);
            return empty($allowed) || in_array(self::CONSUMER_NAME, $allowed, true);
        } catch (\Throwable $e) {
            $this->logger->warning('CacheBoost: unable to read the consumers runner configuration — ' . $e->getMessage());
            return true;
        }
    }

    /** Queue connection the consumer reads from ("db" unless remapped in env.php). */
    public function getConnectionName(): string
    {
        try {
            return (string) $this->consumerConfig->getConsumer(self::CONSUMER_NAME)->getConnection();
        } catch (\Throwable) {
            return 'db';
        }
    }

    /**
     * Pending message statistics, only available for the MySQL ("db") queue.
     *
     * @return array{count: int, oldest_age: int|null}|null null when the queue
     *         cannot be inspected (AMQP connection, missing tables, DB error)
     */
    public function getPendingStats(): ?array
    {
        if ($this->getConnectionName() !== 'db') {
            return null;
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from(
                    ['s' => $this->resource->getTableName('queue_message_status')],
                    [
                        'cnt' => 'COUNT(*)',
                        // Both sides in the session time zone: TIMESTAMP column vs NOW().
                        'oldest_age' => 'TIMESTAMPDIFF(SECOND, MIN(s.updated_at), NOW())',
                    ]
                )
                ->join(['q' => $this->resource->getTableName('queue')], 'q.id = s.queue_id', [])
                ->where('q.name = ?', $this->getQueueName())
                ->where('s.status IN (?)', [self::STATUS_NEW, self::STATUS_RETRY_REQUIRED]);

            $row = $connection->fetchRow($select) ?: [];
            $count = (int) ($row['cnt'] ?? 0);

            return [
                'count' => $count,
                'oldest_age' => $count > 0 && isset($row['oldest_age']) ? max(0, (int) $row['oldest_age']) : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('CacheBoost: unable to inspect the warm queue — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Seconds since Magento cron last executed any job, or null when cron has
     * never run (or cron_schedule cannot be read). Diagnostic only.
     */
    public function getLastCronRunAge(): ?int
    {
        try {
            $connection = $this->resource->getConnection();
            // cron_schedule stores GMT values written by PHP, hence UTC_TIMESTAMP().
            $select = $connection->select()
                ->from(
                    $this->resource->getTableName('cron_schedule'),
                    ['age' => 'TIMESTAMPDIFF(SECOND, MAX(executed_at), UTC_TIMESTAMP())']
                )
                ->where('executed_at IS NOT NULL');

            $age = $connection->fetchOne($select);
            return $age === null || $age === false ? null : max(0, (int) $age);
        } catch (\Throwable $e) {
            $this->logger->warning('CacheBoost: unable to read cron_schedule — ' . $e->getMessage());
            return null;
        }
    }

    private function getQueueName(): string
    {
        try {
            return (string) $this->consumerConfig->getConsumer(self::CONSUMER_NAME)->getQueue();
        } catch (\Throwable) {
            return 'cacheboost.warm';
        }
    }
}
