<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Controller\Adminhtml\Connect;

use CacheBoost\CacheWarmer\Model\Connect\ConnectFlow;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * "Connect to CacheBoost" button: sends the admin to the CacheBoost sign-up / consent page.
 * GET with the admin secret key in the URL, like every admin link.
 */
class Start extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CacheBoost_CacheWarmer::config';

    public function __construct(
        Context $context,
        private readonly ConnectFlow $flow
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();

        // Built now, with this admin session's secret key: the storefront callback sends the admin back here.
        $completeUrl = $this->getUrl('cacheboost/connect/complete', ['state' => '__STATE__']);
        $url = $this->flow->start((int) $this->_auth->getUser()->getId(), $completeUrl);

        if ($url === null) {
            $this->messageManager->addErrorMessage(__('The storefront URL of the default store view could not be determined.'));
            return $redirect->setPath('adminhtml/system_config/edit', ['section' => 'cacheboost']);
        }

        // External on purpose: the CacheBoost consent page.
        return $redirect->setUrl($url);
    }
}
