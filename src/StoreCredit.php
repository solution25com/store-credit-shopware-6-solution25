<?php declare(strict_types=1);

namespace Solu1StoreCredit;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Solu1StoreCredit\Service\OrderStateInstaller;

class StoreCredit extends Plugin
{
    public function activate(ActivateContext $activateContext): void
    {
        if ($this->container->get(SystemConfigService::class)->getBool('StoreCredit.config.runInstallOrderStateCommand')) {
            $this->stateInstaller()->managePresaleStatuses($activateContext->getContext(), true);
        }
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // A refund action must never remain available while its credit subscriber is inactive.
        $this->stateInstaller()->managePresaleStatuses($deactivateContext->getContext(), false);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->stateInstaller()->managePresaleStatuses($uninstallContext->getContext(), false);
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `solu1_store_credit_history`');
        $connection->executeStatement('DROP TABLE IF EXISTS `solu1_store_credit`');
    }

    private function stateInstaller(): OrderStateInstaller
    {
        // Core repositories also exist when uninstalling an already inactive plugin.
        return new OrderStateInstaller(
            $this->container->get('state_machine.repository'),
            $this->container->get('state_machine_state.repository'),
            $this->container->get('state_machine_transition.repository'),
        );
    }
}
