<?php

namespace Solu1StoreCredit\Core\Checkout\Cart\Processor;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Constants\StoreCreditConstants;

class ValidateStoreCreditCartProcessor implements CartProcessorInterface
{
    private const FLAG = 'store-credit-validation.already-processed';
    private SystemConfigService $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        if ($data->has(self::FLAG)) {
            return;
        }

        $storeCreditLineItem = $toCalculate->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);

        if (!$storeCreditLineItem) {
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->hasPremiumProtectionFee($toCalculate)) {
            $toCalculate->getLineItems()->remove(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->hasRestrictedProducts($toCalculate, $context)) {
            $toCalculate->getLineItems()->remove(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
            $data->set(self::FLAG, true);
            return;
        }

        $storeCreditPrice = $storeCreditLineItem->getPrice();
        if ($storeCreditPrice) {
            $appliedCreditAmount = abs($storeCreditPrice->getTotalPrice());
            $maxCreditPerOrder = $this->systemConfigService->get('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId());
            
            if ($maxCreditPerOrder > 0 && $appliedCreditAmount > $maxCreditPerOrder) {
                $toCalculate->getLineItems()->remove(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
                $data->set(self::FLAG, true);
                return;
            }
        }

        $cartTotalWithoutCredit = $this->getCartTotalWithoutStoreCredit($toCalculate);

        if ($cartTotalWithoutCredit <= 0) {
            $toCalculate->getLineItems()->remove(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
        }

        $data->set(self::FLAG, true);
    }

    private function hasPremiumProtectionFee(Cart $cart): bool
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getReferencedId() === 'premium-protection-fee') {
                return true;
            }
        }
        return false;
    }

    private function hasRestrictedProducts(Cart $cart, SalesChannelContext $context): bool
    {
        $restrictedProductIds = $this->systemConfigService->get('StoreCredit.config.restrictedProducts', $context->getSalesChannelId());
        if (empty($restrictedProductIds)) {
            return false;
        }

        foreach ($cart->getLineItems() as $lineItem) {
            if (in_array($lineItem->getReferencedId(), $restrictedProductIds)) {
                return true;
            }
        }

        return false;
    }

    private function getCartTotalWithoutStoreCredit(Cart $cart): float
    {
        $total = 0.0;

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID) {
                continue;
            }
            $price = $lineItem->getPrice();
            if ($price) {
                $total += $price->getTotalPrice();
            }
        }

        return $total;
    }
}
