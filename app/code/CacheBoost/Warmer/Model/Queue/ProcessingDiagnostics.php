<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Queue;

use CacheBoost\Warmer\Cron\DrainWarmQueue;
use CacheBoost\Warmer\Model\Config;
use Magento\Framework\FlagManager;

/**
 * Builds the "Background Processing" status shown in the admin: which strategy
 * is in effect, the state of the queue, and problems that would leave cache
 * invalidations unwarmed (cron not running, messages piling up…).
 */
class ProcessingDiagnostics
{
    /** Magento cron is considered down when no job ran for this long. */
    public const CRON_DOWN_AFTER = 900;

    public const LEVEL_OK      = 'ok';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    public function __construct(
        private readonly Config $config,
        private readonly ProcessingStrategy $strategy,
        private readonly QueueInspector $inspector,
        private readonly FlagManager $flagManager
    ) {}

    /**
     * @return array{
     *     strategy: string,
     *     runner_active: bool,
     *     connection: string,
     *     pending: array{count: int, oldest_age: int|null}|null,
     *     cron_age: int|null,
     *     last_drain: array{at: int, processed: int}|null,
     *     level: string,
     *     messages: string[]
     * }
     */
    public function collect(): array
    {
        $strategy = $this->strategy->resolve();
        $runnerActive = $this->inspector->isConsumerRunByCron();
        $pending = $this->inspector->getPendingStats();
        $cronAge = $this->inspector->getLastCronRunAge();
        $lastDrain = $this->flagManager->getFlagData(DrainWarmQueue::FLAG_LAST_DRAIN);

        $level = self::LEVEL_OK;
        $messages = [];
        $raise = static function (string $newLevel, string $message) use (&$level, &$messages): void {
            $messages[] = $message;
            if ($newLevel === self::LEVEL_ERROR || $level === self::LEVEL_OK) {
                $level = $newLevel;
            }
        };

        $cronDown = $cronAge === null || $cronAge > self::CRON_DOWN_AFTER;
        if ($cronDown && $strategy !== ProcessingStrategy::SYNC) {
            $raise(self::LEVEL_ERROR, (string) __(
                'Magento cron does not seem to run on this server, so queued warm requests are never processed. '
                . 'Set up cron (bin/magento cron:install) or switch Processing Mode to Synchronous.'
            ));
        }

        if ($this->config->getProcessingMode() === Config::PROCESSING_QUEUE && !$runnerActive) {
            $raise(self::LEVEL_WARNING, (string) __(
                'The consumers runner is disabled or does not include cacheboostWarmConsumer: '
                . 'make sure a process supervisor runs "bin/magento queue:consumers:start cacheboostWarmConsumer", '
                . 'or switch Processing Mode to Auto.'
            ));
        }

        $oldest = $pending['oldest_age'] ?? null;
        if ($oldest !== null && $oldest > ProcessingStrategy::GRACE_WITH_RUNNER) {
            $raise(self::LEVEL_WARNING, (string) __(
                '%1 warm request(s) have been waiting for more than %2 minutes.',
                $pending['count'],
                (int) floor($oldest / 60)
            ));
        }

        return [
            'strategy' => $strategy,
            'runner_active' => $runnerActive,
            'connection' => $this->inspector->getConnectionName(),
            'pending' => $pending,
            'cron_age' => $cronAge,
            'last_drain' => is_array($lastDrain) && isset($lastDrain['at'])
                ? ['at' => (int) $lastDrain['at'], 'processed' => (int) ($lastDrain['processed'] ?? 0)]
                : null,
            'level' => $level,
            'messages' => $messages,
        ];
    }
}
