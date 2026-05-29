import template from './sw-customer-detail.html.twig';

Shopware.Component.override('sw-customer-detail', {
    template,

    computed: {
        storeCreditsRoute() {
            if (!this.customerId) {
                return null;
            }
            return {
                name: 'sw.customer.detail.store-credits',
                params: { id: this.customerId },
                query: { edit: this.editMode || false },
            };
        },
    },
});

