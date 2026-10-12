<?php

namespace Solu1StoreCredit\Core\Checkout\Cart\Processor;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ValidateStoreCreditCartProcessor implements CartProcessorInterface
{
    private const FLAG = 'store-credit-validation.already-processed';
    private const EPSILON = 0.001;
    private const RECALCULATE_ROUTES = [
        'frontend.checkout.confirm.page',
    ];
    private const SNIPPET_PLACEMENT_REDUCED = 'store-credit.placement.clampedTo';
    private const SNIPPET_PLACEMENT_REMOVED = 'store-credit.placement.removed';

    private SystemConfigService $systemConfigService;
    private RequestStack $requestStack;
    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;
    private TranslatorInterface $translator;
    private CurrencyFormatter $currencyFormatter;
    private ?StoreCreditCurrencyConverter $currencyConverter;

    public function __construct(
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        CurrencyFormatter $currencyFormatter,
        ?StoreCreditCurrencyConverter $currencyConverter = null
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->storeCreditManager = $storeCreditManager;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->currencyFormatter = $currencyFormatter;
        $this->currencyConverter = $currencyConverter;
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $this->convertToCheckoutCurrency($toCalculate, $context);

        if ($context->hasState('checkout-order-route')) {
            $this->clampCreditToAvailableBalance($toCalculate, $context);
            $data->set(self::FLAG, true);
            return;
        }

        if ($data->has(self::FLAG)) {
            return;
        }

        $storeCreditLineItem = $this->findStoreCreditItem($toCalculate);

        if (!$storeCreditLineItem) {
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->hasOnlyStoreCreditItems($toCalculate)) {
            $this->removeAllCredits($toCalculate);
            $toCalculate->removeExtension('store_credit_context');
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->hasBlockedLineItems($toCalculate, $context)) {
            $toCalculate->getLineItems()->removeElement($storeCreditLineItem);
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->hasRestrictedProducts($toCalculate, $context)) {
            $toCalculate->getLineItems()->removeElement($storeCreditLineItem);
            $data->set(self::FLAG, true);
            return;
        }

        $storeCreditPrice = $storeCreditLineItem->getPrice();
        if ($storeCreditPrice) {
            $appliedCreditAmount = abs($storeCreditPrice->getTotalPrice());
            $maxCreditPerOrder = $this->systemConfigService->get('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId());

            if ($maxCreditPerOrder > 0 && $appliedCreditAmount > $maxCreditPerOrder) {
                $toCalculate->getLineItems()->removeElement($storeCreditLineItem);
                $data->set(self::FLAG, true);
                return;
            }

            $customer = $context->getCustomer();
            if ($customer !== null) {
                $balanceAmount = $this->availableInCheckoutCurrency($customer->getId(), $context);

                if ($balanceAmount <= 0.0) {
                    $toCalculate->getLineItems()->removeElement($storeCreditLineItem);
                    $this->flashCreditClamp(self::SNIPPET_PLACEMENT_REMOVED, 0.0, $context);
                    $data->set(self::FLAG, true);
                    return;
                }

                if ($appliedCreditAmount > $balanceAmount + self::EPSILON) {
                    $this->setTaxFreeCreditPrice($storeCreditLineItem, $balanceAmount);
                    $this->flashCreditClamp(self::SNIPPET_PLACEMENT_REDUCED, round($balanceAmount, 2), $context);
                }
            }
        }

        $cartTotalWithoutCredit = $this->getCartTotalWithoutStoreCredit($toCalculate, $storeCreditLineItem);

        if ($cartTotalWithoutCredit <= 0) {
            $toCalculate->getLineItems()->removeElement($storeCreditLineItem);
            $data->set(self::FLAG, true);
            return;
        }

        if ($this->isRecalculateRoute()) {
            $this->clampCreditToCartTotal($toCalculate, $storeCreditLineItem);
        }

        $data->set(self::FLAG, true);
    }

    private function clampCreditToAvailableBalance(Cart $cart, SalesChannelContext $context): void
    {
        $storeCredit = $this->findStoreCreditItem($cart);
        $customerId  = $context->getCustomer()?->getId();
        if ($storeCredit === null || $customerId === null) {
            return;
        }

        $applied = $this->appliedAmount($storeCredit);
        if ($applied <= self::EPSILON) {
            return;
        }

        try {
            $available = $this->availableInCheckoutCurrency($customerId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Store credit balance lookup failed at order placement; credit left as applied', [
                'customerId' => $customerId,
                'applied'    => $applied,
                'error'      => $e->getMessage(),
            ]);

            return;
        }

        if ($applied <= $available + self::EPSILON) {
            return;
        }

        if ($available <= self::EPSILON) {
            $cart->getLineItems()->removeElement($storeCredit);
            $this->flashCreditClamp(self::SNIPPET_PLACEMENT_REMOVED, 0.0, $context);
        } else {
            $this->setTaxFreeCreditPrice($storeCredit, round($available, 2));
            $this->flashCreditClamp(self::SNIPPET_PLACEMENT_REDUCED, round($available, 2), $context);
        }

        $this->logger->info('Store credit exceeded the available balance at order placement and was clamped', [
            'customerId' => $customerId,
            'applied'    => $applied,
            'available'  => $available,
        ]);
    }

