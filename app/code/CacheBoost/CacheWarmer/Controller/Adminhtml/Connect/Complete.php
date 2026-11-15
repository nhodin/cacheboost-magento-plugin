<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Controller\Adminhtml\Connect;

use CacheBoost\CacheWarmer\Model\Account\AccountSetup;
use CacheBoost\CacheWarmer\Model\ConfigWriter;
use CacheBoost\CacheWarmer\Model\Connect\ConnectFlow;
use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Last step of "Connect to CacheBoost", reached from the storefront callback with the flow's
 * state. Exchanges the code for an API key restricted to this site, saves it, then sets up the
 * site like the WordPress plugin: regions, domain validation and the full-site Boost.
 */
class Complete extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CacheBoost_CacheWarmer::config';

    public function __construct(
        Context $context,
        private readonly ConnectFlow $flow,
        private readonly ConfigWriter $writer,
        private readonly SiteValidation $validation,
        private readonly AccountSetup $setup,
        private readonly EnvironmentReporter $environment
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->flow->complete(
            (string) $this->getRequest()->getParam('state'),
            (int) $this->_auth->getUser()->getId()
        );

        if ($result['success']) {
            $this->validation->reset();
            $this->writer->saveConnection($result['api_key'], $result['site_id']);
            $setup = $this->setup->run($result['api_key'], $result['site_id'], true);
            // Connecting is the consent to share the cache configuration (off-switch in Cache Diagnostics).
            $this->environment->schedule();

            $this->messageManager->addSuccessMessage(__('Connected to CacheBoost. Your pages will be warmed after each cache invalidation.'));
            foreach ($setup['notices'] as $notice) {
                $this->messageManager->addNoticeMessage($notice);
            }
            if (!$setup['success']) {
                $this->messageManager->addWarningMessage($setup['message']);
            }
        } else {
            match ($result['reason'] ?? '') {
                'denied' => $this->messageManager->addWarningMessage(__('Connection cancelled.')),
                'exchange_failed' => $this->messageManager->addErrorMessage(__('Could not finish connecting to CacheBoost. Please try again, or paste an API key below.')),
                default => $this->messageManager->addErrorMessage(__('The connection link expired or was opened in another session. Please try again.')),
            };
        }

        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', ['section' => 'cacheboost']);
    }
}
