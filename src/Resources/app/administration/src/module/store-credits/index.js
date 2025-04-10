import './page/store-credits-index';
import './page/store-credits-history'

Shopware.Module.register('store-credits', {
    type: 'plugin',
    name: 'store-credits',
    title: 'Store Credits',
    description: 'Manage customer store credits.',
    color: '#ffcc00',
    icon: 'default-money-coin',
    routes: {
        index: {
            component: 'store-credits-index',
            path: 'index',
        },
        history: {
            component: 'store-credits-history',
            path: 'history/:id',
        },
    },
    navigation: [
        {
            label: 'Store Credits',
            color: '#ffcc00',
            path: 'store.credits.index',
            icon: 'default-money-coin',
            position: 100,
            parent: 'sw-customer',
        },
    ],
});
