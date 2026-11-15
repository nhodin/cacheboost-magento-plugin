<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\System\Message;

use CacheBoost\CacheWarmer\Model\Account\AccountStatus;
use CacheBoost\CacheWarmer\Model\Config;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin system message for accounts created with "Connect to CacheBoost" whose email is not
 * verified yet: a warning before the deadline, a critical message once warming is paused.
 * The state is cached (AccountStatus), so most admin pages make no API call.
 */
class AccountEmail implements MessageInterface
{
    private ?array $status = null;

    public function __construct(
        private readonly Config $config,
        private readonly AccountStatus $accountStatus
    ) {}

    public function getIdentity(): string
    {
        return 'cacheboost_account_email_' . ($this->status()['state']);
    }

    public function isDisplayed(): bool
    {
        if ($this->config->getApiKey() === '') {
            return false;
        }
        return in_array($this->status()['state'], [AccountStatus::PENDING, AccountStatus::BLOCKED], true);
    }

    public function getText(): string
    {
        $status = $this->status();
        if ($status['state'] === AccountStatus::BLOCKED) {
            return (string) __('<strong>CacheBoost: cache warming is paused</strong> because your email address is not verified. Click the link we emailed you, or <a href="%1" target="_blank" rel="noopener">log in to CacheBoost</a> to get a new one.', 'https://app.cache-boost.com/login');
        }
        $deadline = strtotime((string) ($status['deadline'] ?? ''));
        return (string) __('CacheBoost: verify your email address before %1, or cache warming will stop. Click the link we emailed you, or <a href="%2" target="_blank" rel="noopener">resend it</a>.', $deadline ? date('Y-m-d', $deadline) : '', 'https://app.cache-boost.com/');
    }

    public function getSeverity(): int
    {
        return $this->status()['state'] === AccountStatus::BLOCKED ? self::SEVERITY_CRITICAL : self::SEVERITY_MAJOR;
    }

    private function status(): array
    {
        return $this->status ??= $this->accountStatus->get();
    }
}
