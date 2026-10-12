<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
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

/**
 * Service for managing store credit operations.
 *
 * This service handles adding credit to customer accounts, deducting credit,
 * and retrieving credit balances. All operations are performed within database
 * transactions to ensure data consistency.
 */
class StoreCreditManager
{
    /** @var EntityRepository<\Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditCollection> */
    private EntityRepository $storeCreditRepository;
    /** @var EntityRepository<\Solu1StoreCredit\Core\Content\StoreCreditHistory\StoreCreditHistoryCollection> */
    private EntityRepository $storeCreditHistoryRepository;
    private Connection $connection;
    private SystemConfigService $systemConfigService;
    private CardRefundedAmountResolver $cardRefundedResolver;
    private RefundCapCalculator $capCalculator;
    private StoreCreditCurrencyConverter $currencyConverter;

    /**
     * @var array<string, StoreCreditEntity|null>
     */
    private array $entityCache = [];

    /**
     * @param EntityRepository<\Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditCollection> $storeCreditRepository
     * @param EntityRepository<\Solu1StoreCredit\Core\Content\StoreCreditHistory\StoreCreditHistoryCollection> $storeCreditHistoryRepository
     * @param iterable<CardRefundedAmountProviderInterface> $cardRefundedProviders
     */
    public function __construct(
        EntityRepository $storeCreditRepository,
        EntityRepository $storeCreditHistoryRepository,
        Connection $connection,
        SystemConfigService $systemConfigService,
        iterable $cardRefundedProviders = [],
        ?StoreCreditCurrencyConverter $currencyConverter = null,
    ) {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
        $this->connection                   = $connection;
        $this->systemConfigService          = $systemConfigService;
        $this->cardRefundedResolver         = new CardRefundedAmountResolver($cardRefundedProviders);
        $this->capCalculator                = new RefundCapCalculator();
        $this->currencyConverter            = $currencyConverter ?? new StoreCreditCurrencyConverter($connection);
    }

    /**
     * Adds store credit to a customer's account.
     *
     * This method adds the specified amount to the customer's store credit balance.
     * If the customer doesn't have an existing store credit record, one will be created.
     * The operation is performed within a database transaction to ensure atomicity.
     *
     * @param string $customerId The UUID of the customer. Must be a valid UUID.
     * @param float $amount The amount to add. Must be greater than zero and finite.
     *                      Negative amounts are not allowed and will throw InvalidArgumentException.
     * @param Context $context The Shopware context for the operation.
     * @param string|null $orderId Optional order ID associated with this credit addition.
     * @param string|null $currencyId Optional currency ID. If provided, must be a valid UUID and exist in the system.
     *                                If null, the currency from the context will be used.
     * @param string|null $reason Optional reason for adding credit. Defaults to 'Not specified' if null.
     *
     * @return string The UUID of the created store credit history record.
     *
     * @throws \InvalidArgumentException When:
     *   - Customer ID is empty or not a valid UUID
     *   - Amount is less than or equal to zero
     *   - Amount is not finite (NaN or Infinity)
     *   - Currency ID is provided but empty, invalid UUID, or doesn't exist
     *
     * @throws \RuntimeException When database operations fail within the transaction.
     *
     * @see deductCredit() For deducting credit from customer accounts.
     * @see getCreditBalance() For retrieving current credit balance.
     */
    public function addCredit(
        string $customerId,
        float $amount,
        Context $context,
        ?string $orderId,
        ?string $currencyId,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): string {
        $this->validateCustomerId($customerId);
        $this->validateAmount($amount, 'Amount must be greater than zero.');
        $this->validateCurrencyId($currencyId, $context);

        $amount = round($amount, 2);

        $outcome = $this->runInLedgerTransaction(
            fn (): array => $this->writeCredit($customerId, $amount, $context, $orderId, $currencyId, $reason, $idempotencyKey),
            $customerId,
            $idempotencyKey
        );

        return (string) $outcome['historyId'];
    }

