const { ApiService } = Shopware.Classes;

export default class StoreCreditApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'store-credit', 'application/json');
    }

    async balance(customerId) {
        const response = await this.httpClient.get(`${this.getApiBasePath()}/balance`, {
            headers: this.getBasicHeaders(), params: { customerId },
        });
        return ApiService.handleResponse(response);
    }

    async adjust(action, customerId, amount, reason, currencyId = null) {
        const response = await this.httpClient.post(`${this.getApiBasePath()}/${action}`, {
            customerId, amount, reason, currencyId,
        }, { headers: this.getBasicHeaders() });
        return ApiService.handleResponse(response);
    }
}
