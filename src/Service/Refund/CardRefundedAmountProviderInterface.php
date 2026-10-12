<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service\Refund;

interface CardRefundedAmountProviderInterface
{
    /**
     * @return array<string, float>|null
     */
    public function getCardRefundedByTransaction(string $orderId): ?array;

    /**
     * @return array<string, float>|null
     */
    public function getCardReleasedByTransaction(string $orderId): ?array;
}