    /**
     * Deducts store credit from a customer's account.
     *
     * This method deducts the specified amount from the customer's store credit balance.
     * The operation is performed within a database transaction to ensure atomicity.
     * The customer must have an existing store credit record with sufficient balance.
     *
     * @param string $customerId The UUID of the customer. Must be a valid UUID.
     * @param float $amount The amount to deduct. Must be greater than zero and finite.
     *                      Negative amounts are not allowed and will throw InvalidArgumentException.
     * @param Context $context The Shopware context for the operation.
     * @param string|null $orderId Optional order ID associated with this credit deduction.
     * @param string|null $currencyId Optional currency ID. If provided, must be a valid UUID and exist in the system.
     *                                If null, the currency from the context will be used.
     * @param string|null $reason Optional reason for deducting credit. Defaults to 'Not specified' if null.
     *
     * @return string The UUID of the created store credit history record.
     *
     * @throws \InvalidArgumentException When:
     *   - Customer ID is empty or not a valid UUID
     *   - Amount is less than or equal to zero
     *   - Amount is not finite (NaN or Infinity)
     *   - Currency ID is provided but empty, invalid UUID, or doesn't exist
     *
     * @throws StoreCreditNotFoundException When no store credit record exists for the customer.
     *
     * @throws InsufficientCreditException When the customer's balance is less than the requested deduction amount.
     *
     * @throws \RuntimeException When database operations fail within the transaction.
     *
     * @see addCredit() For adding credit to customer accounts.
     * @see getCreditBalance() For retrieving current credit balance before deduction.
     */
    public function deductCredit(
        string $customerId,
        float $amount,
        Context $context,
        ?string $orderId,
        ?string $currencyId,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): string {
        $this->validateCustomerId($customerId);
        $this->validateAmount($amount, 'Amount must be greater than zero.');
        $this->validateCurrencyId($currencyId, $context);

        $amount = round($amount, 2);

        if ($idempotencyKey !== null) {
            $existingHistoryId = $this->findHistoryIdByIdempotencyKey($idempotencyKey);
            if ($existingHistoryId !== null) {
                return $existingHistoryId;
            }
        }

        $storeCredit = $this->getStoreCreditEntity($customerId, $context);
        if (!$storeCredit) {
            throw new StoreCreditNotFoundException('No store credit found for this customer.');
        }

        $storeCreditId = $storeCredit->getId();

        $outcome = $this->runInLedgerTransaction(
            fn (): array => $this->writeDebit($storeCreditId, $amount, $context, $orderId, $currencyId, $reason, $idempotencyKey),
            $customerId,
            $idempotencyKey
        );

        return (string) $outcome['historyId'];
    }

