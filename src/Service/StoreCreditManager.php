<?php

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Exception\StoreCreditNotFoundException;

/**
 * Service for managing store credit operations.
 *
 * This service handles adding credit to customer accounts, deducting credit,
 * and retrieving credit balances. All operations are performed within database
 * transactions to ensure data consistency.
 */
class StoreCreditManager
{
    private EntityRepository $storeCreditRepository;
    private EntityRepository $storeCreditHistoryRepository;
    private Connection $connection;

    /**
     * @var array<string, StoreCreditEntity|null>
     */
    private array $entityCache = [];

    public function __construct(
        EntityRepository $storeCreditRepository,
        EntityRepository $storeCreditHistoryRepository,
        Connection $connection,
    ) {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
        $this->connection = $connection;
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
    public function addCredit(string $customerId, float $amount, Context $context, ?string $orderId, ?string $currencyId, ?string $reason = null): string
    {
        $this->validateCustomerId($customerId);
        $this->validateAmount($amount, 'Amount must be greater than zero.');
        $this->validateCurrencyId($currencyId, $context);

        $amount = round($amount, 2);

        return $this->connection->transactional(function () use ($customerId, $amount, $context, $orderId, $currencyId, $reason): string {
            $storeCredit = $this->getStoreCreditEntity($customerId, $context);

            if ($storeCredit) {
                $storeCreditId = $storeCredit->getId();
                $currentBalance = $storeCredit->getBalance();
                $newBalance = round($currentBalance + $amount, 2);

                $this->storeCreditRepository->update([
                    [
                        'id'         => $storeCreditId,
                        'balance'    => $newBalance,
                        'currencyId' => $currencyId,
                    ]
                ], $context);

                $this->invalidateEntityCache($customerId);
            } else {
                $storeCreditId = Uuid::randomHex();
                $this->storeCreditRepository->create([
                    [
                        'id'         => $storeCreditId,
                        'customerId' => $customerId,
                        'balance'    => $amount,
                        'currencyId' => $currencyId,
                    ]
                ], $context);

                $this->invalidateEntityCache($customerId);
            }

            $historyId = Uuid::randomHex();
            $this->storeCreditHistoryRepository->create([
                [
                    'id'            => $historyId,
                    'storeCreditId' => $storeCreditId,
                    'orderId'       => $orderId,
                    'amount'        => $amount,
                    'currencyId'    => $currencyId,
                    'reason'        => $reason ?: 'Not specified',
                    'actionType'    => 'add',
                ]
            ], $context);

            return $historyId;
        });
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
    public function deductCredit(string $customerId, float $amount, Context $context, ?string $orderId, ?string $currencyId, ?string $reason = null): string
    {
        $this->validateCustomerId($customerId);
        $this->validateAmount($amount, 'Amount must be greater than zero.');
        $this->validateCurrencyId($currencyId, $context);

        $amount = round($amount, 2);

        return $this->connection->transactional(function () use ($customerId, $amount, $context, $orderId, $currencyId, $reason): string {
            $storeCredit = $this->getStoreCreditEntity($customerId, $context);

            if (!$storeCredit) {
                throw new StoreCreditNotFoundException('No store credit found for this customer.');
            }

            $storeCreditId = $storeCredit->getId();
            $currentBalance = $storeCredit->getBalance();

            if ($currentBalance < $amount) {
                throw new InsufficientCreditException('Insufficient store credit balance.');
            }

            $newBalance = round($currentBalance - $amount, 2);

            $this->storeCreditRepository->update([
                [
                    'id'         => $storeCreditId,
                    'balance'    => $newBalance,
                    'currencyId' => $currencyId,
                ]
            ], $context);

            $this->invalidateEntityCache($customerId);

            $historyId = Uuid::randomHex();
            $this->storeCreditHistoryRepository->create([
                [
                    'id'            => $historyId,
                    'storeCreditId' => $storeCreditId,
                    'orderId'       => $orderId,
                    'amount'        => $amount,
                    'currencyId'    => $currencyId,
                    'reason'        => $reason ?: 'Not specified',
                    'actionType'    => 'deduct',
                ]
            ], $context);

            return $historyId;
        });
    }

    private function getStoreCreditEntity(string $customerId, Context $context): ?StoreCreditEntity
    {
        if (isset($this->entityCache[$customerId])) {
            return $this->entityCache[$customerId];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
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
            'balanceCurrencyId' => $storeCredit ? $storeCredit->getCurrencyId() : null,
        ];
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
                    'SELECT id FROM currency WHERE id = UNHEX(REPLACE(?, \'-\', \'))',
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
