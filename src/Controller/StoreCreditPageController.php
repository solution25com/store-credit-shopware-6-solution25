<?php

namespace Solu1StoreCredit\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoader;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditEntity;
use Solu1StoreCredit\Service\StoreCreditCurrencyConverter;
use Solu1StoreCredit\Service\StoreCreditManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StoreCreditPageController extends StorefrontController
{
    /** @var EntityRepository<\Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditCollection> */
    private EntityRepository $storeCreditRepository;

    /** @var EntityRepository<\Solu1StoreCredit\Core\Content\StoreCreditHistory\StoreCreditHistoryCollection> */
    private EntityRepository $storeCreditHistoryRepository;

    private GenericPageLoader $genericPageLoader;

    private StoreCreditManager $storeCreditManager;

    private ?StoreCreditCurrencyConverter $currencyConverter;

    /**
     * @param EntityRepository<\Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditCollection> $storeCreditRepository
     * @param EntityRepository<\Solu1StoreCredit\Core\Content\StoreCreditHistory\StoreCreditHistoryCollection> $storeCreditHistoryRepository
     */
    public function __construct(
        EntityRepository $storeCreditRepository,
        EntityRepository $storeCreditHistoryRepository,
        GenericPageLoader $genericPageLoader,
        StoreCreditManager $storeCreditManager,
        ?StoreCreditCurrencyConverter $currencyConverter = null
    ) {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
        $this->genericPageLoader            = $genericPageLoader;
        $this->storeCreditManager           = $storeCreditManager;
        $this->currencyConverter            = $currencyConverter;
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
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);
        $criteria->addAssociation('currency');
        $storeCreditResult = $this->storeCreditRepository->search($criteria, $context->getContext());
        /** @var StoreCreditEntity|null $storeCredit */
        $storeCredit       = $storeCreditResult->first();

        $page = $this->genericPageLoader->load($request, $context);

        $historyPage = max(1, (int) $request->query->get('page', 1));
        $limit = 10;

        $historyCriteria = new Criteria();
        $historyCriteria->setLimit($limit);
        $historyCriteria->setOffset(($historyPage - 1) * $limit);
        $historyCriteria->addSorting(new FieldSorting('createdAt', 'DESC'));
        $historyCriteria->addAssociation('currency');
        if ($storeCredit !== null) {
            $historyCriteria->addFilter(new EqualsFilter('storeCreditId', $storeCredit->getId()));
        }

        $storeCreditsHistory = [];
        $totalPages          = 0;
        $breakdown           = null;

        if ($storeCredit !== null) {
            $storeCreditsHistoryResult = $this->storeCreditHistoryRepository->search($historyCriteria, $context->getContext());
            $storeCreditsHistory       = $storeCreditsHistoryResult->getElements();
            $totalPages                = (int) ceil($storeCreditsHistoryResult->getTotal() / $limit);
            $breakdown                 = $this->storeCreditManager->getBalanceBreakdown($customerId, $context->getContext());
        }

        $customer = $context->getCustomer();

        $walletIsoCode = $storeCredit?->getCurrency()?->getIsoCode();
        if ($walletIsoCode === null && $storeCredit !== null && $this->currencyConverter !== null) {
            $walletIsoCode = $this->currencyConverter->getIsoCode(StoreCreditCurrencyConverter::walletCurrency($storeCredit->getCurrencyId()));
        }

        return $this->renderStorefront('@StoreCredit/storefront/page/account/store-credit.html.twig', [
            'page'                => $page,
            'storeCredit'         => $storeCredit,
            'storeCreditBreakdown' => $breakdown,
            'storeCreditsHistory' => $storeCreditsHistory,
            'storeCreditHistoryPage'       => $historyPage,
            'storeCreditHistoryTotalPages' => $totalPages,
            'customer'            => $customer,
            'storeCreditCurrencyIsoCode' => $walletIsoCode ?? $context->getCurrency()->getIsoCode(),
        ]);
    }
}
