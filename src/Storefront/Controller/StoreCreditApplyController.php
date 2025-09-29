<?php

namespace StoreCredit\Storefront\Controller;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Package('storefront')]
#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditApplyController extends StorefrontController
{
  private CartService $cartService;
  private EntityRepository $storeCreditRepository;
  private SystemConfigService $systemConfigurationService;
  private string $storeCreditLineItemId = 'store-credit-discount';

  public function __construct(
    CartService $cartService,
    EntityRepository $storeCreditRepository,
    SystemConfigService $systemConfigurationService
  ) {
    $this->cartService = $cartService;
    $this->storeCreditRepository = $storeCreditRepository;
    $this->systemConfigurationService = $systemConfigurationService;
  }

  #[Route(path: '/store-credit-apply', name: 'frontend.store.credit.apply', defaults: ['_routeScope' => ['storefront']], methods: ['POST'])]
  public function applyStoreCredit(Request $request, SalesChannelContext $context): Response
  {
    $amount = (float)$request->get('amount');
    $customer = $context->getCustomer();

    if (!$customer || $amount <= 0) {
      $this->addFlash('danger', 'Invalid amount or customer not logged in.');
      return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
    }

    if ($restrictedProducts = $this->hasRestrictedProductsInCart($context)) {
      $productNames = array_map(
        static fn ($product) => '<strong>' . htmlspecialchars($product['name'], ENT_QUOTES) . '</strong>',
        $restrictedProducts
      );
      $this->addFlash(
        'warning',
        'Store credit cannot be applied due to restricted products in the cart: ' . implode(', ', $productNames)
      );
      return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
    }

    $creditBalance = $this->getCreditBalance($customer->getId());
    $amountToApply = min($creditBalance, $amount);

    $cart = $this->cartService->getCart($context->getToken(), $context);
    $lineItems = $cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE);
    $storeCreditDiscount = $lineItems->get($this->storeCreditLineItemId);
    $totalAppliedCredit = 0;

    foreach ($lineItems as $lineItem) {
      $totalAppliedCredit += abs($lineItem->getPrice()->getTotalPrice());
    }

    $maxCreditPerOrder = $this->systemConfigurationService->get('StoreCredit.config.maxCreditPerOrder');
    if ($maxCreditPerOrder > 0) {
      $totalAfterApply = $totalAppliedCredit + $amountToApply;
      if ($totalAfterApply > $maxCreditPerOrder) {
        $this->addFlash('warning', "Maximum store credit per order is $" . number_format($maxCreditPerOrder, 2) . ". You can only apply $" . number_format($maxCreditPerOrder - $totalAppliedCredit, 2) . " more.");
        return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
      }
    }

    if ($totalAppliedCredit + $amountToApply > $creditBalance) {
      $this->addFlash('warning', 'Requested amount exceeds available store credit.');
      return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
    }

    if ($storeCreditDiscount) {
      $currentPrice = $storeCreditDiscount->getPrice()->getTotalPrice();
      $newPrice = max(-$creditBalance, $currentPrice - $amountToApply);
      $storeCreditDiscount->setPriceDefinition(new AbsolutePriceDefinition($newPrice));
    } else {
      $discount = new LineItem($this->storeCreditLineItemId, LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
      $discount->setLabel('Store credit discount');
      $discount->setRemovable(true);
      $discount->setStackable(true);
      $discount->setPriceDefinition(new AbsolutePriceDefinition(-$amountToApply));
      $discount->setGood(false);
      $this->cartService->add($cart, $discount, $context);
    }

    $this->cartService->recalculate($cart, $context);
    $this->addFlash('success', 'Store credit applied successfully.');
    return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
  }

  private function getCreditBalance(?string $customerId): float
  {
    if (!$customerId) {
      return 0.0;
    }

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('customerId', $customerId));
    $result = $this->storeCreditRepository->search($criteria, Context::createDefaultContext());
    $storeCreditEntity = $result->first();

    return $storeCreditEntity ? (float)$storeCreditEntity->get('balance') : 0.0;
  }

  private function hasRestrictedProductsInCart(SalesChannelContext $context): array|bool
  {
    $restrictedProductIds = $this->systemConfigurationService->get('StoreCredit.config.restrictedProducts');
    if (empty($restrictedProductIds)) {
      return false;
    }

    $cart = $this->cartService->getCart($context->getToken(), $context);
    $restrictedProducts = [];

    foreach ($cart->getLineItems() as $lineItem) {
      if (in_array($lineItem->getReferencedId(), $restrictedProductIds)) {
        $restrictedProducts[] = [
          'id' => $lineItem->getReferencedId(),
          'name' => $lineItem->getLabel(),
        ];
      }
    }
    return !empty($restrictedProducts) ? $restrictedProducts : false;
  }

}