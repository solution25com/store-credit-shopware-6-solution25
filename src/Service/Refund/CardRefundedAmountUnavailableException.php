<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service\Refund;

class CardRefundedAmountUnavailableException extends \RuntimeException
{
    public function __construct(private readonly string $providerName, \Throwable $previous)
    {
        parent::__construct(
            sprintf('Card refund totals are unavailable (%s): %s', $providerName, $previous->getMessage()),
            0,
            $previous
        );
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }
}
