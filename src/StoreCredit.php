<?php

declare(strict_types=1);

namespace Solu1StoreCredit;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Doctrine\DBAL\Connection;

class StoreCredit extends Plugin
{
    /**
     * Plugin installation.
     *
     * Database tables are created automatically via migrations during installation.
     * No additional setup is required here.
     */
    public function install(InstallContext $installContext): void
    {
        // Database schema is handled by migrations (Migration1732876338StoreCredit, Migration1732876350StoreCreditHistory)
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `solu1_store_credit_history`');
        $connection->executeStatement('DROP TABLE IF EXISTS `solu1_store_credit`');
    }

    /**
     * Plugin activation.
     *
     * No activation-specific setup is required. The plugin becomes active immediately
     * and event subscribers will start listening to events.
     */
    public function activate(ActivateContext $activateContext): void
    {
        // No activation-specific setup needed - event subscribers are automatically registered
    }

    /**
     * Plugin deactivation.
     *
     * No deactivation-specific cleanup is required. Event subscribers will automatically
     * stop listening when the plugin is deactivated.
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // No deactivation-specific cleanup needed - event subscribers are automatically unregistered
    }

    /**
     * Plugin update.
     *
     * Database schema changes are handled automatically via migrations during updates.
     * No additional update logic is required here.
     */
    public function update(UpdateContext $updateContext): void
    {
        // Database schema changes are handled by migrations
    }

    /**
     * Post-installation hook.
     *
     * Order state installation is handled via the command `store-credit:install-order-state`
     * or through the plugin configuration toggle. This allows administrators to control
     * when the order state is installed, rather than installing it automatically.
     */
    public function postInstall(InstallContext $installContext): void
    {
        // Order state installation is handled via command/config toggle (OrderStateInstallerCommand)
        // This allows administrators to control when the order state is installed
    }

    /**
     * Post-update hook.
     *
     * No post-update logic is required. Database migrations handle schema updates,
     * and the plugin configuration remains unchanged.
     */
    public function postUpdate(UpdateContext $updateContext): void
    {
        // No post-update logic needed - migrations handle schema updates
    }
}
