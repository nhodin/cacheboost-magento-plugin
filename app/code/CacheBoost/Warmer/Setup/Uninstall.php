<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs on `bin/magento module:uninstall CacheBoost_Warmer --remove-data`.
 * Removes every stored setting (including the encrypted API key).
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $setup->getConnection()->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => 'cacheboost/%']
        );
        $setup->endSetup();
    }
}
