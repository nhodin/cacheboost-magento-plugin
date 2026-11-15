<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Connect;

use CacheBoost\CacheWarmer\Model\StoreDomain;
use CacheBoost\CacheWarmer\Service\AccountApi;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * "Connect to CacheBoost": replaces copy-pasting an API key, like the WordPress plugin.
 *
 * 1. start() sends the admin to app.cache-boost.com/connect/magento (sign up or log in, then consent).
 * 2. CacheBoost redirects to the STOREFRONT (/cacheboost/connect/callback): the app only accepts a
 *    return URL on the connected site's host, and the Magento admin often lives on another host,
 *    behind secret-key URLs. receiveCallback() parks the one-time code with the pending flow and
 *    hands back the admin URL generated, with its secret key, when the flow started.
 * 3. complete() — in the admin, for the admin who started the flow — exchanges the code for an API
 *    key restricted to this site (PKCE S256: the code is useless without the verifier kept here).
 *
 * Pending flows live in the Magento cache (shared by every web node) for 15 minutes.
 */
class ConnectFlow
{
    public const APP_URL       = 'https://app.cache-boost.com';
    public const CALLBACK_PATH = 'cacheboost/connect/callback/';
    private const CACHE_PREFIX = 'cacheboost_connect_';
    private const CACHE_TAG    = 'CACHEBOOST_CONNECT';
    private const TTL          = 900;
    private const STATE_FORMAT = '/^[A-Za-z0-9_-]{16,128}$/D';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly StoreDomain $storeDomain,
        private readonly AccountApi $api,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param string $completeUrl admin URL of the complete step, built by the caller with its
     *                            secret key; "__STATE__" is replaced with the flow's state
     * @return string|null URL of the CacheBoost consent page, null when the storefront URL is unknown
     */
    public function start(int $adminUserId, string $completeUrl): ?string
    {
        $siteUrl = $this->storeDomain->getBaseUrl();
        if ($siteUrl === '') {
            return null;
        }

        $verifier = self::base64url(random_bytes(48));
        $state    = self::base64url(random_bytes(24));

        $this->save($state, [
            'verifier'     => $verifier,
            'user_id'      => $adminUserId,
            'complete_url' => str_replace('__STATE__', $state, $completeUrl),
            'code'         => null,
            'error'        => null,
        ]);

        return self::APP_URL . '/connect/magento?' . http_build_query([
            'site_url'              => $siteUrl,
            'redirect_uri'          => $siteUrl . self::CALLBACK_PATH,
            'state'                 => $state,
            'code_challenge'        => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Storefront callback. Parks the code (or the refusal) with the pending flow.
     *
     * @return string|null admin URL to continue on, null for an unknown or expired flow
     */
    public function receiveCallback(string $state, string $code, string $error): ?string
    {
        $pending = $this->load($state);
        if ($pending === null || $pending['code'] !== null || $pending['error'] !== null) {
            return null;
        }
        if ($error === '' && !preg_match('/^[A-Za-z0-9]{16,256}$/D', $code)) {
            return null;
        }

        $pending['code']  = $error === '' ? $code : null;
        $pending['error'] = $error !== '' ? 'denied' : null;
        $this->save($state, $pending);

        return (string) $pending['complete_url'];
    }

    /**
     * Admin step: the flow must come back to the admin who started it, once.
     *
     * @return array{success: bool, reason?: string, api_key?: string, site_id?: int}
     */
    public function complete(string $state, int $adminUserId): array
    {
        $pending = $this->load($state);
        if ($pending === null || (int) $pending['user_id'] !== $adminUserId) {
            return ['success' => false, 'reason' => 'invalid_state'];
        }
        $this->cache->remove(self::CACHE_PREFIX . $state);

        if ($pending['error'] !== null) {
            return ['success' => false, 'reason' => 'denied'];
        }
        if (!is_string($pending['code']) || $pending['code'] === '') {
            return ['success' => false, 'reason' => 'invalid_state'];
        }

        $response = $this->api->exchangeConnectCode($pending['code'], (string) $pending['verifier']);
        $apiKey = (string) ($response['body']['api_key'] ?? '');
        $siteId = (int) ($response['body']['site_id'] ?? 0);
        if ($response['status'] !== 200 || !preg_match('/^cb_live_[A-Za-z0-9]+$/D', $apiKey) || $siteId <= 0) {
            $this->logger->error(sprintf('CacheBoost: POST /v1/connect/token failed: HTTP %d', $response['status']));
            return ['success' => false, 'reason' => 'exchange_failed'];
        }

        $this->logger->info(sprintf('CacheBoost: connected to CacheBoost — site #%d', $siteId));
        return ['success' => true, 'api_key' => $apiKey, 'site_id' => $siteId];
    }

    /** PKCE S256: base64url(SHA-256(verifier)) without padding (RFC 7636). */
    public static function challenge(string $verifier): string
    {
        return self::base64url(hash('sha256', $verifier, true));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function save(string $state, array $pending): void
    {
        $this->cache->save($this->json->serialize($pending), self::CACHE_PREFIX . $state, [self::CACHE_TAG], self::TTL);
    }

    private function load(string $state): ?array
    {
        if (!preg_match(self::STATE_FORMAT, $state)) {
            return null;
        }
        $raw = $this->cache->load(self::CACHE_PREFIX . $state);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $pending = $this->json->unserialize($raw);
        } catch (\Throwable) {
            return null;
        }
        return is_array($pending) && isset($pending['verifier'], $pending['user_id'], $pending['complete_url'])
            ? $pending + ['code' => null, 'error' => null]
            : null;
    }
}
