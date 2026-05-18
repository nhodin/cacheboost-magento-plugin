<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Service;

use CacheBoost\Warmer\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ApiClient
{
    private const TIMEOUT = 3;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * POST /v1/sites/{id}/warm
     * Triggers targeted warming for the given URLs.
     */
    public function triggerWarm(array $urls): bool
    {
        if (empty($urls)) {
            return false;
        }

        $siteId   = $this->config->getSiteId();
        $endpoint = $this->config->getApiEndpoint() . "/v1/sites/{$siteId}/warm";

        return $this->post($endpoint, [
            'urls'   => array_values(array_unique($urls)),
            'region' => $this->config->getRegions(),
        ]);
    }

    /**
     * POST /v1/boosts/{id}/run
     * Triggers a full run on an existing scheduled Boost (sitemap/csv).
     */
    public function triggerBoostRun(int $boostId): bool
    {
        $endpoint = $this->config->getApiEndpoint() . "/v1/boosts/{$boostId}/run";
        return $this->post($endpoint, []);
    }

    /**
     * GET /v1/sites/{id}/warm-runs
     * Returns the most recent inline warm runs for the configured site.
     */
    public function getWarmRuns(int $limit = 10): array
    {
        try {
            $siteId   = $this->config->getSiteId();
            $endpoint = $this->config->getApiEndpoint() . "/v1/sites/{$siteId}/warm-runs?limit={$limit}";

            $this->prepareCurl();
            $this->curl->get($endpoint);

            $status = $this->curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning("CacheBoost: GET warm-runs returned HTTP {$status}");
                return [];
            }

            $data = json_decode($this->curl->getBody(), true);
            return is_array($data) ? ($data['data'] ?? []) : [];

        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: getWarmRuns failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Verifies that the API key and Site ID are valid.
     * Calls GET /v1/sites/{id}/warm-runs (requires boosts:read) — lightweight, no side effects.
     * Returns ['success' => bool, 'message' => string].
     */
    public function ping(): array
    {
        try {
            if (!$this->config->isConfigured()) {
                return ['success' => false, 'message' => (string) __('API Key or Site ID is not configured.')];
            }

            $siteId   = $this->config->getSiteId();
            $endpoint = $this->config->getApiEndpoint() . "/v1/sites/{$siteId}/warm-runs?limit=1";

            $this->prepareCurl();
            $this->curl->get($endpoint);

            $status = $this->curl->getStatus();

            return match (true) {
                $status === 200 => ['success' => true,  'message' => (string) __('Connection OK — API key is valid, site found.')],
                $status === 401 => ['success' => false, 'message' => (string) __('Error 401 — invalid or expired API key.')],
                $status === 403 => ['success' => false, 'message' => (string) __('Error 403 — insufficient scope. Check that the key has boosts:read and boosts:write scopes.')],
                $status === 404 => ['success' => false, 'message' => (string) __('Error 404 — site not found. Check your Site ID and that the key authorizes this site.')],
                default         => ['success' => false, 'message' => (string) __('HTTP error %1 — %2', $status, $this->curl->getBody())],
            };

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => (string) __('Network error: %1', $e->getMessage())];
        }
    }

    /**
     * GET /v1/runs?boost_id={id}
     * Returns the most recent runs for a scheduled Boost (full flush history).
     */
    public function getBoostRuns(int $boostId, int $limit = 10): array
    {
        try {
            $endpoint = $this->config->getApiEndpoint() . "/v1/runs?boost_id={$boostId}&limit={$limit}";

            $this->prepareCurl();
            $this->curl->get($endpoint);

            $status = $this->curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning("CacheBoost: GET runs returned HTTP {$status}");
                return [];
            }

            $data = json_decode($this->curl->getBody(), true);
            return is_array($data) ? ($data['data'] ?? []) : [];

        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: getBoostRuns failed — ' . $e->getMessage());
            return [];
        }
    }

    private function post(string $endpoint, array $payload): bool
    {
        try {
            $this->prepareCurl();
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->post($endpoint, json_encode($payload));

            $status = $this->curl->getStatus();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->warning(
                "CacheBoost: POST {$endpoint} returned HTTP {$status} — " . $this->curl->getBody()
            );
            return false;

        } catch (\Throwable $e) {
            $this->logger->error("CacheBoost: POST {$endpoint} failed — " . $e->getMessage());
            return false;
        }
    }

    private function prepareCurl(): void
    {
        $this->curl->setTimeout(self::TIMEOUT);
        $this->curl->addHeader('Authorization', 'Bearer ' . $this->config->getApiKey());
        $this->curl->addHeader('Accept', 'application/json');
    }
}
