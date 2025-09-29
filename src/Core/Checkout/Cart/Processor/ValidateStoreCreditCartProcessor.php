<?php

namespace StoreCredit\Core\Checkout\Cart\Processor;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ValidateStoreCreditCartProcessor implements CartProcessorInterface
{
  private const FLAG = 'store-credit-validation.already-processed';

  public function process(
    CartDataCollection  $data,
    Cart                $original,
    Cart                $toCalculate,
    SalesChannelContext $context,
    CartBehavior        $behavior): void
  {
    if ($data->has(self::FLAG)) {
      return;
    }

    if (!$this->shouldProcessCart()) {
      return;
    }

    if ($context->getCustomer() && $context->getCustomer()->getAccountType() === 'private') {
      $shippingRate = $toCalculate->getDeliveries()->getShippingCosts()->getCalculatedTaxes()->first();
      $shippingTax = $toCalculate->getDeliveries()->getShippingCosts()->getCalculatedTaxes()->first();
      $unitPrice = $toCalculate->getDeliveries()->getShippingCosts()->first();

      if($shippingRate){
        $shippingRate = $shippingRate->getTaxRate();
      }
      if($shippingTax){
        $shippingTax = $shippingTax->getTax();
      }
      if($unitPrice){
        $unitPrice = $unitPrice->getUnitPrice();
      }

      if ($shippingRate > 0 && $unitPrice > 0 && $shippingTax == 0) {
        return;
      }
    }

    $rawTotal = $toCalculate->getPrice()->getRawTotal();
    $total = $toCalculate->getPrice()->getTotalPrice();
    $rawOriginalTotal = $original->getPrice()->getRawTotal();
    $originalTotal = $original->getPrice()->getTotalPrice();

    $highestCartTotal = max($rawTotal, $total, $rawOriginalTotal, $originalTotal);

    if ($highestCartTotal < 0) {
      foreach ($toCalculate->getLineItems() as $lineItem) {
        if ($lineItem->getType() !== 'credit') {
          continue;
        }
        $toCalculate->getLineItems()->removeElement($lineItem);
      }
    }

    $data->set(self::FLAG, true);
  }

  private function shouldProcessCart(): bool
  {
    $currentPage = $_SERVER['REQUEST_URI'] ?? '';
    $allowedPages = ['checkout/cart', 'checkout/confirm', 'checkout/order'];

    $containsAllowedPage = array_filter($allowedPages, fn($page) => str_contains($currentPage, $page));

    return preg_match('#^/api/_action/order/#', $currentPage) || !empty($containsAllowedPage);
  }
}
