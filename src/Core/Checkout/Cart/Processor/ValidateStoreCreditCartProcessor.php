<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart\Processor;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\AbsolutePriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Core\Checkout\Cart\StoreCreditLineItem;
use Solu1StoreCredit\Service\StoreCreditManager;

class ValidateStoreCreditCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private const DATA_KEY = 'solu1-store-credit';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly StoreCreditManager $manager,
        private readonly AbsolutePriceCalculator $calculator,
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $customer = $context->getCustomer();
        $balance = $customer ? $this->manager->getCreditBalance($customer->getId(), $context->getContext()) : null;
        $data->set(self::DATA_KEY, [
            'balance' => $balance && $balance['currencyId'] === $context->getCurrencyId() ? $balance['balanceAmount'] : 0.0,
            'maximum' => (float) $this->config->get('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId()),
            'restricted' => (array) $this->config->get('StoreCredit.config.restrictedProducts', $context->getSalesChannelId()),
        ]);
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $settings = $data->get(self::DATA_KEY) ?? ['balance' => 0.0, 'maximum' => 0.0, 'restricted' => []];
        $credits = $original->getLineItems()->filter(StoreCreditLineItem::matches(...));
        foreach ($toCalculate->getLineItems()->filter(StoreCreditLineItem::matches(...)) as $credit) {
            $toCalculate->getLineItems()->remove($credit->getId());
        }
        if ($settings['balance'] <= 0 || !$context->getCustomer()) {
            return;
        }
        foreach ($toCalculate->getLineItems()->getFlat() as $item) {
            if ($item->getReferencedId() === 'premium-protection-fee' || in_array($item->getReferencedId(), $settings['restricted'], true)
                || in_array($item->getPayloadValue('parentId'), $settings['restricted'], true)) {
                return;
            }
        }
        $prices = $toCalculate->getLineItems()->getPrices()->merge($toCalculate->getDeliveries()->getShippingCosts());
        $available = min($settings['balance'], max(0.0, $prices->getTotalPriceAmount()));
        if ($settings['maximum'] > 0) {
            $available = min($available, $settings['maximum']);
        }
        foreach ($credits as $credit) {
            $definition = $credit->getPriceDefinition();
            if (!$definition instanceof AbsolutePriceDefinition || !is_finite($definition->getPrice()) || $definition->getPrice() >= 0) {
                continue;
            }
            $rounding = $context->getItemRounding();
            $step = max(0.01, 10 ** -$rounding->getDecimals(), $rounding->getInterval());
            $amount = round(floor(min(abs($definition->getPrice()), $available) / $step + 0.0000001) * $step, 2);
            if ($amount < 0.01) {
                continue;
            }
            $credit->setType(LineItem::CREDIT_LINE_ITEM_TYPE);
            $credit->setStackable(true)->setQuantity(1)->setStackable(false);
            $credit->setGood(false)->setRemovable(true)->setShippingCostAware(false);
            $credit->setPayloadValue('isStoreCredit', true);
            $credit->setPriceDefinition(new AbsolutePriceDefinition(-$amount));
            $credit->setPrice($this->calculator->calculate(-$amount, $prices, $context));
            $toCalculate->add($credit);
            $available -= abs($credit->getPrice()->getTotalPrice());
        }
    }
}
