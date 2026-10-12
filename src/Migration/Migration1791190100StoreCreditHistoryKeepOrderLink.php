<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('framework')]
class Migration1791190100StoreCreditHistoryKeepOrderLink extends MigrationStep
{
    private const FOREIGN_KEY = 'fk_store_credit_history_order_id';

    public function getCreationTimestamp(): int
    {
        return 1791190100;
    }

    public function update(Connection $connection): void
    {
        $foreignKeyExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME        = 'solu1_store_credit_history'
               AND CONSTRAINT_NAME   = :name
               AND CONSTRAINT_TYPE   = 'FOREIGN KEY'",
            ['name' => self::FOREIGN_KEY]
        );

        if ($foreignKeyExists > 0) {
            $connection->executeStatement(
                'ALTER TABLE `solu1_store_credit_history` DROP FOREIGN KEY `' . self::FOREIGN_KEY . '`'
            );
        }

        $indexExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'solu1_store_credit_history'
               AND COLUMN_NAME  = 'order_id'"
        );

        if ($indexExists === 0) {
            $connection->executeStatement(
                'ALTER TABLE `solu1_store_credit_history` ADD INDEX `idx_store_credit_history_order_id` (`order_id`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
