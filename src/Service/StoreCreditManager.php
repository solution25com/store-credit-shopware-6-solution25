<?php declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Exception\StoreCreditNotFoundException;

class StoreCreditManager
{
    public function __construct(
        private readonly EntityRepository $storeCreditRepository,
        private readonly EntityRepository $storeCreditHistoryRepository,
        private readonly Connection $connection,
    ) {
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

    public static function validateId(string $id, string $label): void
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException($label . ' ID must be a 32-character hexadecimal UUID.');
        }
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
