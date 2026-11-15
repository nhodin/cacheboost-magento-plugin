<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Controller\Adminhtml\Environment;

use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/** "Send now" in the Cache Diagnostics group: resends the snapshot at once. */
class Send extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CacheBoost_CacheWarmer::config';

    public function __construct(
        Context $context,
        private readonly EnvironmentReporter $reporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        match ($this->reporter->send(true)) {
            EnvironmentReporter::STATUS_SENT => $this->messageManager->addSuccessMessage(__('Cache configuration sent to CacheBoost.')),
            EnvironmentReporter::STATUS_DISABLED => $this->messageManager->addWarningMessage(__('Sharing is turned off: nothing was sent.')),
            EnvironmentReporter::STATUS_SKIPPED => $this->messageManager->addWarningMessage(__('Connect to CacheBoost first.')),
            EnvironmentReporter::STATUS_FORBIDDEN => $this->messageManager->addErrorMessage(__('Your API key needs the sites:write scope to share the cache configuration. Use "Reconnect" to get a key with the right scopes.')),
            default => $this->messageManager->addErrorMessage(__('The cache configuration could not be sent. Check var/log/system.log.')),
        };
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', ['section' => 'cacheboost']);
    }
}
