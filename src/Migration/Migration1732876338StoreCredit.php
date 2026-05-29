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
class Migration1732876338StoreCredit extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732876338;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS `solu1_store_credit` (
            `id` BINARY(16) NOT NULL,
            `customer_id` BINARY(16) NOT NULL,
            `currency_id` BINARY(16) NULL,
            `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `updated_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_customer_id` (`customer_id`),
            INDEX `idx_currency_id` (`currency_id`),
            CONSTRAINT `fk_customer_id` FOREIGN KEY (`customer_id`)
            REFERENCES `customer` (`id`)
            ON DELETE CASCADE,
            CONSTRAINT `fk_currency_id` FOREIGN KEY (`currency_id`)
            REFERENCES `currency` (`id`)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
