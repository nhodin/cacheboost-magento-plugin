<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Validation;

use CacheBoost\CacheWarmer\Service\AccountApi;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Validates domain ownership from the module instead of asking the merchant to upload a
 * {token}.html file or edit a theme — same as the WordPress plugin.
 *
 * The proof is still served by the domain itself: CacheBoost fetches https://{domain}/{token}.html,
 * which the storefront answers (Controller\Router\ValidationToken) only while a validation call is
 * in flight. Holding an API key alone is never enough to validate a domain.
 *
 * The token lives in the flag table (database), so every web node behind a load balancer can
 * answer CacheBoost's request, and is removed as soon as the call returns.
 */
class SiteValidation
{
    public const FLAG_TOKEN  = 'cacheboost_validation_token';
    public const FLAG_STATUS = 'cacheboost_site_validated';

    /** Tokens issued by CacheBoost are hex strings; product URL keys never look like this. */
    private const TOKEN_PATH = '#^/([a-f0-9]{16,64})\.html$#D';

    public function __construct(
        private readonly AccountApi $api,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Validates the site if needed and records the outcome for the admin warning.
     *
     * @param array<string, mixed>|null $site entry from GET /v1/sites when already fetched
     * @return array{success: bool, attempted: bool, message?: string}
     */
    public function ensure(string $apiKey, int $siteId, ?array $site = null): array
    {
        if ($site === null) {
            $response = $this->api->site($apiKey, $siteId);
            if ($response['status'] !== 200 || !is_array($response['body'])) {
                return ['success' => false, 'attempted' => false, 'message' => (string) __('Could not read the site from CacheBoost (HTTP %1).', $response['status'])];
            }
            $site = $response['body'];
        }

        if (!empty($site['validated'])) {
            $this->flagManager->saveFlag(self::FLAG_STATUS, '1');
            return ['success' => true, 'attempted' => false];
        }

        $result = $this->validate($apiKey, $siteId, $site);
        $this->flagManager->saveFlag(self::FLAG_STATUS, $result['success'] ? '1' : '0');
        return $result + ['attempted' => true];
    }

    /** True when automatic validation was attempted and failed: warming requests are rejected. */
    public function isPending(): bool
    {
        return $this->flagManager->getFlagData(self::FLAG_STATUS) === '0';
    }

    /** A new API key or site: the previous outcome no longer applies. */
    public function reset(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_STATUS);
    }

    /**
     * Token to serve for this storefront request path, or null when the path is not a pending
     * validation. The cheap path check runs first so regular pages never query the flag table.
     */
    public function tokenForPath(string $path): ?string
    {
        if (!preg_match(self::TOKEN_PATH, $path, $m)) {
            return null;
        }
        $token = $this->flagManager->getFlagData(self::FLAG_TOKEN);
        return is_string($token) && $token !== '' && hash_equals($token, $m[1]) ? $token : null;
    }

    /**
     * @param array<string, mixed> $site
     * @return array{success: bool, message?: string}
     */
    private function validate(string $apiKey, int $siteId, array $site): array
    {
        $token = $site['validation_token'] ?? null;
        if (!is_string($token) || $token === '') {
            // GET /v1/sites does not always carry the token: read the site itself.
            $response = $this->api->site($apiKey, $siteId);
            $token = $response['body']['validation_token'] ?? null;
        }
        if (!is_string($token) || $token === '') {
            return ['success' => false, 'message' => (string) __('Could not retrieve the validation token.')];
        }

        $this->flagManager->saveFlag(self::FLAG_TOKEN, $token);
        try {
            $response = $this->api->validateSite($apiKey, $siteId);
        } finally {
            // The file is only needed while CacheBoost checks it; don't leave it public.
            $this->flagManager->deleteFlag(self::FLAG_TOKEN);
        }

        if ($response['status'] === 200 && !empty($response['body']['validated'])) {
            $this->logger->info(sprintf('CacheBoost: site #%d validated.', $siteId));
            return ['success' => true];
        }
        if ($response['status'] === 403) {
            return ['success' => false, 'message' => (string) __('Your API key needs the sites:write scope to validate the domain automatically.')];
        }

        $detail = is_string($response['body']['error'] ?? null) ? $response['body']['error'] : 'HTTP ' . $response['status'];
        $this->logger->warning(sprintf('CacheBoost: site #%d validation failed — %s', $siteId, $detail));
        return ['success' => false, 'message' => $detail];
    }
}
