<?php

namespace StoreCredit\Controller;

use StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api'], "_loginRequired" => true])]
class StoreCreditController
{
    private StoreCreditManager $storeCreditManager;


    public function __construct(
        StoreCreditManager $storeCreditManager,
    ) {
        $this->storeCreditManager = $storeCreditManager;
    }

    #[Route(path: '/api/store-credit/add', name: 'api.store.credit.add', methods: ['POST'])]
    public function addCredit(Request $request): JsonResponse
    {
        $customerId = $request->get('customerId');
        $amount     = (float)$request->get('amount');
        $reason     = $request->get('reason');
        $orderId    = $request->get('orderId');
        $currencyId = $request->get('currencyId');

        try {
            $historyId = $this->storeCreditManager->addCredit($customerId, $orderId, $currencyId, $amount, $reason);

            return new JsonResponse(['success' => true, 'historyId' => $historyId]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }


    #[Route(path: '/api/store-credit/deduct', name: 'api.store.credit.deduct', methods: ['POST'])]
    public function deductCredit(Request $request): JsonResponse
    {
        $customerId = $request->get('customerId');
        $amount     = (float)$request->get('amount');
        $reason     = $request->get('reason');

        try {
            $historyId = $this->storeCreditManager->deductCredit($customerId, $amount, null, null, $reason);
            return new JsonResponse(['success' => true, 'historyId' => $historyId]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/store-credit/balance', name: 'api.store-credit.balance', methods: ['GET'])]
    public function getCreditBalance(Request $request): JsonResponse
    {
        $customerId = $request->get('customerId');

        try {
            $balance = $this->storeCreditManager->getCreditBalance($customerId);

            return new JsonResponse([
                'success'    => true,
                'balance'    => $balance['balanceAmount'],
                'currencyId' => $balance['balanceCurrencyId'],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
