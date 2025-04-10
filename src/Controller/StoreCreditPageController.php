<?php

namespace StoreCredit\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditPageController extends StorefrontController
{
    private EntityRepository $storeCreditRepository;

    private EntityRepository $storeCreditHistoryRepository;


    public function __construct(EntityRepository $storeCreditRepository, EntityRepository $storeCreditHistoryRepository)
    {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
    }

    #[Route(path: '/account/store-credit', name: 'frontend.account.store-credit.page', methods: ['GET'])]
    public function index(SalesChannelContext $context): Response
    {
        $customerId = $context->getCustomer()?->getId();

        if (!$customerId) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $storeCreditResult = $this->storeCreditRepository->search($criteria, $context->getContext());
        $storeCredit       = $storeCreditResult->first();

        $historyCriteria = new Criteria();
        $historyCriteria->addSorting(new FieldSorting('createdAt', 'DESC'));
        $historyCriteria->addFilter(new EqualsFilter('storeCreditId', $storeCredit?->getId()));
        $storeCreditsHistory = $this->storeCreditHistoryRepository->search($historyCriteria, $context->getContext())->getElements();

        return $this->renderStorefront('@StoreCredit/storefront/page/account/store-credit.html.twig', [
            'storeCredit'         => $storeCredit,
            'storeCreditsHistory' => $storeCreditsHistory
        ]);
    }
}
