<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartSubscriber implements EventSubscriberInterface
{
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
    private EntityRepository $orderRepository;
    private LoggerInterface $logger;
    private ?StoreCreditManager $storeCreditManager;
    private ?StoreCreditCurrencyConverter $currencyConverter;

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        EntityRepository $orderRepository,
        LoggerInterface $logger,
        ?StoreCreditManager $storeCreditManager = null,
        ?StoreCreditCurrencyConverter $currencyConverter = null
    ) {
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->storeCreditManager = $storeCreditManager;
        $this->currencyConverter = $currencyConverter;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function toWalletCurrency(string $customerId, float $amount, string $orderCurrencyId, Context $context): array
    {
        if ($this->storeCreditManager === null || $this->currencyConverter === null) {
            return [$amount, $orderCurrencyId];
        }

        $walletCurrency = $this->storeCreditManager->getCreditBalance($customerId, $context)['balanceCurrencyId'];
        if ($walletCurrency === null || $walletCurrency === strtolower($orderCurrencyId)) {
            return [$amount, $orderCurrencyId];
        }

        $converted = $this->currencyConverter->convertRoundingUp($amount, $orderCurrencyId, $walletCurrency);
        if ($converted === null) {
            return [$amount, $orderCurrencyId];
        }

        return [$converted, $walletCurrency];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order      = $event->getOrder();
        $orderCustomer = $order->getOrderCustomer();
        $customerId = $orderCustomer?->getCustomerId();
        if ($customerId === null) {
            return;
        }

        $orderId = $order->getId();

        $orderLineItems = $order->getLineItems();
        if ($orderLineItems === null) {
            return;
        }

        $storeCreditLineItem = $orderLineItems->filter(function ($lineItem) {
            return ($lineItem->getReferencedId() === StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID
                || ($lineItem->getType() === LineItem::CREDIT_LINE_ITEM_TYPE
                    && $lineItem->getLabel() === StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL));
        })->first();

        if (!$storeCreditLineItem) {
            return;
        }

        [$amount, $currencyId] = $this->toWalletCurrency(
            $customerId,
            abs((float) $storeCreditLineItem->getTotalPrice()),
            $order->getCurrencyId(),
            $event->getContext()
        );

        try {
            $this->orderRepository->update([
                [
                    'id'           => $orderId,
                    'customFields' => [
                        StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT   => $amount,
                        StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_CURRENCY => $currencyId,
                    ],
                ]
            ], $event->getContext());
        } catch (\Exception $e) {
            $this->logger->error('Failed to store pending store credit deduction for order', [
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
                'customerId' => $customerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
