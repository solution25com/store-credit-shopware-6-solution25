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

    public const REFUND_OPERATION_KEY_PREFIX = 'return:';

    public const ORDER_DEBIT_OPERATION_KEY_PREFIX = 'order:';

    public const LEGACY_REFUND_REASON_PREFIXES = [
        'Refunded from order with Order Number :',
        'Store credit refund for order ',
    ];

    public const ORDER_CUSTOM_FIELD_PENDING_AMOUNT = 'store_credit_pending_amount';

    public const ORDER_CUSTOM_FIELD_PENDING_CURRENCY = 'store_credit_pending_currency_id';

    public const ORDER_CUSTOM_FIELD_DEDUCTED = 'store_credit_deducted';

    public const ORDER_CUSTOM_FIELD_DEDUCT_FAILED = 'store_credit_deduct_failed';

    public const CONFIG_PENDING_RESERVATION_MAX_AGE_DAYS = 'StoreCredit.config.pendingReservationMaxAgeDays';

    public const PENDING_RESERVATION_MAX_AGE_DAYS_DEFAULT = 14;

    public const DEDUCT_FAILED_ORDER_TAG = 'store-credit-issue';
}
