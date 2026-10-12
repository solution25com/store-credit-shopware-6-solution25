<?php

declare(strict_types=1);

namespace Solu1StoreCredit\EventSubscriber;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StoreCreditCheckoutSubscriber implements EventSubscriberInterface
{
    private const EPSILON = 0.001;

    private StoreCreditManager $storeCreditManager;
    private CartService $cartService;
    private SystemConfigService $systemConfigService;
    private ?StoreCreditCurrencyConverter $currencyConverter;

    public function __construct(
        StoreCreditManager $storeCreditManager,
        CartService $cartService,
        SystemConfigService $systemConfigService,
        ?StoreCreditCurrencyConverter $currencyConverter = null
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->cartService = $cartService;
        $this->systemConfigService = $systemConfigService;
        $this->currencyConverter = $currencyConverter;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmPageLoaded',
            CheckoutCartPageLoadedEvent::class => 'onCheckoutCartPageLoaded',
        ];
    }

    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->assignStoreCreditData($event->getPage(), $event->getSalesChannelContext());
    }

    public function onCheckoutCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $this->assignStoreCreditData($event->getPage(), $event->getSalesChannelContext());
    }

    /**
     * @param CheckoutConfirmPage|CheckoutCartPage $page
     */
    private function assignStoreCreditData(CheckoutConfirmPage|CheckoutCartPage $page, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();

        if (!$customer) {
            return;
        }

        try {
            $creditBalance = $this->storeCreditManager->getAvailableCreditBalance($customer->getId(), $context->getContext());
            $checkoutBalance = $this->toCheckoutCurrency($creditBalance, $context);
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $this->validateStoreCredits($cart, $checkoutBalance, $context);

            $maxCreditPerOrder = $this->systemConfigService->get('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId());

            $page->assign([
                'storeCreditBalance' => $checkoutBalance,
                'storeCreditCurrencyId' => $creditBalance['balanceCurrencyId'],
                'storeCreditWalletCurrencyIsoCode' => $this->isoCode($creditBalance['balanceCurrencyId'], $context),
                'storeCreditId' => StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID,
                'maxCreditPerOrder' => is_numeric($maxCreditPerOrder) ? (float) $maxCreditPerOrder : 0.0,
                'storeCreditExpandedByDefault' => (bool) $this->systemConfigService->get('StoreCredit.config.expandStoreCreditByDefault', $context->getSalesChannelId()),
            ]);
        } catch (\Exception $e) {
            $page->assign([
                'storeCreditBalance' => 0.0,
                'storeCreditCurrencyId' => null,
                'storeCreditId' => StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID,
                'maxCreditPerOrder' => 0.0,
            ]);
        }
    }

    /**
     * @param array{balanceAmount: float, balanceCurrencyId: ?string} $creditBalance
     */
    private function toCheckoutCurrency(array $creditBalance, SalesChannelContext $context): float
    {
        if ($this->currencyConverter === null || $creditBalance['balanceCurrencyId'] === null) {
            return (float) $creditBalance['balanceAmount'];
        }

        return $this->currencyConverter->toCheckout((float) $creditBalance['balanceAmount'], $creditBalance['balanceCurrencyId'], $context);
    }

    private function isoCode(?string $walletCurrencyId, SalesChannelContext $context): ?string
    {
        if ($walletCurrencyId === null || $walletCurrencyId === strtolower($context->getCurrencyId())) {
            return null;
        }

        return $this->currencyConverter?->getIsoCode($walletCurrencyId);
    }

    private function validateStoreCredits(Cart $cart, float $creditBalance, SalesChannelContext $context): void
    {
        $storeCreditLineItem = $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);

        if (!$storeCreditLineItem) {
            return;
        }

        $price = $storeCreditLineItem->getPrice();
        if ($price === null) {
            return;
        }

        $totalAppliedCredit = abs($price->getTotalPrice());

        if ($totalAppliedCredit > $creditBalance + self::EPSILON) {
            if ($creditBalance > 0) {
                $storeCreditLineItem->setPriceDefinition(new AbsolutePriceDefinition(-$creditBalance));
                $this->cartService->recalculate($cart, $context);
            } else {
                $this->cartService->remove($cart, StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID, $context);
            }
        }
    }
}
