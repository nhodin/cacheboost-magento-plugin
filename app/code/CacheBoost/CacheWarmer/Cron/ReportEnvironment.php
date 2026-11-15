<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Cron;

use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes (etc/crontab.xml): sends the cache configuration snapshot when a trigger
 * scheduled it, or once an hour, and only when it changed.
 */
class ReportEnvironment
{
    public function __construct(
        private readonly EnvironmentReporter $reporter,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        try {
            $this->reporter->runScheduled();
        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: environment snapshot cron failed — ' . $e->getMessage());
        }
    }
}
