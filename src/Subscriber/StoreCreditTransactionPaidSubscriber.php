<?php declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Tag\TagCollection;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StoreCreditTransactionPaidSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     * @param EntityRepository<TagCollection> $tagRepository
     * @param EntityRepository<EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $orderTagRepository
     */
    public function __construct(
        private readonly StoreCreditManager $manager,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $tagRepository,
        private readonly EntityRepository $orderTagRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['state_enter.order_transaction.state.paid' => 'onTransactionPaid'];
    }

    public function onTransactionPaid(OrderStateMachineStateChangeEvent $event): void
    {
        $fields = $event->getOrder()->getCustomFields() ?? [];
        $pending = (float) ($fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT] ?? 0);
        if ($pending <= 0 || ($fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED] ?? false)) {
            return;
        }

        try {
            $this->deduct($event->getOrder()->getId(), $event->getContext());
        } catch (\Throwable $e) {
            $this->logger->error('Store credit: deduction on transaction paid failed unexpectedly.', ['orderId' => $event->getOrder()->getId(), 'error' => $e->getMessage()]);
        }
    }

    private function deduct(string $orderId, Context $context): void
    {
        $order = $this->orderRepository->search((new Criteria([$orderId]))->addAssociation('orderCustomer'), $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            return;
        }
        $fields = $order->getCustomFields() ?? [];
        $amount = round((float) ($fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT] ?? 0), 2);
        $customerId = $order->getOrderCustomer()?->getCustomerId();
        $pendingCurrency = $fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_CURRENCY] ?? null;
        $currencyId = \is_string($pendingCurrency) && $pendingCurrency !== '' ? $pendingCurrency : null;
        if ($amount <= 0 || ($fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED] ?? false) || $customerId === null) {
            return;
        }

        try {
            $this->manager->deductCredit(
                $customerId,
                $amount,
                $context,
                $orderId,
                $currencyId,
                'Store credit used for order payment',
                StoreCreditConstants::ORDER_DEBIT_OPERATION_KEY_PREFIX . $orderId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Store credit: could not deduct the pending amount of a paid order.', ['orderId' => $orderId, 'amount' => $amount, 'error' => $e->getMessage()]);
            $this->markFailed($orderId, $amount, $currencyId, $e, $context);

            return;
        }

        $hadFailed = isset($fields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED]);
        $update = [StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED => true, StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT => 0.0];
        if ($hadFailed) {
            $update[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED] = null;
        }
        try {
            $this->orderRepository->update([['id' => $orderId, 'customFields' => $update]], $context);
        } catch (\Throwable $e) {
            $this->logger->error('Store credit: deducted, but could not mark the order as deducted.', ['orderId' => $orderId, 'error' => $e->getMessage()]);
        }
        if ($hadFailed) {
            try {
                $this->orderTagRepository->delete([[
                    'orderId' => $orderId, 'orderVersionId' => Defaults::LIVE_VERSION,
                    'tagId' => Uuid::fromStringToHex(StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG),
                ]], $context);
            } catch (\Throwable $e) {
                $this->logger->error('Store credit: could not remove the review tag from the order.', ['orderId' => $orderId, 'error' => $e->getMessage()]);
            }
        }
        $this->logger->info('Store credit: deducted the pending amount of a paid order.', ['orderId' => $orderId, 'amount' => $amount]);
    }

    private function markFailed(string $orderId, float $amount, ?string $currencyId, \Throwable $e, Context $context): void
    {
        try {
            $this->orderRepository->update([['id' => $orderId, 'customFields' => [StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED => [
                'amount' => $amount,
                'currencyId' => $currencyId,
                'message' => mb_substr($e->getMessage(), 0, 500),
                'failedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]]]], $context);
            $tagId = Uuid::fromStringToHex(StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG);
            $this->tagRepository->upsert([['id' => $tagId, 'name' => StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG]], $context);
            $this->orderRepository->update([['id' => $orderId, 'tags' => [['id' => $tagId]]]], $context);
        } catch (\Throwable $stampError) {
            $this->logger->error('Store credit: could not record the failed deduction on the order.', ['orderId' => $orderId, 'error' => $stampError->getMessage()]);
        }
    }
}
