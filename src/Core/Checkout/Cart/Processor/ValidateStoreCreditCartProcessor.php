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
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Core\Checkout\Cart\PlacedOrderCartRegistry;
use Solu1StoreCredit\Core\Checkout\Cart\StoreCreditLineItem;
use Solu1StoreCredit\Service\StoreCreditManager;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ValidateStoreCreditCartProcessor implements CartDataCollectorInterface, CartProcessorInterface
{
    private const DATA_KEY = 'solu1-store-credit';
    private const CALCULATED_CURRENCY_KEY = 'storeCreditCalculatedCurrencyId';
    private const EPSILON = 0.001;
    private const SNIPPET_REDUCED = 'store-credit.placement.clampedTo';
    private const SNIPPET_REMOVED = 'store-credit.placement.removed';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly StoreCreditManager $manager,
        private readonly AbsolutePriceCalculator $calculator,
        private readonly StoreCreditCurrencyConverter $converter,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?CurrencyFormatter $currencyFormatter = null,
        private readonly ?PlacedOrderCartRegistry $placedCarts = null,
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $customer = $context->getCustomer();
        $balance = $customer ? $this->manager->getAvailableCreditBalance($customer->getId(), $context->getContext()) : null;
        $currencyIds = $balance ? [$balance['currencyId']] : [];
        foreach ($original->getLineItems()->filter(StoreCreditLineItem::matches(...)) as $credit) {
            $currencyIds[] = StoreCreditLineItem::getRequest($credit, $context->getCurrencyId())['currencyId'];
        }
        $rates = $this->converter->getRates($currencyIds, $context);
        $data->set(self::DATA_KEY, [
            'balance' => $balance ? $this->converter->roundCheckoutAmount($balance['balanceAmount'] * ($rates[$balance['currencyId']] ?? 0.0), $context) : 0.0,
            'rates' => $rates,
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
        if (!$context->getCustomer()) {
            return;
        }
        $placed = $this->placedCarts?->isPlaced($original->getToken()) ?? false;
        if ($settings['balance'] <= 0) {
            if ($placed) {
                return;
            }
            foreach ($credits as $credit) {
                if ($this->previouslyApplied($credit, $context) >= 0.01) {
                    $this->notify(self::SNIPPET_REMOVED, 0.0, $context);
                    break;
                }
            }

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
        $balanceLeft = $settings['balance'];
        foreach ($credits as $credit) {
            $previous = $this->previouslyApplied($credit, $context);
            $definition = $credit->getPriceDefinition();
            if (!$definition instanceof AbsolutePriceDefinition || !is_finite($definition->getPrice()) || $definition->getPrice() >= 0) {
                continue;
            }
            $request = StoreCreditLineItem::getRequest($credit, $context->getCurrencyId());
            $requested = $request['amount'] * ($settings['rates'][$request['currencyId']] ?? 0.0);
            $amount = $this->converter->roundCheckoutAmount(min($requested, $available), $context);
            if (!$placed && $previous > 0.0 && $amount < $previous - self::EPSILON && $balanceLeft < $previous - self::EPSILON) {
                $this->notify($amount < 0.01 ? self::SNIPPET_REMOVED : self::SNIPPET_REDUCED, $amount, $context);
            }
            if ($amount < 0.01) {
                continue;
            }
            $balanceLeft -= $amount;
            $credit->setType(LineItem::CREDIT_LINE_ITEM_TYPE);
            $credit->setStackable(true)->setQuantity(1)->setStackable(false);
            $credit->setGood(false)->setRemovable(true)->setShippingCostAware(false);
            $credit->setPayloadValue('isStoreCredit', true);
            $credit->setPayloadValue('storeCreditAmount', $request['amount']);
            $credit->setPayloadValue('storeCreditCurrencyId', $request['currencyId']);
            $credit->setPayloadValue('storeCreditCalculatedAmount', $amount);
            $credit->setPayloadValue(self::CALCULATED_CURRENCY_KEY, $context->getCurrencyId());
            $credit->setPriceDefinition(new AbsolutePriceDefinition(-$amount));
            $credit->setPrice($this->calculator->calculate(-$amount, $prices, $context));
            $toCalculate->add($credit);
            $available -= abs($credit->getPrice()->getTotalPrice());
        }
    }

    private function previouslyApplied(LineItem $credit, SalesChannelContext $context): float
    {
        $amount = $credit->getPayloadValue('storeCreditCalculatedAmount');
        if ($credit->getPayloadValue(self::CALCULATED_CURRENCY_KEY) !== $context->getCurrencyId() || !is_numeric($amount)) {
            return 0.0;
        }

        return abs((float) $amount);
    }

    private function notify(string $snippet, float $amount, SalesChannelContext $context): void
    {
        try {
            $request = $this->requestStack?->getCurrentRequest();
            if ($request === null || !$request->hasSession() || $this->translator === null) {
                return;
            }
            $session = $request->getSession();
            if (!$session instanceof FlashBagAwareSessionInterface) {
                return;
            }
            $formatted = $this->currencyFormatter?->formatCurrencyByLanguage(
                $amount,
                $context->getCurrency()->getIsoCode(),
                $context->getLanguageId(),
                $context->getContext()
            ) ?? number_format($amount, 2);
            $message = $this->translator->trans($snippet, ['%amount%' => $formatted]);
            if (!in_array($message, $session->getFlashBag()->peek('danger'), true)) {
                $session->getFlashBag()->add('danger', $message);
            }
        } catch (\Throwable) {
        }
    }
}
