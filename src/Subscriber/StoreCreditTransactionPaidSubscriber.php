<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StoreCreditTransactionPaidSubscriber implements EventSubscriberInterface
{
    private StoreCreditManager $storeCreditManager;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
    private EntityRepository $orderRepository;
    private LoggerInterface $logger;
    /** @var EntityRepository<\Shopware\Core\System\Tag\TagCollection> */
    private EntityRepository $tagRepository;
    /** @var EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> */
    private EntityRepository $orderTagRepository;

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     * @param EntityRepository<\Shopware\Core\System\Tag\TagCollection> $tagRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $orderTagRepository
     */
    public function __construct(
        StoreCreditManager $storeCreditManager,
        EntityRepository $orderRepository,
        LoggerInterface $logger,
        EntityRepository $tagRepository,
        EntityRepository $orderTagRepository
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->orderRepository    = $orderRepository;
        $this->logger             = $logger;
        $this->tagRepository      = $tagRepository;
        $this->orderTagRepository = $orderTagRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.paid' => 'onTransactionPaid',
        ];
    }

    public function onTransactionPaid(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $this->deductForPaidOrder($event);
        } catch (\Throwable $e) {
            $this->logError('Unexpected failure while deducting store credit on transaction paid', $event->getOrder()->getId(), $e);
        }
    }

    private function deductForPaidOrder(OrderStateMachineStateChangeEvent $event): void
    {
        $orderId = $event->getOrder()->getId();
        $context = $event->getContext();

        $customFields = $event->getOrder()->getCustomFields() ?? [];
        if ($customFields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED] ?? false) {
            return;
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer');

        $freshOrder = $this->orderRepository->search($criteria, $context)->first();
        if ($freshOrder === null) {
            return;
        }

        $freshCustomFields = $freshOrder->getCustomFields() ?? [];
        if ($freshCustomFields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED] ?? false) {
            return;
        }

        $amountToDeduct = (float) ($freshCustomFields[StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT] ?? 0.0);
        $currencyId     = $freshCustomFields[StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_CURRENCY] ?? $freshOrder->getCurrencyId();
        $currencyId     = \is_string($currencyId) && $currencyId !== '' ? $currencyId : null;

        if ($amountToDeduct <= 0.0) {
            return;
        }

        $customerId = $freshOrder->getOrderCustomer()?->getCustomerId();
        if ($customerId === null) {
            return;
        }

        $walletCurrency = $this->storeCreditManager->getCreditBalance($customerId, $context)['balanceCurrencyId'];
        if ($walletCurrency !== null) {
            $amountToDeduct = $this->storeCreditManager->toWalletCurrency($amountToDeduct, $currencyId, $walletCurrency);
            $currencyId     = $walletCurrency;
        }

        try {
            $this->storeCreditManager->deductCredit(
                $customerId,
                $amountToDeduct,
                $context,
                $orderId,
                $currencyId,
                'Store credit used for order payment',
                StoreCreditConstants::DEBIT_IDEMPOTENCY_KEY_PREFIX . strtolower($orderId)
            );
        } catch (\Throwable $e) {
            $this->quarantine($orderId, $customerId, $amountToDeduct, $currencyId, $e, $context);

            return;
        }

        $hadFailed = isset($freshCustomFields[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED]);

        try {
            $update = [
                StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED       => true,
                StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT => 0.0,
            ];
            if ($hadFailed) {
                $update[StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED] = null;
            }

            $this->orderRepository->update([['id' => $orderId, 'customFields' => $update]], $context);
        } catch (\Throwable $e) {
            $this->logError('Store credit was deducted but the order flag could not be written; a re-fired paid transition replays as a no-op', $orderId, $e);
        }

        if ($hadFailed) {
            try {
                $this->untagOrder($orderId, $context);
            } catch (\Throwable $e) {
                $this->logError('Failed to remove the store credit review tag from the order', $orderId, $e);
            }
        }

        $this->logger->info('Store credit deducted on transaction paid', [
            'orderId'    => $orderId,
            'customerId' => $customerId,
            'amount'     => $amountToDeduct,
        ]);
    }

    private function quarantine(
        string $orderId,
        string $customerId,
        float $amount,
        ?string $currencyId,
        \Throwable $e,
        Context $context
    ): void {
        $this->logError('Failed to deduct store credit on transaction paid', $orderId, $e, [
            'customerId' => $customerId,
            'amount'     => $amount,
        ]);

        try {
            $this->orderRepository->update([[
                'id'           => $orderId,
                'customFields' => [
                    StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCT_FAILED => [
                        'amount'     => $amount,
                        'currencyId' => $currencyId,
                        'message'    => mb_substr($e->getMessage(), 0, 500),
                        'failedAt'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                            ->format(\DateTimeInterface::ATOM),
                    ],
                ],
            ]], $context);
        } catch (\Throwable $stampError) {
            $this->logError('Failed to record the unsettled store credit deduction on the order', $orderId, $stampError);
        }

        try {
            $this->tagOrder($orderId, $context);
        } catch (\Throwable $tagError) {
            $this->logError('Failed to tag the order for store credit review', $orderId, $tagError);
        }
    }

    private function tagOrder(string $orderId, Context $context): void
    {
        $tagId = Uuid::fromStringToHex(StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG);
        $this->tagRepository->upsert([['id' => $tagId, 'name' => StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG]], $context);

        $this->orderRepository->update([[
            'id'   => $orderId,
            'tags' => [['id' => $tagId]],
        ]], $context);
    }

    private function untagOrder(string $orderId, Context $context): void
    {
        $this->orderTagRepository->delete([[
            'orderId'        => $orderId,
            'orderVersionId' => Defaults::LIVE_VERSION,
            'tagId'          => Uuid::fromStringToHex(StoreCreditConstants::DEDUCT_FAILED_ORDER_TAG),
        ]], $context);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logError(string $message, string $orderId, \Throwable $e, array $extra = []): void
    {
        try {
            $this->logger->error($message, [
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ] + $extra);
        } catch (\Throwable) {
        }
    }
}
