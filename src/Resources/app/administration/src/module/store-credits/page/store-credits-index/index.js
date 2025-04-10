import template from './store-credits-index.html.twig';
import "./store-credits-index.scss"
import '../../components/sw-customer-grid'
const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('store-credits-index', {
    template,

    inject: ['repositoryFactory', 'httpClient'],

    mixins: [Mixin.getByName('notification')],

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
            ]
        };
    },

    created() {
        this.repository = this.repositoryFactory.create('store_credit');
        this.fetchStoreCredits();
    },

    methods: {
        fetchStoreCredits() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addAssociation('customer');

            this.repository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.storeCredits = result.map((credit) => ({
                        id: credit.id,
                        customerFullName: `${credit.customer.firstName} ${credit.customer.lastName}`,
                        balance: credit.balance || 0,
                        customerId: credit.customerId,
                        storeCreditId: credit.id,
                        // actions: '', // Placeholder to prevent grid misalignment
                        // history: '', // Placeholder to prevent grid misalignment
                    }));
                })
                .catch((error) => {
                    console.error('Error fetching store credits:', error);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        formatCurrency(value) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
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
            console.log("Opening Add Customer Modal");
            this.selectedNewCustomer = null;
            this.newCustomerAmount = 0;
            this.addCustomerModalVisible = true;
            this.fetchCustomers();
        },


        addBalance() {
            const amount = parseFloat(this.amount);

            if (isNaN(amount) || amount <= 0) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Amount must be greater than zero.',
                });
                return;
            }

            const payload = {
                customerId: this.selectedCustomer.customerId,
                amount: amount,
                reason: this.reason || 'Admin update',
            };
            fetch('/api/store-credit/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify(payload),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(() => {
                    this.createNotificationSuccess({
                        title: 'Success',
                        message: 'Balance added successfully!',
                    });
                    this.addBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch((error) => {
                    console.error('Error adding balance:', error);
                    this.createNotificationError({
                        title: 'Error',
                        message: 'Failed to add balance.',
                    });
                });
        },

        deductBalance() {
            const amount = parseFloat(this.amount);

            if (isNaN(amount) || amount <= 0) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Amount must be greater than zero.',
                });
                return;
            }

            const payload = {
                customerId: this.selectedCustomer.customerId,
                amount: amount,
                reason: this.reason || 'Admin update',
            };

            fetch('/api/store-credit/deduct', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                },
                body: JSON.stringify(payload),
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(() => {
                    this.createNotificationSuccess({
                        title: 'Success',
                        message: 'Balance deducted successfully!',
                    });
                    this.deductBalanceModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch((error) => {
                    console.error('Error deducting balance:', error);
                    this.createNotificationError({
                        title: 'Error',
                        message: 'Failed to deduct balance.',
                    });
                });
        },

        addCustomerCredit() {
            const amount = parseFloat(this.newCustomerAmount);

            if (!this.selectedNewCustomer) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Please select a customer.',
                });
                return;
            }

            if (isNaN(amount) || amount <= 0) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Amount must be greater than zero.',
                });
                return;
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
                    amount: amount,
                    reason: 'Admin added store credit',
                }),
            })
                .then((response) => {
                    if (!response.ok) {
                        return response.json().then((errorData) => {
                            throw new Error(errorData.message || 'An error occurred');
                        });
                    }
                    return response.json();
                })
                .then((responseData) => {
                    if (responseData.success) {
                        this.createNotificationSuccess({
                            title: 'Success',
                            message: 'Store credit added successfully!',
                        });
                        this.addCustomerModalVisible = false;
                        this.fetchStoreCredits();
                    } else {
                        throw new Error(responseData.message || 'An error occurred');
                    }
                })
                .catch((error) => {
                    console.error('Error adding store credit:', error);
                    this.createNotificationError({
                        title: 'Error',
                        message: error.message || 'Failed to add store credit.',
                    });
                });
        },

        navigateToCustomerHistory(storeCreditId, customerName, balance) {
            if (!storeCreditId) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Invalid store credit ID.',
                });
                return;
            }
            this.$router.push({
                name: 'store.credits.history',
                params: { id: storeCreditId },
                query: {
                    name: customerName,
                    balance: balance,
                },
            });
        },
        openDeleteModal(storeCredit) {
            console.log('Opening delete modal for:', storeCredit); // Debugging
            this.selectedStoreCredit = { ...storeCredit };
            this.confirmDeleteModalVisible = true;
        },

        deleteStoreCredit() {
            if (!this.selectedStoreCredit || !this.selectedStoreCredit.id) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Invalid store credit selection.',
                });
                return;
            }

            this.repository.delete(this.selectedStoreCredit.id, Shopware.Context.api)
                .then(() => {
                    this.createNotificationSuccess({
                        title: 'Success',
                        message: 'Store credit deleted successfully!',
                    });
                    this.confirmDeleteModalVisible = false;
                    this.fetchStoreCredits();
                })
                .catch((error) => {
                    console.error('Error deleting store credit:', error);
                    this.createNotificationError({
                        title: 'Error',
                        message: 'Failed to delete store credit.',
                    });
                });
        },
    },
});
