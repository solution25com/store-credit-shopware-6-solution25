import template from './store-credits-history.html.twig';
import './store-credits-history.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('store-credits-history', {
    template,

    "inject": ['repositoryFactory'],

    "mixins": [Mixin.getByName('notification')],

    "data"() {
        return {
            "history": [],
            "isLoading": false,
            "customerName": this.$route.query.name || 'Unknown',
            "customerBalance": parseFloat(this.$route.query.balance) || 0,
            "currencyIsoCode": null,
            "page": 1,
            "limit": 10,
            "total": 0,
        };
    },

    "created"() {
        this.loadStoreCreditCurrency();
        this.fetchHistory();
    },

    methods: {
        goBack() {
            this.$router.push({ name: 'store.credits.index' });
        },
        async loadStoreCreditCurrency() {
            try {
                const storeCreditRepository = this.repositoryFactory.create('solu1_store_credit');
                const storeCredit = await storeCreditRepository.get(this.$route.params.id, Shopware.Context.api, {
                    associations: ['currency'],
                });
                if (storeCredit?.currency?.isoCode) {
                    this.currencyIsoCode = storeCredit.currency.isoCode;
                } else {
                    this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
                }
            } catch (error) {
                console.error('Error loading store credit currency:', error);
                this.currencyIsoCode = Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            }
        },
        fetchHistory() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.setPage(this.page);
            criteria.setLimit(this.limit);
            criteria.addFilter(Criteria.equals('storeCreditId', this.$route.params.id));
            criteria.addAssociation('currency');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            try {
                const repository = this.repositoryFactory.create('solu1_store_credit_history');
                repository.search(criteria, Shopware.Context.api)
                    .then((result) => {
                        this.history = result.map((item) => ({
                            ...item,
                            currencyIsoCode: item.currency?.isoCode || this.currencyIsoCode || Shopware.Context.app.systemCurrencyISOCode || 'EUR',
                        }));
                        this.total = result.total || 0;
                    })
                    .catch((error) => {
                        console.error('Error fetching store credit history:', error);
                        console.error('Error details:', {
                            message: error.message,
                            response: error.response,
                            stack: error.stack,
                        });
                        this.createNotificationError({
                            title: 'Error',
                            message: error.response?.data?.errors?.[0]?.detail || error.message || 'Failed to fetch history.',
                        });
                    })
                    .finally(() => {
                        this.isLoading = false;
                    });
            } catch (error) {
                console.error('Error creating repository:', error);
                this.createNotificationError({
                    title: 'Error',
                    message: error.message || 'Failed to create repository for store credit history.',
                });
                this.isLoading = false;
            }
        },
        onPageChange({ page, limit }) {
            this.page = page;
            if (limit) {
                this.limit = limit;
            }
            this.fetchHistory();
        },
        onLimitChange(limit) {
            this.limit = limit;
            this.page = 1;
            this.fetchHistory();
        },
        formatCurrency(value, currencyIsoCode = null) {
            const currency = currencyIsoCode || this.currencyIsoCode || Shopware.Context.app.systemCurrencyISOCode || 'EUR';
            const locale = Shopware.Context.app.locale?.replace('_', '-') || 'en-US';
            return new Intl.NumberFormat(locale, {
                style: 'currency',
                currency: currency,
            }).format(value);
        },
        formatDate(dateString) {
            const options = {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            };
            return new Date(dateString).toLocaleDateString('en-US', options);
        },
    },
});
