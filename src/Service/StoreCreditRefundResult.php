<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service;

final class StoreCreditRefundResult
{
    public function __construct(
        private readonly ?string $historyId,
        private readonly float $creditedAmount,
        private readonly float $requestedAmount,
        private readonly bool $alreadyProcessed = false,
        private readonly bool $wasCapped = false,
    ) {
    }

    public function getHistoryId(): ?string
    {
        return $this->historyId;
    }

    public function getCreditedAmount(): float
    {
        return $this->creditedAmount;
    }

    public function getRequestedAmount(): float
    {
        return $this->requestedAmount;
    }

    public function isAlreadyProcessed(): bool
    {
        return $this->alreadyProcessed;
    }

    public function wasCapped(): bool
    {
        return $this->wasCapped;
    }
}
