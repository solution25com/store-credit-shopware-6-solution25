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
        };
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
    },
    methods: {
        async loadStoreCreditBalance() {
            const sequence = ++this.loadSequence;
            this.balance = 0;
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
        closeDeductModal() { this.showDeductModal = false; },
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
                await this.storeCreditApiService.adjust(action, this.customer.id, amount, reason || 'Admin update', this.currencyId);
                this.createNotificationSuccess({ message: 'Store credit updated successfully.' });
                this.closeAddModal();
                this.closeDeductModal();
                await this.loadStoreCreditBalance();
            } catch (error) {
                this.createNotificationError({ message: error.response?.data?.message || error.message || 'Could not update store credit.' });
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
