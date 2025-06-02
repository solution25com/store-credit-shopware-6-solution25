<?php

declare(strict_types=1);

namespace StoreCredit\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartSubscriber implements EventSubscriberInterface
{
    private StoreCreditManager $storeCreditManager;

    public function __construct(StoreCreditManager $storeCreditManager)
    {
        $this->storeCreditManager = $storeCreditManager;
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

        $storeCreditLineItem = $orderLineItems
            ->filterByType('credit')
            ->filter(function ($lineItem) {
                return $lineItem->getLabel() === 'Store credit discount';
            })
            ->first();

        if ($storeCreditLineItem) {
            $amountToDeduct = $storeCreditLineItem->getTotalPrice();
            $this->storeCreditManager->deductCredit(
                $customerId,
                abs($amountToDeduct),
                $orderId,
                $currencyId,
                'Store credit used for order payment'
            );
        }
    }
}
