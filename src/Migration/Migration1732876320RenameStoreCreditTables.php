<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Migration to rename old table names to new namespaced names.
 * This preserves data when updating from old versions.
 *
 * Handles migration from:
 * - `store_credit` → `solu1_store_credit` (original old tables)
 * - `store_credit_history` → `solu1_store_credit_history` (original old tables)
 *
 * Handles three scenarios:
 * 1. Old tables exist, new don't: Rename old to new (preserves all data)
 * 2. Both exist: Migrate data from old to new, then drop old
 * 3. Only new exist: Skip (already migrated)
 *
 * @internal
 */
#[Package('framework')]
class Migration1732876320RenameStoreCreditTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732876320;
    }

    public function update(Connection $connection): void
    {
        $this->renameTableIfExists($connection, 'store_credit', 'solu1_store_credit');
        $this->renameTableIfExists($connection, 'store_credit_history', 'solu1_store_credit_history');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function renameTableIfExists(Connection $connection, string $oldTableName, string $newTableName): void
    {
        $oldTableExists = $this->tableExists($connection, $oldTableName);
        $newTableExists = $this->tableExists($connection, $newTableName);

        if (!$oldTableExists) {
            return;
        }

        if ($oldTableExists && !$newTableExists) {
            $this->dropForeignKeys($connection, $oldTableName);
            $connection->executeStatement(sprintf('RENAME TABLE `%s` TO `%s`', $oldTableName, $newTableName));
            $this->recreateForeignKeys($connection, $newTableName);
        } elseif ($oldTableExists && $newTableExists) {
            $this->migrateDataFromOldToNew($connection, $oldTableName, $newTableName);
            $this->dropForeignKeys($connection, $oldTableName);
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $oldTableName));
        }
    }

    private function dropForeignKeys(Connection $connection, string $tableName): void
    {
        try {
            $foreignKeys = $connection->executeQuery(
                "SELECT CONSTRAINT_NAME 
                 FROM information_schema.KEY_COLUMN_USAGE 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = ? 
                 AND REFERENCED_TABLE_NAME IS NOT NULL",
                [$tableName]
            )->fetchAllAssociative();

            foreach ($foreignKeys as $fk) {
                $constraintName = $fk['CONSTRAINT_NAME'];
                try {
                    $connection->executeStatement(
                        sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName)
                    );
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
        }
    }

    private function recreateForeignKeys(Connection $connection, string $tableName): void
    {
        if ($tableName === 'solu1_store_credit') {
            if (!$this->constraintExists($connection, $tableName, 'fk_customer_id')) {
                try {
                    $connection->executeStatement('
                        ALTER TABLE `solu1_store_credit`
                        ADD CONSTRAINT `fk_customer_id` FOREIGN KEY (`customer_id`)
                        REFERENCES `customer` (`id`) ON DELETE CASCADE
                    ');
                } catch (\Exception $e) {
                }
            }
            if (!$this->constraintExists($connection, $tableName, 'fk_currency_id')) {
                try {
                    $connection->executeStatement('
                        ALTER TABLE `solu1_store_credit`
                        ADD CONSTRAINT `fk_currency_id` FOREIGN KEY (`currency_id`)
                        REFERENCES `currency` (`id`) ON DELETE CASCADE
                    ');
                } catch (\Exception $e) {
                }
            }
        } elseif ($tableName === 'solu1_store_credit_history') {
            if (!$this->constraintExists($connection, $tableName, 'fk_store_credit_history_store_credit_id')) {
                try {
                    $connection->executeStatement('
                        ALTER TABLE `solu1_store_credit_history`
                        ADD CONSTRAINT `fk_store_credit_history_store_credit_id` FOREIGN KEY (`store_credit_id`)
                        REFERENCES `solu1_store_credit` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                    ');
                } catch (\Exception $e) {
                }
            }
            if (!$this->constraintExists($connection, $tableName, 'fk_store_credit_history_order_id')) {
                try {
                    $connection->executeStatement('
                        ALTER TABLE `solu1_store_credit_history`
                        ADD CONSTRAINT `fk_store_credit_history_order_id` FOREIGN KEY (`order_id`)
                        REFERENCES `order` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
                    ');
                } catch (\Exception $e) {
                }
            }
            if (!$this->constraintExists($connection, $tableName, 'fk_store_credit_history_currency_id')) {
                try {
                    $connection->executeStatement('
                        ALTER TABLE `solu1_store_credit_history`
                        ADD CONSTRAINT `fk_store_credit_history_currency_id` FOREIGN KEY (`currency_id`)
                        REFERENCES `currency` (`id`) ON DELETE CASCADE
                    ');
                } catch (\Exception $e) {
                }
            }
        }
    }

    private function constraintExists(Connection $connection, string $tableName, string $constraintName): bool
    {
        try {
            $result = $connection->executeQuery(
                "SELECT COUNT(*) 
                 FROM information_schema.TABLE_CONSTRAINTS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = ? 
                 AND CONSTRAINT_NAME = ?",
                [$tableName, $constraintName]
            );

            return (int) $result->fetchOne() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function tableExists(Connection $connection, string $tableName): bool
    {
        try {
            $result = $connection->executeQuery(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$tableName]
            );

            return (int) $result->fetchOne() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function migrateDataFromOldToNew(Connection $connection, string $oldTableName, string $newTableName): void
    {
        if ($oldTableName === 'store_credit' && $newTableName === 'solu1_store_credit') {
            try {
                $connection->executeStatement('
                    INSERT IGNORE INTO `solu1_store_credit` 
                    SELECT * FROM `store_credit`
                ');
            } catch (\Exception $e) {
            }
        } elseif ($oldTableName === 'store_credit_history' && $newTableName === 'solu1_store_credit_history') {
            try {
                $connection->executeStatement('
                    INSERT IGNORE INTO `solu1_store_credit_history` 
                    SELECT 
                        h.id,
                        COALESCE(
                            (SELECT sc_new.id 
                             FROM `solu1_store_credit` sc_new 
                             WHERE sc_new.id = h.store_credit_id
                             LIMIT 1),
                            (SELECT sc_new.id 
                             FROM `solu1_store_credit` sc_new 
                             WHERE sc_new.customer_id = (
                                 SELECT sc_old.customer_id 
                                 FROM `store_credit` sc_old 
                                 WHERE sc_old.id = h.store_credit_id
                                 LIMIT 1
                             ) LIMIT 1),
                            h.store_credit_id
                        ) as store_credit_id,
                        h.order_id,
                        h.currency_id,
                        h.amount,
                        h.reason,
                        h.action_type,
                        h.created_at,
                        h.updated_at
                    FROM `store_credit_history` h
                    WHERE NOT EXISTS (
                        SELECT 1 FROM `solu1_store_credit_history` h_new 
                        WHERE h_new.id = h.id
                    )
                ');
            } catch (\Exception $e) {
            }
        }
    }
}

