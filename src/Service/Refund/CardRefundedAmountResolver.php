<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service\Refund;

class CardRefundedAmountResolver
{
    /**
     * @param iterable<CardRefundedAmountProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers = [])
    {
    }

    /**
      * @throws CardRefundedAmountUnavailableException
     */
    public function resolve(string $orderId): float
    {
        $total = 0.0;

        foreach ($this->providers as $provider) {
            try {
                $refunded = $provider->getCardRefundedByTransaction($orderId) ?? [];
                $released = $provider->getCardReleasedByTransaction($orderId) ?? [];
            } catch (\Throwable $e) {
                throw new CardRefundedAmountUnavailableException($provider::class, $e);
            }

            foreach ([...array_values($refunded), ...array_values($released)] as $amount) {
                $total += max(0.0, (float) $amount);
            }
        }

        return round($total, 2);
    }

    /**
     * @throws CardRefundedAmountUnavailableException
     */
    public function resolveReleased(string $orderId): float
    {
        $released = 0.0;

        foreach ($this->providers as $provider) {
            try {
                $reported = $provider->getCardReleasedByTransaction($orderId) ?? [];
            } catch (\Throwable $e) {
                throw new CardRefundedAmountUnavailableException($provider::class, $e);
            }

            foreach ($reported as $amount) {
                $released += max(0.0, (float) $amount);
            }
        }

        return round($released, 2);
    }
}
