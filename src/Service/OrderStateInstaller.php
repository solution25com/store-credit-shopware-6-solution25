<?php

declare(strict_types=1);

namespace StoreCredit\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

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
        $stateMachine = $stateMachineRepository
            ->search($criteria, $context)
            ->first();
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
        $state = $this->stateMachineStateRepository
            ->search($criteria, $context)
            ->first();
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
        foreach (array_keys(self::TRANSITIONS) as $actionName) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter("actionName", $actionName));
            $transitions = $this->stateMachineTransitionRepository->search(
                $criteria,
                $context
            );
            foreach ($transitions->getIds() as $transitionId) {
                $this->stateMachineTransitionRepository->delete(
                    [["id" => $transitionId]],
                    $context
                );
            }
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
        foreach ($states->getIds() as $stateId) {
            $this->removeStateMachineHistoryReferences($stateId, $context);
            $this->stateMachineStateRepository->delete(
                [["id" => $stateId]],
                $context
            );
        }
    }
    private function removeStateMachineHistoryReferences(
        string $stateId,
        Context $context
    ): void {
        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter("fromStateId", $stateId),
                new EqualsFilter("toStateId", $stateId),
            ])
        );
        $historyEntries = $this->stateMachineHistoryRepository->search(
            $criteria,
            $context
        );
        foreach ($historyEntries->getIds() as $entryId) {
            $this->stateMachineHistoryRepository->delete(
                [["id" => $entryId]],
                $context
            );
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

        return array_map(
            fn ($transition) => $transition->getActionName(),
            $existingTransitions->getElements()
        );
    }
}
