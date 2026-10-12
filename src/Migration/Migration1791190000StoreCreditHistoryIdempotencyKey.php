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
class Migration1791190000StoreCreditHistoryIdempotencyKey extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791190000;
    }

    public function update(Connection $connection): void
    {
        $columnExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'solu1_store_credit_history'
               AND COLUMN_NAME  = 'idempotency_key'"
        );

        if ($columnExists === 0) {
            $connection->executeStatement(
                'ALTER TABLE `solu1_store_credit_history`
                 ADD COLUMN `idempotency_key` VARCHAR(128) NULL AFTER `action_type`'
            );
        }

        $indexExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'solu1_store_credit_history'
               AND INDEX_NAME   = 'uniq_solu1_store_credit_history_idempotency_key'"
        );

        if ($indexExists === 0) {
            $connection->executeStatement(
                'ALTER TABLE `solu1_store_credit_history`
                 ADD UNIQUE KEY `uniq_solu1_store_credit_history_idempotency_key` (`idempotency_key`)'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
