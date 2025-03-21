<?php

declare(strict_types=1);

namespace StoreCredit\Core\Content\OrderRecreated\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;

class OrderRecreatedEvent implements ShopwareEvent
{
    protected Context $context;
    private $orderId;
    private $orderData;

    public function __construct(Context $context, string $orderId, array $orderData)
    {
        $this->context   = $context;
        $this->orderId   = $orderId;
        $this->orderData = $orderData;
    }


    public function getContext(): Context
    {
        return $this->context;
    }
    public function getOrderId(): string
    {
        return $this->orderId;
    }
    public function getOrderData(): array
    {
        return $this->orderData;
    }
}
