<?php

declare(strict_types=1);

namespace StoreCredit\Storefront\Controller;

use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractLogoutRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\CheckoutController;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoader;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedHook;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoader;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoader;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]

#[Package('storefront')]
class CustomCheckoutController extends CheckoutController
{
    private CartService $cartService;
    private EntityRepository $storeCreditRepository;
    private SystemConfigService $systemConfigurationService;
    private CheckoutConfirmPageLoader $confirmPageLoader;
    private ?string $storeCreditLineItemId = 'store-credit-discount';

    public function __construct(
        CartService $cartService,
        CheckoutCartPageLoader $cartPageLoader,
        CheckoutConfirmPageLoader $confirmPageLoader,
        CheckoutFinishPageLoader $finishPageLoader,
        OrderService $orderService,
        PaymentProcessor $paymentProcessor,
        OffcanvasCartPageLoader $offcanvasCartPageLoader,
        SystemConfigService $config,
        AbstractLogoutRoute $logoutRoute,
        AbstractCartLoadRoute $cartLoadRoute,
        EntityRepository $storeCreditRepository,
        SystemConfigService $systemConfigurationService
    ) {
        $this->cartService                = $cartService;
        $this->confirmPageLoader          = $confirmPageLoader;
        $this->storeCreditRepository      = $storeCreditRepository;
        $this->systemConfigurationService = $systemConfigurationService;

        parent::__construct(
            $cartService,
            $cartPageLoader,
            $confirmPageLoader,
            $finishPageLoader,
            $orderService,
            $paymentProcessor,
            $offcanvasCartPageLoader,
            $config,
            $logoutRoute,
            $cartLoadRoute
        );
    }

    public function lineItemId(): string
    {
        return Uuid::fromStringToHex($this->storeCreditLineItemId);
    }

    public function confirmPage(Request $request, SalesChannelContext $context): Response
    {
        if (!$context->getCustomer()) {
            return $this->redirectToRoute('frontend.checkout.register.page');
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->getLineItems()->count() === 0) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        $customer = $context->getCustomer();
        if (!$customer) {
            throw new \RuntimeException('Customer not found in SalesChannelContext.');
        }
        $creditBalance = $this->getCreditBalance($customer?->getId());

        $this->validateStoreCredits($cart, $creditBalance, $context);

        $page = $this->confirmPageLoader->load($request, $context);
        $page->assign(['storeCreditBalance' => $creditBalance,
          'storeCreditId'                   => $this->lineItemId()]);

        $this->hook(new CheckoutConfirmPageLoadedHook($page, $context));
        $this->addCartErrors($page->getCart());

        return $this->renderStorefront('@Storefront/storefront/page/checkout/confirm/index.html.twig', [
          'page' => $page,
        ]);
    }

    private function getCreditBalance(?string $customerId): float
    {
        if (!$customerId) {
            return 0.0;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $result            = $this->storeCreditRepository->search($criteria, Context::createDefaultContext());
        $storeCreditEntity = $result->first();

        return $storeCreditEntity ? (float)$storeCreditEntity->get('balance') : 0.0;
    }

    #[Route(
        path: '/store-credit-apply',
        name: 'frontend.store.credit.apply',
        defaults: ['_routeScope' => ['storefront']],
        methods: ['POST']
    )]
    public function applyStoreCredit(Request $request, SalesChannelContext $context): Response
    {
        $amount   = (float)$request->get('amount');
        $customer = $context->getCustomer();

        if (!$customer || $amount <= 0) {
            return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
        }

        if ($restrictedProducts = $this->hasRestrictedProductsInCart($context)) {
            $productNames = array_map(
                static fn ($product) => '<strong>' . htmlspecialchars($product['name'], ENT_QUOTES) . '</strong>',
                $restrictedProducts
            );
            $this->addFlash(
                'warning',
                'Store credit cannot be applied due to restricted products in the cart: ' . implode(', ', $productNames)
            );
            return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
        }

        try {
            $creditBalance = $this->getCreditBalance($customer->getId());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Error fetching credit balance: ' . $e->getMessage());
        }
        $amountToApply = min($creditBalance, $amount);




        $cart = $this->cartService->getCart($context->getToken(), $context);
        if (!$cart) {
            throw new \RuntimeException('Cart could not be retrieved. Token might be invalid.');
        }
        $lineItems           = $cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE);
        $storeCreditDiscount = $lineItems->get($this->lineItemId());
        $totalAppliedCredit  = 0;

        foreach ($lineItems as $lineItem) {
            $totalAppliedCredit += abs($lineItem->getPrice()->getTotalPrice());
        }

        if ($totalAppliedCredit + $amountToApply > $creditBalance) {
            return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
        }

        if ($storeCreditDiscount) {
            $currentPrice = $storeCreditDiscount->getPrice()->getTotalPrice();
            $newPrice     = max(-$creditBalance, $currentPrice - $amountToApply);
            $storeCreditDiscount->setPriceDefinition(new AbsolutePriceDefinition($newPrice));
        } else {
            $discount = new LineItem($this->lineItemId(), LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
            $discount->setLabel('Store credit discount');
            $discount->setRemovable(true);
            $discount->setStackable(true);
            $discount->setPriceDefinition(new AbsolutePriceDefinition(-$amountToApply));
            $discount->setGood(false);

            $this->cartService->add($cart, $discount, $context);
        }

        $this->cartService->recalculate($cart, $context);

        return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page'));
    }

    private function hasRestrictedProductsInCart(SalesChannelContext $context): array|bool
    {
        $restrictedProductIds = $this->systemConfigurationService->get('StoreCredit.config.restrictedProducts');
        if (empty($restrictedProductIds)) {
            return false;
        }

        $cart               = $this->cartService->getCart($context->getToken(), $context);
        $restrictedProducts = [];

        foreach ($cart->getLineItems() as $lineItem) {
            if (in_array($lineItem->getReferencedId(), $restrictedProductIds)) {
                $restrictedProducts[] = [
                  'id'   => $lineItem->getReferencedId(),
                  'name' => $lineItem->getLabel(),
                ];
            }
        }
        return !empty($restrictedProducts) ? $restrictedProducts : false;
    }

    private function validateStoreCredits($cart, float $creditBalance, SalesChannelContext $context): void
    {
        $lineItems          = $cart->getLineItems()->filterType(LineItem::CREDIT_LINE_ITEM_TYPE);
        $totalAppliedCredit = 0;

        foreach ($lineItems as $lineItem) {
            $totalAppliedCredit += abs($lineItem->getPrice()->getTotalPrice());
        }

        if ($totalAppliedCredit > $creditBalance) {
            foreach ($lineItems as $lineItem) {
                $this->cartService->remove($cart, $lineItem->getId(), $context);
            }

            if ($creditBalance > 0) {
                $discount = new LineItem($this->lineItemId(), LineItem::CREDIT_LINE_ITEM_TYPE, null, 1);
                $discount->setLabel('Store credit discount');
                $discount->setRemovable(true);
                $discount->setPriceDefinition(new AbsolutePriceDefinition(-$creditBalance));
                $discount->setGood(false);

                $this->cartService->add($cart, $discount, $context);
            }

            $this->cartService->recalculate($cart, $context);
        }
    }
}
