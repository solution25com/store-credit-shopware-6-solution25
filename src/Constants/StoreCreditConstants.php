<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Constants;

/**
 * Constants for Store Credit plugin.
 */
class StoreCreditConstants
{
    /**
     * Label used for store credit discount line items.
     */
    public const STORE_CREDIT_DISCOUNT_LABEL = 'Store credit discount';

    /**
     * Line item ID used for store credit discount.
     */
    public const STORE_CREDIT_LINE_ITEM_ID = 'store-credit-discount';

    /**
     * Payload key used to identify store credit line items unambiguously.
     */
    public const PAYLOAD_KEY = 'isStoreCredit';
}

