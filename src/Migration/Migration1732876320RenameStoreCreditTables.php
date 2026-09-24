<?php declare(strict_types=1);

namespace Solu1StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1732876320RenameStoreCreditTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1732876320;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        foreach (['store_credit' => 'solu1_store_credit', 'store_credit_history' => 'solu1_store_credit_history'] as $old => $new) {
            if (!$schema->tablesExist([$old])) {
                continue;
            }
            if (!$schema->tablesExist([$new])) {
                // Renaming retains constraints and lets MySQL update references atomically.
                $connection->executeStatement(sprintf('RENAME TABLE `%s` TO `%s`', $old, $new));
                continue;
            }
            $columns = $old === 'store_credit'
                ? ['id', 'customer_id', 'currency_id', 'balance', 'created_at', 'updated_at']
                : ['id', 'store_credit_id', 'order_id', 'currency_id', 'amount', 'reason', 'action_type', 'created_at', 'updated_at'];
            $comparisons = array_map(static fn (string $column): string => sprintf('NOT (o.`%1$s` <=> n.`%1$s`)', $column), $columns);
            if ($connection->fetchOne(sprintf('SELECT 1 FROM `%s` o JOIN `%s` n ON n.id = o.id WHERE %s LIMIT 1', $old, $new, implode(' OR ', $comparisons)))) {
                throw new \RuntimeException('Conflicting legacy store credit data detected. Reconcile the old and new tables before retrying; the source tables have been preserved.');
            }
            if ($old === 'store_credit' && $connection->fetchOne('SELECT 1 FROM store_credit o JOIN solu1_store_credit n ON n.customer_id = o.customer_id AND n.id != o.id LIMIT 1')) {
                throw new \RuntimeException('A customer has both a legacy and a namespaced store credit account. Reconcile their balances and history before retrying; no source data has been deleted.');
            }
            $names = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            $select = implode(', ', array_map(static fn (string $column): string => 'o.`' . $column . '`', $columns));
            $connection->executeStatement(sprintf('INSERT INTO `%s` (%s) SELECT %s FROM `%s` o WHERE NOT EXISTS (SELECT 1 FROM `%s` n WHERE n.id = o.id)', $new, $names, $select, $old, $new));
            // Keep the legacy source as a recovery copy when both tables existed. No INSERT IGNORE or DROP.
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
