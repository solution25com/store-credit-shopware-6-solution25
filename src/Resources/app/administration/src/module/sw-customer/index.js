import './view/sw-customer-detail-store-credits';
import './extension/sw-customer-detail';

Shopware.Component.register('sw-customer-detail-store-credits', () => import('./view/sw-customer-detail-store-credits'));

const { Module } = Shopware;

Module.register('sw-customer-store-credits-extension', {
    type: 'extension',
    name: 'sw-customer-store-credits-extension',
    
    routeMiddleware(next, currentRoute) {
        if (currentRoute && currentRoute.name === 'sw.customer.detail') {
            if (!currentRoute.children) {
                currentRoute.children = [];
            }
            currentRoute.children.push({
                component: 'sw-customer-detail-store-credits',
                name: 'sw.customer.detail.store-credits',
                isChildren: true,
                path: 'store-credits',
                meta: {
                    parentPath: 'sw.customer.index',
                    privilege: 'customer.viewer',
                },
            });
        }
        next(currentRoute);
    },
});


