<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Exception;

/**
 * Exception thrown when no store credit record exists for a customer.
 */
class StoreCreditNotFoundException extends \RuntimeException
{
    public function __construct(
        string $message = 'No store credit found for this customer.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

