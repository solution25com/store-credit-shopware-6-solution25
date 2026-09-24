import './view/sw-customer-detail-store-credits';
import './extension/sw-customer-detail';


const { Module } = Shopware;

Module.register('sw-customer-store-credits-extension', {
    type: 'extension',
    name: 'sw-customer-store-credits-extension',

    routeMiddleware(next, currentRoute) {
        if (currentRoute && currentRoute.name === 'sw.customer.detail') {
            if (!currentRoute.children) {
                currentRoute.children = [];
            }
            if (currentRoute.children.some(route => route.name === 'sw.customer.detail.store-credits')) {
                next(currentRoute);
                return;
            }
            currentRoute.children.push({
                component: 'sw-customer-detail-store-credits',
                name: 'sw.customer.detail.store-credits',
                isChildren: true,
                path: 'store-credits',
                meta: {
                    parentPath: 'sw.customer.index',
                    privilege: 'store_credit.viewer',
                },
            });
        }
        next(currentRoute);
    },
});


