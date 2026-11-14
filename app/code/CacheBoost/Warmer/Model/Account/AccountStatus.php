<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Account;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\AccountApi;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Email verification state of the CacheBoost account behind the API key.
 *
 * Accounts created with "Connect to CacheBoost" must verify their email within 7 days; after
 * that the API refuses the key (403, code "email_unverified"). Warming runs in the background,
 * so without this check the merchant would never learn why it stopped. Same states and cache
 * lifetimes as the WordPress plugin.
 */
class AccountStatus
{
    public const OK      = 'ok';
    public const PENDING = 'pending';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    private const CACHE_KEY   = 'cacheboost_account_status';
    private const TTL_OK      = 43200; // 12 h: nothing to report
    private const TTL_PENDING = 3600;  // 1 h: the notice disappears soon after verification
    private const TTL_UNKNOWN = 900;   // 15 min: API unreachable, retry without slowing every admin page

    /** Admin pages must not wait long on the API: the status is informative. */
    private const TIMEOUT = 3;

    public function __construct(
        private readonly Config $config,
        private readonly AccountApi $api,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {}

    /**
     * @return array{state: string, deadline?: string}
     */
    public function get(): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            return ['state' => self::UNKNOWN];
        }

        $cacheKey = self::CACHE_KEY . '_' . substr(hash('sha256', $apiKey), 0, 16);
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $status = $this->json->unserialize($cached);
                if (is_array($status) && isset($status['state'])) {
                    return $status;
                }
            } catch (\Throwable) {
                // Corrupted entry: fetch again.
            }
        }

        $status = $this->fetch($apiKey);
        $ttl = match ($status['state']) {
            self::OK      => self::TTL_OK,
            self::UNKNOWN => self::TTL_UNKNOWN,
            default       => self::TTL_PENDING,
        };
        $this->cache->save($this->json->serialize($status), $cacheKey, [], $ttl);
        return $status;
    }

    /**
     * @return array{state: string, deadline?: string}
     */
    public function fetch(string $apiKey): array
    {
        $response = $this->api->me($apiKey, self::TIMEOUT);
        return self::fromResponse($response['status'], $response['body']);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{state: string, deadline?: string}
     */
    public static function fromResponse(int $status, ?array $body): array
    {
        if ($status === 403 && ($body['code'] ?? '') === 'email_unverified') {
            return ['state' => self::BLOCKED];
        }
        if ($status !== 200 || !is_array($body)) {
            return ['state' => self::UNKNOWN];
        }
        // Older API versions do not send these fields: treat as verified.
        if (($body['email_verified'] ?? true) === false && !empty($body['email_verify_deadline'])) {
            return ['state' => self::PENDING, 'deadline' => (string) $body['email_verify_deadline']];
        }
        return ['state' => self::OK];
    }

    /** A new key or a fresh verification: read the state again on the next admin page. */
    public function clear(): void
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey !== '') {
            $this->cache->remove(self::CACHE_KEY . '_' . substr(hash('sha256', $apiKey), 0, 16));
        }
    }
}
