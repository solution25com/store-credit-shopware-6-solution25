<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Solu1StoreCredit\Service\StoreCreditManager;

final class ConcurrencyTest extends TestCase
{
    use KernelTestBehaviour;

    public function testConcurrentSpendingAndFirstWalletCreation(): void
    {
        $db = self::getContainer()->get(Connection::class);
        if (!str_contains((string) $db->getDatabase(), 'test')) {
            self::markTestSkipped('Concurrency tests require an explicitly named test database.');
        }
        $context = Context::createCLIContext();
        $channel = $db->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = UNHEX(:type) LIMIT 1', ['type' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT]);
        $customer = (new CustomerBuilder(new IdsCollection(), 'race-' . Uuid::randomHex(), $channel))->build();
        $fixtureKey = 'credit-test-' . $customer['id'];
        array_walk_recursive($customer, static function (&$value, $key) use ($fixtureKey): void { if ($key === 'salutationKey') $value = $fixtureKey; });
        $customerRepo = self::getContainer()->get('customer.repository');
        $customerRepo->create([$customer], $context);
        $manager = self::getContainer()->get(StoreCreditManager::class);
        try {
            $results = $this->race('add', $customer['id']);
            self::assertSame(['success', 'success'], $results);
            self::assertSame(40.0, $manager->getCreditBalance($customer['id'], $context)['balanceAmount']);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit WHERE customer_id = UNHEX(:id)', ['id' => $customer['id']]));
            $manager->addCredit($customer['id'], 60.0, $context);
            $results = $this->race('deduct', $customer['id']);
            sort($results);
            self::assertSame(['insufficient', 'success'], $results);
            self::assertSame(20.0, $manager->getCreditBalance($customer['id'], $context)['balanceAmount']);
            self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM solu1_store_credit_history h JOIN solu1_store_credit c ON c.id = h.store_credit_id WHERE c.customer_id = UNHEX(:id) AND h.action_type = 'deduct'", ['id' => $customer['id']]));
        } finally {
            $customerRepo->delete([['id' => $customer['id']]], $context);
            self::getContainer()->get('salutation.repository')->delete([['id' => $customer['salutation']['id']]], $context);
            self::getContainer()->get('customer_group.repository')->delete([['id' => $customer['group']['id']]], $context);
        }
    }

    private function race(string $action, string $customerId): array
    {
        $barrier = sys_get_temp_dir() . '/store-credit-race-' . Uuid::randomHex();
        $processes = [];
        try {
            foreach (['a', 'b'] as $workerId) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Concurrency/worker.php', $action, $customerId, $barrier, $workerId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (!is_file($barrier . '.a') || !is_file($barrier . '.b')) {
                if (microtime(true) > $deadline) self::fail('Workers did not reach the concurrency barrier.');
                usleep(10000);
                clearstatcache();
            }
            file_put_contents($barrier, 'start');
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            return $results;
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            foreach (['', '.a', '.b'] as $suffix) if (is_file($barrier . $suffix)) unlink($barrier . $suffix);
        }
    }
}
