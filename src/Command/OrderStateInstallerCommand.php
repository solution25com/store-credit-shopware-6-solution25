<?php

namespace StoreCredit\Command;

use Shopware\Core\Framework\Context;
use StoreCredit\Service\OrderStateInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'store-credit:install-order-state')]

class OrderStateInstallerCommand extends Command
{
    protected static string $defaultName = 'store-credit:install-order-state';

    public function __construct(
        private readonly OrderStateInstaller $orderStateInstaller
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Installs the store credit order state and transitions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();

        try {
            $this->orderStateInstaller->managePresaleStatuses($context, true);
            $output->writeln('<info>Store credit order state and transitions installed successfully.</info>');
        } catch (\Exception $e) {
            $output->writeln('<error>Error during installation: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
