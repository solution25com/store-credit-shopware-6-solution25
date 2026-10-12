<?php declare(strict_types=1);

namespace Solu1StoreCredit\Core\Checkout\Cart;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Order\OrderPersisterInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditManager;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StoreCreditOrderPersister implements OrderPersisterInterface
{
    public function __construct(
        private readonly OrderPersisterInterface $inner,
        private readonly StoreCreditManager $manager,
        private readonly Connection $connection,
        private readonly StoreCreditCurrencyConverter $converter,
        private readonly ?PlacedOrderCartRegistry $placedCarts = null,
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
            $walletCurrencyId = $wallet['currency_id'] ?? Defaults::CURRENCY;
            $rate = $this->converter->getRates([$walletCurrencyId], $context)[$walletCurrencyId] ?? 0.0;
            $debit = $rate > 0 ? $this->converter->walletDebit($amount, $rate) : INF;
            $available = $wallet ? $this->manager->getAvailableCreditBalance($customer->getId(), $context->getContext())['balanceAmount'] : 0.0;
            if (!$wallet || !is_finite($debit) || $debit <= 0 || $available < $debit) {
                throw new BadRequestHttpException('Store credit balance has changed. Please recalculate your cart.');
            }
            $orderId = $this->inner->persist($cart, $context);
            $this->connection->executeStatement(
                'UPDATE `order` SET custom_fields = JSON_SET(COALESCE(custom_fields, JSON_OBJECT()), :amountPath, CAST(:amount AS DECIMAL(10,2)), :currencyPath, :currency)
                 WHERE id = :id AND version_id = :version',
                [
                    'amountPath' => '$."' . StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT . '"',
                    'amount' => $debit,
                    'currencyPath' => '$."' . StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_CURRENCY . '"',
                    'currency' => $walletCurrencyId,
                    'id' => Uuid::fromHexToBytes($orderId),
                    'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                ]
            );
            $this->placedCarts?->markPlaced($cart->getToken());

            return $orderId;
        });
    }
}
