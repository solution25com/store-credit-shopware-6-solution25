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
            "customerBalance": this.$route.query.balance || 0,
        };
    },

    "created"() {
        this.fetchHistory();
    },

    methods: {
        goBack() {
            this.$router.push({ name: 'store.credits.index' });
        },
        fetchHistory() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('storeCreditId', this.$route.params.id));
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            const repository = this.repositoryFactory.create('store_credit_history');
            repository.search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.history = result;
                })
                .catch((error) => {
                    console.error(error);
                    this.createNotificationError({
                        title: 'Error',
                        message: 'Failed to fetch history.',
                    });
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
