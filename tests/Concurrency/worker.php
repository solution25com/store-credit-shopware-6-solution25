<?php declare(strict_types=1);

require dirname(__DIR__) . '/UnitBootstrap.php';

use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Kernel;
use Shopware\Core\Framework\Context;
use Solu1StoreCredit\Service\StoreCreditManager;
use Solu1StoreCredit\Exception\InsufficientCreditException;

$plugins = new DbalKernelPluginLoader($loader, null, Kernel::getConnection());
$kernel = KernelFactory::create('test', true, $loader, $plugins);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$db = $container->get(\Doctrine\DBAL\Connection::class);
if (!str_contains((string) $db->getDatabase(), 'test')) {
    throw new RuntimeException('Concurrency tests require an explicitly named test database.');
}
[$script, $action, $customerId, $barrier, $workerId] = $argv;
file_put_contents($barrier . '.' . $workerId, 'ready');
$deadline = microtime(true) + 20;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) throw new RuntimeException('Concurrency barrier timeout');
    usleep(10000);
    clearstatcache(true, $barrier);
}
try {
    $manager = $container->get(StoreCreditManager::class);
    if ($action === 'deduct') {
        $manager->deductCredit($customerId, 80.0, Context::createCLIContext(), reason: 'Concurrent test');
    } else {
        $manager->addCredit($customerId, 20.0, Context::createCLIContext(), reason: 'Concurrent test');
    }
    echo 'success';
} catch (InsufficientCreditException) {
    echo 'insufficient';
}
