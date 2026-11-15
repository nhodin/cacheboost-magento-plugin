<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Block\Adminhtml;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * frontend_model of the "Cache Diagnostics" status row: what is shared, the last send, "Send now".
 */
class EnvironmentPanel extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly EnvironmentReporter $reporter,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setTemplate('CacheBoost_CacheWarmer::system/config/environment_panel.phtml');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding:12px 0">' . $this->toHtml() . '</td></tr>';
    }

    public function isSharing(): bool
    {
        return $this->config->isEnvironmentSharingEnabled();
    }

    public function isConnected(): bool
    {
        return $this->config->getApiKey() !== '' && $this->config->getSiteId() > 0;
    }

    public function getState(): array
    {
        return $this->reporter->state();
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('cacheboost/environment/send');
    }

    public function getStatusLabel(string $status): string
    {
        return (string) match ($status) {
            EnvironmentReporter::STATUS_SENT, EnvironmentReporter::STATUS_UNCHANGED => __('up to date'),
            EnvironmentReporter::STATUS_FORBIDDEN => __('refused: the API key needs the sites:write scope ("Reconnect" gives it)'),
            EnvironmentReporter::STATUS_REJECTED => __('refused by CacheBoost (see var/log/system.log)'),
            EnvironmentReporter::STATUS_FAILED => __('failed, retried within the hour'),
            EnvironmentReporter::STATUS_DISABLED => __('sharing turned off'),
            default => __('not sent yet'),
        };
    }

    public function formatSentAt(?int $timestamp): string
    {
        return $timestamp ? $this->_localeDate->formatDateTime((new \DateTime())->setTimestamp($timestamp), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) : (string) __('never');
    }
}
