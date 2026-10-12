<?php declare(strict_types=1);

namespace Solu1StoreCredit\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Solu1StoreCredit\Exception\InsufficientCreditException;
use Solu1StoreCredit\Exception\StoreCreditNotFoundException;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class StoreCreditController
{
    public function __construct(private readonly StoreCreditManager $manager, private readonly LoggerInterface $logger)
    {
    }

    #[Route(path: '/api/store-credit/add', name: 'api.store.credit.add', defaults: ['_acl' => ['solu1_store_credit:create', 'solu1_store_credit:update']], methods: ['POST'])]
    public function add(Request $request, Context $context): JsonResponse
    {
        return $this->adjust($request, $context, true);
    }

    #[Route(path: '/api/store-credit/deduct', name: 'api.store.credit.deduct', defaults: ['_acl' => ['solu1_store_credit:update']], methods: ['POST'])]
    public function deduct(Request $request, Context $context): JsonResponse
    {
        return $this->adjust($request, $context, false);
    }

    #[Route(path: '/api/store-credit/balance', name: 'api.store.credit.balance', defaults: ['_acl' => ['solu1_store_credit:read']], methods: ['GET'])]
    public function balance(Request $request, Context $context): JsonResponse
    {
        try {
            $id = $request->query->all()['customerId'] ?? null;
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Customer ID is required.');
            }
            $breakdown = $this->manager->getBalanceBreakdown($id, $context);
            return new JsonResponse([
                'success' => true,
                'balance' => $breakdown['balance'],
                'currencyId' => $breakdown['currencyId'],
                'reserved' => $breakdown['reserved'],
                'available' => $breakdown['available'],
                'reservedOrders' => $breakdown['reservedOrders'],
                'expiredOrders' => $breakdown['expiredOrders'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function adjust(Request $request, Context $context, bool $add): JsonResponse
    {
        try {
            $data = $request->getPayload()->all();
            $customerId = $data['customerId'] ?? null;
            $amount = $data['amount'] ?? null;
            if (!is_string($customerId) || !(is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount)))) {
                throw new \InvalidArgumentException('Customer ID and a numeric amount are required.');
            }
            foreach (['reason', 'orderId', 'currencyId'] as $key) {
                if (isset($data[$key]) && !is_string($data[$key])) {
                    throw new \InvalidArgumentException($key . ' must be a string.');
                }
            }
            if (!$add) {
                $refusal = $this->refuseReservedDeduct($customerId, (float) $amount, $data['allowReserved'] ?? null, $context);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
            $method = $add ? 'addCredit' : 'deductCredit';
            $historyId = $this->manager->$method($customerId, (float) $amount, $context, $data['orderId'] ?? null, $data['currencyId'] ?? null, $data['reason'] ?? null);
            return new JsonResponse([
                'success' => true,
                'historyId' => $historyId,
                'balance' => $this->manager->getCreditBalance($customerId, $context)['balanceAmount'],
            ]);
        } catch (\InvalidArgumentException|InsufficientCreditException|StoreCreditNotFoundException|\Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Store credit adjustment failed.', ['exception' => $e]);
            return new JsonResponse(['success' => false, 'message' => 'Store credit could not be updated.'], 500);
        }
    }

    private function refuseReservedDeduct(string $customerId, float $amount, mixed $allowReserved, Context $context): ?JsonResponse
    {
        $breakdown = $this->manager->getBalanceBreakdown($customerId, $context);
        if (
            $breakdown['reserved'] <= 0.001
            || $amount <= $breakdown['available'] + 0.001
            || filter_var($allowReserved, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) === true
        ) {
            return null;
        }

        return new JsonResponse([
            'success' => false,
            'message' => sprintf(
                'Only %s is available. %s is reserved for pending orders. Confirm to deduct reserved credit.',
                number_format($breakdown['available'], 2),
                number_format($breakdown['reserved'], 2)
            ),
            'available' => $breakdown['available'],
            'reserved' => $breakdown['reserved'],
            'requiresReservedConfirmation' => true,
        ], 400);
    }
}
