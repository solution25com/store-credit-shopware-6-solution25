<?php declare(strict_types=1);

namespace Solu1StoreCredit\Subscriber;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Solu1StoreCredit\Service\OrderStateInstaller;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigCommandSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OrderStateInstaller $installer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onConfigChange'];
    }

    public function onConfigChange(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== 'StoreCredit.config.runInstallOrderStateCommand' || $event->getSalesChannelId() !== null) {
            return;
        }
        $this->installer->managePresaleStatuses(new Context(new SystemSource()), (bool) $event->getValue());
    }
}
