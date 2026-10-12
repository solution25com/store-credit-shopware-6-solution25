<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service\Refund;

use Solu1StoreCredit\Constants\StoreCreditConstants;

class RefundCapCalculator
{
    public const REFUND_ACTION_TYPE = 'add';

    public function remainder(
        float $amountTotal,
        float $storeCreditTendered,
        float $alreadyCredited,
        float $cardRefunded
    ): float {
        $refundable = round($amountTotal + abs($storeCreditTendered), 2);

        return round(max(0.0, $refundable - $alreadyCredited - $cardRefunded), 2);
    }

    public function countsAsIssuedRefund(string $actionType, ?string $idempotencyKey, ?string $reason): bool
    {
        if ($actionType !== self::REFUND_ACTION_TYPE) {
            return false;
        }

        if ($idempotencyKey !== null && str_starts_with($idempotencyKey, StoreCreditConstants::REFUND_IDEMPOTENCY_KEY_PREFIX)) {
            return true;
        }

        return $reason !== null && str_starts_with($reason, StoreCreditConstants::LEGACY_REFUND_REASON_PREFIX);
    }
}
