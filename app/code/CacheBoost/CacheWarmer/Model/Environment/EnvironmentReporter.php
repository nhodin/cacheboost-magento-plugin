<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Environment;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Service\AccountApi;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Sends the cache configuration snapshot (SnapshotCollector) to POST /v1/sites/{id}/environment,
 * like the WordPress plugin 1.3:
 *
 *   - triggers (Connect, Save Config) only schedule a send; the module cron performs it, so the
 *     blocking API call never delays an admin page, and every trigger of a request ends in one send;
 *   - an hourly check catches changes without a trigger (cache types, deploy mode, modules);
 *   - the snapshot is sent only when its hash changed;
 *   - sharing is on by default (connecting is the consent); turning it off sends one DELETE so
 *     CacheBoost drops what it received, and nothing more.
 */
class EnvironmentReporter
{
    public const FLAG_STATE = 'cacheboost_environment_state';

    private const CHECK_INTERVAL = 3600;

    public const STATUS_SENT      = 'sent';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_FORBIDDEN = 'forbidden';  // key without sites:write
    public const STATUS_REJECTED  = 'rejected';   // 422: the API refused a value
    public const STATUS_FAILED    = 'failed';
    public const STATUS_DISABLED  = 'disabled';
    public const STATUS_SKIPPED   = 'skipped';    // module not configured

    public function __construct(
        private readonly Config $config,
        private readonly SnapshotCollector $collector,
        private readonly AccountApi $api,
        private readonly FlagManager $flagManager,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {}

    /** Asks the next cron run to check the snapshot. Cheap: one flag write. */
    public function schedule(): void
    {
        $state = $this->state();
        $state['pending'] = true;
        $this->saveState($state);
    }

    /** Called by the module cron every few minutes: sends when scheduled or once an hour. */
    public function runScheduled(): ?string
    {
        $state = $this->state();
        $due = !empty($state['pending']) || (time() - (int) ($state['checked_at'] ?? 0)) >= self::CHECK_INTERVAL;
        return $due ? $this->send(false) : null;
    }

    /** Builds and sends the snapshot; $force resends an unchanged snapshot ("Send now"). */
    public function send(bool $force): string
    {
        $state = $this->state();
        $state['pending'] = false;
        $state['checked_at'] = time();

        $apiKey = $this->config->getApiKey();
        $siteId = $this->config->getSiteId();
        if ($apiKey === '' || $siteId <= 0) {
            return $this->finish($state, self::STATUS_SKIPPED);
        }
        if (!$this->config->isEnvironmentSharingEnabled()) {
            return $this->finish($state, self::STATUS_DISABLED);
        }

        $snapshot = $this->collector->collect();
        $hash = hash('sha256', $this->json->serialize($snapshot));
        if (!$force && ($state['hash'] ?? null) === $hash && ($state['site_id'] ?? null) === $siteId) {
            return $this->finish($state, self::STATUS_UNCHANGED, keepStatus: true);
        }

        $response = $this->api->sendEnvironment($apiKey, $siteId, $snapshot);
        if ($response['status'] === 200) {
            $state['hash'] = $hash;
            $state['site_id'] = $siteId;
            $state['sent_at'] = time();
            return $this->finish($state, self::STATUS_SENT);
        }

        if ($response['status'] === 403) {
            $status = self::STATUS_FORBIDDEN;
        } elseif ($response['status'] === 422) {
            $status = self::STATUS_REJECTED;
            $this->logger->warning(sprintf(
                'CacheBoost: environment snapshot refused — %s: %s',
                (string) ($response['body']['field'] ?? '?'),
                (string) ($response['body']['reason'] ?? '?')
            ));
        } else {
            $status = self::STATUS_FAILED;
        }
        return $this->finish($state, $status);
    }

    /** Sharing turned off: CacheBoost deletes the snapshots it received for this site. */
    public function stopSharing(): void
    {
        $apiKey = $this->config->getApiKey();
        $siteId = $this->config->getSiteId();
        if ($apiKey !== '' && $siteId > 0) {
            $response = $this->api->deleteEnvironment($apiKey, $siteId);
            if ($response['status'] !== 200) {
                $this->logger->warning(sprintf('CacheBoost: DELETE environment returned HTTP %d', $response['status']));
            }
        }
        $this->saveState(['status' => self::STATUS_DISABLED, 'checked_at' => time(), 'pending' => false]);
    }

    /**
     * @return array{status?: string, sent_at?: int, checked_at?: int, pending?: bool, hash?: string, site_id?: int}
     */
    public function state(): array
    {
        $raw = $this->flagManager->getFlagData(self::FLAG_STATE);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
                return is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    private function finish(array $state, string $status, bool $keepStatus = false): string
    {
        if (!$keepStatus || !isset($state['status'])) {
            $state['status'] = $status;
        }
        $this->saveState($state);
        return $status;
    }

    private function saveState(array $state): void
    {
        $this->flagManager->saveFlag(self::FLAG_STATE, $state);
    }
}
