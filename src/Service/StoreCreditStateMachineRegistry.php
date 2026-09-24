<?php declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/** Keeps the return state/history and its wallet credit in the same transaction. */
class StoreCreditStateMachineRegistry extends StateMachineRegistry
{
    public function __construct(private readonly StateMachineRegistry $inner, private readonly Connection $connection)
    {
    }

    public function transition(Transition $transition, Context $context): StateMachineStateCollection
    {
        if ($transition->getEntityName() !== 'order_return' || $transition->getTransitionName() !== 'mark_as_store_credit') {
            return $this->inner->transition($transition, $context);
        }

        return $this->connection->transactional(fn (): StateMachineStateCollection => $this->inner->transition($transition, $context));
    }

    public function getStateMachine(string $name, Context $context): StateMachineEntity
    {
        return $this->inner->getStateMachine($name, $context);
    }

    public function getAvailableTransitions(string $entityName, string $entityId, string $stateFieldName, Context $context): array
    {
        return $this->inner->getAvailableTransitions($entityName, $entityId, $stateFieldName, $context);
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
