<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Controller\Adminhtml\Test;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * AJAX endpoint for the "Test" panel in Stores → Configuration → CacheBoost.
 * URL: {admin}/cacheboost/test/api?action={connection|warm|boost_run}
 */
class Api extends Action
{
    public const ADMIN_RESOURCE = 'CacheBoost_Warmer::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $action = (string) $this->getRequest()->getParam('action');

        return match ($action) {
            'connection' => $result->setData($this->testConnection()),
            'warm'       => $result->setData($this->testWarm()),
            'boost_run'  => $result->setData($this->testBoostRun()),
            default      => $result->setData(['success' => false, 'message' => (string) __('Unknown action: %1', $action)]),
        };
    }

    private function testConnection(): array
    {
        $result = $this->apiClient->ping();
        if (!$result['success']) {
            return $result;
        }

        $required = ['sites:read', 'boosts:read', 'boosts:write'];
        $missing  = array_diff($required, $result['scopes'] ?? []);

        if (!empty($missing)) {
            return [
                'success' => false,
                'message' => (string) __('Connected, but missing required scopes: %1. Please regenerate your API key with the correct permissions.', implode(', ', $missing)),
            ];
        }

        return ['success' => true, 'message' => (string) __('Connection successful. All required scopes are granted.')];
    }

    private function testWarm(): array
    {
        if (!$this->config->isConfigured()) {
            return ['success' => false, 'message' => (string) __('API Key or Site ID is not configured.')];
        }

        try {
            $baseUrl = rtrim($this->storeManager->getDefaultStoreView()->getBaseUrl(), '/') . '/';
        } catch (\Throwable) {
            $baseUrl = null;
        }

        if ($baseUrl === null) {
            return ['success' => false, 'message' => (string) __('Unable to retrieve the store base URL.')];
        }

        $success = $this->apiClient->triggerWarm([$baseUrl]);

        return $success
            ? ['success' => true,  'message' => (string) __('Inline warm triggered for: %1', $baseUrl)]
            : ['success' => false, 'message' => (string) __('Failed — check var/log/system.log for details.')];
    }

    private function testBoostRun(): array
    {
        if (!$this->config->isConfigured()) {
            return ['success' => false, 'message' => (string) __('API Key or Site ID is not configured.')];
        }

        $boostId = $this->config->getBoostId();
        if ($boostId <= 0) {
            return ['success' => false, 'message' => (string) __('No Boost ID configured in the "Full Flush" section.')];
        }

        $success = $this->apiClient->triggerBoostRun($boostId);

        return $success
            ? ['success' => true,  'message' => (string) __('Boost run #%1 triggered successfully.', $boostId)]
            : ['success' => false, 'message' => (string) __('Failed — the run may already be in progress. Check var/log/system.log.')];
    }
}
