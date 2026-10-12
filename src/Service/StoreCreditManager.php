<?php declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Constants\StoreCreditConstants;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Exception\StoreCreditNotFoundException;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountProviderInterface;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountResolver;
use Solu1StoreCredit\Service\Refund\CardRefundedAmountUnavailableException;
use Solu1StoreCredit\Service\Refund\RefundCapCalculator;

class StoreCreditManager
{
    private readonly CardRefundedAmountResolver $cardRefundedResolver;

    private readonly RefundCapCalculator $capCalculator;

    /**
     * @param iterable<CardRefundedAmountProviderInterface> $cardRefundedProviders
     */
    public function __construct(
        private readonly EntityRepository $storeCreditRepository,
        private readonly EntityRepository $storeCreditHistoryRepository,
        private readonly Connection $connection,
        iterable $cardRefundedProviders = [],
        private readonly ?SystemConfigService $systemConfigService = null,
    ) {
        $this->cardRefundedResolver = new CardRefundedAmountResolver($cardRefundedProviders);
        $this->capCalculator = new RefundCapCalculator();
    }

    public function addCredit(string $customerId, float $amount, Context $context, ?string $orderId = null, ?string $currencyId = null, ?string $reason = null, ?string $operationKey = null): string
    {
        return $this->changeCredit($customerId, $amount, $context, $orderId, $currencyId, $reason, 'add', $operationKey);
    }

    public function deductCredit(string $customerId, float $amount, Context $context, ?string $orderId = null, ?string $currencyId = null, ?string $reason = null, ?string $operationKey = null): string
    {
        return $this->changeCredit($customerId, $amount, $context, $orderId, $currencyId, $reason, 'deduct', $operationKey);
    }

    /** The customer row also serializes the creation of the first wallet. */
    public function withCustomerLock(string $customerId, callable $operation): mixed
    {
        self::validateId($customerId, 'Customer');

        return $this->connection->transactional(function () use ($customerId, $operation): mixed {
            if (!$this->connection->fetchOne('SELECT id FROM customer WHERE id = :id FOR UPDATE', ['id' => Uuid::fromHexToBytes($customerId)])) {
                throw new \InvalidArgumentException('Customer not found.');
            }

            return $operation();
        });
    }

