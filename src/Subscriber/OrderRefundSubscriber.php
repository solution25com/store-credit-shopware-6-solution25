<?php

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderRefundSubscriber implements EventSubscriberInterface
{
    private EntityRepository $orderRepository;
    private ?EntityRepository $orderReturnRepository;
    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $orderRepository,
        ?EntityRepository $orderReturnRepository,
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger,
    ) {
        $this->orderRepository       = $orderRepository;
        $this->orderReturnRepository = $orderReturnRepository;
        $this->storeCreditManager = $storeCreditManager;
        $this->logger = $logger;
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

        $order = $this->getOrderWithAssociations($orderId, $context);

        if (!$this->shouldProcessRefund($order)) {
            return;
        }

        $orderReturn = $this->getOrderReturn($orderId, $context);
        $refundAmount = $this->calculateRefundAmount($orderReturn);

        $this->addStoreCreditForRefund($event->getOrder(), $refundAmount, $context);
    }

    private function getOrderWithAssociations(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.stateMachineState');

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search($criteria, $context)->last();

        return $order;
    }

    private function getOrderReturn(string $orderId, Context $context): ?OrderEntity
    {
        if (!$this->orderReturnRepository) {
            return null;
        }

        $returnCriteria = new Criteria();
        $returnCriteria->addFilter(new EqualsFilter('orderId', $orderId));
        $returnCriteria->addAssociation('lineItems');

        /** @var OrderEntity|null $orderReturn */
        $orderReturn = $this->orderReturnRepository->search($returnCriteria, $context)->last();

        return $orderReturn;
    }

    private function shouldProcessRefund(OrderEntity $order): bool
    {
        $paymentState = $order->getTransactions()->last()?->getStateMachineState()?->getTechnicalName();

        return $paymentState === 'paid';
    }

    private function calculateRefundAmount(?OrderEntity $orderReturn): float
    {
        if (!$orderReturn) {
            return 0.0;
        }

        $totalPrice = 0.0;
        /* @phpstan-ignore-next-line */
        $returnLineItems = $orderReturn->getLineItems();
        foreach ($returnLineItems as $lineItem) {
            $totalPrice += $lineItem->getPrice()->getTotalPrice();
        }

        return $totalPrice;
    }

    private function addStoreCreditForRefund(OrderEntity $order, float $amount, Context $context): void
    {
        try {
            $customerId = $order->getOrderCustomer()->getCustomerId();
            $orderId = $order->getId();
            $orderNumber = $order->getOrderNumber();
            $currencyId = $context->getCurrencyId();
            $reason = "Refunded from order with Order Number : " . $orderNumber;

            $this->storeCreditManager->addCredit($customerId, $amount, $context, $orderId, $currencyId, $reason);

            $this->logger->info('Store credit added to customer account from order refund', [
                'customerId' => $customerId,
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'amount' => $amount,
                'currencyId' => $currencyId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add store credit from order refund', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException($e->getMessage());
        }
    }
}
