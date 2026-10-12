<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountProviderInterface;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountResolver;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountUnavailableException;

final class CardRefundedAmountResolverTest extends TestCase
{
    private const ORDER_ID = '0190c8e2a1b47c6f9d5e3a2b1c0d9e8f';

    public function testWithoutProvidersNothingIsReported(): void
    {
        $resolver = new CardRefundedAmountResolver([]);

        static::assertSame(0.0, $resolver->resolve(self::ORDER_ID));
        static::assertSame(0.0, $resolver->resolveReleased(self::ORDER_ID));
    }

    public function testRefundsAndReleasesOfAllProvidersAreSummed(): void
    {
        $resolver = new CardRefundedAmountResolver([
            $this->provider(['tx1' => 10.00, 'tx2' => 5.50], ['tx3' => 2.25]),
            $this->provider(['tx4' => 1.25], null),
        ]);

        static::assertSame(19.0, $resolver->resolve(self::ORDER_ID));
        static::assertSame(2.25, $resolver->resolveReleased(self::ORDER_ID));
    }

    public function testAProviderThatCannotAnswerContributesNothing(): void
    {
        $resolver = new CardRefundedAmountResolver([$this->provider(null, null)]);

        static::assertSame(0.0, $resolver->resolve(self::ORDER_ID));
    }

    public function testNegativeAmountsAreIgnored(): void
    {
        $resolver = new CardRefundedAmountResolver([$this->provider(['tx1' => -4.00, 'tx2' => 3.00], null)]);

        static::assertSame(3.0, $resolver->resolve(self::ORDER_ID));
    }

    public function testAFailingProviderStopsTheCapInsteadOfUnderCounting(): void
    {
        $failing = $this->createMock(CardRefundedAmountProviderInterface::class);
        $failing->method('getCardRefundedByTransaction')->willThrowException(new \RuntimeException('gateway down'));

        $this->expectException(CardRefundedAmountUnavailableException::class);
        $this->expectExceptionMessage('gateway down');

        (new CardRefundedAmountResolver([$failing]))->resolve(self::ORDER_ID);
    }

    /**
     * @param array<string, float>|null $refunded
     * @param array<string, float>|null $released
     */
    private function provider(?array $refunded, ?array $released): CardRefundedAmountProviderInterface
    {
        $provider = $this->createMock(CardRefundedAmountProviderInterface::class);
        $provider->method('getCardRefundedByTransaction')->willReturn($refunded);
        $provider->method('getCardReleasedByTransaction')->willReturn($released);

        return $provider;
    }
}
