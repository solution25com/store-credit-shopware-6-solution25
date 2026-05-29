<?php

namespace Solu1StoreCredit\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Exception\StoreCreditNotFoundException;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api'], "_loginRequired" => true])]
class StoreCreditController
{
    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;

    public function __construct(
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger,
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->logger = $logger;
    }

    #[Route(path: '/api/store-credit/add', name: 'api.store.credit.add', methods: ['POST'], defaults: ['_acl' => ['store_credit:create', 'store_credit:update']])]
    public function addCredit(Request $request, Context $context): JsonResponse
    {
        $customerId = $request->get('customerId');
        $amount     = (float)$request->get('amount');
        $reason     = $request->get('reason');
        $orderId    = $request->get('orderId');
        $currencyId = $request->get('currencyId');

        if ($amount <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Amount must be greater than zero.'], 400);
        }

        try {
            $historyId = $this->storeCreditManager->addCredit($customerId, $amount, $context, $orderId, $currencyId, $reason);

            return new JsonResponse(['success' => true, 'historyId' => $historyId]);
        } catch (\InvalidArgumentException | StoreCreditNotFoundException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add store credit', [
                'customerId' => $customerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => 'An unexpected error occurred while adding store credit.'], 500);
        }
    }

    #[Route(path: '/api/store-credit/deduct', name: 'api.store.credit.deduct', methods: ['POST'], defaults: ['_acl' => ['store_credit:create', 'store_credit:update']])]
    public function deductCredit(Request $request, Context $context): JsonResponse
    {
        $customerId = $request->get('customerId');
        $amount     = (float)$request->get('amount');
        $reason     = $request->get('reason');

        if ($amount <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Amount must be greater than zero.'], 400);
        }

        try {
            $historyId = $this->storeCreditManager->deductCredit($customerId, $amount, $context, null, null, $reason);
            return new JsonResponse(['success' => true, 'historyId' => $historyId]);
        } catch (\InvalidArgumentException | StoreCreditNotFoundException | InsufficientCreditException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to deduct store credit', [
                'customerId' => $customerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => 'An unexpected error occurred while deducting store credit.'], 500);
        }
    }

    #[Route(path: '/api/store-credit/balance', name: 'api.store-credit.balance', methods: ['GET'], defaults: ['_acl' => ['store_credit:read']])]
    public function getCreditBalance(Request $request, Context $context): JsonResponse
    {
        $customerId = $request->get('customerId');

        try {
            $balance = $this->storeCreditManager->getCreditBalance($customerId, $context);

            return new JsonResponse([
                'success'    => true,
                'balance'    => $balance['balanceAmount'],
                'currencyId' => $balance['balanceCurrencyId'],
            ]);
        } catch (\InvalidArgumentException | StoreCreditNotFoundException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve store credit balance', [
                'customerId' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => 'An unexpected error occurred while retrieving the credit balance.'], 500);
        }
    }
}
