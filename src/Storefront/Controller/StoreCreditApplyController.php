<?php declare(strict_types=1);

namespace Solu1StoreCredit\Storefront\Controller;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\AbsolutePriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Core\Checkout\Cart\StoreCreditLineItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditApplyController extends StorefrontController
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    #[Route(path: '/store-credit-apply', name: 'frontend.store.credit.apply', defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => true], methods: ['POST'])]
    public function apply(Request $request, SalesChannelContext $context): Response
    {
        if (!$context->getCustomer()) {
            throw CartException::customerNotLoggedIn();
        }
        $input = $request->request->all()['amount'] ?? null;
        if (!is_scalar($input) || !is_numeric($input) || !is_finite((float) $input) || (float) $input < 0.01 || (float) $input > 99999999.99 || abs((float) $input - round((float) $input, 2)) > 0.0000001) {
            $this->addFlash('danger', $this->trans('store-credit.invalidAmount'));
            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $amount = (float) $input;
        foreach ($cart->getLineItems()->filter(StoreCreditLineItem::matches(...)) as $existing) {
            $amount += abs($existing->getPrice()?->getTotalPrice() ?? 0.0);
            $cart->getLineItems()->remove($existing->getId());
        }
        $lineItem = new LineItem(StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID, LineItem::CREDIT_LINE_ITEM_TYPE, StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID);
        $lineItem->setLabel(StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL);
        $lineItem->setPriceDefinition(new AbsolutePriceDefinition(-$amount));
        $lineItem->setPayloadValue('isStoreCredit', true);
        $lineItem->setGood(false)->setRemovable(true);
        $cart = $this->cartService->add($cart, $lineItem, $context);
        $applied = abs($cart->getLineItems()->get($lineItem->getId())?->getPrice()?->getTotalPrice() ?? 0.0);
        $this->addFlash($applied > 0 ? 'success' : 'warning', $this->trans($applied > 0 ? 'store-credit.applied' : 'store-credit.unavailable'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
