<?php

namespace Solu1StoreCredit\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Symfony\Component\HttpFoundation\Request;
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
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $customerId = $context->getCustomer()?->getId();

        if (!$customerId) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $storeCreditResult = $this->storeCreditRepository->search($criteria, $context->getContext());
        /** @var StoreCreditEntity $storeCredit */
        $storeCredit       = $storeCreditResult->first();

        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $historyCriteria = new Criteria();
        $historyCriteria->setLimit($limit);
        $historyCriteria->setOffset(($page - 1) * $limit);
        $historyCriteria->addSorting(new FieldSorting('createdAt', 'DESC'));
        /* @phpstan-ignore-next-line */
        $historyCriteria->addFilter(new EqualsFilter('storeCreditId', $storeCredit?->getId()));

        $storeCreditsHistoryResult = $this->storeCreditHistoryRepository->search($historyCriteria, $context->getContext());
        $storeCreditsHistory       = $storeCreditsHistoryResult->getElements();
        $totalHistory              = $storeCreditsHistoryResult->getTotal();
        $totalPages                = $limit > 0 ? (int) ceil($totalHistory / $limit) : 1;

        $customer = $context->getCustomer();

        return $this->renderStorefront('@StoreCredit/storefront/page/account/store-credit.html.twig', [
            'storeCredit'         => $storeCredit,
            'storeCreditsHistory' => $storeCreditsHistory,
            'storeCreditHistoryPage'       => $page,
            'storeCreditHistoryTotalPages' => $totalPages,
            'customer'            => $customer,
        ]);
    }
}
