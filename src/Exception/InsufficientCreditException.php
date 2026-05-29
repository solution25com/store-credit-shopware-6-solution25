<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Exception;

/**
 * Exception thrown when attempting to deduct more store credit than available.
 */
class InsufficientCreditException extends \RuntimeException
{
    public function __construct(
        string $message = 'Insufficient store credit balance.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