    private function flashCreditClamp(string $snippet, float $amount, SalesChannelContext $context): void
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            if ($request === null || !$request->hasSession()) {
                return;
            }

            $session = $request->getSession();
            if (!$session instanceof FlashBagAwareSessionInterface) {
                return;
            }

            $formatted = $this->currencyFormatter->formatCurrencyByLanguage(
                $amount,
                $context->getCurrency()->getIsoCode(),
                $context->getLanguageId(),
                $context->getContext()
            );

            $session->getFlashBag()->add('danger', (string) $this->translator->trans($snippet, ['%amount%' => $formatted]));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not show the store credit placement notice', ['error' => $e->getMessage()]);
        }
    }

    private function availableInCheckoutCurrency(string $customerId, SalesChannelContext $context): float
    {
        $balance = $this->storeCreditManager->getAvailableCreditBalance($customerId, $context->getContext());
        if ($this->currencyConverter === null || $balance['balanceCurrencyId'] === null) {
            return (float) $balance['balanceAmount'];
        }

        return $this->currencyConverter->toCheckout((float) $balance['balanceAmount'], $balance['balanceCurrencyId'], $context);
    }

    private function convertToCheckoutCurrency(Cart $cart, SalesChannelContext $context): void
    {
        $storeCredit = $this->findStoreCreditItem($cart);
        if ($storeCredit === null) {
            return;
        }

        $current = strtolower($context->getCurrencyId());
        $payload = $storeCredit->getPayloadValue(StoreCreditConstants::PAYLOAD_CURRENCY_KEY);
        $appliedIn = \is_string($payload) ? StoreCreditCurrencyConverter::normalize($payload) : null;

        if ($appliedIn === null || $appliedIn === $current || $this->currencyConverter === null) {
            $storeCredit->setPayloadValue(StoreCreditConstants::PAYLOAD_CURRENCY_KEY, $current);

            return;
        }

        $requested    = $storeCredit->getPayloadValue(StoreCreditConstants::PAYLOAD_REQUESTED_AMOUNT_KEY);
        $requestedRaw = $storeCredit->getPayloadValue(StoreCreditConstants::PAYLOAD_REQUESTED_CURRENCY_KEY);
        $requestedIn  = \is_string($requestedRaw) ? StoreCreditCurrencyConverter::normalize($requestedRaw) : null;
        if (!is_numeric($requested) || (float) $requested <= 0 || $requestedIn === null) {
            $requested   = $this->appliedAmount($storeCredit);
            $requestedIn = $appliedIn;
        }

        if ($requestedIn === $current) {
            $amount = round((float) $requested, 2);
        } else {
            $rate   = $this->currencyConverter->rateToCheckout($requestedIn, $context);
            $amount = $rate === null ? 0.0 : $this->currencyConverter->roundCheckoutAmount((float) $requested * $rate, $context);
        }

        if ($amount < 0.01) {
            $cart->getLineItems()->removeElement($storeCredit);

            return;
        }

        $this->setTaxFreeCreditPrice($storeCredit, $amount);
        $storeCredit->setPayloadValue(StoreCreditConstants::PAYLOAD_CURRENCY_KEY, $current);
    }

    private function appliedAmount(LineItem $storeCredit): float
    {
        $price = $storeCredit->getPrice();
        if ($price !== null) {
            return abs($price->getTotalPrice());
        }

        $definition = $storeCredit->getPriceDefinition();

        return $definition instanceof AbsolutePriceDefinition ? abs($definition->getPrice()) : 0.0;
    }

    private function findStoreCreditItem(Cart $cart): ?LineItem
    {
        $exact = $cart->getLineItems()->get(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
        if ($exact !== null) {
            return $exact;
        }

        foreach ($cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE) as $lineItem) {
            if ($lineItem->getLabel() === StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL) {
                return $lineItem;
            }
        }

        return null;
    }

    private function hasOnlyStoreCreditItems(Cart $cart): bool
    {
        $lineItems = $cart->getLineItems();
        if ($lineItems->count() === 0) {
            return false;
        }

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getType() !== LineItem::CREDIT_LINE_ITEM_TYPE) {
                return false;
            }
        }

        return true;
    }

    private function removeAllCredits(Cart $cart): void
    {
        foreach ($cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE) as $lineItem) {
            $cart->getLineItems()->removeElement($lineItem);
        }
    }

    private function hasBlockedLineItems(Cart $cart, SalesChannelContext $context): bool
    {
        $blockedIds = $this->getBlockedLineItemReferenceIds($context);
        if ($blockedIds === []) {
            return false;
        }

        foreach ($cart->getLineItems() as $lineItem) {
            $referencedId = $lineItem->getReferencedId();
            if ($referencedId !== null && in_array($referencedId, $blockedIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function getBlockedLineItemReferenceIds(SalesChannelContext $context): array
    {
        $ids = $this->systemConfigService->get('StoreCredit.config.blockedLineItemReferenceIds', $context->getSalesChannelId());

        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }

        return is_array($ids) ? array_values($ids) : [];
    }

    private function hasRestrictedProducts(Cart $cart, SalesChannelContext $context): bool
    {
        $restrictedProductIds = $this->systemConfigService->get('StoreCredit.config.restrictedProducts', $context->getSalesChannelId());
        if (!is_array($restrictedProductIds) || empty($restrictedProductIds)) {
            return false;
        }

        foreach ($cart->getLineItems() as $lineItem) {
            if (in_array($lineItem->getReferencedId(), $restrictedProductIds)) {
                return true;
            }
        }

        return false;
    }

    private function isRecalculateRoute(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return false;
        }

        return \in_array((string) $request->attributes->get('_route'), self::RECALCULATE_ROUTES, true);
    }

    private function clampCreditToCartTotal(Cart $cart, LineItem $storeCredit): void
    {
        if ($storeCredit->getPrice() === null) {
            return;
        }

        $appliedCredit = abs($storeCredit->getPrice()->getTotalPrice());
        if ($appliedCredit <= self::EPSILON) {
            return;
        }

        $total = $cart->getPrice()->getTotalPrice();
        $target = min($appliedCredit, max(0.0, $total + $appliedCredit));

        if (abs($target - $appliedCredit) <= self::EPSILON) {
            return;
        }

        $this->setTaxFreeCreditPrice($storeCredit, $target);
    }

    private function setTaxFreeCreditPrice(LineItem $lineItem, float $amount): void
    {
        $lineItem->setPriceDefinition(new AbsolutePriceDefinition(-$amount));
        $lineItem->setPrice(new CalculatedPrice(
            -$amount,
            -$amount,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));
    }

    private function getCartTotalWithoutStoreCredit(Cart $cart, LineItem $storeCreditLineItem): float
    {
        $total = 0.0;

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getId() === $storeCreditLineItem->getId()) {
                continue;
            }
            $price = $lineItem->getPrice();
            if ($price) {
                $total += $price->getTotalPrice();
            }
        }

        return $total;
    }
}
