<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Solu1StoreCredit\Core\Checkout\Cart\PlacedOrderCartRegistry;

class PlacedOrderCartRegistryTest extends TestCase
{
    public function testRemembersPlacedCartsUntilReset(): void
    {
        $registry = new PlacedOrderCartRegistry();
        static::assertFalse($registry->isPlaced('token-a'));

        $registry->markPlaced('token-a');
        static::assertTrue($registry->isPlaced('token-a'));
        static::assertFalse($registry->isPlaced('token-b'));

        $registry->reset();
        static::assertFalse($registry->isPlaced('token-a'));
    }
}
