<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Block\Adminhtml;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\StoreDomain;
use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * frontend_model of the "CacheBoost account" row: the "Connect to CacheBoost" button when no key
 * is set, the connection state and a "Reconnect" button otherwise. No JavaScript (CSP-safe).
 */
class ConnectPanel extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreDomain $storeDomain,
        private readonly SiteValidation $validation,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setTemplate('CacheBoost_CacheWarmer::system/config/connect_panel.phtml');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding:12px 0">' . $this->toHtml() . '</td></tr>';
    }

    public function isConnected(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    public function getConnectUrl(): string
    {
        return $this->getUrl('cacheboost/connect/start');
    }

    public function getDomain(): string
    {
        return $this->storeDomain->getDomain();
    }

    public function getSiteId(): int
    {
        return $this->config->getSiteId();
    }

    public function isValidationPending(): bool
    {
        return $this->validation->isPending();
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }
}
