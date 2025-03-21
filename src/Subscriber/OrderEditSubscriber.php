<?php

declare(strict_types=1);

namespace StoreCredit\Subscriber;

use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use StoreCredit\Core\Content\OrderRecreated\Event\OrderRecreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;

class OrderEditSubscriber implements EventSubscriberInterface
{
    private EntityRepository $orderRepository;
    private EventDispatcherInterface $eventDispatcher;
    private StateMachineRegistry $stateMachineRegistry;
    private SystemConfigService $systemConfigService;
    private NumberRangeValueGeneratorInterface $numberRangeValueGenerator;


    public function __construct(
        EntityRepository $orderRepository,
        StateMachineRegistry $stateMachineRegistry,
        EventDispatcherInterface $eventDispatcher,
        SystemConfigService $systemConfigService,
        NumberRangeValueGeneratorInterface $numberRangeValueGenerator
    ) {
        $this->orderRepository           = $orderRepository;
        $this->stateMachineRegistry      = $stateMachineRegistry;
        $this->eventDispatcher           = $eventDispatcher;
        $this->systemConfigService       = $systemConfigService;
        $this->numberRangeValueGenerator = $numberRangeValueGenerator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $postPurchaesEnabled = $this->systemConfigService->get('StoreCredit.config.postPurchaseFeatures');

        if ($event->getContext()->getSource()->type !== 'admin-api') {
            return;
        }
        if ($postPurchaesEnabled === false) {
            return;
        }

        foreach ($event->getWriteResults() as $writeResult) {
            if (!$writeResult instanceof EntityWriteResult || $writeResult->getEntityName() !== 'order') {
                continue;
            }


            $orderId = $writeResult->getPrimaryKey();



            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('transactions.paymentMethod');
            $criteria->addAssociation('transactions.stateMachineState');
            $criteria->addAssociation('transactions.stateMachineState.stateMachine');
            $criteria->addAssociation('deliveries');
            $criteria->addAssociation('deliveries.shippingOrderAddress');
            $criteria->addAssociation('stateMachineState');
            $criteria->addAssociation('lineItems');

            $order = $this->orderRepository->search($criteria, $event->getContext())->last();
            if (!$order instanceof OrderEntity) {
                continue;
            }
            $orderNumber = $order->getOrderNumber();
            if (str_ends_with($orderNumber, '-R')) {
                return;
            }

            $stateTechnicalName = $order->getStateMachineState()->getTechnicalName();

            if (!$stateTechnicalName && $stateTechnicalName !== "open") {
                return;
            }

            $this->updateOrderTypePayment($order, $event->getContext());
        }
    }

    /**
     * @throws \JsonException
     */
    private function updateOrderTypePayment(OrderEntity $order, Context $context): void
    {
        $transaction        = $order->getTransactions()->first();
        $paymentMethod      = $transaction?->getPaymentMethod();
        $orderTypePayment   = 'other';
        $paymentStatus      = $transaction?->getStateMachineState()?->getTechnicalName();
        $stateTechnicalName = $order->getStateMachineState()?->getTechnicalName();
        $isAchOrWire        = false;
        $zipCodeChanged     = false;

        $customFields = $order->getCustomFields() ?? [];
        if (isset($customFields['zipCodeChangeHandled']) && $customFields['zipCodeChangeHandled'] === true) {
            return;
        }
        if ($paymentMethod) {
            $shortName = $paymentMethod->getShortName();
            if ($shortName === 'credit_card') {
                $orderTypePayment = 'credit_card';
            } elseif ($shortName === 'ach_echeck' && $stateTechnicalName === 'open') {
                $orderTypePayment = 'ach_echeck';
                if (isset($customFields['zipCodeChanged']) && $customFields['zipCodeChanged'] === true) {
                    $zipCodeChanged = true;
                }
                $isAchOrWire = true;
            }
        }


        $this->eventDispatcher->removeSubscriber($this);
        if (!isset($customFields['orderTypePayment']) || $customFields['orderTypePayment'] !== $orderTypePayment) {
            $customFields['orderTypePayment'] = $orderTypePayment;

            $this->orderRepository->update([
                [
                    'id'           => $order->getId(),
                    'customFields' => $customFields
                ]
            ], $context);
        }


        if ($isAchOrWire && $zipCodeChanged) {
            $this->handleZipCodeChange($order, $context);
        }
    }

