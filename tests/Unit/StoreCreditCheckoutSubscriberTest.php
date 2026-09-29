<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Solu1StoreCredit\EventSubscriber\StoreCreditCheckoutSubscriber;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\Request;

final class StoreCreditCheckoutSubscriberTest extends TestCase
{
    #[DataProvider('currencyScenarios')]
    public function testCheckoutShowsConvertedCreditWithoutChangingWallet(
        float $balance,
        float $sourceFactor,
        float $targetFactor,
        float $expectedBalance,
        bool $cartPage,
    ): void {
        $walletCurrency = new CurrencyEntity();
        $walletCurrency->setId(Defaults::CURRENCY);
        $walletCurrency->setFactor($sourceFactor);
        $checkoutCurrency = new CurrencyEntity();
        $checkoutCurrency->setId(Uuid::randomHex());
        $checkoutCurrency->setFactor($targetFactor);
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getCurrency')->willReturn($checkoutCurrency);
        $context->method('getCurrencyId')->willReturn($checkoutCurrency->getId());
        $context->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $context->method('getItemRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(new EntitySearchResult('currency', 1, new CurrencyCollection([$walletCurrency]), null, new Criteria(), $context->getContext()));
        $converter = new StoreCreditCurrencyConverter($repository);

        $manager = $this->createMock(StoreCreditManager::class);
        $manager->expects(self::once())->method('getCreditBalance')->with($customer->getId(), $context->getContext())
            ->willReturn(['balanceAmount' => $balance, 'currencyId' => $walletCurrency->getId()]);
        $manager->expects(self::never())->method('addCredit');
        $manager->expects(self::never())->method('deductCredit');
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getFloat')->willReturn(25.0);
        $config->method('get')->willReturn(true);
        $cart = new Cart(Uuid::randomHex());
        $cart->setPrice(new CartPrice(100.0, 100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
        $page = $cartPage ? new CheckoutCartPage() : new CheckoutConfirmPage();
        $page->setCart($cart);
        $event = $page instanceof CheckoutCartPage
            ? new CheckoutCartPageLoadedEvent($page, $context, new Request())
            : new CheckoutConfirmPageLoadedEvent($page, $context, new Request());

        (new StoreCreditCheckoutSubscriber($manager, $config, $converter))->onPageLoaded($event);

        $credit = $page->getExtension('storeCredit');
        self::assertNotNull($credit);
        self::assertSame($expectedBalance, $credit->get('balance'));
        self::assertSame($expectedBalance, $credit->get('remaining'));
        self::assertSame(min(25.0, $expectedBalance), $credit->get('maximum'));
        self::assertSame(100.0, $cart->getPrice()->getTotalPrice());
        self::assertCount(0, $cart->getLineItems());
    }

    public static function currencyScenarios(): iterable
    {
        foreach ([false, true] as $cartPage) {
            $page = $cartPage ? 'cart' : 'confirm';
            yield "$page: EUR credit in USD checkout" => [10666.0, 1.0, 1.17085, 12488.28, $cartPage];
            yield "$page: non-system wallet currency" => [10.0, 1.25, 1.0, 8.0, $cartPage];
            yield "$page: no credit" => [0.0, 1.0, 1.25, 0.0, $cartPage];
            yield "$page: invalid rate" => [50.0, 0.0, 1.25, 0.0, $cartPage];
        }
    }
}
