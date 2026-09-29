<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Solu1StoreCredit\Constants\StoreCreditConstants;

final class StoreCreditLineItem
{
    /** @return array{amount: float, currencyId: string} */
    public static function getRequest(LineItem $item, string $currentCurrencyId): array
    {
        $definition = $item->getPriceDefinition();
        $price = $definition instanceof AbsolutePriceDefinition ? $definition->getPrice() : 0.0;
        $amount = $item->getPayloadValue('storeCreditAmount');
        $currencyId = $item->getPayloadValue('storeCreditCurrencyId');
        $calculated = $item->getPayloadValue('storeCreditCalculatedAmount');
        if (is_numeric($amount) && is_finite((float) $amount) && (float) $amount > 0
            && is_string($currencyId) && Uuid::isValid($currencyId)
            && is_numeric($calculated) && is_finite((float) $calculated)
            && abs($price + (float) $calculated) < 0.0000001) {
            return ['amount' => (float) $amount, 'currencyId' => $currencyId];
        }

        return ['amount' => abs($price), 'currencyId' => $currentCurrencyId];
    }

    public static function matches(LineItem $item): bool
    {
        return $item->getId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
            || $item->getReferencedId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
            || ($item->getType() === LineItem::CREDIT_LINE_ITEM_TYPE
                && ($item->getPayloadValue('isStoreCredit') === true || $item->getLabel() === StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL));
    }
}
