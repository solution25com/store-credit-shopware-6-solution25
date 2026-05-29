<?php

declare(strict_types=1);

namespace Solu1StoreCredit\EventSubscriber;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StoreCreditCheckoutSubscriber implements EventSubscriberInterface
{
    private StoreCreditManager $storeCreditManager;
    private CartService $cartService;

    public function __construct(
        StoreCreditManager $storeCreditManager,
        CartService $cartService
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->cartService = $cartService;
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

    private function assignStoreCreditData($page, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();

        if (!$customer) {
            return;
        }

        try {
            $creditBalance = $this->storeCreditManager->getCreditBalance($customer->getId(), $context->getContext());
            $cart = $this->cartService->getCart($context->getToken(), $context);
            $this->validateStoreCredits($cart, $creditBalance['balanceAmount'], $context);

            $page->assign([
                'storeCreditBalance' => $creditBalance['balanceAmount'],
                'storeCreditCurrencyId' => $creditBalance['balanceCurrencyId'],
                'storeCreditId' => StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID,
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

    private function validateStoreCredits($cart, float $creditBalance, SalesChannelContext $context): void
    {
        $storeCreditLineItem = $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);

        if (!$storeCreditLineItem) {
            return;
        }

        $totalAppliedCredit = abs($storeCreditLineItem->getPrice()->getTotalPrice());

        if ($totalAppliedCredit > $creditBalance) {
            if ($creditBalance > 0) {
                $storeCreditLineItem->setPriceDefinition(new AbsolutePriceDefinition(-$creditBalance));
                $this->cartService->recalculate($cart, $context);
            } else {
                $this->cartService->remove($cart, StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID, $context);
            }
        }
    }
}