    public function getStoreCreditEntity(string $customerId, Context $context): ?StoreCreditEntity
    {
        self::validateId($customerId, 'Customer');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $customerId))->setLimit(2);
        $result = $this->storeCreditRepository->search($criteria, $context);
        if ($result->getEntities()->count() > 1) {
            throw new \RuntimeException('Multiple store credit accounts exist for this customer. Reconcile them before using store credit.');
        }
        /** @var StoreCreditEntity|null $credit */
        $credit = $result->getEntities()->first();

        return $credit;
    }

    public function getStoreCreditId(string $customerId, Context $context): ?string
    {
        return $this->getStoreCreditEntity($customerId, $context)?->getId();
    }

    /** @return array{balanceAmount: float, currencyId: string} */
    public function getCreditBalance(string $customerId, Context $context): array
    {
        $credit = $this->getStoreCreditEntity($customerId, $context);

        return ['balanceAmount' => $credit?->getBalance() ?? 0.0, 'currencyId' => $credit?->getCurrencyId() ?? Defaults::CURRENCY];
    }

    /**
     * @return array{balanceAmount: float, currencyId: string}
     */
    public function getAvailableCreditBalance(string $customerId, Context $context): array
    {
        $breakdown = $this->getBalanceBreakdown($customerId, $context);

        return ['balanceAmount' => $breakdown['available'], 'currencyId' => $breakdown['currencyId']];
    }

    /**
     * @return array{
     *     balance: float, reserved: float, available: float, currencyId: string,
     *     reservedOrders: list<array{orderId: string, orderNumber: string, orderDateTime: string, amount: float}>,
     *     expiredOrders: list<array{orderId: string, orderNumber: string, orderDateTime: string, amount: float}>
     * }
     */
    public function getBalanceBreakdown(string $customerId, Context $context): array
    {
        $balance = $this->getCreditBalance($customerId, $context);
        $rawBalance = round((float) $balance['balanceAmount'], 2);
        $reservedOrders = [];
        $expiredOrders = [];
        $reserved = 0.0;

        foreach ($this->fetchPendingOrders($customerId) as $row) {
            $order = [
                'orderId' => (string) $row['orderId'],
                'orderNumber' => (string) $row['orderNumber'],
                'orderDateTime' => (new \DateTimeImmutable((string) $row['orderDateTime'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'amount' => round((float) $row['amount'], 2),
            ];
            if ((int) $row['expired'] === 1) {
                $expiredOrders[] = $order;
                continue;
            }
            $reservedOrders[] = $order;
            $reserved += $order['amount'];
        }
        $reserved = round($reserved, 2);

        return [
            'balance' => $rawBalance,
            'reserved' => $reserved,
            'available' => max(0.0, round($rawBalance - $reserved, 2)),
            'currencyId' => $balance['currencyId'],
            'reservedOrders' => $reservedOrders,
            'expiredOrders' => $expiredOrders,
        ];
    }

    public function getPendingReservationMaxAgeDays(): int
    {
        $days = $this->systemConfigService?->get(StoreCreditConstants::CONFIG_PENDING_RESERVATION_MAX_AGE_DAYS);

        return is_numeric($days) ? max(0, (int) $days) : StoreCreditConstants::PENDING_RESERVATION_MAX_AGE_DAYS_DEFAULT;
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function creditForReturn(
        string $customerId,
        string $orderId,
        float $requestedAmount,
        string $returnId,
        Context $context,
        ?string $currencyId = null,
        ?string $reason = null
    ): StoreCreditRefundResult {
        self::validateId($orderId, 'Order');
        self::validateId($returnId, 'Return');
        $requestedAmount = round($requestedAmount, 2);
        $operationKey = StoreCreditConstants::REFUND_OPERATION_KEY_PREFIX . strtolower($returnId);

        return $this->connection->transactional(function () use ($customerId, $orderId, $requestedAmount, $returnId, $context, $currencyId, $reason, $operationKey): StoreCreditRefundResult {
            $this->connection->fetchOne('SELECT id FROM `order` WHERE id = :id AND version_id = :version FOR UPDATE', [
                'id' => Uuid::fromHexToBytes($orderId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]);

            $existing = $this->findReturnCreditHistoryId($returnId);
            if ($existing !== null) {
                return new StoreCreditRefundResult($existing, 0.0, $requestedAmount, true);
            }

            $creditedAmount = round(min($requestedAmount, $this->getRefundableRemainder($orderId)), 2);
            if ($creditedAmount < 0.01) {
                return new StoreCreditRefundResult(null, 0.0, $requestedAmount, false, true);
            }

            $historyId = $this->addCredit($customerId, $creditedAmount, $context, $orderId, $currencyId, $reason, $operationKey);

            return new StoreCreditRefundResult($historyId, $creditedAmount, $requestedAmount, false, $creditedAmount < $requestedAmount);
        });
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function getRefundableRemainderForOrder(string $orderId): float
    {
        self::validateId($orderId, 'Order');

        return $this->getRefundableRemainder($orderId);
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function getCardReleasedForOrder(string $orderId): float
    {
        return Uuid::isValid($orderId) ? $this->cardRefundedResolver->resolveReleased($orderId) : 0.0;
    }

    public function findReturnCreditHistoryId(string $returnId): ?string
    {
        if (!Uuid::isValid($returnId)) {
            return null;
        }

        $id = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM solu1_store_credit_history WHERE operation_key = :key', [
            'key' => StoreCreditConstants::REFUND_OPERATION_KEY_PREFIX . strtolower($returnId),
        ]);

        return $id === false ? null : (string) $id;
    }

    public static function validateId(string $id, string $label): void
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException($label . ' ID must be a 32-character hexadecimal UUID.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPendingOrders(string $customerId): array
    {
        $pendingExpr = sprintf("JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.\"%s\"'))", StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT);
        $deductedExpr = sprintf("JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.\"%s\"'))", StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED);
        $parameters = [
            'customerId' => Uuid::fromHexToBytes($customerId),
            'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'cancelled' => OrderStates::STATE_CANCELLED,
        ];
        $expiredExpr = '0';
        $maxAgeDays = $this->getPendingReservationMaxAgeDays();
        if ($maxAgeDays > 0) {
            $expiredExpr = 'CASE WHEN o.order_date_time < :cutoff THEN 1 ELSE 0 END';
            $parameters['cutoff'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(sprintf('-%d days', $maxAgeDays))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        return $this->connection->fetchAllAssociative(sprintf(
            "SELECT LOWER(HEX(o.id)) AS orderId, o.order_number AS orderNumber, o.order_date_time AS orderDateTime,
                    CAST(%1\$s AS DECIMAL(10,2)) AS amount, %3\$s AS expired
             FROM `order` o
             INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
             INNER JOIN state_machine_state s ON s.id = o.state_id
             WHERE oc.customer_id = :customerId AND o.version_id = :version AND s.technical_name != :cancelled
               AND %1\$s IS NOT NULL AND CAST(%1\$s AS DECIMAL(10,2)) > 0
               AND LOWER(COALESCE(%2\$s, 'false')) NOT IN ('true', '1')
             ORDER BY o.order_date_time ASC",
            $pendingExpr,
            $deductedExpr,
            $expiredExpr
        ), $parameters);
    }

    private function getRefundableRemainder(string $orderId): float
    {
        $orderIdBytes = Uuid::fromHexToBytes($orderId);
        $liveVersion = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $amountTotal = $this->connection->fetchOne('SELECT amount_total FROM `order` WHERE id = :id AND version_id = :version', [
            'id' => $orderIdBytes, 'version' => $liveVersion,
        ]);
        if ($amountTotal === false) {
            return 0.0;
        }

        $creditTendered = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(ABS(total_price)), 0) FROM order_line_item
             WHERE order_id = :id AND order_version_id = :version
               AND (identifier = :identifier OR referenced_id = :identifier OR (type = :type AND label = :label))',
            [
                'id' => $orderIdBytes, 'version' => $liveVersion, 'identifier' => StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID,
                'type' => LineItem::CREDIT_LINE_ITEM_TYPE, 'label' => StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL,
            ]
        );

        $conditions = ['operation_key LIKE :keyPrefix', "reason LIKE 'Return % refunded as store credit'"];
        $parameters = [
            'id' => $orderIdBytes, 'action' => RefundCapCalculator::REFUND_ACTION_TYPE,
            'keyPrefix' => StoreCreditConstants::REFUND_OPERATION_KEY_PREFIX . '%',
        ];
        foreach (StoreCreditConstants::LEGACY_REFUND_REASON_PREFIXES as $i => $prefix) {
            $conditions[] = 'reason LIKE :legacyReason' . $i;
            $parameters['legacyReason' . $i] = $prefix . '%';
        }
        if (isset($this->connection->createSchemaManager()->listTableColumns('solu1_store_credit_history')['idempotency_key'])) {
            $conditions[] = "idempotency_key LIKE 'credit:return:%'";
        }

        $alreadyCredited = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM solu1_store_credit_history
             WHERE order_id = :id AND action_type = :action AND (' . implode(' OR ', $conditions) . ')',
            $parameters
        );

        return $this->capCalculator->remainder(
            (float) $amountTotal,
            $creditTendered,
            $alreadyCredited,
            $this->cardRefundedResolver->resolve($orderId)
        );
    }

    private function changeCredit(string $customerId, float $amount, Context $context, ?string $orderId, ?string $currencyId, ?string $reason, string $action, ?string $operationKey): string
    {
        if (!is_finite($amount) || $amount <= 0 || $amount > 99999999.99 || abs($amount - round($amount, 2)) > 0.0000001) {
            throw new \InvalidArgumentException('Amount must be positive, within 99999999.99, and have at most two decimal places.');
        }
        $amount = round($amount, 2);
        if ($amount < 0.01) {
            throw new \InvalidArgumentException('Amount must be at least 0.01.');
        }
        if ($orderId !== null) {
            self::validateId($orderId, 'Order');
        }
        if ($currencyId !== null) {
            self::validateId($currencyId, 'Currency');
        }
        if ($reason !== null && mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('Reason must contain at most 255 characters.');
        }
        if ($operationKey !== null && (strlen($operationKey) > 100 || $operationKey === '')) {
            throw new \InvalidArgumentException('Invalid operation key.');
        }

        return $this->withCustomerLock($customerId, function () use ($customerId, $amount, $context, $orderId, $currencyId, $reason, $action, $operationKey): string {
            // Locking reads see the latest committed balance even within a repeatable-read transaction.
            $credit = $this->connection->fetchAssociative('SELECT LOWER(HEX(id)) AS id, LOWER(HEX(currency_id)) AS currency_id, balance FROM solu1_store_credit WHERE customer_id = :id FOR UPDATE', ['id' => Uuid::fromHexToBytes($customerId)]);
            $walletCurrency = $credit ? ($credit['currency_id'] ?: Defaults::CURRENCY) : ($currencyId ?? Defaults::CURRENCY);
            $currencyId ??= $walletCurrency;
            if ($currencyId !== $walletCurrency) {
                throw new \InvalidArgumentException('Store credit currency does not match the requested currency.');
            }
            if (!$this->connection->fetchOne('SELECT id FROM currency WHERE id = :id', ['id' => Uuid::fromHexToBytes($currencyId)])) {
                throw new \InvalidArgumentException('Currency not found.');
            }
            if ($orderId !== null && !$this->connection->fetchOne('SELECT id FROM order_customer WHERE order_id = :orderId AND customer_id = :customerId AND order_version_id = :version', ['orderId' => Uuid::fromHexToBytes($orderId), 'customerId' => Uuid::fromHexToBytes($customerId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)])) {
                throw new \InvalidArgumentException('Order does not belong to this customer.');
            }
            if ($operationKey !== null) {
                $history = $this->connection->fetchAssociative('SELECT LOWER(HEX(id)) AS id, LOWER(HEX(store_credit_id)) AS credit_id, amount, action_type FROM solu1_store_credit_history WHERE operation_key = :key FOR UPDATE', ['key' => $operationKey]);
                if ($history) {
                    if (!$credit || $history['credit_id'] !== $credit['id'] || (float) $history['amount'] !== $amount || $history['action_type'] !== $action) {
                        throw new \InvalidArgumentException('This operation was already processed with different credit details.');
                    }
                    return $history['id'];
                }
            }
            if (!$credit && $action === 'deduct') {
                throw new StoreCreditNotFoundException();
            }
            $balanceCents = (int) round((float) ($credit['balance'] ?? 0) * 100);
            $amountCents = (int) round($amount * 100);
            if ($action === 'deduct' && $balanceCents < $amountCents) {
                throw new InsufficientCreditException();
            }
            $nextBalance = ($balanceCents + ($action === 'add' ? $amountCents : -$amountCents)) / 100;
            if ($nextBalance > 99999999.99) {
                throw new \InvalidArgumentException('The resulting balance exceeds 99999999.99.');
            }
            $id = $credit['id'] ?? Uuid::randomHex();
            $historyId = Uuid::randomHex();
            $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($customerId, $id, $nextBalance, $currencyId, $historyId, $orderId, $amount, $reason, $action, $operationKey): void {
                $this->storeCreditRepository->upsert([['id' => $id, 'customerId' => $customerId, 'currencyId' => $currencyId, 'balance' => $nextBalance]], $context);
                $this->storeCreditHistoryRepository->create([[
                    'id' => $historyId, 'storeCreditId' => $id, 'orderId' => $orderId, 'orderVersionId' => $orderId !== null ? Defaults::LIVE_VERSION : null, 'currencyId' => $currencyId,
                    'amount' => $amount, 'reason' => $reason ?: 'Not specified', 'actionType' => $action, 'operationKey' => $operationKey,
                ]], $context);
            });

            return $historyId;
        });
    }
}
