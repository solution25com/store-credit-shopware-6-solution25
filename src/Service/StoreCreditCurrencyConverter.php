<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class StoreCreditCurrencyConverter
{
    private Connection $connection;

    /** @var array<string, float|null> */
    private array $factors = [];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function walletCurrency(?string $currencyId): string
    {
        $normalized = self::normalize($currencyId);

        return $normalized ?? Defaults::CURRENCY;
    }

    public static function normalize(?string $currencyId): ?string
    {
        if ($currencyId === null || trim($currencyId) === '') {
            return null;
        }

        $hex = strtolower(str_replace('-', '', $currencyId));

        return Uuid::isValid($hex) ? $hex : null;
    }

    public function rateToCheckout(string $fromCurrencyId, SalesChannelContext $context): ?float
    {
        $from = self::walletCurrency($fromCurrencyId);
        if ($from === strtolower($context->getCurrencyId())) {
            return 1.0;
        }

        $targetFactor = $context->getCurrency()->getFactor();
        $sourceFactor = $this->getFactor($from);
        if ($sourceFactor === null || !is_finite($targetFactor) || $targetFactor <= 0) {
            return null;
        }

        $rate = $targetFactor / $sourceFactor;

        return is_finite($rate) && $rate > 0 ? $rate : null;
    }

    public function toCheckout(float $amount, string $fromCurrencyId, SalesChannelContext $context): float
    {
        $rate = $this->rateToCheckout($fromCurrencyId, $context);
        if ($rate === null) {
            return 0.0;
        }

        return $this->roundCheckoutAmount($amount * $rate, $context);
    }

    public function roundCheckoutAmount(float $amount, SalesChannelContext $context): float
    {
        if (!is_finite($amount) || $amount <= 0) {
            return 0.0;
        }

        $rounding = $context->getItemRounding();
        $step = max(0.01, 10 ** -$rounding->getDecimals(), $rounding->getInterval());

        return round(floor($amount / $step + 0.0000001) * $step, 2);
    }

    public function convertRoundingUp(float $amount, string $fromCurrencyId, string $toCurrencyId): ?float
    {
        $from = self::walletCurrency($fromCurrencyId);
        $to   = self::walletCurrency($toCurrencyId);
        if (!is_finite($amount) || $amount <= 0) {
            return 0.0;
        }
        if ($from === $to) {
            return round($amount, 2);
        }

        $fromFactor = $this->getFactor($from);
        $toFactor   = $this->getFactor($to);
        if ($fromFactor === null || $toFactor === null) {
            return null;
        }

        $converted = $amount / $fromFactor * $toFactor;
        if (!is_finite($converted) || $converted <= 0) {
            return null;
        }

        return max(0.01, ceil($converted * 100 - 0.0000001) / 100);
    }

    public function getIsoCode(string $currencyId): ?string
    {
        $isoCode = $this->connection->fetchOne(
            'SELECT `iso_code` FROM `currency` WHERE `id` = :id',
            ['id' => Uuid::fromHexToBytes(self::walletCurrency($currencyId))]
        );

        return \is_string($isoCode) && $isoCode !== '' ? $isoCode : null;
    }

    private function getFactor(string $currencyId): ?float
    {
        if (!\array_key_exists($currencyId, $this->factors)) {
            $factor = $this->connection->fetchOne(
                'SELECT `factor` FROM `currency` WHERE `id` = :id',
                ['id' => Uuid::fromHexToBytes($currencyId)]
            );
            $factor = is_numeric($factor) ? (float) $factor : null;
            $this->factors[$currencyId] = $factor !== null && is_finite($factor) && $factor > 0 ? $factor : null;
        }

        return $this->factors[$currencyId];
    }
}
