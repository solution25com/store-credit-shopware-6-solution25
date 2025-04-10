<?php

declare(strict_types=1);

namespace StoreCredit\Service;

use Shopware\Core\Framework\Context;
use StoreCredit\Core\Content\OrderRecreated\Event\OrderRecreatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderRecreatedEventService
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function fireEvent(Context $context, string $orderId, array $orderData): void
    {
        $this->eventDispatcher->dispatch(new OrderRecreatedEvent($context, $orderId, $orderData));
    }
}
