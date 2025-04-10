<?php

declare(strict_types=1);

namespace StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1732876350StoreCreditHistory extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732876350;
    }

    public function update(Connection $connection): void
    {
        $connection->exec("
        CREATE TABLE IF NOT EXISTS `store_credit_history` (
            `id` BINARY(16) NOT NULL,
            `store_credit_id` BINARY(16) NOT NULL,
            `order_id` BINARY(16) DEFAULT NULL,
            `currency_id` BINARY(16) DEFAULT NULL,
            `amount` DECIMAL(10, 2) NOT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `action_type` VARCHAR(24) NOT NULL,
            `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (`id`),
            INDEX `idx_store_credit_history_store_credit_id` (`store_credit_id`),
            INDEX `idx_store_credit_history_order_id` (`order_id`),
            INDEX `idx_store_credit_history_currency_id` (`currency_id`),
            CONSTRAINT `fk_store_credit_history_store_credit_id` FOREIGN KEY (`store_credit_id`)
                REFERENCES `store_credit` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_store_credit_history_order_id` FOREIGN KEY (`order_id`)
                REFERENCES `order` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_store_credit_history_currency_id` FOREIGN KEY (`currency_id`)
                REFERENCES `currency` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    }
}
