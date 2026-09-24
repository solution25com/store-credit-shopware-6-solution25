<?php declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class OrderStateInstaller
{
    public function __construct(
        private readonly EntityRepository $stateMachineRepository,
        private readonly EntityRepository $stateRepository,
        private readonly EntityRepository $transitionRepository,
    ) {
    }

    public function managePresaleStatuses(Context $context, bool $isAdding): void
    {
        $machine = $this->stateMachineRepository->search((new Criteria())->addFilter(new EqualsFilter('technicalName', 'order_return.state')), $context)->getEntities()->first();
        if (!$machine) {
            if (!$isAdding) {
                return;
            }
            throw new \RuntimeException('Shopware Commercial Return Management must be installed before adding its store credit refund state.');
        }
        $machineId = $machine->getUniqueIdentifier();
        $criteria = (new Criteria())->addFilter(new EqualsFilter('stateMachineId', $machineId), new EqualsFilter('technicalName', 'store_credit'));
        $creditStateId = $this->stateRepository->searchIds($criteria, $context)->firstId();
        if (!$isAdding) {
            // Retain the state, historical entries and the transition back to open for existing returns.
            $criteria = (new Criteria())->addFilter(new EqualsFilter('stateMachineId', $machineId), new EqualsFilter('actionName', 'mark_as_store_credit'));
            $ids = $this->transitionRepository->searchIds($criteria, $context)->getIds();
            if ($ids) {
                $this->transitionRepository->delete(array_map(static fn (string $id): array => ['id' => $id], $ids), $context);
            }
            return;
        }
        if (!$creditStateId) {
            $creditStateId = Uuid::randomHex();
            $this->stateRepository->create([['id' => $creditStateId, 'stateMachineId' => $machineId, 'technicalName' => 'store_credit', 'name' => 'Refund as Store Credits']], $context);
        }
        $openId = $this->stateRepository->searchIds((new Criteria())->addFilter(new EqualsFilter('stateMachineId', $machineId), new EqualsFilter('technicalName', 'open')), $context)->firstId();
        if (!$openId) {
            throw new \RuntimeException('The order return state machine has no open state.');
        }
        $existing = $this->transitionRepository->search((new Criteria())->addFilter(new EqualsFilter('stateMachineId', $machineId)), $context)->getEntities();
        $newTransitions = [];
        foreach ([['mark_as_store_credit', $openId, $creditStateId], ['mark_as_open', $creditStateId, $openId]] as [$action, $from, $to]) {
            $found = $existing->filter(static fn ($transition): bool => $transition->get('actionName') === $action && $transition->get('fromStateId') === $from && $transition->get('toStateId') === $to);
            if ($found->count() === 0) {
                $newTransitions[] = ['id' => Uuid::randomHex(), 'stateMachineId' => $machineId, 'actionName' => $action, 'fromStateId' => $from, 'toStateId' => $to];
            }
        }
        if ($newTransitions) {
            $this->transitionRepository->create($newTransitions, $context);
        }
    }
}
