<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

require __DIR__ . '/UnitBootstrap.php';
(new TestBootstrapper())->addCallingPlugin()->addActivePlugins(...(getenv('STORE_CREDIT_TEST_COMMERCIAL') ? ['StoreCredit', 'SwagCommercial'] : ['StoreCredit']))->bootstrap();
