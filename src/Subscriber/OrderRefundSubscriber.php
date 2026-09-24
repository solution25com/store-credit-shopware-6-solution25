<?php declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class OrderRefundSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?EntityRepository $orderReturnRepository,
        private readonly StoreCreditManager $manager,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['state_machine.order_return.state_changed' => 'onOrderReturnStateChanged'];
    }

    public function onOrderReturnStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
            || $event->getNextState()->getTechnicalName() !== 'store_credit') {
            return;
        }
        if (!$this->orderReturnRepository) {
            throw new BadRequestHttpException('Shopware Commercial Return Management is required for store credit refunds.');
        }
        $returnId = $event->getTransition()->getEntityId();
        $operationKey = 'return:' . $returnId;
        $alreadyRecorded = $this->connection->fetchOne('SELECT id FROM solu1_store_credit_history WHERE operation_key = :key', ['key' => $operationKey]);
        $entries = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM state_machine_history h JOIN state_machine_state s ON s.id = h.to_state_id WHERE h.entity_name = :entity AND h.referenced_id = :id AND h.referenced_version_id = :version AND s.technical_name = :state', [
            'entity' => 'order_return', 'id' => Uuid::fromHexToBytes($returnId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'state' => 'store_credit',
        ]);
        if (!$alreadyRecorded && $entries > 1) {
            throw new BadRequestHttpException('This return was previously marked as refunded. Reconcile its legacy or deleted credit history before issuing another credit.');
        }
        $criteria = (new Criteria([$returnId]))->addAssociation('lineItems')->addAssociation('order.orderCustomer')->addAssociation('order.transactions.stateMachineState');
        $criteria->getAssociation('order.transactions')->addSorting(new FieldSorting('createdAt'));
        // @phpstan-ignore function.alreadyNarrowedType (The primary transaction was added after Shopware 6.7.0.)
        if (method_exists(OrderEntity::class, 'getPrimaryOrderTransaction')) {
            $criteria->addAssociation('order.primaryOrderTransaction.stateMachineState');
        }
        $return = $this->orderReturnRepository->search($criteria, $event->getContext())->getEntities()->first();
        // Keep Commercial optional: the generic DAL interface is available without its classes installed.
        $order = $return?->get('order');
        if (!$order instanceof OrderEntity || !$order->getOrderCustomer()?->getCustomerId()) {
            throw new BadRequestHttpException('The returned order has no customer account to receive store credit.');
        }
        // @phpstan-ignore function.alreadyNarrowedType (Shopware 6.7.0 requires the transactions fallback.)
        $transaction = method_exists($order, 'getPrimaryOrderTransaction') ? $order->getPrimaryOrderTransaction() : $order->getTransactions()?->last();
        if ($transaction?->getStateMachineState()?->getTechnicalName() !== 'paid') {
            throw new BadRequestHttpException('Only paid orders can be refunded as store credit.');
        }
        $amount = 0.0;
        foreach ($return->get('lineItems') ?? [] as $item) {
            $refund = (float) $item->get('refundAmount');
            if (!is_finite($refund) || $refund < 0) {
                throw new BadRequestHttpException('Invalid return refund amount.');
            }
            $amount += $refund;
        }
        // Commercial calculates the total from requested refunds, taxes and refunded shipping.
        $amount = $return->get('amountTotal') ?? $amount;
        if (!is_finite($amount) || $amount <= 0) {
            throw new BadRequestHttpException('The return has no positive refund amount.');
        }
        $this->manager->addCredit(
            $order->getOrderCustomer()->getCustomerId(), round($amount, 2), $event->getContext(),
            $order->getId(), $order->getCurrencyId(), 'Store credit refund for order ' . $order->getOrderNumber(), $operationKey,
        );
    }
}
