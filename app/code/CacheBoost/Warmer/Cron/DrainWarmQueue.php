<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Cron;

use CacheBoost\Warmer\Model\Queue\ProcessingStrategy;
use CacheBoost\Warmer\Model\Queue\QueueDrainer;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute (etc/crontab.xml). Processes the warm queue itself when the
 * queue consumer is not expected to — consumers runner disabled in env.php, our
 * consumer missing from its allow-list, or "Cron job" processing mode — and as
 * a safety net when queued messages have been waiting too long.
 */
class DrainWarmQueue
{
    public const FLAG_LAST_DRAIN = 'cacheboost_warm_last_cron_drain';

    /** Stay well under the one-minute cron interval. */
    private const MAX_MESSAGES = 200;
    private const TIME_BUDGET_SECONDS = 45;

    public function __construct(
        private readonly ProcessingStrategy $strategy,
        private readonly QueueDrainer $drainer,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        try {
            if (!$this->strategy->shouldCronDrain()) {
                return;
            }
            $processed = $this->drainer->drain(self::MAX_MESSAGES, self::TIME_BUDGET_SECONDS);
            $this->flagManager->saveFlag(self::FLAG_LAST_DRAIN, ['at' => time(), 'processed' => $processed]);
        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: warm queue cron failed — ' . $e->getMessage());
        }
    }
}
