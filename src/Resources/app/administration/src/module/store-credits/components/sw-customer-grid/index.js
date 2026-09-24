import template from './sw-customer-grid.html.twig';

Shopware.Component.register('sw-customer-grid', {
    template,
    props: { value: { type: String, default: null } },
    emits: ['update:value'],
    computed: {
        customerCriteria() {
            return new Shopware.Data.Criteria(1, 25);
        },
    },
});
