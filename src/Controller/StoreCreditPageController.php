<?php declare(strict_types=1);

namespace Solu1StoreCredit\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditPageController extends StorefrontController
{
    public function __construct(
        private readonly EntityRepository $creditRepository,
        private readonly EntityRepository $historyRepository,
        private readonly GenericPageLoaderInterface $pageLoader,
    ) {
    }

    #[Route(path: '/account/store-credit', name: 'frontend.account.store-credit.page', defaults: ['_loginRequired' => true, '_noStore' => true], methods: ['GET'])]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (!$customer || $customer->getGuest()) {
            return $this->redirectToRoute('frontend.account.login.page');
        }
        $criteria = (new Criteria())->addFilter(new EqualsFilter('customerId', $customer->getId()))->addAssociation('currency')->setLimit(1);
        /** @var StoreCreditEntity|null $credit */
        $credit = $this->creditRepository->search($criteria, $context->getContext())->getEntities()->first();
        $pageNumber = max(1, min(1000000, $request->query->getInt('page', 1)));
        $history = [];
        $totalPages = 1;
        if ($credit) {
            $criteria = (new Criteria())->addFilter(new EqualsFilter('storeCreditId', $credit->getId()))
                ->addAssociation('currency')->addSorting(new FieldSorting('createdAt', 'DESC'), new FieldSorting('id', 'DESC'))
                ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT)->setLimit(10)->setOffset(($pageNumber - 1) * 10);
            $result = $this->historyRepository->search($criteria, $context->getContext());
            $history = $result->getEntities()->getElements();
            $totalPages = max(1, (int) ceil($result->getTotal() / 10));
        }
        $page = $this->pageLoader->load($request, $context);
        $page->getMetaInformation()?->setRobots('noindex,follow');

        return $this->renderStorefront('@StoreCredit/storefront/page/account/store-credit.html.twig', [
            'page' => $page, 'storeCredit' => $credit, 'storeCreditsHistory' => $history,
            'storeCreditHistoryPage' => $pageNumber, 'storeCreditHistoryTotalPages' => $totalPages,
        ]);
    }
}
