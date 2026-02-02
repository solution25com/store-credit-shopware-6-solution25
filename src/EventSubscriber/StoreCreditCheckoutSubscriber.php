<?php

declare(strict_types=1);

namespace StoreCredit\EventSubscriber;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use StoreCredit\Service\StoreCreditManager;
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
      $creditBalance = $this->storeCreditManager->getCreditBalance($customer->getId());
      $cart = $this->cartService->getCart($context->getToken(), $context);
      $this->validateStoreCredits($cart, $creditBalance['balanceAmount'], $context);

      $page->assign([
        'storeCreditBalance' => $creditBalance['balanceAmount'],
        'storeCreditCurrencyId' => $creditBalance['balanceCurrencyId'],
        'storeCreditId' => 'store-credit-discount',
      ]);
    } catch (\Exception $e) {
      $page->assign([
        'storeCreditBalance' => 0.0,
        'storeCreditCurrencyId' => null,
        'storeCreditId' => 'store-credit-discount',
        'maxCreditPerOrder' => 0.0,
      ]);
    }
  }

  private function validateStoreCredits($cart, float $creditBalance, SalesChannelContext $context): void
  {
    $lineItems = $cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE);
    $totalAppliedCredit = 0;

    foreach ($lineItems as $lineItem) {
      $totalAppliedCredit += abs($lineItem->getPrice()->getTotalPrice());
    }

    if ($totalAppliedCredit > $creditBalance) {
      foreach ($lineItems as $lineItem) {
        $this->cartService->remove($cart, $lineItem->getId(), $context);
      }

      if ($creditBalance > 0) {
        $discount = new LineItem('store-credit-discount', LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
        $discount->setLabel('Store credit discount');
        $discount->setRemovable(true);
        $discount->setPriceDefinition(new AbsolutePriceDefinition(-$creditBalance));
        $discount->setGood(false);
        $this->cartService->add($cart, $discount, $context);
      }

      $this->cartService->recalculate($cart, $context);
    }
  }

}
