<?php declare(strict_types=1);

if ($extra = getenv('STORE_CREDIT_TEST_EXTRA_AUTOLOAD')) { require $extra; }

$autoload = getenv('STORE_CREDIT_TEST_AUTOLOAD') ?: null;
$directory = dirname(__DIR__);
while ($autoload === null && dirname($directory) !== $directory) {
    if (is_file($directory . '/vendor/autoload.php')) {
        $autoload = $directory . '/vendor/autoload.php';
        break;
    }
    $directory = dirname($directory);
}
if ($autoload === null) {
    throw new RuntimeException('Install Composer dependencies or set STORE_CREDIT_TEST_AUTOLOAD to the Shopware autoloader.');
}
$loader = require $autoload;
$loader->addPsr4('Solu1StoreCredit\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Solu1StoreCredit\\Tests\\', __DIR__ . '/');