    /**
     * @param string $returnReference
     * @param string $method
     * @throws \InvalidArgumentException
     * @throws CardRefundedAmountUnavailableException
     */
    public function creditForReturn(
        string $customerId,
        string $orderId,
        float $requestedAmount,
        string $returnReference,
        Context $context,
        ?string $currencyId = null,
        ?string $reason = null,
        string $method = 'store_credit'
    ): StoreCreditRefundResult {
        $this->validateCustomerId($customerId);
        $this->validateAmount($requestedAmount, 'Refund amount must be greater than zero.');
        $this->validateCurrencyId($currencyId, $context);

        if ($returnReference === '') {
            throw new \InvalidArgumentException('A return reference is required to credit a return refund.');
        }

        $requestedAmount = round($requestedAmount, 2);
        $idempotencyKey  = StoreCreditConstants::REFUND_IDEMPOTENCY_KEY_PREFIX . strtolower($returnReference) . ':' . $method;

        $existingHistoryId = $this->findHistoryIdByIdempotencyKey($idempotencyKey);
        if ($existingHistoryId !== null) {
            return new StoreCreditRefundResult($existingHistoryId, 0.0, $requestedAmount, true);
        }

        $outcome = $this->runInLedgerTransaction(
            function () use ($customerId, $orderId, $requestedAmount, $context, $currencyId, $reason, $idempotencyKey): array {
                $this->lockOrder($orderId);

                $remainder      = $this->getRefundableRemainder($orderId);
                $creditedAmount = round(min($requestedAmount, $remainder), 2);

                if ($creditedAmount <= 0.0) {
                    return ['historyId' => null, 'replayed' => false, 'creditedAmount' => 0.0, 'wasCapped' => true];
                }

                $written = $this->writeCredit($customerId, $creditedAmount, $context, $orderId, $currencyId, $reason, $idempotencyKey);

                return $written + [
                    'creditedAmount' => $creditedAmount,
                    'wasCapped'      => $creditedAmount < $requestedAmount,
                ];
            },
            $customerId,
            $idempotencyKey
        );

        if ($outcome['replayed']) {
            return new StoreCreditRefundResult($outcome['historyId'], 0.0, $requestedAmount, true);
        }

        return new StoreCreditRefundResult(
            $outcome['historyId'],
            (float) ($outcome['creditedAmount'] ?? 0.0),
            $requestedAmount,
            false,
            (bool) ($outcome['wasCapped'] ?? false)
        );
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function getRefundableRemainderForOrder(string $orderId): float
    {
        return $this->getRefundableRemainder($orderId);
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function getCardReleasedForOrder(string $orderId): float
    {
        return Uuid::isValid($orderId) ? $this->cardRefundedResolver->resolveReleased($orderId) : 0.0;
    }

    public function findReturnCreditHistoryId(string $returnReference, string $method = 'store_credit'): ?string
    {
        if ($returnReference === '') {
            return null;
        }

        return $this->findHistoryIdByIdempotencyKey(
            StoreCreditConstants::REFUND_IDEMPOTENCY_KEY_PREFIX . strtolower($returnReference) . ':' . $method
        );
    }

    /**
     * Retrieves the store credit record ID for a customer.
     *
     * @param string $customerId The UUID of the customer. Must be a valid UUID.
     * @param Context $context The Shopware context for the operation.
     *
     * @return string|null The UUID of the store credit record, or null if no record exists for the customer.
     *
     * @throws \InvalidArgumentException When customer ID is empty or not a valid UUID.
     */
    public function getStoreCreditId(string $customerId, Context $context): ?string
    {
        $this->validateCustomerId($customerId);

        $storeCredit = $this->getStoreCreditEntity($customerId, $context);

        return $storeCredit ? $storeCredit->getId() : null;
    }

    /**
     * Retrieves the current store credit balance for a customer.
     *
     * Returns the balance amount and currency ID. If no store credit record exists
     * for the customer, returns zero balance and null currency ID.
     *
     * @param string $customerId The UUID of the customer. Must be a valid UUID.
     * @param Context $context The Shopware context for the operation.
     *
     * @return array{balanceAmount: float, balanceCurrencyId: ?string} An array containing:
     *   - balanceAmount: The current credit balance (0.0 if no record exists)
     *   - balanceCurrencyId: The currency ID associated with the balance (null if no record exists)
     *
     * @throws \InvalidArgumentException When customer ID is empty or not a valid UUID.
     */
    public function getCreditBalance(string $customerId, Context $context): array
    {
        $this->validateCustomerId($customerId);

        $storeCredit = $this->getStoreCreditEntity($customerId, $context);

        return [
            'balanceAmount'     => $storeCredit ? $storeCredit->getBalance() : 0.0,
            'balanceCurrencyId' => $storeCredit ? StoreCreditCurrencyConverter::walletCurrency($storeCredit->getCurrencyId()) : null,
        ];
    }

    /**
     * Retrieves the credit balance still available to apply to a new order.
     *
     * The raw balance (see getCreditBalance()) is only reduced once an order's
     * payment is confirmed. Between placing an order and it being paid, the raw
     * balance still reflects the un-deducted amount, so it alone is not a safe
     * limit for a second order placed in that window. This subtracts whatever
     * is already committed to the customer's other placed-but-not-yet-paid
     * orders, so the same balance cannot be applied to more than one order at once.
     *
     * @param string $customerId The UUID of the customer. Must be a valid UUID.
     * @param Context $context The Shopware context for the operation.
     *
     * @return array{balanceAmount: float, balanceCurrencyId: ?string}
     *
     * @throws \InvalidArgumentException When customer ID is empty or not a valid UUID.
     */
    public function getAvailableCreditBalance(string $customerId, Context $context): array
    {
        $breakdown = $this->getBalanceBreakdown($customerId, $context);

        return [
            'balanceAmount'     => $breakdown['available'],
            'balanceCurrencyId' => $breakdown['currencyId'],
        ];
    }

    /**
     * @return array{
     *     balance: float, reserved: float, available: float, currencyId: ?string,
     *     reservedOrders: list<array{orderId: string, orderNumber: string, orderDateTime: string, amount: float}>,
     *     expiredOrders: list<array{orderId: string, orderNumber: string, orderDateTime: string, amount: float}>
     * }
     * @throws \InvalidArgumentException
     */
    public function getBalanceBreakdown(string $customerId, Context $context): array
    {
        $balance    = $this->getCreditBalance($customerId, $context);
        $rawBalance = round((float) $balance['balanceAmount'], 2);

        $reservedOrders = [];
        $expiredOrders  = [];
        $reserved       = 0.0;

        $walletCurrency = StoreCreditCurrencyConverter::walletCurrency($balance['balanceCurrencyId']);

        foreach ($this->fetchPendingOrders($customerId) as $row) {
            $order = [
                'orderId'       => (string) $row['orderId'],
                'orderNumber'   => (string) $row['orderNumber'],
                'orderDateTime' => (new \DateTimeImmutable((string) $row['orderDateTime'], new \DateTimeZone('UTC')))
                    ->format(\DateTimeInterface::ATOM),
                'amount'        => $this->toWalletCurrency((float) $row['amount'], $row['currencyId'] ?? null, $walletCurrency),
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
            'balance'        => $rawBalance,
            'reserved'       => $reserved,
            'available'      => max(0.0, round($rawBalance - $reserved, 2)),
            'currencyId'     => $balance['balanceCurrencyId'],
            'reservedOrders' => $reservedOrders,
            'expiredOrders'  => $expiredOrders,
        ];
    }

    public function toWalletCurrency(float $amount, mixed $stampedCurrencyId, string $walletCurrencyId): float
    {
        $stamped = \is_string($stampedCurrencyId) ? StoreCreditCurrencyConverter::normalize($stampedCurrencyId) : null;
        if ($stamped === null || $stamped === $walletCurrencyId) {
            return round($amount, 2);
        }

        return $this->currencyConverter->convertRoundingUp($amount, $stamped, $walletCurrencyId) ?? round($amount, 2);
    }

    public function getPendingReservationMaxAgeDays(): int
    {
        $days = $this->systemConfigService->get(StoreCreditConstants::CONFIG_PENDING_RESERVATION_MAX_AGE_DAYS);

        if ($days === null || $days === '' || !is_numeric($days)) {
            return StoreCreditConstants::PENDING_RESERVATION_MAX_AGE_DAYS_DEFAULT;
        }

        return max(0, (int) $days);
    }

    /**
     * @param \Closure(): array{historyId: ?string, replayed: bool, creditedAmount?: float, wasCapped?: bool} $write
     * @return array{historyId: ?string, replayed: bool, creditedAmount?: float, wasCapped?: bool}
     */
    private function runInLedgerTransaction(\Closure $write, string $customerId, ?string $idempotencyKey): array
    {
        try {
            /** @var array{historyId: ?string, replayed: bool, creditedAmount?: float, wasCapped?: bool} $result */
            $result = $this->connection->transactional($write);
        } catch (\Throwable $e) {
            if ($idempotencyKey === null || !$this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            $existingHistoryId = $this->findHistoryIdByIdempotencyKey($idempotencyKey);
            if ($existingHistoryId === null) {
                throw $e;
            }

            $this->invalidateEntityCache($customerId);

            return ['historyId' => $existingHistoryId, 'replayed' => true];
        }

        $this->invalidateEntityCache($customerId);

        return $result;
    }

    /**
     * @return array{historyId: string, replayed: bool}
     */
    private function writeCredit(
        string $customerId,
        float $amount,
        Context $context,
        ?string $orderId,
        ?string $currencyId,
        ?string $reason,
        ?string $idempotencyKey
    ): array {
        if ($idempotencyKey !== null) {
            $existingHistoryId = $this->findHistoryIdByIdempotencyKey($idempotencyKey);
            if ($existingHistoryId !== null) {
                return ['historyId' => $existingHistoryId, 'replayed' => true];
            }
        }

        $row = $this->connection->fetchAssociative(
            'SELECT `id`, LOWER(HEX(`currency_id`)) AS `currencyId` FROM `solu1_store_credit`
             WHERE `customer_id` = :customerId
             ORDER BY `created_at` ASC
             LIMIT 1
             FOR UPDATE',
            ['customerId' => Uuid::fromHexToBytes($customerId)]
        );

        if ($row !== false) {
            $storeCreditId = Uuid::fromBytesToHex((string) $row['id']);
            $currencyId    = $this->assertWalletCurrency($row['currencyId'] ?? null, $currencyId);

            $this->connection->executeStatement(
                'UPDATE `solu1_store_credit`
                 SET `balance` = `balance` + :amount, `updated_at` = NOW(3)
                 WHERE `id` = :id',
                [
                    'amount' => $amount,
                    'id'     => $row['id'],
                ]
            );
        } else {
            $storeCreditId = Uuid::randomHex();
            $currencyId    = StoreCreditCurrencyConverter::walletCurrency($currencyId);
            $currencyBytes = Uuid::fromHexToBytes($currencyId);

            $this->connection->executeStatement(
                'INSERT INTO `solu1_store_credit` (`id`, `customer_id`, `balance`, `currency_id`, `created_at`)
                 VALUES (:id, :customerId, :amount, :currencyId, NOW(3))',
                [
                    'id'         => Uuid::fromHexToBytes($storeCreditId),
                    'customerId' => Uuid::fromHexToBytes($customerId),
                    'amount'     => $amount,
                    'currencyId' => $currencyBytes,
                ]
            );
        }

        $historyId = $this->writeHistory($storeCreditId, 'add', $amount, $context, $orderId, $currencyId, $reason, $idempotencyKey);

        return ['historyId' => $historyId, 'replayed' => false];
    }

    /**
     * @return array{historyId: string, replayed: bool}
     */
    private function writeDebit(
        string $storeCreditId,
        float $amount,
        Context $context,
        ?string $orderId,
        ?string $currencyId,
        ?string $reason,
        ?string $idempotencyKey
    ): array {
        if ($idempotencyKey !== null) {
            $existingHistoryId = $this->findHistoryIdByIdempotencyKey($idempotencyKey);
            if ($existingHistoryId !== null) {
                return ['historyId' => $existingHistoryId, 'replayed' => true];
            }
        }

        $walletCurrency = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`currency_id`)) FROM `solu1_store_credit` WHERE `id` = :id FOR UPDATE',
            ['id' => Uuid::fromHexToBytes($storeCreditId)]
        );
        $currencyId = $this->assertWalletCurrency(\is_string($walletCurrency) ? $walletCurrency : null, $currencyId);

        $affected = $this->connection->executeStatement(
            'UPDATE `solu1_store_credit`
             SET `balance` = `balance` - :amount, `updated_at` = NOW(3)
             WHERE `id` = :id AND `balance` >= :amount',
            [
                'amount' => $amount,
                'id'     => Uuid::fromHexToBytes($storeCreditId),
            ]
        );

        if ($affected === 0) {
            throw new InsufficientCreditException(sprintf('Insufficient store credit balance. Requested: %.2f', $amount));
        }

        $historyId = $this->writeHistory($storeCreditId, 'deduct', $amount, $context, $orderId, $currencyId, $reason, $idempotencyKey);

        return ['historyId' => $historyId, 'replayed' => false];
    }

    private function assertWalletCurrency(?string $storedCurrencyId, ?string $requestedCurrencyId): string
    {
        $walletCurrency = StoreCreditCurrencyConverter::walletCurrency($storedCurrencyId);
        $requested      = StoreCreditCurrencyConverter::normalize($requestedCurrencyId);

        if ($requested !== null && $requested !== $walletCurrency) {
            throw new \InvalidArgumentException('Store credit currency does not match the requested currency.');
        }

        return $walletCurrency;
    }

    private function writeHistory(
        string $storeCreditId,
        string $actionType,
        float $amount,
        Context $context,
        ?string $orderId,
        ?string $currencyId,
        ?string $reason,
        ?string $idempotencyKey
    ): string {
        $historyId = Uuid::randomHex();

        $this->storeCreditHistoryRepository->create([
            [
                'id'             => $historyId,
                'storeCreditId'  => $storeCreditId,
                'orderId'        => $orderId,
                'amount'         => $amount,
                'currencyId'     => $currencyId,
                'reason'         => $reason ?: 'Not specified',
                'actionType'     => $actionType,
                'idempotencyKey' => $idempotencyKey,
            ],
        ], $context);

        return $historyId;
    }

    private function lockOrder(string $orderId): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $this->connection->fetchOne(
            'SELECT `id` FROM `order` WHERE `id` = :orderId AND `version_id` = :versionId FOR UPDATE',
            [
                'orderId'   => Uuid::fromHexToBytes($orderId),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        );
    }

    private function getRefundableRemainder(string $orderId): float
    {
        if (!Uuid::isValid($orderId)) {
            return 0.0;
        }

        $orderIdBytes  = Uuid::fromHexToBytes($orderId);
        $liveVersionId = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $amountTotal = $this->connection->fetchOne(
            'SELECT `amount_total` FROM `order` WHERE `id` = :orderId AND `version_id` = :versionId',
            ['orderId' => $orderIdBytes, 'versionId' => $liveVersionId]
        );

        if ($amountTotal === false) {
            return 0.0;
        }

        $creditTendered = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(ABS(`total_price`)), 0) FROM `order_line_item`
             WHERE `order_id` = :orderId
               AND `order_version_id` = :versionId
               AND (`identifier` = :identifier OR (`type` = :type AND `label` = :label))',
            [
                'orderId'    => $orderIdBytes,
                'versionId'  => $liveVersionId,
                'identifier' => StoreCreditConstants::STORE_CREDIT_LINE_ITEM_ID,
                'type'       => LineItem::CREDIT_LINE_ITEM_TYPE,
                'label'      => StoreCreditConstants::STORE_CREDIT_DISCOUNT_LABEL,
            ]
        );

