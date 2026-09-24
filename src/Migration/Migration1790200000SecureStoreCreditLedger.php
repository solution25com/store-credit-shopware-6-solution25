<?php declare(strict_types=1);

namespace Solu1StoreCredit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1790200000SecureStoreCreditLedger extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790200000;
    }

    public function update(Connection $connection): void
    {
        // Never guess whether duplicate balances represent additional credit or copied accounts.
        if ($connection->fetchOne('SELECT customer_id FROM solu1_store_credit GROUP BY customer_id HAVING COUNT(*) > 1 LIMIT 1')) {
            throw new \RuntimeException('Duplicate store credit accounts detected. Reconcile balances and history per customer before retrying the update; no accounts have been deleted.');
        }
        $schema = $connection->createSchemaManager();
        if (!isset($schema->listTableIndexes('solu1_store_credit')['uniq.solu1_store_credit.customer'])) {
            $connection->executeStatement('ALTER TABLE solu1_store_credit ADD UNIQUE INDEX `uniq.solu1_store_credit.customer` (customer_id)');
        }
        if (!isset($schema->listTableColumns('solu1_store_credit_history')['operation_key'])) {
            $connection->executeStatement('ALTER TABLE solu1_store_credit_history ADD operation_key VARCHAR(100) NULL, ADD UNIQUE INDEX `uniq.solu1_store_credit_history.operation` (operation_key)');
        }
        // Old manual adjustments omitted currency. They were displayed as system-currency amounts.
        $connection->executeStatement('UPDATE solu1_store_credit SET currency_id = :currency WHERE currency_id IS NULL', ['currency' => Uuid::fromHexToBytes(Defaults::CURRENCY)]);
        $connection->executeStatement('UPDATE solu1_store_credit_history h JOIN solu1_store_credit c ON c.id = h.store_credit_id SET h.currency_id = c.currency_id WHERE h.currency_id IS NULL');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
