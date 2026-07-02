<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Service;

use CacheBoost\Warmer\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class ApiClient
{
    private const TIMEOUT = 3;

    /** Hard limit enforced by POST /v1/sites/{id}/warm — requests above it are rejected. */
    private const MAX_URLS = 5000;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
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

        $urls = array_values(array_unique($urls));
        if (count($urls) > self::MAX_URLS) {
            $this->logger->warning(sprintf(
                'CacheBoost: %d URLs collected, truncated to the API limit of %d.',
                count($urls),
                self::MAX_URLS
            ));
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        $payload = ['urls' => $urls];
        $regions = $this->config->getRegions();
        if (!empty($regions)) {
            $payload['region'] = $regions;
        }

        $siteId   = $this->config->getSiteId();
        $endpoint = $this->config->getApiEndpoint() . "/v1/sites/{$siteId}/warm";

        return $this->post($endpoint, $payload);
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

            $curl = $this->newCurl();
            $curl->get($endpoint);

            $status = $curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning("CacheBoost: GET warm-runs returned HTTP {$status}");
                return [];
            }

            $data = json_decode($curl->getBody(), true);
            return is_array($data) ? ($data['data'] ?? []) : [];

        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: getWarmRuns failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calls GET /v1/me to verify the API key and retrieve granted scopes.
     * Returns ['success' => bool, 'scopes' => string[], 'message' => string].
     *
     * Only the API key is required: merchants typically test the connection
     * before switching Enable to Yes, and GET /v1/me needs no site ID.
     */
    public function ping(): array
    {
        try {
            if ($this->config->getApiKey() === '') {
                return ['success' => false, 'message' => (string) __('API Key is not configured.')];
            }

            $curl = $this->newCurl();
            $curl->get($this->config->getApiEndpoint() . '/v1/me');

            $status = $curl->getStatus();

            if ($status === 401) {
                return ['success' => false, 'message' => (string) __('Error 401 — invalid or expired API key.')];
            }
            if ($status !== 200) {
                return ['success' => false, 'message' => (string) __('HTTP error %1', $status)];
            }

            $body = json_decode($curl->getBody(), true);
            return ['success' => true, 'message' => (string) __('Connection successful.'), 'scopes' => $body['scopes'] ?? []];

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

            $curl = $this->newCurl();
            $curl->get($endpoint);

            $status = $curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning("CacheBoost: GET runs returned HTTP {$status}");
                return [];
            }

            $data = json_decode($curl->getBody(), true);
            return is_array($data) ? ($data['data'] ?? []) : [];

        } catch (\Throwable $e) {
            $this->logger->error("CacheBoost: getBoostRuns failed — " . $e->getMessage());
            return [];
        }
    }

    private function post(string $endpoint, array $payload): bool
    {
        try {
            $curl = $this->newCurl();
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($endpoint, json_encode($payload));

            $status = $curl->getStatus();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->warning(
                "CacheBoost: POST {$endpoint} returned HTTP {$status} — " . $curl->getBody()
            );
            return false;

        } catch (\Throwable $e) {
            $this->logger->error("CacheBoost: POST {$endpoint} failed — " . $e->getMessage());
            return false;
        }
    }

    /** A fresh client per call: the shared Curl instance carries headers and state across requests. */
    private function newCurl(): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->addHeader('Authorization', 'Bearer ' . $this->config->getApiKey());
        $curl->addHeader('Accept', 'application/json');
        return $curl;
    }
}
