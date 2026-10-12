<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart;

use Symfony\Contracts\Service\ResetInterface;

class PlacedOrderCartRegistry implements ResetInterface
{
    /** @var array<string, true> */
    private array $tokens = [];

    public function markPlaced(string $cartToken): void
    {
        $this->tokens[$cartToken] = true;
    }

    public function isPlaced(string $cartToken): bool
    {
        return isset($this->tokens[$cartToken]);
    }

    public function reset(): void
    {
        $this->tokens = [];
    }
}
