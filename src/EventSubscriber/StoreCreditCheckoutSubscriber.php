<?php declare(strict_types=1);

namespace Solu1StoreCredit\EventSubscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Solu1StoreCredit\Core\Checkout\Cart\StoreCreditLineItem;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StoreCreditCheckoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly StoreCreditManager $manager, private readonly SystemConfigService $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutCartPageLoadedEvent::class => 'onPageLoaded', CheckoutConfirmPageLoadedEvent::class => 'onPageLoaded'];
    }

    public function onPageLoaded(CheckoutCartPageLoadedEvent|CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();
        if (!$customer) {
            return;
        }
        $balance = $this->manager->getCreditBalance($customer->getId(), $context->getContext());
        $applied = 0.0;
        foreach ($event->getPage()->getCart()->getLineItems()->filter(StoreCreditLineItem::matches(...)) as $item) {
            $applied += abs($item->getPrice()?->getTotalPrice() ?? 0.0);
        }
        $matchesCurrency = $balance['currencyId'] === $context->getCurrencyId();
        $available = $matchesCurrency ? max(0.0, $balance['balanceAmount'] - $applied) : 0.0;
        $maximum = $this->config->getFloat('StoreCredit.config.maxCreditPerOrder', $context->getSalesChannelId());
        $available = min($available, max(0.0, $event->getPage()->getCart()->getPrice()->getTotalPrice()));
        if ($maximum > 0) {
            $available = min($available, max(0.0, $maximum - $applied));
        }
        $expanded = $this->config->get('StoreCredit.config.expandStoreCreditByDefault', $context->getSalesChannelId());
        $event->getPage()->addExtension('storeCredit', new ArrayStruct([
            'balance' => $matchesCurrency ? $balance['balanceAmount'] : 0.0,
            'remaining' => $matchesCurrency ? max(0.0, $balance['balanceAmount'] - $applied) : 0.0,
            'maximum' => floor($available * 100 + 0.0000001) / 100,
            'expanded' => $expanded === null || (bool) $expanded,
        ]));
    }
}