        $alreadyCredited = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(`amount`), 0) FROM `solu1_store_credit_history`
             WHERE `order_id` = :orderId
               AND `action_type` = :actionType
               AND (`idempotency_key` LIKE :keyPrefix OR `reason` LIKE :legacyReason)',
            [
                'orderId'      => $orderIdBytes,
                'actionType'   => RefundCapCalculator::REFUND_ACTION_TYPE,
                'keyPrefix'    => StoreCreditConstants::REFUND_IDEMPOTENCY_KEY_PREFIX . '%',
                'legacyReason' => StoreCreditConstants::LEGACY_REFUND_REASON_PREFIX . '%',
            ]
        );

        return $this->capCalculator->remainder(
            (float) $amountTotal,
            $creditTendered,
            $alreadyCredited,
            $this->cardRefundedResolver->resolve($orderId)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPendingOrders(string $customerId): array
    {
        $pendingExpr  = sprintf(
            'JSON_UNQUOTE(JSON_EXTRACT(`order`.`custom_fields`, \'$."%s"\'))',
            StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_AMOUNT
        );
        $deductedExpr = sprintf(
            'JSON_UNQUOTE(JSON_EXTRACT(`order`.`custom_fields`, \'$."%s"\'))',
            StoreCreditConstants::ORDER_CUSTOM_FIELD_DEDUCTED
        );
        $currencyExpr = sprintf(
            'JSON_UNQUOTE(JSON_EXTRACT(`order`.`custom_fields`, \'$."%s"\'))',
            StoreCreditConstants::ORDER_CUSTOM_FIELD_PENDING_CURRENCY
        );

        $parameters = [
            'customerId'     => Uuid::fromHexToBytes($customerId),
            'liveVersion'    => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'cancelledState' => OrderStates::STATE_CANCELLED,
        ];

        $expiredExpr = '0';
        $maxAgeDays  = $this->getPendingReservationMaxAgeDays();
        if ($maxAgeDays > 0) {
            $expiredExpr = 'CASE WHEN `order`.`order_date_time` < :cutoff THEN 1 ELSE 0 END';
            $parameters['cutoff'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify(sprintf('-%d days', $maxAgeDays))
                ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        $sql = sprintf(
            'SELECT LOWER(HEX(`order`.`id`)) AS `orderId`,
                    `order`.`order_number` AS `orderNumber`,
                    `order`.`order_date_time` AS `orderDateTime`,
                    CAST(%1$s AS DECIMAL(10,2)) AS `amount`,
                    %4$s AS `currencyId`,
                    %3$s AS `expired`
             FROM `order`
             INNER JOIN `order_customer`
                 ON `order_customer`.`order_id` = `order`.`id`
                 AND `order_customer`.`order_version_id` = `order`.`version_id`
             INNER JOIN `state_machine_state`
                 ON `state_machine_state`.`id` = `order`.`state_id`
             WHERE `order_customer`.`customer_id` = :customerId
                 AND `order`.`version_id` = :liveVersion
                 AND `state_machine_state`.`technical_name` != :cancelledState
                 AND %1$s IS NOT NULL
                 AND CAST(%1$s AS DECIMAL(10,2)) > 0
                 AND LOWER(COALESCE(%2$s, \'false\')) NOT IN (\'true\', \'1\')
             ORDER BY `order`.`order_date_time` ASC',
            $pendingExpr,
            $deductedExpr,
            $expiredExpr,
            $currencyExpr
        );

        return $this->connection->fetchAllAssociative($sql, $parameters);
    }

    private function getStoreCreditEntity(string $customerId, Context $context): ?StoreCreditEntity
    {
        if (isset($this->entityCache[$customerId])) {
            return $this->entityCache[$customerId];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);
        $result = $this->storeCreditRepository->search($criteria, $context);

        $storeCreditEntity = $result->first();
        $entity = $storeCreditEntity instanceof StoreCreditEntity ? $storeCreditEntity : null;

        $this->entityCache[$customerId] = $entity;

        return $entity;
    }

    private function invalidateEntityCache(string $customerId): void
    {
        unset($this->entityCache[$customerId]);
    }

    private function findHistoryIdByIdempotencyKey(string $idempotencyKey): ?string
    {
        $historyId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `solu1_store_credit_history` WHERE `idempotency_key` = :key',
            ['key' => $idempotencyKey]
        );

        return $historyId === false ? null : (string) $historyId;
    }

    private function isUniqueConstraintViolation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    private function validateCustomerId(string $customerId): void
    {
        if (empty(trim($customerId))) {
            throw new \InvalidArgumentException('Customer ID cannot be empty.');
        }

        if (!Uuid::isValid($customerId)) {
            throw new \InvalidArgumentException('Customer ID must be a valid UUID.');
        }
    }

    private function validateAmount(float $amount, string $message = 'Amount must be greater than zero.'): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException($message);
        }

        if (!is_finite($amount)) {
            throw new \InvalidArgumentException('Amount must be a finite number.');
        }
    }

    private function validateCurrencyId(?string $currencyId, Context $context): void
    {
        if ($currencyId === null) {
            return;
        }

        if (empty(trim($currencyId))) {
            throw new \InvalidArgumentException('Currency ID cannot be empty.');
        }

        $isHexFormat = strlen($currencyId) === 32 && !str_contains($currencyId, '-');

        if (!$isHexFormat && !Uuid::isValid($currencyId)) {
            throw new \InvalidArgumentException('Currency ID must be a valid UUID.');
        }

        try {
            if ($isHexFormat) {
                $currency = $this->connection->fetchOne(
                    'SELECT id FROM currency WHERE id = UNHEX(?)',
                    [$currencyId]
                );
            } else {
                $currency = $this->connection->fetchOne(
                    'SELECT id FROM currency WHERE id = UNHEX(REPLACE(?, \'-\', \'\'))',
                    [$currencyId]
                );
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid currency ID format: "%s".', $currencyId), 0, $e);
        }

        if ($currency === false) {
            throw new \InvalidArgumentException(sprintf('Currency with ID "%s" does not exist.', $currencyId));
        }
    }
}
