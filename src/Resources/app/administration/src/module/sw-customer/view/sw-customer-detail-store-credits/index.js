import template from './sw-customer-detail-store-credits.html.twig';
import './sw-customer-detail-store-credits.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-customer-detail-store-credits', {
    template,

    inject: ['httpClient', 'repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    props: {
        customer: {
            type: Object,
            required: true,
        },
        customerEditMode: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            isLoading: false,
            balance: 0.0,
            currencyId: null,
            currencyIsoCode: 'EUR',
            addAmount: null,
            deductAmount: null,
            addReason: '',
            deductReason: '',
            showAddModal: false,
            showDeductModal: false,
            reserved: 0.0,
            available: 0.0,
            reservedOrders: [],
            expiredOrders: [],
            reservedConfirmRequired: false,
            allowReserved: false,
            reservedNotice: '',
        };
    },

    created() {
        this.loadStoreCreditBalance();
    },

    computed: {
        reservedOrderNumbers() {
            return this.reservedOrders.map((order) => order.orderNumber).join(', ');
        },

        expiredOrderNumbers() {
            return this.expiredOrders.map((order) => order.orderNumber).join(', ');
        },
    },

    watch: {
        customer() {
            this.loadStoreCreditBalance();
        },

        deductAmount() {
            this.resetReservedConfirmation();
        },
    },

    methods: {
        async loadStoreCreditBalance() {
            if (!this.customer?.id) {
                return;
            }

            this.isLoading = true;
            try {
                const response = await fetch(`/api/store-credit/balance?customerId=${this.customer.id}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                });

                const data = await response.json();
                if (data.success) {
                    this.balance = parseFloat(data.balance) || 0.0;
                    this.reserved = parseFloat(data.reserved) || 0.0;
                    this.available = data.available !== undefined ? (parseFloat(data.available) || 0.0) : this.balance;
                    this.reservedOrders = data.reservedOrders || [];
                    this.expiredOrders = data.expiredOrders || [];
                    this.currencyId = data.currencyId;
                    await this.loadCurrencyIsoCode();
                }
            } catch (error) {
                console.error('Error loading store credit balance:', error);
            } finally {
                this.isLoading = false;
            }
        },

        openAddModal() {
            this.addAmount = null;
            this.addReason = '';
            this.showAddModal = true;
        },

        closeAddModal() {
            this.showAddModal = false;
            this.addAmount = null;
            this.addReason = '';
        },

        openDeductModal() {
            this.deductAmount = null;
            this.deductReason = '';
            this.showDeductModal = true;
        },

        closeDeductModal() {
            this.showDeductModal = false;
            this.deductAmount = null;
            this.deductReason = '';
            this.resetReservedConfirmation();
        },

        resetReservedConfirmation() {
            this.reservedConfirmRequired = false;
            this.allowReserved = false;
            this.reservedNotice = '';
        },

        async addCredit() {
            if (!this.addAmount || this.addAmount <= 0) {
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: this.$tc('store-credits.listing.amountMustBePositive'),
                });
                return;
            }
            const amount = parseFloat(this.addAmount);
            if (isNaN(amount) || amount <= 0) {
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: this.$tc('store-credits.listing.amountMustBePositive'),
                });
                return;
            }

            this.isLoading = true;
            try {
                const response = await fetch('/api/store-credit/add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify({
                        customerId: this.customer.id,
                        amount: amount,
                        reason: this.addReason || this.$tc('store-credits.common.adminAdded'),
                    }),
                });

                const data = await response.json();
                if (data.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('store-credits.common.success'),
                        message: this.$tc('store-credits.customer.addSuccess'),
                    });
                    this.closeAddModal();
                    await this.loadStoreCreditBalance();
                } else {
                    throw new Error(data.message || this.$tc('store-credits.customer.addFailed'));
                }
            } catch (error) {
                console.error('Error adding store credit:', error);
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: error.message || this.$tc('store-credits.customer.addFailed'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async deductCredit() {
            if (!this.deductAmount || this.deductAmount <= 0) {
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: this.$tc('store-credits.listing.amountMustBePositive'),
                });
                return;
            }
            const amount = parseFloat(this.deductAmount);
            if (isNaN(amount) || amount <= 0) {
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: this.$tc('store-credits.listing.amountMustBePositive'),
                });
                return;
            }

            if (amount > this.balance) {
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: this.$tc('store-credits.customer.amountCannotExceedBalance'),
                });
                return;
            }

            this.isLoading = true;
            try {
                const response = await fetch('/api/store-credit/deduct', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify({
                        customerId: this.customer.id,
                        amount: amount,
                        reason: this.deductReason || this.$tc('store-credits.common.adminDeducted'),
                        allowReserved: this.allowReserved,
                    }),
                });

                const data = await response.json();

                if (data.requiresReservedConfirmation) {
                    this.reservedConfirmRequired = true;
                    this.allowReserved = false;
                    this.reservedNotice = this.$tc('store-credits.customer.reservedConfirmMessage', 0, {
                        available: this.formatCurrency(data.available),
                        reserved: this.formatCurrency(data.reserved),
                    });
                    return;
                }

                if (data.success) {
                    this.createNotificationSuccess({
                        title: this.$tc('store-credits.common.success'),
                        message: this.$tc('store-credits.customer.deductSuccess'),
                    });
                    this.closeDeductModal();
                    await this.loadStoreCreditBalance();
                } else {
                    throw new Error(data.message || this.$tc('store-credits.customer.deductFailed'));
                }
            } catch (error) {
                console.error('Error deducting store credit:', error);
                this.createNotificationError({
                    title: this.$tc('store-credits.common.error'),
                    message: error.message || this.$tc('store-credits.customer.deductFailed'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async loadCurrencyIsoCode() {
            if (!this.currencyId) {
                this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
                return;
            }

            try {
                const currencyRepository = this.repositoryFactory.create('currency');
                const currency = await currencyRepository.get(this.currencyId, Shopware.Context.api);
                if (currency && currency.isoCode) {
                    this.currencyIsoCode = currency.isoCode;
                } else {
                    this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
                }
            } catch (error) {
                console.error('Error loading currency:', error);
                this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            }
        },

        formatCurrency(value) {
            const locale = Shopware.Context.app.locale?.replace('_', '-') || 'en-US';
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: this.currencyIsoCode,
            }).format(value);
        },
    },
});
