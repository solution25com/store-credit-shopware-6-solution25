import StoreCreditApiService from './store-credit-api.service';

Shopware.Application.addServiceProvider('storeCreditApiService', () => new StoreCreditApiService(
    Shopware.Application.getContainer('init').httpClient,
    Shopware.Service('loginService'),
));
