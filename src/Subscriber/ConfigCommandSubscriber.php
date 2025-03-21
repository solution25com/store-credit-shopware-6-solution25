<?php

namespace StoreCredit\Subscriber;

use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class ConfigCommandSubscriber implements EventSubscriberInterface
{
    private ContainerCommandLoader $commandLoader;
    private LoggerInterface $logger;

    public function __construct(ContainerCommandLoader $commandLoader, LoggerInterface $logger)
    {
        $this->commandLoader = $commandLoader;
        $this->logger        = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onConfigChange',
        ];
    }

    public function onConfigChange(SystemConfigChangedEvent $event): void
    {
        $key = 'StoreCredit.config.runInstallOrderStateCommand';
        if ($event->getKey() !== $key || !$event->getValue()) {
            return;
        }

        try {
            $command = $this->commandLoader->get('store-credit:install-order-state');
            $input   = new ArrayInput([]);
            $output  = new BufferedOutput();

            $result = $command->run($input, $output);
            if ($result === Command::SUCCESS) {
                $this->logger->info('store-credit:install-order-state command executed successfully.', [
                    'output' => $output->fetch(),
                ]);
            } else {
                $this->logger->error('store-credit:install-order-state command execution failed.', [
                    'output' => $output->fetch(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error executing store-credit:install-order-state command: ' . $e->getMessage());
        }
    }
}
