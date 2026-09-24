<?php declare(strict_types=1);

namespace Solu1StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1790200100ProtectLedgerRelations extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790200100;
    }

    public function update(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!isset($schema->listTableColumns('solu1_store_credit_history')['order_version_id'])) {
            $connection->executeStatement('ALTER TABLE solu1_store_credit_history ADD order_version_id BINARY(16) NULL AFTER order_id');
        }
        $connection->executeStatement('UPDATE solu1_store_credit_history h SET order_id = NULL WHERE order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `order` o WHERE o.id = h.order_id AND o.version_id = :version)', ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]);
        $connection->executeStatement('UPDATE solu1_store_credit_history SET order_version_id = :version WHERE order_id IS NOT NULL AND order_version_id IS NULL', ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]);
        $this->ensureForeignKey($connection, 'solu1_store_credit_history', ['order_id', 'order_version_id'], 'order', ['id', 'version_id'], 'SET NULL', 'fk.solu1_sc_history.order_version');
        $this->ensureForeignKey($connection, 'solu1_store_credit_history', ['store_credit_id'], 'solu1_store_credit', ['id'], 'CASCADE', 'fk.solu1_sc_history.credit');
        $this->ensureForeignKey($connection, 'solu1_store_credit', ['currency_id'], 'currency', ['id'], 'RESTRICT', 'fk.solu1_sc.currency');
        $this->ensureForeignKey($connection, 'solu1_store_credit_history', ['currency_id'], 'currency', ['id'], 'RESTRICT', 'fk.solu1_sc_history.currency');
    }

    /** @param list<string> $localColumns @param list<string> $foreignColumns */
    private function ensureForeignKey(Connection $connection, string $table, array $localColumns, string $foreignTable, array $foreignColumns, string $onDelete, string $name): void
    {
        foreach ($connection->createSchemaManager()->listTableForeignKeys($table) as $key) {
            if (!in_array($localColumns[0], $key->getLocalColumns(), true)) {
                continue;
            }
            if ($key->getLocalColumns() === $localColumns && $key->getForeignTableName() === $foreignTable && $key->getForeignColumns() === $foreignColumns && $key->onDelete() === $onDelete) {
                return;
            }
            // @phpstan-ignore method.deprecatedClass (DBAL versions supported by Shopware 6.7.0 do not have getObjectName().)
            $connection->executeStatement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $key->getName()));
        }
        $quote = static fn (string $column): string => '`' . $column . '`';
        $connection->executeStatement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE CASCADE', $table, $name, implode(', ', array_map($quote, $localColumns)), $foreignTable, implode(', ', array_map($quote, $foreignColumns)), $onDelete));
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
