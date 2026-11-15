<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Queue;

use CacheBoost\CacheWarmer\Service\UrlCollector;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\MessageEncoder;
use Magento\Framework\MessageQueue\QueueFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes pending cacheboost.warm messages in the current process, without a
 * long-running consumer: dequeue() is non-blocking, so draining stops as soon
 * as the queue is empty or the message/time budget is spent.
 *
 * Used by the module cron when the queue consumer is not (or not reliably) run.
 * Competing safely with a real consumer is the queue driver's job: the MySQL
 * driver claims each message atomically before handing it out.
 */
class QueueDrainer
{
    public function __construct(
        private readonly ConsumerConfig $consumerConfig,
        private readonly QueueFactoryInterface $queueFactory,
        private readonly MessageEncoder $messageEncoder,
        private readonly WarmConsumer $consumer,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return int number of messages taken off the queue
     */
    public function drain(int $maxMessages, int $timeBudgetSeconds): int
    {
        $consumerConfig = $this->consumerConfig->getConsumer(QueueInspector::CONSUMER_NAME);
        $queue = $this->queueFactory->create($consumerConfig->getQueue(), $consumerConfig->getConnection());

        $deadline = time() + $timeBudgetSeconds;
        $processed = 0;

        while ($processed < $maxMessages && time() < $deadline) {
            $envelope = $queue->dequeue();
            if ($envelope === null) {
                break;
            }
            $processed++;

            try {
                $message = $this->messageEncoder->decode(UrlCollector::TOPIC, $envelope->getBody());
                // WarmConsumer::process() never throws; API failures are logged there.
                $this->consumer->process((string) $message);
                $queue->acknowledge($envelope);
            } catch (\Throwable $e) {
                // Undecodable message: reject without requeue so it cannot loop.
                $queue->reject($envelope, false, $e->getMessage());
                $this->logger->error('CacheBoost: rejected unreadable warm message — ' . $e->getMessage());
            }
        }

        if ($processed > 0) {
            $this->logger->info(sprintf('CacheBoost: cron processed %d queued warm request(s).', $processed));
        }

        return $processed;
    }
}
