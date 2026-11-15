<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Service;

use CacheBoost\CacheWarmer\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Blocking calls to the CacheBoost API used by the admin (connect, test, domain validation,
 * environment snapshot). The API key is passed explicitly: during "Connect to CacheBoost" the
 * key is not saved yet. Never used on storefront requests.
 *
 * Every method returns ['status' => int, 'body' => array|null]; status 0 means a network error.
 */
class AccountApi
{
    private const TIMEOUT = 10;

    /** The API tries https and http, file then meta tag, 10 s each. */
    public const VALIDATE_TIMEOUT = 45;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {}

    /** GET /v1/me — scopes, regions, email verification state. */
    public function me(string $apiKey, int $timeout = self::TIMEOUT): array
    {
        return $this->request('GET', '/v1/me', $apiKey, null, $timeout);
    }

    /** GET /v1/sites — sites the key can access, with their validation status. */
    public function sites(string $apiKey): array
    {
        return $this->request('GET', '/v1/sites', $apiKey);
    }

    /** GET /v1/sites/{id} — includes the ownership validation token. */
    public function site(string $apiKey, int $siteId): array
    {
        return $this->request('GET', "/v1/sites/{$siteId}", $apiKey);
    }

    /** POST /v1/sites/{id}/validate — CacheBoost fetches https://{domain}/{token}.html. */
    public function validateSite(string $apiKey, int $siteId): array
    {
        return $this->request('POST', "/v1/sites/{$siteId}/validate", $apiKey, [], self::VALIDATE_TIMEOUT);
    }

    /** GET /v1/boosts */
    public function boosts(string $apiKey): array
    {
        return $this->request('GET', '/v1/boosts', $apiKey);
    }

    /** POST /v1/boosts */
    public function createBoost(string $apiKey, array $boost): array
    {
        return $this->request('POST', '/v1/boosts', $apiKey, $boost);
    }

    /** GET /v1/sites/{id}/sitemaps */
    public function sitemaps(string $apiKey, int $siteId): array
    {
        return $this->request('GET', "/v1/sites/{$siteId}/sitemaps", $apiKey);
    }

    /** POST /v1/sites/{id}/sitemaps — 201 created, 409 already registered. */
    public function createSitemap(string $apiKey, int $siteId, string $url): array
    {
        return $this->request('POST', "/v1/sites/{$siteId}/sitemaps", $apiKey, ['url' => $url]);
    }

    /** POST /v1/connect/token — public: exchanges the one-time code (PKCE) for a key. */
    public function exchangeConnectCode(string $code, string $verifier): array
    {
        return $this->request('POST', '/v1/connect/token', null, ['code' => $code, 'code_verifier' => $verifier], 15);
    }

    /** POST /v1/sites/{id}/environment — cache configuration snapshot (needs sites:write). */
    public function sendEnvironment(string $apiKey, int $siteId, array $snapshot): array
    {
        return $this->request('POST', "/v1/sites/{$siteId}/environment", $apiKey, $snapshot);
    }

    /** DELETE /v1/sites/{id}/environment — sharing turned off: CacheBoost drops what it received. */
    public function deleteEnvironment(string $apiKey, int $siteId): array
    {
        return $this->request('DELETE', "/v1/sites/{$siteId}/environment", $apiKey);
    }

    /**
     * @return array{status: int, body: array|null}
     */
    private function request(string $method, string $path, ?string $apiKey, ?array $body = null, int $timeout = self::TIMEOUT): array
    {
        $url = $this->config->getApiEndpoint() . $path;
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout);
            $curl->addHeader('Accept', 'application/json');
            if ($apiKey !== null) {
                $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            }

            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->addHeader('Content-Type', 'application/json');
                if ($method !== 'POST') {
                    // Curl::post() with a custom verb: user options are applied after the POST defaults.
                    $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
                }
                $curl->post($url, $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES));
            }

            $status = $curl->getStatus();
            $decoded = json_decode((string) $curl->getBody(), true);
            if ($status < 200 || $status >= 300) {
                $this->logger->warning(sprintf('CacheBoost: %s %s returned HTTP %d', $method, $path, $status));
            }
            return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('CacheBoost: %s %s failed — %s', $method, $path, $e->getMessage()));
            return ['status' => 0, 'body' => null];
        }
    }
}
