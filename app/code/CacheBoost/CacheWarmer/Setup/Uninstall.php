<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs on `bin/magento module:uninstall CacheBoost_CacheWarmer --remove-data`.
 * Removes every stored setting (including the encrypted API key) and the module's state:
 * flags, scheduled cron jobs and the warm queue.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'cacheboost/%']);

        // State kept outside the configuration: flags, scheduled cron jobs and the warm queue.
        // Each table belongs to another module, so a missing one is simply skipped.
        $leftovers = [
            'flag'          => ['flag_code LIKE ?' => 'cacheboost\\_%'],
            'cron_schedule' => ['job_code LIKE ?' => 'cacheboost\\_%'],
            // queue_message_status rows follow through their foreign keys.
            'queue_message' => ['topic_name = ?' => 'cacheboost.warm'],
            'queue'         => ['name = ?' => 'cacheboost.warm'],
        ];
        foreach ($leftovers as $table => $where) {
            $tableName = $setup->getTable($table);
            if ($connection->isTableExists($tableName)) {
                $connection->delete($tableName, $where);
            }
        }

        $setup->endSetup();
    }
}
