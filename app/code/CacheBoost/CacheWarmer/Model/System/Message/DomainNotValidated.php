<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\System\Message;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use Magento\Framework\Notification\MessageInterface;

/**
 * Warns that automatic domain validation failed: CacheBoost rejects warm requests for a domain
 * that is not validated. Reads a flag only, never calls the API.
 */
class DomainNotValidated implements MessageInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SiteValidation $validation
    ) {}

    public function getIdentity(): string
    {
        return 'cacheboost_domain_not_validated';
    }

    public function isDisplayed(): bool
    {
        return $this->config->getApiKey() !== '' && $this->validation->isPending();
    }

    public function getText(): string
    {
        return (string) __('<strong>CacheBoost: your domain is not validated.</strong> Cache warming requests are rejected until it is. Click "Test connection" in Stores > Configuration > CacheBoost to retry, or <a href="%1" target="_blank" rel="noopener">see the validation methods</a>.', 'https://app.cache-boost.com/sites');
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
