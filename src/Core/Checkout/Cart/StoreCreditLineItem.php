<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Solu1StoreCredit\Constants\StoreCreditConstants;

final class StoreCreditLineItem
{
    public static function matches(LineItem $item): bool
    {
        return $item->getId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
            || $item->getReferencedId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
            || ($item->getType() === LineItem::CREDIT_LINE_ITEM_TYPE
                && ($item->getPayloadValue('isStoreCredit') === true || $item->getLabel() === StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL));
    }
}
