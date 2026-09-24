import './page/store-credits-index';
import './page/store-credits-history';

Shopware.Module.register('store-credits', {
    type: 'plugin',
    name: 'store-credits',
    title: 'Store Credits',
    description: 'Manage customer store credits.',
    color: '#ffcc00',
    icon: 'regular-wallet',
    routes: {
        index: {
            component: 'store-credits-index',
            path: 'index',
            meta: { privilege: 'store_credit.viewer' },
        },
        history: {
            component: 'store-credits-history',
            path: 'history/:id',
            meta: { privilege: 'store_credit.viewer' },
        },
    },
    navigation: [
        {
            label: 'Store Credits',
            color: '#ffcc00',
            path: 'store.credits.index',
            icon: 'regular-wallet',
            position: 100,
            parent: 'sw-customer',
            privilege: 'store_credit.viewer',
        },
    ],
});
