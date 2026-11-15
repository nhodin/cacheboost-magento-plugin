<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Queue;

use CacheBoost\Warmer\Model\Config;

/**
 * Decides who processes warm requests, so the module works on every hosting
 * setup — not only where Magento's consumers runner is active:
 *
 *   consumer  the cacheboostWarmConsumer queue consumer (started by Magento cron
 *             via cron_consumers_runner, or by a supervisor such as supervisord)
 *   cron      the module's own cron job (Cron\DrainWarmQueue) reads the queue every
 *             minute — works when cron_consumers_runner is disabled or excludes us
 *   sync      no queue: processed at the end of the request that invalidated the
 *             cache, after the response is sent where PHP-FPM allows it — for
 *             installations without any cron
 *
 * In auto mode the module cron also acts as a safety net: messages left
 * unprocessed for too long are drained even if a consumer is supposed to run.
 */
class ProcessingStrategy
{
    public const CONSUMER = 'consumer';
    public const CRON     = 'cron';
    public const SYNC     = 'sync';

    /**
     * Auto mode, consumers runner disabled or excluding us: a supervisor-managed
     * consumer may still be running, so give it this long before the cron steps in.
     */
    public const GRACE_WITHOUT_RUNNER = 120;

    /** Auto mode, consumers runner active: drain only if the consumer looks stuck. */
    public const GRACE_WITH_RUNNER = 600;

    public function __construct(
        private readonly Config $config,
        private readonly QueueInspector $inspector
    ) {}

    /**
     * Strategy for warm requests produced now. Cheap (no DB query): called on
     * every request that invalidates cache.
     */
    public function resolve(): string
    {
        return match ($this->config->getProcessingMode()) {
            Config::PROCESSING_QUEUE => self::CONSUMER,
            Config::PROCESSING_CRON  => self::CRON,
            Config::PROCESSING_SYNC  => self::SYNC,
            default => $this->inspector->isConsumerRunByCron() ? self::CONSUMER : self::CRON,
        };
    }

    /**
     * Minimum age (seconds) of the oldest pending message before the module cron
     * drains the queue; 0 drains whatever is pending, null never drains.
     */
    public function getCronDrainThreshold(): ?int
    {
        return match ($this->config->getProcessingMode()) {
            Config::PROCESSING_QUEUE => null,
            // sync: also flushes messages left over from a previous mode.
            Config::PROCESSING_CRON, Config::PROCESSING_SYNC => 0,
            default => $this->inspector->isConsumerRunByCron()
                ? self::GRACE_WITH_RUNNER
                : self::GRACE_WITHOUT_RUNNER,
        };
    }

    /** Called by the module cron every minute. */
    public function shouldCronDrain(): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }

        $threshold = $this->getCronDrainThreshold();
        if ($threshold === null) {
            return false;
        }

        $stats = $this->inspector->getPendingStats();
        if ($stats === null) {
            // Not inspectable (AMQP): only drain when nobody else is expected to.
            return $threshold === 0 || !$this->inspector->isConsumerRunByCron();
        }
        if ($stats['count'] === 0) {
            return false;
        }

        return $threshold === 0 || ($stats['oldest_age'] ?? 0) >= $threshold;
    }
}
