<?php

namespace StoreCredit\EventSubscriber;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use StoreCredit\Service\StoreCreditManager;

class StoreCreditCheckoutSubscriber implements EventSubscriberInterface
{
  private StoreCreditManager $storeCreditManager;
  private CartService $cartService;
  private SystemConfigService $systemConfigService;

  public function __construct(
    StoreCreditManager $storeCreditManager,
    CartService $cartService,
    SystemConfigService $systemConfigService
  ) {
    $this->storeCreditManager = $storeCreditManager;
    $this->cartService = $cartService;
    $this->systemConfigService = $systemConfigService;
  }

  public static function getSubscribedEvents(): array
  {
    return [
      CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmPageLoaded',
    ];
  }

  public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
  {
    $context = $event->getSalesChannelContext();
    $customer = $context->getCustomer();

    if (!$customer) {
      return;
    }

    try {
      $creditBalance = $this->storeCreditManager->getCreditBalance($customer->getId());
      $cart = $this->cartService->getCart($context->getToken(), $context);
      $this->validateStoreCredits($cart, $creditBalance['balanceAmount'], $context);

      $event->getPage()->assign([
        'storeCreditBalance' => $creditBalance['balanceAmount'],
        'storeCreditCurrencyId' => $creditBalance['balanceCurrencyId'],
        'storeCreditId' => 'store-credit-discount',
      ]);
    } catch (\Exception $e) {
    }
  }

  private function validateStoreCredits($cart, float $creditBalance, SalesChannelContext $context): void
  {
    $lineItems = $cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE);
    $totalAppliedCredit = 0;

    foreach ($lineItems as $lineItem) {
      $totalAppliedCredit += abs($lineItem->getPrice()->getTotalPrice());
    }

    // Calculate maximum allowed credit
    $maxAllowedCredit = $this->calculateMaxAllowedCredit($cart);
    $effectiveCreditLimit = min($creditBalance, $maxAllowedCredit);

    if ($totalAppliedCredit > $effectiveCreditLimit) {
      foreach ($lineItems as $lineItem) {
        $this->cartService->remove($cart, $lineItem->getId(), $context);
      }

      if ($effectiveCreditLimit > 0) {
        $discount = new LineItem('store-credit-discount', LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
        $discount->setLabel('Store credit discount');
        $discount->setRemovable(true);
        $discount->setPriceDefinition(new AbsolutePriceDefinition(-$effectiveCreditLimit));
        $discount->setGood(false);
        $this->cartService->add($cart, $discount, $context);
      }

      $this->cartService->recalculate($cart, $context);
    }
  }

  private function calculateMaxAllowedCredit($cart): float
  {
    // Get configuration values
    $maxCreditPerOrder = (float) $this->systemConfigService->get('StoreCredit.config.maxCreditPerOrder', 0);
    $maxCreditPercentage = (float) $this->systemConfigService->get('StoreCredit.config.maxCreditPercentage', 100);

    // Calculate subtotal (excluding store credits and protection fees)
    $subtotal = $this->calculateCartSubtotal($cart);

    // Calculate maximum credit based on percentage
    $maxCreditByPercentage = $subtotal * ($maxCreditPercentage / 100);

    // Return the most restrictive limit
    if ($maxCreditPerOrder > 0) {
      return min($maxCreditPerOrder, $maxCreditByPercentage);
    }

    return $maxCreditByPercentage;
  }

  private function calculateCartSubtotal($cart): float
  {
    $subtotal = 0.0;

    foreach ($cart->getLineItems() as $lineItem) {
      // Skip store credits, protection fees, and other non-product items
      if ($lineItem->getType() === LineItem::CREDIT_LINE_ITEM_TYPE ||
        $lineItem->getId() === 'premium-protection-fee' ||
        $lineItem->getId() === 'store-credit-discount') {
        continue;
      }

      $subtotal += $lineItem->getPrice()->getTotalPrice();
    }

    return $subtotal;
  }
}