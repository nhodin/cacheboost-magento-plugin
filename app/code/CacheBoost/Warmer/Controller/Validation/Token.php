<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Controller\Validation;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Serves the validation token as the body of /{token}.html. Only reachable through
 * Controller\Router\ValidationToken; never cached by the page cache, Varnish or a CDN.
 */
class Token implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory
    ) {}

    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setHeader('X-Robots-Tag', 'noindex', true);
        $result->setContents(htmlspecialchars((string) $this->request->getParam('cacheboost_token'), ENT_QUOTES));
        return $result;
    }
}
