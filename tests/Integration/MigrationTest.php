<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Solu1StoreCredit\Migration\Migration1732876320RenameStoreCreditTables;
use Solu1StoreCredit\Migration\Migration1790200000SecureStoreCreditLedger;

/** Uses a separate, disposable database because MySQL schema changes commit transactions. */
final class MigrationTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $db;
    private string $database;

    protected function setUp(): void
    {
        $source = self::getContainer()->get(Connection::class);
        $this->database = 'store_credit_migration_test_' . substr(Uuid::randomHex(), 0, 10);
        $source->executeStatement('CREATE DATABASE `' . $this->database . '`');
        $params = $source->getParams();
        $params['dbname'] = $this->database;
        $this->db = DriverManager::getConnection($params);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        self::getContainer()->get(Connection::class)->executeStatement('DROP DATABASE `' . $this->database . '`');
    }

    private function tables(string $prefix): void
    {
        $this->db->executeStatement('CREATE TABLE `' . $prefix . 'store_credit` (id BINARY(16) PRIMARY KEY, customer_id BINARY(16) NOT NULL, currency_id BINARY(16) NULL, balance DECIMAL(10,2), created_at DATETIME(3), updated_at DATETIME(3))');
        $this->db->executeStatement('CREATE TABLE `' . $prefix . 'store_credit_history` (id BINARY(16) PRIMARY KEY, store_credit_id BINARY(16) NOT NULL, order_id BINARY(16) NULL, currency_id BINARY(16) NULL, amount DECIMAL(10,2), reason VARCHAR(255), action_type VARCHAR(24), created_at DATETIME(3), updated_at DATETIME(3), FOREIGN KEY (store_credit_id) REFERENCES `' . $prefix . 'store_credit` (id))');
    }

    private function seed(string $prefix): array
    {
        $id = Uuid::randomBytes();
        $customer = Uuid::randomBytes();
        $history = Uuid::randomBytes();
        $this->db->insert($prefix . 'store_credit', ['id' => $id, 'customer_id' => $customer, 'balance' => 12.50, 'created_at' => '2026-01-01 00:00:00']);
        $this->db->insert($prefix . 'store_credit_history', ['id' => $history, 'store_credit_id' => $id, 'amount' => 12.50, 'action_type' => 'add', 'created_at' => '2026-01-01 00:00:00']);
        return [$id, $customer, $history];
    }

    public function testOldOnlyTablesRenameWithoutLosingHistoryOrConstraints(): void
    {
        $this->tables('');
        [$id, $customer, $history] = $this->seed('');
        $migration = new Migration1732876320RenameStoreCreditTables();
        $migration->update($this->db);
        $migration->update($this->db);
        self::assertSame('12.50', $this->db->fetchOne('SELECT balance FROM solu1_store_credit WHERE id = ?', [$id]));
        self::assertSame($id, $this->db->fetchOne('SELECT store_credit_id FROM solu1_store_credit_history WHERE id = ?', [$history]));
        self::assertSame('solu1_store_credit', $this->db->createSchemaManager()->listTableForeignKeys('solu1_store_credit_history')[0]->getForeignTableName());
    }

    public function testBothTableSetsMergeWithoutDroppingEitherSource(): void
    {
        $this->tables('');
        $this->tables('solu1_');
        $old = $this->seed('');
        $this->seed('solu1_');
        $migration = new Migration1732876320RenameStoreCreditTables();
        $migration->update($this->db);
        $migration->update($this->db);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit_history'));
        self::assertSame($old[0], $this->db->fetchOne('SELECT store_credit_id FROM store_credit_history WHERE id = ?', [$old[2]]));
    }

    public function testAmbiguousBalancesFailWithoutDeletingSourceData(): void
    {
        $this->tables('');
        $this->tables('solu1_');
        [$id, $customer] = $this->seed('');
        $this->db->insert('solu1_store_credit', ['id' => Uuid::randomBytes(), 'customer_id' => $customer, 'balance' => 99]);
        try {
            (new Migration1732876320RenameStoreCreditTables())->update($this->db);
            self::fail('Ambiguous accounts must be reconciled rather than summed or discarded.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Reconcile', $e->getMessage());
            self::assertSame('12.50', $this->db->fetchOne('SELECT balance FROM store_credit WHERE id = ?', [$id]));
            self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM store_credit_history'));
        }
    }

    public function testLedgerMigrationIsRepeatableAndEnforcesUniqueness(): void
    {
        $this->tables('solu1_');
        [$id, $customer, $history] = $this->seed('solu1_');
        $migration = new Migration1790200000SecureStoreCreditLedger();
        $migration->update($this->db);
        $migration->update($this->db);
        self::assertSame(Defaults::CURRENCY, strtolower(bin2hex($this->db->fetchOne('SELECT currency_id FROM solu1_store_credit WHERE id = ?', [$id]))));
        self::assertSame(Defaults::CURRENCY, strtolower(bin2hex($this->db->fetchOne('SELECT currency_id FROM solu1_store_credit_history WHERE id = ?', [$history]))));
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->db->insert('solu1_store_credit', ['id' => Uuid::randomBytes(), 'customer_id' => $customer, 'balance' => 1]);
    }

    public function testDuplicateLegacyAccountsBlockUpdateWithoutDiscardingMoney(): void
    {
        $this->tables('solu1_');
        [$id, $customer] = $this->seed('solu1_');
        $this->db->insert('solu1_store_credit', ['id' => Uuid::randomBytes(), 'customer_id' => $customer, 'balance' => 20]);
        try {
            (new Migration1790200000SecureStoreCreditLedger())->update($this->db);
            self::fail('Duplicate customer accounts must block the update.');
        } catch (\RuntimeException) {
            self::assertSame('32.50', $this->db->fetchOne('SELECT SUM(balance) FROM solu1_store_credit'));
            self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit'));
        }
    }
    public function testMixedLegacyTablesAndVersionedOrderLinksKeepTheirHistory(): void
    {
        $this->tables('');
        $this->tables('solu1_');
        $this->db->executeStatement('DROP TABLE solu1_store_credit_history');
        [$id, , $history] = $this->seed('');
        (new Migration1732876320RenameStoreCreditTables())->update($this->db);
        (new Migration1790200000SecureStoreCreditLedger())->update($this->db);
        $currency = Uuid::fromHexToBytes(Defaults::CURRENCY);
        $this->db->executeStatement('CREATE TABLE currency (id BINARY(16) PRIMARY KEY)');
        $this->db->insert('currency', ['id' => $currency]);
        $this->db->executeStatement('CREATE TABLE `order` (id BINARY(16), version_id BINARY(16), PRIMARY KEY (id, version_id))');
        $order = Uuid::randomBytes();
        $draft = Uuid::randomBytes();
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $this->db->insert('`order`', ['id' => $order, 'version_id' => $live]);
        $this->db->insert('`order`', ['id' => $order, 'version_id' => $draft]);
        $this->db->update('solu1_store_credit_history', ['order_id' => $order], ['id' => $history]);
        $migration = new \Solu1StoreCredit\Migration\Migration1790200100ProtectLedgerRelations();
        $migration->update($this->db);
        $migration->update($this->db);
        // The old source wallet is now independent of the migrated live ledger.
        $this->db->delete('store_credit', ['id' => $id]);
        self::assertSame($id, $this->db->fetchOne('SELECT store_credit_id FROM solu1_store_credit_history WHERE id = ?', [$history]));
        $this->db->delete('`order`', ['id' => $order, 'version_id' => $draft]);
        self::assertSame($order, $this->db->fetchOne('SELECT order_id FROM solu1_store_credit_history WHERE id = ?', [$history]));
        self::assertSame($live, $this->db->fetchOne('SELECT order_version_id FROM solu1_store_credit_history WHERE id = ?', [$history]));
        $this->db->delete('`order`', ['id' => $order, 'version_id' => $live]);
        self::assertNull($this->db->fetchOne('SELECT order_id FROM solu1_store_credit_history WHERE id = ?', [$history]));
        self::assertSame('12.50', $this->db->fetchOne('SELECT amount FROM solu1_store_credit_history WHERE id = ?', [$history]));
        try {
            $this->db->delete('currency', ['id' => $currency]);
            self::fail('Deleting a wallet currency must not delete money.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            self::assertSame('12.50', $this->db->fetchOne('SELECT balance FROM solu1_store_credit WHERE id = ?', [$id]));
        }
    }

}
