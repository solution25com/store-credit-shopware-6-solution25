<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Unit\Service\Refund;

use PHPUnit\Framework\TestCase;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\Refund\RefundCapCalculator;

final class RefundCapCalculatorTest extends TestCase
{
    private RefundCapCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RefundCapCalculator();
    }

    public function testAnOrderPaidEntirelyByCardIsRefundableInFull(): void
    {
        static::assertSame(28.97, $this->calculator->remainder(28.97, 0.0, 0.0, 0.0));
    }

    public function testStoreCreditSpentAtPurchaseIsTenderAndStaysRefundable(): void
    {
        static::assertSame(28.97, $this->calculator->remainder(27.97, 1.00, 0.0, 0.0));
    }

    public function testStoreCreditOnlyOrderIsFullyRefundable(): void
    {
        static::assertSame(42.16, $this->calculator->remainder(0.0, 42.16, 0.0, 0.0));
    }

    public function testNegativeTenderLineItemsAreReadAsMagnitudes(): void
    {
        static::assertSame(111.33, $this->calculator->remainder(11.33, -100.00, 0.0, 0.0));
    }

    public function testStoreCreditAlreadyRefundedIsSubtracted(): void
    {
        static::assertSame(8.97, $this->calculator->remainder(28.97, 0.0, 20.00, 0.0));
    }

    public function testCardRefundsAreSubtracted(): void
    {
        static::assertSame(18.97, $this->calculator->remainder(28.97, 0.0, 0.0, 10.00));
    }

    public function testTheRemainderNeverGoesNegative(): void
    {
        static::assertSame(0.0, $this->calculator->remainder(28.97, 0.0, 20.00, 20.00));
    }

    public function testTheRemainderIsRoundedToCents(): void
    {
        static::assertSame(0.1, $this->calculator->remainder(0.3, 0.0, 0.2, 0.0));
    }

    public function testARefundWrittenWithTheOperationKeyCounts(): void
    {
        static::assertTrue($this->calculator->countsAsIssuedRefund(
            'add',
            StoreCreditConstants::REFUND_OPERATION_KEY_PREFIX . '0190c8e2a1b47c6f9d5e3a2b1c0d9e8f',
            'Store credit refund for order 10001'
        ));
    }

    public function testLegacyRefundsAreRecognisedByTheirExactReasons(): void
    {
        foreach (StoreCreditConstants::LEGACY_REFUND_REASON_PREFIXES as $prefix) {
            static::assertTrue($this->calculator->countsAsIssuedRefund('add', null, $prefix . '10001'));
        }
    }

    public function testAManualGrantMentioningAReturnDoesNotCount(): void
    {
        static::assertFalse($this->calculator->countsAsIssuedRefund('add', null, 'Goodwill for return 10001'));
    }

    public function testADeductionNeverCountsAsARefund(): void
    {
        static::assertFalse($this->calculator->countsAsIssuedRefund(
            'deduct',
            StoreCreditConstants::REFUND_OPERATION_KEY_PREFIX . '0190c8e2a1b47c6f9d5e3a2b1c0d9e8f',
            null
        ));
    }
}
