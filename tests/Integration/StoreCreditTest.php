<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderPersister;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Core\Checkout\Cart\StoreCreditOrderPersister;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Service\StoreCreditManager;

final class StoreCreditTest extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;
    use \Shopware\Core\Framework\Test\TestCaseBase\CacheTestBehaviour;
    use \Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;

    private function manager(): StoreCreditManager
    {
        return self::getContainer()->get(StoreCreditManager::class);
    }

    private function customerContext(): SalesChannelContext
    {
        $channelId = self::getContainer()->get(Connection::class)->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = UNHEX(:type) LIMIT 1', ['type' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT]);
        $customer = (new CustomerBuilder(new IdsCollection(), 'credit-' . Uuid::randomHex(), $channelId))->build();
        $fixtureKey = 'credit-test-' . $customer['id'];
        array_walk_recursive($customer, static function (&$value, $key) use ($fixtureKey): void { if ($key === 'salutationKey') $value = $fixtureKey; });
        self::getContainer()->get('customer.repository')->create([$customer], Context::createDefaultContext());
        return self::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), $channelId, [SalesChannelContextService::CUSTOMER_ID => $customer['id'], SalesChannelContextService::CURRENCY_ID => Defaults::CURRENCY]);
    }

    private function cart(SalesChannelContext $context, float $price = 100.0, float $credit = 20.0): Cart
    {
        $cart = new Cart($context->getToken());
        $product = new LineItem(Uuid::randomHex(), LineItem::CUSTOM_LINE_ITEM_TYPE);
        $product->setLabel('Credit test item');
        $product->setPriceDefinition(new QuantityPriceDefinition($price, new TaxRuleCollection([new TaxRule(19)])));
        $product->setGood(false)->setStackable(true)->setRemovable(true);
        $cart->add($product);
        $discount = new LineItem(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID, LineItem::CREDIT_LINE_ITEM_TYPE, StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
        $discount->setPriceDefinition(new AbsolutePriceDefinition(-$credit));
        $discount->setLabel(StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL);
        $cart->add($discount);
        return self::getContainer()->get(CartCalculator::class)->calculate($cart, $context);
    }

    public function testCreditAndHistoryPersistWithTimestampsAndCurrency(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $historyId = $this->manager()->addCredit($id, 50.25, $context->getContext());
        $this->manager()->deductCredit($id, 10.20, $context->getContext());
        self::assertSame(['balanceAmount' => 40.05, 'currencyId' => Defaults::CURRENCY], $this->manager()->getCreditBalance($id, $context->getContext()));
        $history = self::getContainer()->get('solu1_store_credit_history.repository')->search(new Criteria([$historyId]), $context->getContext())->first();
        self::assertSame('add', $history->getActionType());
        self::assertSame(Defaults::CURRENCY, $history->getCurrencyId());
        self::assertInstanceOf(\DateTimeInterface::class, $history->getCreatedAt());
        self::assertNotNull($this->manager()->getStoreCreditEntity($id, $context->getContext())->getUpdatedAt());
    }

    public function testDuplicateOperationIsNotAppliedTwice(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $key = 'return:' . Uuid::randomHex();
        $first = $this->manager()->addCredit($id, 15.0, $context->getContext(), operationKey: $key);
        self::assertSame($first, $this->manager()->addCredit($id, 15.0, $context->getContext(), operationKey: $key));
        self::assertSame(15.0, $this->manager()->getCreditBalance($id, $context->getContext())['balanceAmount']);
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->addCredit($id, 20.0, $context->getContext(), operationKey: $key);
    }

    public function testInsufficientBalanceDoesNotWriteHistory(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $this->manager()->addCredit($id, 10.0, $context->getContext());
        $before = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM solu1_store_credit_history');
        try {
            $this->manager()->deductCredit($id, 10.01, $context->getContext());
            self::fail('Overdraft must fail.');
        } catch (InsufficientCreditException) {
            self::assertSame(10.0, $this->manager()->getCreditBalance($id, $context->getContext())['balanceAmount']);
            self::assertSame($before, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM solu1_store_credit_history'));
        }
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsCannotChangeBalance(float $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->addCredit(Uuid::randomHex(), $amount, Context::createDefaultContext());
    }

    public static function invalidAmounts(): iterable
    {
        foreach ([0, -1, 0.001, 1.001, INF, NAN, 100000000] as $amount) {
            yield [(float) $amount];
        }
    }

    public function testCurrencyCannotBeChangedOrClearedByAnAdjustment(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $this->manager()->addCredit($id, 50.0, $context->getContext());
        $this->manager()->deductCredit($id, 1.0, $context->getContext(), currencyId: null);
        self::assertSame(Defaults::CURRENCY, $this->manager()->getCreditBalance($id, $context->getContext())['currencyId']);
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->addCredit($id, 1.0, $context->getContext(), currencyId: Uuid::randomHex());
    }

    public function testCartBoundsCreditAndRevalidatesAfterBalanceChanges(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 50.0, $context->getContext());
        $cart = $this->cart($context, 30.0, 80.0);
        self::assertSame(-30.0, $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID)->getPrice()->getTotalPrice());
        $this->manager()->deductCredit($context->getCustomer()->getId(), 40.0, $context->getContext());
        $cart = self::getContainer()->get(CartCalculator::class)->calculate($cart, $context);
        self::assertSame(-10.0, $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID)->getPrice()->getTotalPrice());
        self::assertGreaterThanOrEqual(0, $cart->getPrice()->getTotalPrice());
    }

    public function testPerChannelLimitAndRestrictedProductsAreEnforced(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 100.0, $context->getContext());
        $config = self::getContainer()->get(SystemConfigService::class);
        $config->set('StoreCredit.config.maxCreditPerOrder', 12.5, $context->getSalesChannelId());
        $cart = $this->cart($context, 100.0, 90.0);
        self::assertSame(-12.5, $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID)->getPrice()->getTotalPrice());
        $productId = Uuid::randomHex();
        $cart->getLineItems()->filterType(LineItem::CUSTOM_LINE_ITEM_TYPE)->first()->setReferencedId($productId);
        $config->set('StoreCredit.config.restrictedProducts', [$productId], $context->getSalesChannelId());
        $cart = self::getContainer()->get(CartCalculator::class)->calculate($cart, $context);
        self::assertFalse($cart->getLineItems()->has(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID));
    }

    public function testUnfundedCustomersDoNotGetDiscounts(): void
    {
        $context = $this->customerContext();
        $cart = $this->cart($context);
        self::assertFalse($cart->getLineItems()->has(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID));
        self::assertSame(100.0, $cart->getPrice()->getPositionPrice());
    }

    public function testOrderAndDeductionArePersistedTogether(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $this->manager()->addCredit($id, 50.0, $context->getContext());
        $cart = $this->cart($context);
        $persister = self::getContainer()->get(OrderPersister::class);
        self::assertInstanceOf(StoreCreditOrderPersister::class, $persister);
        $orderId = $persister->persist($cart, $context);
        self::assertSame(30.0, $this->manager()->getCreditBalance($id, $context->getContext())['balanceAmount']);
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit_history WHERE operation_key = :key AND order_id = UNHEX(:id)', ['key' => 'order:' . $orderId, 'id' => $orderId]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM `order` WHERE id = UNHEX(:id)', ['id' => $orderId]));
    }

    public function testOrderIsRolledBackWhenDeductionFails(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 50.0, $context->getContext());
        $cart = $this->cart($context);
        $db = self::getContainer()->get(Connection::class);
        $before = $db->fetchOne('SELECT COUNT(*) FROM `order`');
        $manager = $this->createPartialMock(StoreCreditManager::class, ['deductCredit']);
        $ref = new \ReflectionClass(StoreCreditManager::class);
        $ref->getConstructor()->invoke($manager, self::getContainer()->get('solu1_store_credit.repository'), self::getContainer()->get('solu1_store_credit_history.repository'), $db);
        $manager->method('deductCredit')->willThrowException(new \RuntimeException('Injected ledger failure'));
        $inner = self::getContainer()->get(StoreCreditOrderPersister::class . '.inner');
        try {
            (new StoreCreditOrderPersister($inner, $manager, $db))->persist($cart, $context);
            self::fail('The ledger failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected ledger failure', $e->getMessage());
            self::assertSame($before, $db->fetchOne('SELECT COUNT(*) FROM `order`'));
            self::assertSame(50.0, $this->manager()->getCreditBalance($context->getCustomer()->getId(), $context->getContext())['balanceAmount']);
        }
    }
    public function testAdminApiPermissionsAndMalformedPayloads(): void
    {
        $context = $this->customerContext();
        $customerId = $context->getCustomer()->getId();
        $viewer = $this->getBrowser(true, [], ['solu1_store_credit:read']);
        $viewer->request('GET', '/api/store-credit/balance?customerId=' . $customerId);
        self::assertSame(200, $viewer->getResponse()->getStatusCode(), $viewer->getResponse()->getContent());
        $viewer->request('POST', '/api/store-credit/add', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['customerId' => $customerId, 'amount' => 5]));
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
        $this->resetBrowser();
        $editor = $this->getBrowser(true, [], ['solu1_store_credit:create', 'solu1_store_credit:update', 'solu1_store_credit:read']);
        $editor->request('POST', '/api/store-credit/add', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['customerId' => $customerId, 'amount' => 5]));
        self::assertSame(200, $editor->getResponse()->getStatusCode(), $editor->getResponse()->getContent());
        self::assertSame(5.0, $this->manager()->getCreditBalance($customerId, $context->getContext())['balanceAmount']);
        foreach ([['amount' => 3], ['customerId' => [], 'amount' => 3], ['customerId' => $customerId, 'amount' => []], ['customerId' => $customerId, 'amount' => 0.001], ['customerId' => $customerId, 'amount' => 3, 'currencyId' => 'xyz'], ['customerId' => $customerId, 'amount' => 3, 'reason' => []]] as $payload) {
            $editor->request('POST', '/api/store-credit/add', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
            self::assertSame(400, $editor->getResponse()->getStatusCode(), $editor->getResponse()->getContent());
        }
    }

    public function testDirectDalApiCannotBypassLedgerWrites(): void
    {
        $context = $this->customerContext();
        $customerId = $context->getCustomer()->getId();
        $this->manager()->addCredit($customerId, 5.0, $context->getContext());
        $walletId = $this->manager()->getStoreCreditId($customerId, $context->getContext());
        $browser = $this->getBrowser();
        $browser->request('PATCH', '/api/solu1-store-credit/' . $walletId, [], [], ['CONTENT_TYPE' => 'application/json'], '{"balance":9999}');
        self::assertSame(400, $browser->getResponse()->getStatusCode(), $browser->getResponse()->getContent());
        self::assertSame(5.0, $this->manager()->getCreditBalance($customerId, $context->getContext())['balanceAmount']);
    }

    public function testCustomerPageHasLoginAndNoCacheAndApplyIsPostOnly(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();
        $account = $routes->get('frontend.account.store-credit.page');
        self::assertTrue($account->getDefault('_loginRequired'));
        self::assertTrue($account->getDefault('_noStore'));
        $apply = $routes->get('frontend.store.credit.apply');
        self::assertTrue($apply->getDefault('_loginRequired'));
        self::assertSame(['POST'], $apply->getMethods());
    }

    public function testCheckoutPageSubscriberDoesNotChangeBalanceAndReadsConfig(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 50.0, $context->getContext());
        $cart = $this->cart($context);
        $config = self::getContainer()->get(SystemConfigService::class);
        $config->set('StoreCredit.config.maxCreditPerOrder', 25.0, $context->getSalesChannelId());
        $config->set('StoreCredit.config.expandStoreCreditByDefault', false, $context->getSalesChannelId());
        $page = new \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage();
        $page->setCart($cart);
        $event = new \Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent($page, $context, new \Symfony\Component\HttpFoundation\Request());
        self::getContainer()->get(\Solu1StoreCredit\EventSubscriber\StoreCreditCheckoutSubscriber::class)->onPageLoaded($event);
        $credit = $page->getExtension('storeCredit');
        self::assertSame(30.0, $credit->get('remaining'));
        self::assertSame(5.0, $credit->get('maximum'));
        self::assertFalse($credit->get('expanded'));
        self::assertSame(50.0, $this->manager()->getCreditBalance($context->getCustomer()->getId(), $context->getContext())['balanceAmount']);
    }

    public function testFailedBalanceCheckNeverCallsInnerOrderPersister(): void
    {
        $context = $this->customerContext();
        $id = $context->getCustomer()->getId();
        $this->manager()->addCredit($id, 50.0, $context->getContext());
        $cart = $this->cart($context, 100.0, 40.0);
        $this->manager()->deductCredit($id, 30.0, $context->getContext());
        $inner = $this->createMock(OrderPersisterInterface::class);
        $inner->expects(self::never())->method('persist');
        $persister = new StoreCreditOrderPersister($inner, $this->manager(), self::getContainer()->get(Connection::class));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $persister->persist($cart, $context);
    }

    public function testMultipleLegacyAdminCreditsCannotExceedOneWallet(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 30.0, $context->getContext());
        $cart = $this->cart($context, 100.0, 20.0);
        $other = new LineItem(Uuid::randomHex(), LineItem::CREDIT_LINE_ITEM_TYPE);
        $other->setLabel(StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL)->setPriceDefinition(new AbsolutePriceDefinition(-25.0));
        $cart->add($other);
        $cart = self::getContainer()->get(CartCalculator::class)->calculate($cart, $context);
        $total = 0.0;
        foreach ($cart->getLineItems()->filter(\Solu1StoreCredit\Core\Checkout\Cart\StoreCreditLineItem::matches(...)) as $item) {
            $total += $item->getPrice()->getTotalPrice();
        }
        self::assertSame(-30.0, $total);
        self::assertSame(70.0, $cart->getPrice()->getPositionPrice());
    }

    public function testReturnTransitionCreditsTheSelectedReturnExactlyOnce(): void
    {
        if (!getenv('STORE_CREDIT_TEST_COMMERCIAL')) self::markTestSkipped('Enable STORE_CREDIT_TEST_COMMERCIAL with Commercial installed in the test shop.');
        $context = $this->customerContext();
        $customerId = $context->getCustomer()->getId();
        $this->manager()->addCredit($customerId, 1.0, $context->getContext());
        $cart = $this->cart($context, 100.0, 1.0);
        $orderId = self::getContainer()->get(OrderPersister::class)->persist($cart, $context);
        $db = self::getContainer()->get(Connection::class);
        $paid = $db->fetchOne("SELECT LOWER(HEX(s.id)) FROM state_machine_state s JOIN state_machine m ON m.id = s.state_machine_id WHERE m.technical_name = 'order_transaction.state' AND s.technical_name = 'paid'");
        $transactionId = $db->fetchOne('SELECT LOWER(HEX(id)) FROM order_transaction WHERE order_id = UNHEX(:id)', ['id' => $orderId]);
        $context->getContext()->scope(Context::SYSTEM_SCOPE, fn (Context $ctx) => self::getContainer()->get('order_transaction.repository')->update([['id' => $transactionId, 'stateId' => $paid]], $ctx));
        self::getContainer()->get(\Solu1StoreCredit\Service\OrderStateInstaller::class)->managePresaleStatuses($context->getContext(), true);
        $returns = self::getContainer()->get('order_return.repository');
        $lineItemId = $db->fetchOne("SELECT LOWER(HEX(id)) FROM order_line_item WHERE order_id = UNHEX(:id) AND type = 'custom'", ['id' => $orderId]);
        $stateId = $db->fetchOne("SELECT LOWER(HEX(s.id)) FROM state_machine_state s JOIN state_machine m ON m.id = s.state_machine_id WHERE m.technical_name = 'order_return.state' AND s.technical_name = 'open'");
        $reasonId = $db->fetchOne('SELECT LOWER(HEX(id)) FROM order_return_line_item_reason LIMIT 1');
        $lineStateId = $db->fetchOne("SELECT LOWER(HEX(s.id)) FROM state_machine_state s JOIN state_machine m ON m.id = s.state_machine_id WHERE m.technical_name = 'order_line_item.state' LIMIT 1");
        $returnIds = [Uuid::randomHex(), Uuid::randomHex()];
        foreach ($returnIds as $index => $returnId) {
            $returns->create([['id' => $returnId, 'orderId' => $orderId, 'stateId' => $stateId, 'returnNumber' => 'refund-' . $returnId, 'requestedAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT), 'lineItems' => [[
                'id' => Uuid::randomHex(), 'orderLineItemId' => $lineItemId, 'quantity' => 1, 'reasonId' => $reasonId, 'stateId' => $lineStateId,
                'refundAmount' => $index === 0 ? 12.5 : 30.0, 'restockQuantity' => 0,
                'price' => new \Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice(100, 100, new \Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection(), new TaxRuleCollection()),
            ]]]], $context->getContext());
        }
        $registry = self::getContainer()->get(\Shopware\Core\System\StateMachine\StateMachineRegistry::class);
        $registry->transition(new \Shopware\Core\System\StateMachine\Transition('order_return', $returnIds[0], 'mark_as_store_credit', 'stateId'), $context->getContext());
        self::assertSame(12.5, $this->manager()->getCreditBalance($customerId, $context->getContext())['balanceAmount']);
        $registry->transition(new \Shopware\Core\System\StateMachine\Transition('order_return', $returnIds[0], 'mark_as_open', 'stateId'), $context->getContext());
        $registry->transition(new \Shopware\Core\System\StateMachine\Transition('order_return', $returnIds[0], 'mark_as_store_credit', 'stateId'), $context->getContext());
        self::assertSame(12.5, $this->manager()->getCreditBalance($customerId, $context->getContext())['balanceAmount']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM solu1_store_credit_history WHERE operation_key = :key', ['key' => 'return:' . $returnIds[0]]));
        self::assertSame($stateId, $returns->search(new Criteria([$returnIds[1]]), $context->getContext())->first()->get('stateId'));
        // An unpaid order must leave both the return state and ledger unchanged.
        $openPayment = $db->fetchOne("SELECT LOWER(HEX(s.id)) FROM state_machine_state s JOIN state_machine m ON m.id = s.state_machine_id WHERE m.technical_name = 'order_transaction.state' AND s.technical_name = 'open'");
        $context->getContext()->scope(Context::SYSTEM_SCOPE, fn (Context $ctx) => self::getContainer()->get('order_transaction.repository')->update([['id' => $transactionId, 'stateId' => $openPayment]], $ctx));
        try {
            $registry->transition(new \Shopware\Core\System\StateMachine\Transition('order_return', $returnIds[1], 'mark_as_store_credit', 'stateId'), $context->getContext());
            self::fail('Unpaid return must fail.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
            self::assertSame($stateId, $returns->search(new Criteria([$returnIds[1]]), $context->getContext())->first()->get('stateId'));
            self::assertSame(12.5, $this->manager()->getCreditBalance($customerId, $context->getContext())['balanceAmount']);
        }
    }

    public function testStorefrontAccountRendersAndCreditApplicationUsesTheLoggedInCustomer(): void
    {
        $context = $this->customerContext();
        $customer = $context->getCustomer();
        self::getContainer()->get('customer.repository')->update([['id' => $customer->getId(), 'password' => 'store-credit-test-password']], $context->getContext());
        $this->manager()->addCredit($customer->getId(), 50.0, $context->getContext(), reason: '<script>unsafe</script>');
        $domain = self::getContainer()->get(Connection::class)->fetchOne('SELECT url FROM sales_channel_domain WHERE sales_channel_id = UNHEX(:id) LIMIT 1', ['id' => $context->getSalesChannelId()]);
        $browser = \Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::createBrowser(self::getKernel());
        $browser->request('GET', $domain . '/account/store-credit');
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        $browser->request('POST', $domain . '/account/login', ['username' => $customer->getEmail(), 'password' => 'store-credit-test-password', 'redirectTo' => 'frontend.account.store-credit.page']);
        self::assertSame(302, $browser->getResponse()->getStatusCode(), $browser->getResponse()->getContent());
        $browser->request('GET', $domain . '/account/store-credit');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), substr($browser->getResponse()->getContent(), 0, 2000));
        $html = $browser->getResponse()->getContent();
        self::assertStringContainsString('Store Credits', $html);
        // Shopware sanitizes StringField input before Twig renders it.
        self::assertTrue(str_contains($html, '<td>unsafe</td>'), 'The sanitized history reason must be visible.');
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
        $token = self::getContainer()->get(Connection::class)->fetchOne('SELECT token FROM sales_channel_api_context WHERE customer_id = UNHEX(:id) AND sales_channel_id = UNHEX(:channel) ORDER BY updated_at DESC LIMIT 1', ['id' => $customer->getId(), 'channel' => $context->getSalesChannelId()]);
        self::assertNotEmpty($token);
        $loggedContext = self::getContainer()->get(SalesChannelContextFactory::class)->create($token, $context->getSalesChannelId(), [SalesChannelContextService::CUSTOMER_ID => $customer->getId()]);
        $cart = new Cart($token);
        $product = new LineItem(Uuid::randomHex(), LineItem::CUSTOM_LINE_ITEM_TYPE);
        $product->setLabel('HTTP credit test')->setGood(false)->setPriceDefinition(new QuantityPriceDefinition(100.0, new TaxRuleCollection([new TaxRule(19)])));
        self::getContainer()->get(\Shopware\Core\Checkout\Cart\SalesChannel\CartService::class)->add($cart, $product, $loggedContext);
        $browser->request('POST', $domain . '/store-credit-apply', ['amount' => '12.50']);
        self::assertSame(302, $browser->getResponse()->getStatusCode(), $browser->getResponse()->getContent());
        $persisted = self::getContainer()->get(\Shopware\Core\Checkout\Cart\CartPersister::class)->load($token, $loggedContext);
        self::assertSame(-12.5, $persisted->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID)->getPrice()->getTotalPrice());
        self::assertSame(50.0, $this->manager()->getCreditBalance($customer->getId(), $context->getContext())['balanceAmount']);
        // The test browser keeps the kernel alive to retain the fixture transaction.
        self::getContainer()->get(\Twig\Environment::class)->resetGlobals();
        $browser->request('GET', $domain . '/checkout/confirm');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), substr($browser->getResponse()->getContent(), 0, 2000));
        self::assertTrue(str_contains($browser->getResponse()->getContent(), 'data-storecredit-plugin'), 'The checkout must include the store credit form.');
        self::assertTrue(str_contains($browser->getResponse()->getContent(), 'max="37.5"'), 'Only the remaining credit may be applied.');
    }

    public function testCashRoundingCannotIncreaseTheDiscountPastTheWallet(): void
    {
        $context = $this->customerContext();
        $this->manager()->addCredit($context->getCustomer()->getId(), 0.53, $context->getContext());
        $context->setItemRounding(new \Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig(2, 0.05, true));
        $cart = $this->cart($context, 100.0, 0.53);
        self::assertSame(-0.5, $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID)->getPrice()->getTotalPrice());
        $context->setItemRounding(new \Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig(0, 1.0, true));
        $cart = $this->cart($context, 100.0, 0.53);
        self::assertFalse($cart->getLineItems()->has(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID));
    }

    public function testShopwareFindsTheBuiltAdministrationAssets(): void
    {
        $accessor = new \Shopware\Administration\Framework\Twig\ViteFileAccessorDecorator(
            [], new \Symfony\Component\Asset\Package(new \Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy()),
            self::getKernel(), new \Symfony\Component\Filesystem\Filesystem(),
        );
        $bundle = self::getKernel()->getBundle('StoreCredit');
        $data = $accessor->getBundleData($bundle);
        foreach (['js', 'css'] as $type) {
            self::assertNotEmpty($data['entryPoints']['store-credit'][$type]);
            foreach ($data['entryPoints']['store-credit'][$type] as $asset) {
                self::assertFileExists($bundle->getPath() . '/Resources/public/administration/assets/' . basename($asset));
            }
        }
    }

    public function testDeactivationRemovesTheRefundActionAndActivationRestoresConfiguredRefunds(): void
    {
        if (!getenv('STORE_CREDIT_TEST_COMMERCIAL')) self::markTestSkipped('Requires Commercial Return Management.');
        $context = new Context(new \Shopware\Core\Framework\Api\Context\SystemSource());
        self::getContainer()->get(SystemConfigService::class)->set('StoreCredit.config.runInstallOrderStateCommand', true);
        $db = self::getContainer()->get(Connection::class);
        $query = "SELECT COUNT(*) FROM state_machine_transition t JOIN state_machine m ON m.id = t.state_machine_id WHERE m.technical_name = 'order_return.state' AND t.action_name = 'mark_as_store_credit'";
        self::assertSame(1, (int) $db->fetchOne($query));
        $states = (int) $db->fetchOne("SELECT COUNT(*) FROM state_machine_state WHERE technical_name = 'store_credit'");
        $openTransitions = (int) $db->fetchOne("SELECT COUNT(*) FROM state_machine_transition WHERE action_name = 'mark_as_open'");
        $plugin = new \Solu1StoreCredit\StoreCredit(true, dirname(__DIR__, 2));
        $plugin->setContainer(self::getContainer());
        $deactivate = $this->createStub(\Shopware\Core\Framework\Plugin\Context\DeactivateContext::class);
        $deactivate->method('getContext')->willReturn($context);
        $plugin->deactivate($deactivate);
        self::assertSame(0, (int) $db->fetchOne($query));
        self::assertSame($states, (int) $db->fetchOne("SELECT COUNT(*) FROM state_machine_state WHERE technical_name = 'store_credit'"));
        self::assertSame($openTransitions, (int) $db->fetchOne("SELECT COUNT(*) FROM state_machine_transition WHERE action_name = 'mark_as_open'"));
        $activate = $this->createStub(\Shopware\Core\Framework\Plugin\Context\ActivateContext::class);
        $activate->method('getContext')->willReturn($context);
        $plugin->activate($activate);
        self::assertSame(1, (int) $db->fetchOne($query));
    }

}
