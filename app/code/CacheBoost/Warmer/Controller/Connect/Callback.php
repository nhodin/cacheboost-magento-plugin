<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Controller\Connect;

use CacheBoost\Warmer\Model\Connect\ConnectFlow;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Storefront return URL of "Connect to CacheBoost" (/cacheboost/connect/callback/?state=…&code=…).
 *
 * CacheBoost only redirects to the connected site's host, so the code lands here rather than in
 * the admin. It is parked with the pending flow — never put in a URL again — and the admin is
 * sent to the admin URL generated when the flow started. An unknown state gets a plain 400.
 */
class Callback implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ConnectFlow $flow,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory
    ) {}

    public function execute(): ResultInterface
    {
        $next = $this->flow->receiveCallback(
            (string) $this->request->getParam('state'),
            (string) $this->request->getParam('code'),
            (string) $this->request->getParam('error')
        );

        if ($next === null) {
            $raw = $this->rawFactory->create();
            $raw->setHttpResponseCode(400);
            $raw->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
            $raw->setHeader('X-Robots-Tag', 'noindex', true);
            $raw->setContents((string) __('This CacheBoost connection link has expired. Start again from Stores > Configuration > CacheBoost in the Magento admin.'));
            return $raw;
        }

        $redirect = $this->redirectFactory->create();
        $redirect->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $redirect->setHeader('Referrer-Policy', 'no-referrer', true);
        return $redirect->setUrl($next);
    }
}
