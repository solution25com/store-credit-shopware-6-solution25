<?php

namespace StoreCredit\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class StoreCreditManager
{
    private EntityRepository $storeCreditRepository;
    private EntityRepository $storeCreditHistoryRepository;

    public function __construct(
        EntityRepository $storeCreditRepository,
        EntityRepository $storeCreditHistoryRepository,
    ) {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
    }

    public function addCredit(string $customerId, ?string $orderId, ?string $currencyId, float $amount, ?string $reason = null): string
    {
        $context = Context::createDefaultContext();

        $storeCreditId = $this->getStoreCreditId($customerId);

        if ($storeCreditId) {
            $currentBalance = $this->getCreditBalance($customerId)['balanceAmount'];
            $newBalance     = $currentBalance + $amount;

            $this->storeCreditRepository->update([
                [
                    'id'         => $storeCreditId,
                    'balance'    => $newBalance,
                    'currencyId' => $currencyId,
                    'updatedAt'  => (new \DateTime())->format('Y-m-d H:i:s'),
                ]
            ], $context);
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
                'createdAt'     => (new \DateTime())->format('Y-m-d H:i:s.u'),
            ]
        ], $context);

        return $historyId;
    }

    public function deductCredit(string $customerId, float $amount, ?string $orderId, ?string $currencyId, ?string $reason = null): ?string
    {
        $storeCreditId = $this->getStoreCreditId($customerId);

        if ($storeCreditId) {
            $currentBalance = $this->getCreditBalance($customerId)['balanceAmount'];

            if (!($currentBalance < $amount)) {
                $newBalance = $currentBalance - $amount;

                $this->storeCreditRepository->update([
                    [
                        'id'         => $storeCreditId,
                        'balance'    => $newBalance,
                        'currencyId' => $currencyId,
                        'updatedAt'  => (new \DateTime())->format('Y-m-d H:i:s'),
                    ]
                ], Context::createDefaultContext());

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
                        'createdAt'     => (new \DateTime())->format('Y-m-d H:i:s.u'),
                    ]
                ], Context::createDefaultContext());
                return $historyId;
            }
        }
        return('No store credit found for this customer or insufficient store credit balance.');
    }
    public function getCreditBalance(string $customerId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $result = $this->storeCreditRepository->search($criteria, Context::createDefaultContext());

        $storeCreditEntity = $result->first();

        return [
            'balanceAmount'     => $storeCreditEntity ? $storeCreditEntity->get('balance') : 0.0,
            'balanceCurrencyId' => $storeCreditEntity ? $storeCreditEntity->get('currencyId') : null,
        ];
    }

    public function getStoreCreditId(string $customerId): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $result = $this->storeCreditRepository->search($criteria, Context::createDefaultContext());

        $storeCreditEntity = $result->first();

        return $storeCreditEntity ? $storeCreditEntity->get('id') : null;
    }
}
