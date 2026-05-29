<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartSubscriber implements EventSubscriberInterface
{
    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;

    public function __construct(
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order      = $event->getOrder();
        $customerId = $order->getOrderCustomer()->getCustomerId();
        $orderId    = $order->getId();
        $currencyId = $order->getCurrencyId();

        $orderLineItems = $order->getLineItems();

        $storeCreditLineItem = $orderLineItems->filter(function ($lineItem) {
            return ($lineItem->getReferencedId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
                || ($lineItem->getType() === LineItem::CREDIT_LINE_ITEM_TYPE
                    && $lineItem->getLabel() === StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL));
        })->first();

        if ($storeCreditLineItem) {
            $amountToDeduct = $storeCreditLineItem->getTotalPrice();

            try {
                $this->storeCreditManager->deductCredit(
                    $customerId,
                    abs($amountToDeduct),
                    $event->getContext(),
                    $orderId,
                    $currencyId,
                    'Store credit used for order payment'
                );
            } catch (\Exception $e) {
                $this->logger->error('Failed to deduct store credit after order placement', [
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber(),
                    'customerId' => $customerId,
                    'amount' => abs($amountToDeduct),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
