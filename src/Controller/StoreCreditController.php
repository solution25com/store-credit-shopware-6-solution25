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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['api'], "_loginRequired" => true])]
class StoreCreditController
{
    private const EPSILON = 0.001;

    private StoreCreditManager $storeCreditManager;
    private LoggerInterface $logger;
    private TranslatorInterface $translator;

    public function __construct(
        StoreCreditManager $storeCreditManager,
        LoggerInterface $logger,
        TranslatorInterface $translator,
    ) {
        $this->storeCreditManager = $storeCreditManager;
        $this->logger = $logger;
        $this->translator = $translator;
    }

    #[Route(path: '/api/store-credit/add', name: 'api.store.credit.add', methods: ['POST'], defaults: ['_acl' => ['store_credit:create', 'store_credit:update']])]
    public function addCredit(Request $request, Context $context): JsonResponse
    {
        $customerId = $this->stringOrNull($request->get('customerId'));
        $rawAmount  = $request->get('amount');
        $amount     = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
        $reason     = $this->normalizeReason($request->get('reason'));
        $orderId    = $this->stringOrNull($request->get('orderId'));
        $currencyId = $this->stringOrNull($request->get('currencyId'));

        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.customerRequired')], 400);
        }

        if ($amount <= 0) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.amountMustBePositive')], 400);
        }

        try {
            $historyId = $this->storeCreditManager->addCredit($customerId, $amount, $context, $orderId, $currencyId, $reason);

            return new JsonResponse([
                'success'   => true,
                'historyId' => $historyId,
                'balance'   => $this->storeCreditManager->getCreditBalance($customerId, $context)['balanceAmount'],
            ]);
        } catch (\InvalidArgumentException | StoreCreditNotFoundException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to add store credit', [
                'customerId' => $customerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.addUnexpectedError')], 500);
        }
    }

    #[Route(path: '/api/store-credit/deduct', name: 'api.store.credit.deduct', methods: ['POST'], defaults: ['_acl' => ['store_credit:create', 'store_credit:update']])]
    public function deductCredit(Request $request, Context $context): JsonResponse
    {
        $customerId = $this->stringOrNull($request->get('customerId'));
        $rawAmount  = $request->get('amount');
        $amount     = is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
        $reason     = $this->normalizeReason($request->get('reason'));

        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.customerRequired')], 400);
        }

        if ($amount <= 0) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.amountMustBePositive')], 400);
        }

        try {
            $refusal = $this->refuseReservedDeduct($customerId, $amount, $request->get('allowReserved'), $context);
            if ($refusal !== null) {
                return $refusal;
            }

            $historyId = $this->storeCreditManager->deductCredit($customerId, $amount, $context, null, null, $reason);

            return new JsonResponse([
                'success'   => true,
                'historyId' => $historyId,
                'balance'   => $this->storeCreditManager->getCreditBalance($customerId, $context)['balanceAmount'],
            ]);
        } catch (\InvalidArgumentException | StoreCreditNotFoundException | InsufficientCreditException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Failed to deduct store credit', [
                'customerId' => $customerId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.deductUnexpectedError')], 500);
        }
    }

    private function refuseReservedDeduct(string $customerId, float $amount, mixed $allowReserved, Context $context): ?JsonResponse
    {
        $breakdown = $this->storeCreditManager->getBalanceBreakdown($customerId, $context);
        $reserved  = $breakdown['reserved'];
        $available = $breakdown['available'];

        if (
            $reserved <= self::EPSILON
            || $amount <= $available + self::EPSILON
            || filter_var($allowReserved, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) === true
        ) {
            return null;
        }

        return new JsonResponse([
            'success'                      => false,
            'message'                      => $this->translate('store-credit.api.reservedConfirmationRequired', [
                '%available%' => number_format($available, 2),
                '%reserved%'  => number_format($reserved, 2),
            ]),
            'available'                    => $available,
            'reserved'                     => $reserved,
            'requiresReservedConfirmation' => true,
        ], 400);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeReason(mixed $reason): ?string
    {
        return $this->stringOrNull($reason);
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function translate(string $snippetKey, array $parameters = []): string
    {
        return (string) $this->translator->trans($snippetKey, $parameters);
    }

    #[Route(path: '/api/store-credit/balance', name: 'api.store-credit.balance', methods: ['GET'], defaults: ['_acl' => ['store_credit:read']])]
    public function getCreditBalance(Request $request, Context $context): JsonResponse
    {
        $customerId = $this->stringOrNull($request->get('customerId'));

        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => $this->translate('store-credit.api.customerRequired')], 400);
        }

        try {
            $breakdown = $this->storeCreditManager->getBalanceBreakdown($customerId, $context);

            return new JsonResponse([
                'success'        => true,
                'balance'        => $breakdown['balance'],
                'currencyId'     => $breakdown['currencyId'],
                'reserved'       => $breakdown['reserved'],
                'available'      => $breakdown['available'],
                'reservedOrders' => $breakdown['reservedOrders'],
                'expiredOrders'  => $breakdown['expiredOrders'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        /* @phpstan-ignore-next-line Controller boundary: log and return safe JSON for unexpected failures. */
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch store credit balance', [
                'customerId' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => $this->translate('store-credit.api.balanceUnexpectedError'),
            ], 500);
        }
    }
}
