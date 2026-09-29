<?php declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class StoreCreditCurrencyConverter
{
    public function __construct(private readonly EntityRepository $currencyRepository)
    {
    }

    /**
     * @param list<string> $currencyIds
     * @return array<string, float>
     */
    public function getRates(array $currencyIds, SalesChannelContext $context): array
    {
        $rates = [$context->getCurrencyId() => 1.0];
        $ids = array_values(array_filter(array_unique($currencyIds), static fn (string $id): bool => Uuid::isValid($id) && $id !== $context->getCurrencyId()));
        $targetFactor = $context->getCurrency()->getFactor();
        if ($ids === [] || !is_finite($targetFactor) || $targetFactor <= 0) {
            return $rates;
        }
        foreach ($this->currencyRepository->search(new Criteria($ids), $context->getContext())->getEntities() as $currency) {
            $factor = $currency->getFactor();
            if (!is_finite($factor) || $factor <= 0) {
                continue;
            }
            $rate = $targetFactor / $factor;
            if (is_finite($rate) && $rate > 0) {
                $rates[$currency->getId()] = $rate;
            }
        }

        return $rates;
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

    public function walletDebit(float $checkoutAmount, float $rate): float
    {
        if (!is_finite($checkoutAmount) || $checkoutAmount <= 0 || !is_finite($rate) || $rate <= 0) {
            throw new \InvalidArgumentException('Invalid store credit conversion.');
        }
        return max(0.01, ceil($checkoutAmount / $rate * 100 - 0.0000001) / 100);
    }
}
