<?php

declare(strict_types=1);

namespace Solu1StoreCredit\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;

class OrderStateInstaller
{
    private const STATE_MACHINE_TECHNICAL_NAME = "order_return.state";
    private const NEW_STATE_TECHNICAL_NAME     = "store_credit";
    private const NEW_STATE_NAME               = "Refund as Store Credits";

    private const TRANSITIONS = [
        [
            'actionName' => 'mark_as_store_credit',
            'from'       => 'open',
            'to'         => 'store_credit',
        ],
        [
            'actionName' => 'mark_as_open',
            'from'       => 'store_credit',
            'to'         => 'open',
        ],
    ];

    public function __construct(
        private readonly EntityRepository $stateMachineRepository,
        private readonly EntityRepository $stateMachineStateRepository,
        private readonly EntityRepository $stateMachineTransitionRepository,
        private readonly EntityRepository $stateMachineHistoryRepository
    ) {
    }
    public function managePresaleStatuses(
        Context $context,
        bool $isAdding
    ): void {
        $stateMachineId = $this->getStateMachineId(
            $this->stateMachineRepository,
            $context
        );

        if ($isAdding) {
            if (!$this->stateExists($stateMachineId, $context)) {
                $this->stateMachineStateRepository->upsert(
                    [
                        [
                            "technicalName"  => self::NEW_STATE_TECHNICAL_NAME,
                            "name"           => self::NEW_STATE_NAME,
                            "stateMachineId" => $stateMachineId,
                        ],
                    ],
                    $context
                );
            }

            $transitions         = $this->buildTransitions($stateMachineId, $context);
            $existingTransitions = $this->getExistingTransitions($transitions, $context);

            $newTransitions = array_filter($transitions, function ($transition) use ($existingTransitions) {
                return !in_array($transition['actionName'], $existingTransitions);
            });

            if (!empty($newTransitions)) {
                $this->stateMachineTransitionRepository->upsert(
                    $newTransitions,
                    $context
                );
            }
        } else {
            $this->removeTransitions($context);
            $this->removeState($context, $stateMachineId);
        }
    }
    private function getStateMachineId(
        EntityRepository $stateMachineRepository,
        Context $context
    ): string {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter(
                "technicalName",
                self::STATE_MACHINE_TECHNICAL_NAME
            )
        );
        /** @var StateMachineEntity $stateMachine */
        $stateMachine = $stateMachineRepository
            ->search($criteria, $context)
            ->first();
        /* @phpstan-ignore-next-line */
        if (!$stateMachine) {
            throw new \RuntimeException(
                sprintf(
                    'State machine "%s" not found.',
                    self::STATE_MACHINE_TECHNICAL_NAME
                )
            );
        }
        return $stateMachine->getId();
    }
    private function getStateId(
        string $technicalName,
        string $stateMachineId,
        Context $context
    ): string {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter("technicalName", $technicalName));
        $criteria->addFilter(
            new EqualsFilter("stateMachineId", $stateMachineId)
        );
        /** @var StateMachineEntity $state */
        $state = $this->stateMachineStateRepository
            ->search($criteria, $context)
            ->first();
        /* @phpstan-ignore-next-line */
        if (!$state) {
            throw new \RuntimeException(
                sprintf(
                    'State "%s" not found in state machine "%s".',
                    $technicalName,
                    $stateMachineId
                )
            );
        }
        return $state->getId();
    }
    private function buildTransitions(
        string $stateMachineId,
        Context $context
    ): array {
        $transitions = [];
        foreach (self::TRANSITIONS as $transition) {
            $transitions[] = [
                "actionName"  => $transition["actionName"],
                "fromStateId" => $this->getStateId(
                    $transition["from"],
                    $stateMachineId,
                    $context
                ),
                "toStateId" => $this->getStateId(
                    $transition["to"],
                    $stateMachineId,
                    $context
                ),
                "stateMachineId" => $stateMachineId,
            ];
        }
        return $transitions;
    }

    private function removeTransitions(Context $context): void
    {
        $actionNames = array_column(self::TRANSITIONS, 'actionName');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter("actionName", $actionNames)); // EqualsFilter accepts array for "IN"
        $transitions = $this->stateMachineTransitionRepository->search($criteria, $context);

        $transitionIds = $transitions->getIds();

        if (!empty($transitionIds)) {
            $deleteData = array_map(fn($id) => ["id" => $id], $transitionIds);
            $this->stateMachineTransitionRepository->delete($deleteData, $context);
        }
    }
    private function removeState(Context $context, string $stateMachineId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter("technicalName", self::NEW_STATE_TECHNICAL_NAME)
        );
        $criteria->addFilter(
            new EqualsFilter("stateMachineId", $stateMachineId)
        );
        $states = $this->stateMachineStateRepository->search(
            $criteria,
            $context
        );
        $stateIds = $states->getIds();

        $this->removeStateMachineHistoryReferences($stateIds, $context);

        $deleteData = array_map(fn($id) => ['id' => $id], $stateIds);
        $this->stateMachineStateRepository->delete($deleteData, $context);
    }
    private function removeStateMachineHistoryReferences(
        array $stateIds,
        Context $context
    ): void {
        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsAnyFilter('fromStateId', $stateIds),
                new EqualsAnyFilter('toStateId', $stateIds),
            ])
        );

        $historyEntries = $this->stateMachineHistoryRepository->search($criteria, $context);
        $historyIds = $historyEntries->getIds();

        if (!empty($historyIds)) {
            $deleteData = array_map(fn($id) => ['id' => $id], $historyIds);
            $this->stateMachineHistoryRepository->delete($deleteData, $context);
        }
    }
    private function stateExists(string $stateMachineId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter("technicalName", self::NEW_STATE_TECHNICAL_NAME));
        $criteria->addFilter(new EqualsFilter("stateMachineId", $stateMachineId));
        $state = $this->stateMachineStateRepository->search($criteria, $context)->first();
        return $state !== null;
    }

    private function getExistingTransitions(array $transitions, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter("stateMachineId", $transitions[0]['stateMachineId']));
        $existingTransitions = $this->stateMachineTransitionRepository->search($criteria, $context);
        $elements = $existingTransitions->getElements();
        /** @var StateMachineTransitionEntity[] $elements */
        return array_map(
            fn (StateMachineTransitionEntity $transition) => $transition->getActionName(), //this is line 215
            $elements
        );
    }
}
