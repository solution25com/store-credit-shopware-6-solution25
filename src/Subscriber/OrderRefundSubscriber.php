<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountUnavailableException;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderRefundSubscriber implements EventSubscriberInterface
{
    private const STATE_MACHINE_NAME = 'order_return.state';
    private const STORE_CREDIT_STATE = 'store_credit';
    private const REFUNDABLE_PAYMENT_STATES = ['paid', 'refunded_partially'];

    /** @var EntityRepository<EntityCollection<Entity>>|null */
    private ?EntityRepository $orderReturnRepository;
    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;

    /**
     * @param EntityRepository<EntityCollection<Entity>>|null $orderReturnRepository
     */
    public function __construct(
        ?EntityRepository $orderReturnRepository,
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger,
    ) {
        $this->orderReturnRepository = $orderReturnRepository;
        $this->storeCreditManager    = $storeCreditManager;
        $this->logger                = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_return.state_changed' => 'onStoreCreditStateEnter',
        ];
    }

    public function onStoreCreditStateEnter(StateMachineStateChangeEvent $event): void
    {
        if (
            $event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
            || $event->getStateMachine()->getTechnicalName() !== self::STATE_MACHINE_NAME
            || $event->getNextState()->getTechnicalName() !== self::STORE_CREDIT_STATE
        ) {
            return;
        }

        $returnId = $event->getTransition()->getEntityId();

        try {
            $this->creditReturn($returnId, $event);
        } catch (CardRefundedAmountUnavailableException $e) {
            $this->logger->error('Store credit refund skipped: card refund totals unavailable.', [
                'orderReturnId' => $returnId,
                'provider'      => $e->getProviderName(),
                'error'         => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Store credit refund failed.', [
                'orderReturnId' => $returnId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function creditReturn(string $returnId, StateMachineStateChangeEvent $event): void
    {
        if ($this->orderReturnRepository === null) {
            return;
        }

        $context = $event->getContext();

        $criteria = new Criteria([$returnId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('order.orderCustomer');
        $criteria->addAssociation('order.transactions.stateMachineState');
        $criteria->getAssociation('order.transactions')
            ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

        $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
        if ($orderReturn === null || !method_exists($orderReturn, 'getOrder')) {
            $this->logger->error('Store credit refund skipped: order return not found.', ['orderReturnId' => $returnId]);

            return;
        }

        $order = $orderReturn->getOrder();
        if ($order === null) {
            $this->logger->error('Store credit refund skipped: order return has no order.', ['orderReturnId' => $returnId]);

            return;
        }

        $paymentState = $order->getTransactions()?->last()?->getStateMachineState()?->getTechnicalName();
        if (!\in_array($paymentState, self::REFUNDABLE_PAYMENT_STATES, true)) {
            $this->logger->info('Store credit refund skipped: order is not in a refundable payment state.', [
                'orderReturnId' => $returnId,
                'orderId'       => $order->getId(),
                'paymentState'  => $paymentState,
            ]);

            return;
        }

        $customerId = $order->getOrderCustomer()?->getCustomerId();
        if ($customerId === null) {
            $this->logger->error('Store credit refund skipped: order has no customer.', [
                'orderReturnId' => $returnId,
                'orderId'       => $order->getId(),
            ]);

            return;
        }

        $amount = $this->resolveRefundAmount($orderReturn);
        if ($amount <= 0.0) {
            $this->logger->error('Store credit refund skipped: nothing to refund.', [
                'orderReturnId' => $returnId,
                'orderId'       => $order->getId(),
            ]);

            return;
        }

        $walletCurrency = $this->storeCreditManager->getCreditBalance($customerId, $context)['balanceCurrencyId'];
        if ($walletCurrency !== null && $walletCurrency !== strtolower($order->getCurrencyId())) {
            $this->logger->error('Store credit refund skipped: the order currency differs from the customer\'s store credit currency. Credit the customer manually.', [
                'orderReturnId'    => $returnId,
                'orderId'          => $order->getId(),
                'orderCurrencyId'  => $order->getCurrencyId(),
                'walletCurrencyId' => $walletCurrency,
            ]);

            return;
        }

        $returnNumber = method_exists($orderReturn, 'getReturnNumber') ? (string) $orderReturn->getReturnNumber() : $returnId;

        $result = $this->storeCreditManager->creditForReturn(
            $customerId,
            $order->getId(),
            $amount,
            $returnId,
            $context,
            $order->getCurrencyId(),
            sprintf('Return %s of order %s refunded as store credit', $returnNumber, $order->getOrderNumber())
        );

        if ($result->isAlreadyProcessed()) {
            $this->logger->info('Store credit refund already applied for this return, skipped.', [
                'orderReturnId' => $returnId,
                'orderId'       => $order->getId(),
                'historyId'     => $result->getHistoryId(),
            ]);

            return;
        }

        if ($result->wasCapped()) {
            $this->logger->warning('Store credit refund capped at the order\'s refundable remainder.', [
                'orderReturnId'   => $returnId,
                'orderId'         => $order->getId(),
                'requestedAmount' => $result->getRequestedAmount(),
                'creditedAmount'  => $result->getCreditedAmount(),
            ]);
        }

        if ($result->getHistoryId() !== null) {
            $this->logger->info('Store credit added to customer account from order return', [
                'orderReturnId' => $returnId,
                'orderId'       => $order->getId(),
                'customerId'    => $customerId,
                'amount'        => $result->getCreditedAmount(),
            ]);
        }
    }

    private function resolveRefundAmount(object $orderReturn): float
    {
        if (method_exists($orderReturn, 'getAmountTotal')) {
            $amountTotal = $orderReturn->getAmountTotal();
            if (is_numeric($amountTotal) && (float) $amountTotal > 0) {
                return (float) $amountTotal;
            }
        }

        if (!method_exists($orderReturn, 'getLineItems')) {
            return 0.0;
        }

        $lineItems = $orderReturn->getLineItems();
        if (!is_iterable($lineItems)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($lineItems as $lineItem) {
            $total += (float) ($lineItem->getPrice()?->getTotalPrice() ?? 0.0);
        }

        return $total;
    }
}
