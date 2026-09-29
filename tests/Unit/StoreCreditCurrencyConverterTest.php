<?php declare(strict_types=1);

namespace Solu1StoreCredit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;

final class StoreCreditCurrencyConverterTest extends TestCase
{
    #[DataProvider('debits')]
    public function testDebitsCoverTheActualDiscount(float $amount, float $rate, float $expected): void
    {
        $converter = new StoreCreditCurrencyConverter($this->createMock(EntityRepository::class));
        self::assertSame($expected, $converter->walletDebit($amount, $rate));
    }

    public static function debits(): iterable
    {
        yield 'same currency' => [23.0, 1.0, 23.0];
        yield 'USD to EUR' => [12.5, 1.25, 10.0];
        yield 'partial cent is charged' => [10.0, 1.17085, 8.55];
        yield 'full wallet with discarded checkout fraction' => [12488.28, 1.17085, 10666.0];
        yield 'tiny amount still debits a cent' => [0.01, 100000000.0, 0.01];
    }

    #[DataProvider('rounding')]
    public function testDiscountNeverExceedsConvertedFunds(float $amount, int $decimals, float $interval, float $expected): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getItemRounding')->willReturn(new CashRoundingConfig($decimals, $interval, true));
        $converter = new StoreCreditCurrencyConverter($this->createMock(EntityRepository::class));
        self::assertSame($expected, $converter->roundCheckoutAmount($amount, $context));
    }

    public static function rounding(): iterable
    {
        yield 'cents' => [12.345, 2, 0.01, 12.34];
        yield 'cash' => [12.345, 2, 0.05, 12.3];
        yield 'zero decimals' => [12.345, 0, 1.0, 12.0];
        yield 'sub-cent balance' => [0.009, 2, 0.01, 0.0];
        yield 'invalid number' => [INF, 2, 0.01, 0.0];
    }
}
