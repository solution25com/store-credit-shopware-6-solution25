Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'customers',
    key: 'store_credit',
    roles: {
        viewer: {
            privileges: ['solu1_store_credit:read', 'solu1_store_credit_history:read', 'customer:read', 'currency:read'],
            dependencies: ['customer.viewer'],
        },
        editor: {
            privileges: ['solu1_store_credit:create', 'solu1_store_credit:update'],
            dependencies: ['store_credit.viewer'],
        },
        deleter: {
            privileges: ['solu1_store_credit:delete'],
            dependencies: ['store_credit.viewer'],
        },
    },
});
