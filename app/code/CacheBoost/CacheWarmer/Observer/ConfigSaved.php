<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Observer;

use CacheBoost\CacheWarmer\Model\Account\AccountSetup;
use CacheBoost\CacheWarmer\Model\Account\AccountStatus;
use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Model\Environment\EnvironmentReporter;
use CacheBoost\CacheWarmer\Model\Validation\SiteValidation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;

/**
 * admin_system_config_changed_section_cacheboost — after "Save Config" on the CacheBoost section.
 *
 * A new API key is set up at once (site, regions, domain validation), like the WordPress plugin,
 * so warming works without clicking "Test connection". The environment snapshot follows changes
 * of the settings it reports, and turning sharing off asks CacheBoost to drop what it received.
 */
class ConfigSaved implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly AccountSetup $setup,
        private readonly SiteValidation $validation,
        private readonly AccountStatus $accountStatus,
        private readonly EnvironmentReporter $environment,
        private readonly ManagerInterface $messageManager
    ) {}

    public function execute(Observer $observer): void
    {
        $changed = (array) $observer->getEvent()->getData('changed_paths');

        if (in_array(Config::XML_API_KEY, $changed, true)) {
            // Validation status and account state belonged to the previous key.
            $this->validation->reset();
            $this->accountStatus->clear();

            $apiKey = $this->config->getApiKey();
            if ($apiKey !== '') {
                $result = $this->setup->run($apiKey);
                $result['success']
                    ? $this->messageManager->addSuccessMessage($result['message'])
                    : $this->messageManager->addWarningMessage($result['message']);
                foreach ($result['notices'] as $notice) {
                    $this->messageManager->addNoticeMessage($notice);
                }
            }
        }

        if (in_array(Config::XML_SHARE_ENVIRONMENT, $changed, true) && !$this->config->isEnvironmentSharingEnabled()) {
            $this->environment->stopSharing();
            return;
        }
        $this->environment->schedule();
    }
}
