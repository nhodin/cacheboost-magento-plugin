<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Controller\Router;

use CacheBoost\CacheWarmer\Controller\Validation\Token;
use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Answers GET /{token}.html while a domain validation is in flight (Model\Validation\SiteValidation).
 * Registered before the standard and URL-rewrite routers (etc/frontend/di.xml); any other path
 * falls through after a regex check, without touching the database.
 */
class ValidationToken implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly SiteValidation $validation
    ) {}

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!method_exists($request, 'getPathInfo')) {
            return null;
        }
        $token = $this->validation->tokenForPath((string) $request->getPathInfo());
        if ($token === null) {
            return null;
        }

        $request->setParam('cacheboost_token', $token);
        return $this->actionFactory->create(Token::class);
    }
}
