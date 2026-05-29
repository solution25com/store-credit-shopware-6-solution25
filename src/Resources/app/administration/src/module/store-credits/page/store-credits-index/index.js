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
            total: 0,
            columns: [
                { property: 'customerFullName', label: 'Customer Full Name', allowResize: true, sortable: true },
                { property: 'balance', label: 'Balance', allowResize: true },
                {
                    property: 'actions',
                    label: 'Balance Actions',
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
        
        this.fetchStoreCredits();
    },

    watch: {
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
    },

    methods: {
        fetchStoreCredits() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.setPage(this.page);
            criteria.setLimit(this.limit);
            criteria.addAssociation('customer');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            this.repository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.storeCredits = result.map((credit) => {
                        const customer = credit.customer;
                        const firstName = customer?.firstName || '';
                        const lastName = customer?.lastName || '';
                        const customerFullName = (firstName + ' ' + lastName).trim() || 'N/A';
                        
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
                        title: 'Error',
                        message: error.response?.data?.errors?.[0]?.detail || 'Failed to load store credits. Please refresh the page.',
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
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
            this.$router.push({
                name: this.$route.name,
                query: {
                    ...this.$route.query,
                    page: this.page,
                    limit: this.limit,
                },
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
            this.deductBalanceModalVisible = true;
        },

        openAddCustomerModal() {
            this.selectedNewCustomer = null;
            this.newCustomerAmount = 0;
            this.addCustomerModalVisible = true;
            this.fetchCustomers();
        },

        addBalance() {
            const amount = parseFloat(this.amount);
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: 'Error', message: 'Amount must be greater than zero.' });
            }

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
                    reason: this.reason || 'Admin update',
                }),
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || 'Failed to add balance.');
                    this.createNotificationSuccess({ title: 'Success', message: 'Balance added successfully!' });
                    this.addBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error adding balance:', error);
                    this.createNotificationError({ title: 'Error', message: error.message });
                });
        },

        deductBalance() {
            const amount = parseFloat(this.amount);
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: 'Error', message: 'Amount must be greater than zero.' });
            }

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
                    reason: this.reason || 'Admin update',
                }),
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || 'Failed to deduct balance.');
                    this.createNotificationSuccess({ title: 'Success', message: 'Balance deducted successfully!' });
                    this.deductBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error deducting balance:', error);
                    this.createNotificationError({ title: 'Error', message: error.message });
                });
        },

        addCustomerCredit() {
            const amount = parseFloat(this.newCustomerAmount);
            if (!this.selectedNewCustomer) {
                return this.createNotificationError({ title: 'Error', message: 'Please select a customer.' });
            }
            if (isNaN(amount) || amount <= 0) {
                return this.createNotificationError({ title: 'Error', message: 'Amount must be greater than zero.' });
            }

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
                    reason: 'Admin added store credit',
                }),
            })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok || !data.success) throw new Error(data.message || 'Failed to add store credit.');
                    this.createNotificationSuccess({ title: 'Success', message: 'Store credit added successfully!' });
                    this.addCustomerModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch(error => {
                    console.error('Error adding store credit:', error);
                    this.createNotificationError({ title: 'Error', message: error.message });
                });
        },

        navigateToCustomerHistory(storeCreditId, customerName, balance, customerId) {
            if (!storeCreditId) return this.createNotificationError({ title: 'Error', message: 'Invalid store credit ID.' });

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
                return this.createNotificationError({ title: 'Error', message: 'Invalid store credit selection.' });
            }

            this.repository.delete(this.selectedStoreCredit.id, Shopware.Context.api)
                .then(() => {
                    this.createNotificationSuccess({ title: 'Success', message: 'Store credit deleted successfully!' });
                    this.confirmDeleteModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch((error) => {
                    console.error('Error deleting store credit:', error);
                    this.createNotificationError({ title: 'Error', message: 'Failed to delete store credit.' });
                    this.confirmDeleteModalVisible = false;
                });
        },
    },
});