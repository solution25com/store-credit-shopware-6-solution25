<?php

namespace StoreCredit\Subscriber;

use Exception;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use StoreCredit\Controller\StoreCreditController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpFoundation\Request;

class OrderRefundSubscriber implements EventSubscriberInterface
{
    private EntityRepository $orderRepository;
    private EntityRepository $orderReturnRepository;
    private StoreCreditController $storeCreditController;

    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $orderReturnRepository,
        StoreCreditController $storeCreditController,
    ) {
        $this->orderRepository       = $orderRepository;
        $this->orderReturnRepository = $orderReturnRepository;
        $this->storeCreditController = $storeCreditController;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_return.state.store_credit' => 'onStoreCreditStateEnter',
        ];
    }

    public function onStoreCreditStateEnter(OrderStateMachineStateChangeEvent $event): void
    {
        $orderId = $event->getOrder()->getId();
        $context = $event->getContext();

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.stateMachineState');
        $order        = $this->orderRepository->search($criteria, $context)->last();
        $paymentState = $order->getTransactions()->last()?->getStateMachineState()?->getTechnicalName();

        if (!$order || $paymentState !== 'paid') {
            return;
        }

        $returnCriteria = new Criteria();
        $returnCriteria->addFilter(new EqualsFilter('orderId', $orderId));
        $returnCriteria->addAssociation('lineItems.lineItem');
        $orderReturn = $this->orderReturnRepository->search($returnCriteria, $context)->last();

        $returnLineItemData = [];

        if ($orderReturn) {
            $returnLineItems    = $orderReturn->getLineItems();
            $returnLineItemData = $returnLineItems->map(function ($lineItem) {
                $associatedLineItem = $lineItem->getLineItem();
                return [
                    'id'       => $lineItem->getId(),
                    'name'     => $associatedLineItem ? $associatedLineItem->getLabel() : 'Unknown',
                    'quantity' => $lineItem->getQuantity(),
                    'price'    => $lineItem->getPrice()->getTotalPrice(),
                ];
            });
        }

        $totalPrice = array_reduce($returnLineItemData, function ($carry, $lineItem) {
            return $carry + ($lineItem['price'] ?? 0);
        }, 0);


        $storeCreditsReqData = [
            'customerId' => $event->getOrder()->getOrderCustomer()->getCustomerId(),
            'orderId'    => $event->getOrder()->getId(),
            'currencyId' => $event->getContext()->getCurrencyId(),
            'amount'     => $totalPrice,
            'reason'     => "Refunded from order with Order Number : " . $event->getOrder()->getOrderNumber(),
        ];

        $request = new Request([], $storeCreditsReqData);
        try {
            $response = $this->storeCreditController->addCredit($request);
            if ($response->getStatusCode() === 200) {
                echo('Credit added to order with Order Number: ' . $event->getOrder()->getOrderNumber());
            }
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        }
    }
}
