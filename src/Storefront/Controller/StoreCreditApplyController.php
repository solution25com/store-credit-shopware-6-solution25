<?php

namespace Solu1StoreCredit\Storefront\Controller;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditApplyController extends StorefrontController
{
    private CartService $cartService;
    private StoreCreditManager $storeCreditManager;
    private SystemConfigService $systemConfigurationService;
    private ?StoreCreditCurrencyConverter $currencyConverter;
    private ?CurrencyFormatter $currencyFormatter;
    private string $storeCreditLineItemId = StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID;

    public function __construct(
        CartService $cartService,
        StoreCreditManager $storeCreditManager,
        SystemConfigService $systemConfigurationService,
        ?StoreCreditCurrencyConverter $currencyConverter = null,
        ?CurrencyFormatter $currencyFormatter = null
    ) {
        $this->cartService = $cartService;
        $this->storeCreditManager = $storeCreditManager;
        $this->systemConfigurationService = $systemConfigurationService;
        $this->currencyConverter = $currencyConverter;
        $this->currencyFormatter = $currencyFormatter;
    }

    #[Route(path: '/store-credit-apply', name: 'frontend.store.credit.apply', defaults: ['_routeScope' => ['storefront']], methods: ['POST'])]
    public function applyStoreCredit(Request $request, SalesChannelContext $context): Response
    {
        $amount = (float)$request->get('amount');
        $customer = $context->getCustomer();

        if (!$customer || $amount <= 0) {
            $this->addFlash('danger', (string) $this->trans('store-credit.flash.invalidAmountOrCustomer'));
            return $this->redirectBack($request);
        }

        $restrictedProducts = $this->hasRestrictedProductsInCart($context);
        if ($restrictedProducts !== []) {
            $productNames = array_map(
                static fn ($product) => '<strong>' . htmlspecialchars($product['name'], ENT_QUOTES) . '</strong>',
                $restrictedProducts
            );
            $this->addFlash(
                'warning',
                (string) $this->trans('store-credit.flash.restrictedProducts', ['%products%' => implode(', ', $productNames)])
            );
            return $this->redirectBack($request);
        }

        $creditBalance = $this->getAvailableCreditBalance($customer->getId(), $context);
        $amountToApply = min($creditBalance, $amount);

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $storeCreditDiscount = $cart->getLineItems()->get($this->storeCreditLineItemId);
        $totalAppliedCredit = 0;

        if ($storeCreditDiscount && $storeCreditDiscount->getPrice()) {
            $totalAppliedCredit = abs($storeCreditDiscount->getPrice()->getTotalPrice());
        }

        $maxCreditPerOrder = $this->systemConfigurationService->get('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId());
        $maxCreditPerOrder = is_numeric($maxCreditPerOrder) ? (float) $maxCreditPerOrder : 0.0;
        if ($maxCreditPerOrder > 0) {
            $totalAfterApply = $totalAppliedCredit + $amountToApply;
            if ($totalAfterApply > $maxCreditPerOrder) {
                $this->addFlash(
                    'warning',
                    (string) $this->trans('store-credit.flash.maxPerOrder', [
                        '%max%' => $this->formatAmount($maxCreditPerOrder, $context),
                        '%remaining%' => $this->formatAmount($maxCreditPerOrder - $totalAppliedCredit, $context),
                    ])
                );
                return $this->redirectBack($request);
            }
        }

        if ($totalAppliedCredit + $amountToApply > $creditBalance) {
            $this->addFlash('warning', (string) $this->trans('store-credit.flash.exceedsAvailable'));
            return $this->redirectBack($request);
        }

        if ($this->hasBlockedLineItems($context)) {
            $this->addFlash('warning', (string) $this->trans('store-credit.flash.premiumProtection'));
            return $this->redirectBack($request);
        }

        $remainingPayable = max(0.0, $cart->getPrice()->getTotalPrice());
        $amountToApply = min($amountToApply, $remainingPayable);

        if ($amountToApply <= 0.0) {
            $this->addFlash('info', (string) $this->trans('store-credit.flash.alreadyFullyCovered'));
            return $this->redirectBack($request);
        }

        if ($storeCreditDiscount) {
            $price = $storeCreditDiscount->getPrice();
            $currentPrice = $price ? $price->getTotalPrice() : 0.0;
            $newPrice = max(-$creditBalance, $currentPrice - $amountToApply);
            $storeCreditDiscount->setPriceDefinition(new AbsolutePriceDefinition($newPrice));
            $this->rememberRequest($storeCreditDiscount, abs($newPrice), $context);
        } else {
            $discount = new LineItem($this->storeCreditLineItemId, LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
            $discount->setLabel(StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL);
            $discount->setRemovable(true);
            $discount->setStackable(true);
            $discount->setPriceDefinition(new AbsolutePriceDefinition(-$amountToApply));
            $discount->setGood(false);
            $discount->setPayloadValue(StoreCreditConstants::PAYLOAD_KEY, true);
            $this->rememberRequest($discount, $amountToApply, $context);
            $this->cartService->add($cart, $discount, $context);
        }

        $this->cartService->recalculate($cart, $context);

        $this->addFlash('success', (string) $this->trans('store-credit.flash.appliedSuccess'));
        return $this->redirectBack($request);
    }

    private function getAvailableCreditBalance(?string $customerId, SalesChannelContext $context): float
    {
        if (!$customerId) {
            return 0.0;
        }

        $balance = $this->storeCreditManager->getAvailableCreditBalance($customerId, $context->getContext());
        if ($this->currencyConverter === null || $balance['balanceCurrencyId'] === null) {
            return (float) $balance['balanceAmount'];
        }

        return $this->currencyConverter->toCheckout((float) $balance['balanceAmount'], $balance['balanceCurrencyId'], $context);
    }

    private function rememberRequest(LineItem $storeCredit, float $amount, SalesChannelContext $context): void
    {
        $currencyId = strtolower($context->getCurrencyId());
        $storeCredit->setPayloadValue(StoreCreditConstants::PAYLOAD_CURRENCY_KEY, $currencyId);
        $storeCredit->setPayloadValue(StoreCreditConstants::PAYLOAD_REQUESTED_AMOUNT_KEY, round($amount, 2));
        $storeCredit->setPayloadValue(StoreCreditConstants::PAYLOAD_REQUESTED_CURRENCY_KEY, $currencyId);
    }

    private function formatAmount(float $amount, SalesChannelContext $context): string
    {
        if ($this->currencyFormatter === null) {
            return number_format($amount, 2);
        }

        return $this->currencyFormatter->formatCurrencyByLanguage(
            $amount,
            $context->getCurrency()->getIsoCode(),
            $context->getLanguageId(),
            $context->getContext()
        );
    }

    private function hasBlockedLineItems(SalesChannelContext $context): bool
    {
        $blockedIds = $this->getBlockedLineItemReferenceIds($context);
        if ($blockedIds === []) {
            return false;
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
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
        $ids = $this->systemConfigurationService->get('StoreCredit.config.blockedLineItemReferenceIds', $context->getSalesChannelId());

        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }

        return is_array($ids) ? array_values($ids) : [];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function hasRestrictedProductsInCart(SalesChannelContext $context): array
    {
        $restrictedProductIds = $this->systemConfigurationService->get('StoreCredit.config.restrictedProducts', $context->getSalesChannelId());
        if (!is_array($restrictedProductIds) || empty($restrictedProductIds)) {
            return [];
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $restrictedProducts = [];

        foreach ($cart->getLineItems() as $lineItem) {
            $referencedId = $lineItem->getReferencedId();
            if ($referencedId !== null && in_array($referencedId, $restrictedProductIds, true)) {
                $restrictedProducts[] = [
                    'id' => $referencedId,
                    'name' => (string) $lineItem->getLabel(),
                ];
            }
        }
        return $restrictedProducts;
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer');
        $path = $referer !== '' ? (parse_url($referer, PHP_URL_PATH) ?: '') : '';
        $unsafe = $referer === ''
            || str_contains($path, '/checkout/order')
            || str_contains($path, '/checkout/finish');

        return $unsafe
            ? new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'), 303)
            : new RedirectResponse($referer, 303);
    }
}
