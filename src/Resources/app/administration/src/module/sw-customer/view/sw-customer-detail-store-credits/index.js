import template from './sw-customer-detail-store-credits.html.twig';
import './sw-customer-detail-store-credits.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-customer-detail-store-credits', {
    template,
    inject: ['storeCreditApiService', 'repositoryFactory', 'acl'],
    mixins: [Mixin.getByName('notification')],
    props: {
        customer: { type: Object, required: true },
        customerEditMode: { type: Boolean, default: false },
    },
    data() {
        return {
            isLoading: false, isSaving: false, balance: 0, currencyId: null,
            currencyIsoCode: Shopware.Context.app.systemCurrencyISOCode || 'EUR',
            addAmount: null, deductAmount: null, addReason: '', deductReason: '',
            showAddModal: false, showDeductModal: false, loadSequence: 0,
            reserved: 0, available: 0, reservedOrders: [], expiredOrders: [],
            reservedConfirmRequired: false, allowReserved: false, reservedNotice: '',
        };
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
        'customer.id': {
            immediate: true,
            handler() {
                this.closeAddModal();
                this.closeDeductModal();
                this.loadStoreCreditBalance();
            },
        },
        deductAmount() {
            this.resetReservedConfirmation();
        },
    },
    methods: {
        async loadStoreCreditBalance() {
            const sequence = ++this.loadSequence;
            this.balance = 0;
            this.reserved = 0;
            this.available = 0;
            this.reservedOrders = [];
            this.expiredOrders = [];
            this.currencyId = null;
            this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            this.isLoading = false;
            if (!this.customer?.id) return;
            this.isLoading = true;
            try {
                const data = await this.storeCreditApiService.balance(this.customer.id);
                const currency = data.currencyId
                    ? await this.repositoryFactory.create('currency').get(data.currencyId, Shopware.Context.api) : null;
                if (sequence !== this.loadSequence) return;
                this.balance = Number(data.balance) || 0;
                this.reserved = Number(data.reserved) || 0;
                this.available = data.available === undefined ? this.balance : (Number(data.available) || 0);
                this.reservedOrders = data.reservedOrders || [];
                this.expiredOrders = data.expiredOrders || [];
                this.currencyId = data.currencyId;
                this.currencyIsoCode = currency?.isoCode || Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            } catch (error) {
                if (sequence === this.loadSequence) {
                    this.createNotificationError({ message: error.response?.data?.message || 'Could not load store credit.' });
                }
            } finally {
                if (sequence === this.loadSequence) this.isLoading = false;
            }
        },
        openAddModal() { this.addAmount = null; this.addReason = ''; this.showAddModal = true; },
        closeAddModal() { this.showAddModal = false; },
        openDeductModal() { this.deductAmount = null; this.deductReason = ''; this.showDeductModal = true; },
        closeDeductModal() { this.showDeductModal = false; this.resetReservedConfirmation(); },
        resetReservedConfirmation() {
            this.reservedConfirmRequired = false;
            this.allowReserved = false;
            this.reservedNotice = '';
        },
        addCredit() { return this.adjustCredit('add', this.addAmount, this.addReason); },
        deductCredit() { return this.adjustCredit('deduct', this.deductAmount, this.deductReason); },
        async adjustCredit(action, value, reason) {
            if (this.isSaving || this.isLoading || !this.customer?.id || !this.acl.can('store_credit.editor')) return;
            const amount = Number(value);
            if (!Number.isFinite(amount) || amount <= 0 || (action === 'deduct' && amount > this.balance)) {
                this.createNotificationError({ message: 'Enter a positive amount within the available balance.' });
                return;
            }
            this.isSaving = true;
            try {
                const args = [action, this.customer.id, amount, reason || 'Admin update', this.currencyId];
                if (action === 'deduct' && this.allowReserved) args.push(true);
                await this.storeCreditApiService.adjust(...args);
                this.createNotificationSuccess({ message: 'Store credit updated successfully.' });
                this.closeAddModal();
                this.closeDeductModal();
                await this.loadStoreCreditBalance();
            } catch (error) {
                const details = error.response?.data;
                if (details?.requiresReservedConfirmation) {
                    this.reservedConfirmRequired = true;
                    this.allowReserved = false;
                    this.reservedNotice = this.$t('store-credits.customer.reservedConfirmMessage', {
                        available: this.formatCurrency(details.available),
                        reserved: this.formatCurrency(details.reserved),
                    });
                } else {
                    this.createNotificationError({ message: details?.message || error.message || 'Could not update store credit.' });
                }
            } finally {
                this.isSaving = false;
            }
        },
        formatCurrency(value) {
            return new Intl.NumberFormat(Shopware.Context.app.locale?.replace('_', '-') || 'en-GB', {
                style: 'currency', currency: this.currencyIsoCode,
            }).format(value);
        },
    },
});
