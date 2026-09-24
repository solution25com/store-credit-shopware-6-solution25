<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StoreCreditOrderPersister implements OrderPersisterInterface
{
    public function __construct(
        private readonly OrderPersisterInterface $inner,
        private readonly StoreCreditManager $manager,
        private readonly Connection $connection,
    ) {
    }

    public function persist(Cart $cart, SalesChannelContext $context): string
    {
        $amount = 0.0;
        foreach ($cart->getLineItems()->filter(StoreCreditLineItem::matches(...)) as $item) {
            $price = $item->getPrice()?->getTotalPrice();
            if ($price === null || !is_finite($price) || $price >= 0) {
                throw new BadRequestHttpException('Invalid store credit discount. Please recalculate your cart.');
            }
            $amount += abs($price);
        }
        if ($amount === 0.0) {
            return $this->inner->persist($cart, $context);
        }
        $customer = $context->getCustomer();
        if (!$customer) {
            throw CartException::customerNotLoggedIn();
        }

        return $this->manager->withCustomerLock($customer->getId(), function () use ($cart, $context, $customer, $amount): string {
            $wallet = $this->connection->fetchAssociative('SELECT balance, LOWER(HEX(currency_id)) AS currency_id FROM solu1_store_credit WHERE customer_id = :id FOR UPDATE', ['id' => Uuid::fromHexToBytes($customer->getId())]);
            if (!$wallet || ($wallet['currency_id'] ?: Defaults::CURRENCY) !== $context->getCurrencyId() || (float) $wallet['balance'] < round($amount, 2)) {
                throw new BadRequestHttpException('Store credit balance has changed. Please recalculate your cart.');
            }
            $orderId = $this->inner->persist($cart, $context);
            $this->manager->deductCredit($customer->getId(), round($amount, 2), $context->getContext(), $orderId, $context->getCurrencyId(), 'Store credit used for order payment', 'order:' . $orderId);

            return $orderId;
        });
    }
}
