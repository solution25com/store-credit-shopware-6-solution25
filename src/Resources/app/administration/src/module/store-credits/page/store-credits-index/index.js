import template from './store-credits-index.html.twig';
import "./store-credits-index.scss";
import '../../components/sw-customer-grid';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('store-credits-index', {
    template,

    inject: ['repositoryFactory', 'httpClient'],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            confirmDeleteModalVisible: false,
            addBalanceModalVisible: false,
            deductBalanceModalVisible: false,
            addCustomerModalVisible: false,
            repository: null,
            storeCredits: [],
            customers: [],
            isLoading: false,
            amount: 0,
            reason: '',
            selectedCustomer: null,
            selectedNewCustomer: null,
            selectedStoreCredit: null,
            newCustomerAmount: 0,
            isSubmitting: false,
            reservedConfirmRequired: false,
            allowReserved: false,
            reservedNotice: '',
            total: 0,
            term: '',
            columns: [
                { property: 'customerFullName', label: this.$tc('store-credits.listing.customerFullName'), allowResize: true, sortable: true },
                { property: 'balance', label: this.$tc('store-credits.listing.balance'), allowResize: true },
                {
                    property: 'actions',
                    label: this.$tc('store-credits.listing.balanceActions'),
                    allowResize: false,
                    align: 'center',
                    sortable: false,
                    width: '300px',
                },
            ],
        };
    },

    created() {
        this.repository = this.repositoryFactory.create('solu1_store_credit');
        
        // Initialize page and limit from URL query parameters
        if (this.$route.query.page) {
            this.page = parseInt(this.$route.query.page, 10) || 1;
        }
        if (this.$route.query.limit) {
            this.limit = parseInt(this.$route.query.limit, 10) || 10;
        }
        if (this.$route.query.term) {
            this.term = String(this.$route.query.term);
        }

        this.fetchStoreCredits();
    },

    watch: {
        amount() {
            this.resetReservedConfirmation();
        },

        '$route.query.page'() {
            if (this.$route.query.page) {
                this.page = parseInt(this.$route.query.page, 10);
                this.fetchStoreCredits();
            }
        },
        '$route.query.limit'() {
            if (this.$route.query.limit) {
                this.limit = parseInt(this.$route.query.limit, 10);
                this.fetchStoreCredits();
            }
        },
        '$route.query.term'(newTerm) {
            const term = newTerm ? String(newTerm) : '';
            if (term === this.term) {
                return;
            }
            this.term = term;
            this.page = 1;
            this.fetchStoreCredits();
        },
    },

    methods: {
        fetchStoreCredits() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.setPage(this.page);
            criteria.setLimit(this.limit);
            criteria.addAssociation('customer');
            criteria.addAssociation('currency');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            const searchTerm = (this.term || '').trim();
            if (searchTerm !== '') {
                criteria.addFilter(
                    Criteria.multi('OR', [
                        Criteria.contains('customer.firstName', searchTerm),
                        Criteria.contains('customer.lastName', searchTerm),
                        Criteria.contains('customer.email', searchTerm),
                        Criteria.contains('customer.customerNumber', searchTerm),
                    ]),
                );
            }

            this.repository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.storeCredits = result.map((credit) => {
                        const customer = credit.customer;
                        const firstName = customer?.firstName || '';
                        const lastName = customer?.lastName || '';
                        const customerFullName = (firstName + ' ' + lastName).trim() || this.$tc('store-credits.common.unknown');
                        
                        return {
                            id: credit.id,
                            customerFullName: customerFullName,
                            balance: credit.balance || 0,
                            currencyId: credit.currencyId,
                            currencyIsoCode: credit.currency?.isoCode || Shopware.Context.app.systemCurrencyISOCode || 'EUR',
                            customerId: credit.customerId,
                            storeCreditId: credit.id,
                        };
                    });
                    this.total = result.total || 0;
                })
                .catch((error) => {
                    console.error('Error fetching store credits:', error);
                    this.createNotificationError({
                        title: this.$tc('store-credits.common.error'),
                        message: error.response?.data?.errors?.[0]?.detail || this.$tc('store-credits.listing.loadFailed'),
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onSearch(searchTerm) {
            this.term = searchTerm ? String(searchTerm).trim() : '';
            this.page = 1;
            this.updateRouteQuery();
            this.fetchStoreCredits();
        },

        onPageChange({ page, limit }) {
            this.page = page;
            if (limit) {
                this.limit = limit;
            }
            this.updateRouteQuery();
        },

        onLimitChange(limit) {
            this.limit = limit;
            this.page = 1;
            this.updateRouteQuery();
        },

        updateRouteQuery() {
            const query = {
                page: this.page,
                limit: this.limit,
            };

            if (this.term) {
                query.term = this.term;
            }

            this.$router.push({
                name: this.$route.name,
                query,
            });
        },

        formatCurrency(value, currencyIsoCode = null) {
            const currency = currencyIsoCode || Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            const locale = Shopware.Context.app.locale?.replace('_', '-') || 'en-US';
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currency,
            }).format(value);
        },

        fetchCustomers() {
            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('lastName', 'ASC'));

            this.repositoryFactory.create('customer').search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.customers = result.map(customer => ({
                        id: customer.id,
                        name: `${customer.firstName} ${customer.lastName}`,
                    }));
                })
                .catch((error) => {
                    console.error('Error fetching customers:', error);
                });
        },

        openAddBalanceModal(customer) {
            this.selectedCustomer = customer;
            this.amount = 0;
            this.reason = '';
            this.addBalanceModalVisible = true;
        },

        openDeductBalanceModal(customer) {
            this.selectedCustomer = customer;
            this.amount = 0;
            this.reason = '';
            this.resetReservedConfirmation();
            this.deductBalanceModalVisible = true;
        },

        resetReservedConfirmation() {
            this.reservedConfirmRequired = false;
            this.allowReserved = false;
            this.reservedNotice = '';
        },

        openAddCustomerModal() {
            this.selectedNewCustomer = null;
            this.newCustomerAmount = 0;
            this.addCustomerModalVisible = true;
            this.fetchCustomers();
        },

        addBalance() {
            if (this.isSubmitting) return;

            const amount = parseFloat(this.amount);
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.amountMustBePositive') });
            }

            this.isSubmitting = true;

            fetch('/api/store-credit/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify({
                    customerId: this.selectedCustomer.customerId,
                    amount,
                    reason: this.reason || this.$tc('store-credits.common.adminUpdate'),
                }),
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || this.$tc('store-credits.listing.addBalanceFailed'));
                    this.createNotificationSuccess({ title: this.$tc('store-credits.common.success'), message: this.$tc('store-credits.listing.addedSuccess') });
                    this.addBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error adding balance:', error);
                    this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: error.message });
                })
                .finally(() => {
                    this.isSubmitting = false;
                });
        },

        deductBalance() {
            if (this.isSubmitting) return;

            const amount = parseFloat(this.amount);
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.amountMustBePositive') });
            }

            this.isSubmitting = true;

            fetch('/api/store-credit/deduct', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify({
                    customerId: this.selectedCustomer.customerId,
                    amount,
                    reason: this.reason || this.$tc('store-credits.common.adminUpdate'),
                    allowReserved: this.allowReserved,
                }),
            })
                .then(async response => {
                    const data = await response.json();

                    if (data.requiresReservedConfirmation) {
                        this.reservedConfirmRequired = true;
                        this.allowReserved = false;
                        this.reservedNotice = this.$tc('store-credits.customer.reservedConfirmMessage', 0, {
                            available: data.available.toFixed(2),
                            reserved: data.reserved.toFixed(2),
                        });
                        return;
                    }

                    if (!response.ok || !data.success) throw new Error(data.message || this.$tc('store-credits.listing.deductBalanceFailed'));
                    this.createNotificationSuccess({ title: this.$tc('store-credits.common.success'), message: this.$tc('store-credits.listing.deductedSuccess') });
                    this.deductBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error deducting balance:', error);
                    this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: error.message });
                })
                .finally(() => {
                    this.isSubmitting = false;
                });
        },

        addCustomerCredit() {
            if (this.isSubmitting) return;

            const amount = parseFloat(this.newCustomerAmount);
            if (!this.selectedNewCustomer) {
                return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.selectCustomer') });
            }
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.amountMustBePositive') });
            }

            this.isSubmitting = true;

            fetch('/api/store-credit/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify({
                    customerId: this.selectedNewCustomer,
                    amount,
                    reason: this.$tc('store-credits.common.adminAdded'),
                }),
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || this.$tc('store-credits.listing.addStoreCreditFailed'));
                    this.createNotificationSuccess({ title: this.$tc('store-credits.common.success'), message: this.$tc('store-credits.listing.creditAddedSuccess') });
                    this.addCustomerModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error adding store credit:', error);
                    this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: error.message });
                })
                .finally(() => {
                    this.isSubmitting = false;
                });
        },

        navigateToCustomerHistory(storeCreditId, customerName, balance, customerId) {
            if (!storeCreditId) return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.invalidStoreCreditId') });

            this.$router.push({
                name: 'store.credits.history',
                params: { id: storeCreditId },
                query: { name: customerName, balance, customerId },
            });
        },

        openDeleteModal(storeCredit) {
            this.selectedStoreCredit = { ...storeCredit };
            this.confirmDeleteModalVisible = true;
        },

        deleteStoreCredit() {
            if (!this.selectedStoreCredit || !this.selectedStoreCredit.id) {
                return this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.invalidSelection') });
            }

            this.repository.delete(this.selectedStoreCredit.id, Shopware.Context.api)
                .then(() => {
                    this.createNotificationSuccess({ title: this.$tc('store-credits.common.success'), message: this.$tc('store-credits.listing.deletedSuccess') });
                    this.confirmDeleteModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch((error) => {
                    console.error('Error deleting store credit:', error);
                    this.createNotificationError({ title: this.$tc('store-credits.common.error'), message: this.$tc('store-credits.listing.deleteStoreCreditFailed') });
                    this.confirmDeleteModalVisible = false;
                });
        },
    },
});