    private function handleZipCodeChange(OrderEntity $order, Context $context): void
    {
        $nextOrderNumber = $this->numberRangeValueGenerator->getValue(
            'order',
            $context,
            $order->getSalesChannelId()
        );
        $mappedDeliveries                      = $order->getDeliveries()->getElements();
        $deliveryStateId                       = reset($mappedDeliveries)->getStateId();
        $customFields                          = $order->getCustomFields() ?? [];
        $customFields['zipCodeChangedHandled'] = true;
        $this->orderRepository->update([
            [
                'id'           => $order->getId(),
                'customFields' => $customFields
            ]
        ], $context);
        $orderId     = $order->getId();
        $transaction = $order->getTransactions()->first();
        $this->stateMachineRegistry->transition(
            new Transition(
                'order',
                $orderId,
                'cancel',
                'stateId'
            ),
            $context
        );
        $newOrderData = [
            'id'               => Uuid::randomHex(),
            'orderNumber'      => $nextOrderNumber,
            'stateId'          => $order->getStateId(),
            'currencyId'       => $order->getCurrencyId(),
            'currencyFactor'   => $order->getCurrencyFactor(),
            'salesChannelId'   => $order->getSalesChannelId(),
            'orderDateTime'    => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'billingAddressId' => $order->getBillingAddressId(),
            'price'            => $order->getPrice(),
            'shippingCosts'    => $order->getShippingCosts(),
            'itemRounding'     => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding'    => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'deliveries'       => [
                [
                'stateId'              => $deliveryStateId,
                "shippingMethodId"     => reset($mappedDeliveries)->getShippingMethodId(),
                "shippingOrderAddress" => [
                    'id'        => reset($mappedDeliveries)->getShippingOrderAddressId(),
                    'firstName' => reset($mappedDeliveries)->getShippingOrderAddress()->getFirstName(),
                    'lastName'  => reset($mappedDeliveries)->getShippingOrderAddress()->getLastName(),
                    'zipcode'   => reset($mappedDeliveries)->getShippingOrderAddress()->getZipcode(),
                    'city'      => reset($mappedDeliveries)->getShippingOrderAddress()->getCity(),
                    'street'    => reset($mappedDeliveries)->getShippingOrderAddress()->getStreet(),
                    'countryId' => reset($mappedDeliveries)->getShippingOrderAddress()->getCountryId(),
                ],
                "shippingDateEarliest" => reset($mappedDeliveries)->getShippingDateEarliest(),
                "shippingDateLatest"   => reset($mappedDeliveries)->getShippingDateLatest(),
                "shippingCosts"        => [
                    "unitPrice"       => 0,
                    "totalPrice"      => 0,
                    "quantity"        => 1,
                    "calculatedTaxes" => [],
                    "taxRules"        => []
                ]
                ],
            ],
            'transactions' => [
                [
                'paymentMethodId' => $order->getTransactions()->first()->getPaymentMethodId(),
                    'stateId'     => $order->getTransactions()->first()->getStateId(),
                    'amount'      => $order->getTransactions()->first()->getAmount(),
                    ],
            ],
            'customerId'    => $order->getOrderCustomer()->getCustomerId(),
            'orderCustomer' => [
                'id'         => Uuid::randomHex(),
                'email'      => $order->getOrderCustomer()->getEmail(),
                'firstName'  => $order->getOrderCustomer()->getFirstName(),
                'lastName'   => $order->getOrderCustomer()->getLastName(),
                'customerId' => $order->getOrderCustomer()->getCustomerId(),
            ],
            'lineItems' => [],
        ];
        foreach ($order->getLineItems() as $lineItem) {
            $newOrderData['lineItems'][] = [
                'id'              => Uuid::randomHex(),
                'identifier'      => $lineItem->getIdentifier(),
                'label'           => $lineItem->getLabel(),
                'price'           => $lineItem->getPrice(),
                'productId'       => $lineItem->getProductId(),
                'quantity'        => $lineItem->getQuantity(),
                'unitPrice'       => $lineItem->getUnitPrice(),
                'totalPrice'      => $lineItem->getTotalPrice(),
                'priceDefinition' => $lineItem->getPriceDefinition(),
            ];
        }
        $this->orderRepository->create([$newOrderData], $context);
        $this->stateMachineRegistry->transition(
            new Transition(
                'order_transaction',
                $transaction->getId(),
                'cancel',
                'stateId'
            ),
            $context
        );
        $this->eventDispatcher->dispatch(new OrderRecreatedEvent($context, $orderId, $newOrderData));
    }
}
