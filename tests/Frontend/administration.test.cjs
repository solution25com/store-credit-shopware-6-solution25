const { test, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!doctype html><html><body></body></html>');
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node', 'MutationObserver']) global[key] = dom.window[key];
global.navigator = dom.window.navigator;
global.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
const { mount, flushPromises } = require('@vue/test-utils');
const { h } = require('vue');
const { createI18n } = require('vue-i18n');
const root = path.resolve(__dirname, '../..');
const admin = path.join(root, 'src/Resources/app/administration/src');
const load = Module._extensions['.js'];
Module._extensions['.js'] = (module, filename) => filename.includes('meteor')
    ? module._compile(fs.readFileSync(filename, 'utf8').replace(/^import ['"].*\.css['"];?\s*$/gm, ''), filename)
    : load(module, filename);
Module._extensions['.css'] = () => {};
const latest = Object.fromEntries(['MtNumberField', 'MtTextField', 'MtTextarea', 'MtButton', 'MtCard', 'MtIcon'].map(name => [name, require(`@shopware-ag/meteor-component-library/${name}`)]));
const lowest = require(path.join(root, 'node_modules/meteor-lowest/dist/common/index.js'));
const wrappers = [];
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); });
const twig = file => fs.readFileSync(path.join(admin, file), 'utf8').replace(/{%[\s\S]*?%}/g, '');
function config(file, additional = {}) {
    let result;
    vm.runInNewContext(fs.readFileSync(path.join(admin, file), 'utf8').replace(/^import[^;]*;\s*/gm, ''), {
        template: '', Intl, console, ...additional,
        Shopware: {
            Component: { register: (_name, value) => { result = value; } },
            Mixin: { getByName: () => ({}) },
            Context: { api: {}, app: { locale: 'en-GB', systemCurrencyISOCode: 'EUR' } },
            Data: { Criteria: class { constructor() {} addAssociation() { return this; } addFilter() { return this; } addSorting() { return this; } setPage() {} setLimit() {} } },
        },
    });
    return result;
}
function customerTab(components, canEdit = true, service = {}) {
    const events = [];
    const cfg = config('module/sw-customer/view/sw-customer-detail-store-credits/index.js');
    cfg.template = twig('module/sw-customer/view/sw-customer-detail-store-credits/sw-customer-detail-store-credits.html.twig');
    cfg.methods.createNotificationError = message => events.push(['error', message]);
    cfg.methods.createNotificationSuccess = message => events.push(['success', message]);
    const wrapper = mount(cfg, {
        props: { customer: { id: 'customer-a' } },
        global: {
            components,
            plugins: [createI18n({ legacy: false, locale: 'en', messages: { en: {} }, missingWarn: false, fallbackWarn: false })],
            provide: {
                acl: { can: () => canEdit },
                repositoryFactory: { create: () => ({ get: async () => ({ isoCode: 'USD' }) }) },
                storeCreditApiService: { balance: async () => ({ balance: 50, currencyId: 'currency' }), adjust: async () => ({ success: true }), ...service },
            },
            stubs: {
                'sw-container': { template: '<div><slot /></div>' },
                'sw-modal': { template: '<div><slot /><slot name="modal-footer" /></div>' },
            },
            mocks: { $t: value => value },
        },
    });
    wrappers.push(wrapper);
    return { wrapper, events };
}
for (const [version, components] of [['4.12.2 (Shopware 6.7.0)', lowest], ['5.2.0 (Shopware 6.7.13)', latest]]) {
    test(`customer tab receives real Meteor amount and textarea updates on ${version}`, async () => {
        const calls = [];
        const { wrapper } = customerTab(components, true, { adjust: async (...args) => { calls.push(args); } });
        await flushPromises();
        assert.equal(wrapper.vm.balance, 50);
        wrapper.vm.openAddModal();
        await wrapper.vm.$nextTick();
        await wrapper.find('input').setValue('12.50');
        await wrapper.find('input').trigger('change');
        await wrapper.find('textarea').setValue('Return credit');
        assert.equal(wrapper.vm.addAmount, 12.5);
        assert.equal(wrapper.vm.addReason, 'Return credit');
        await wrapper.vm.addCredit();
        assert.deepEqual(calls[0], ['add', 'customer-a', 12.5, 'Return credit', 'currency']);
        assert.equal(wrapper.vm.showAddModal, false);
    });
    test(`read-only customer tab disables mutations on ${version}`, async () => {
        const calls = [];
        const { wrapper } = customerTab(components, false, { adjust: async (...args) => calls.push(args) });
        await flushPromises();
        assert.ok(wrapper.findAll('.sw-customer-detail-store-credits__actions button').every(button => button.attributes('disabled') !== undefined));
        wrapper.vm.addAmount = 10;
        await wrapper.vm.addCredit();
        assert.equal(calls.length, 0);
    });
}

test('a pending adjustment blocks duplicate submissions', async () => {
    let complete;
    let count = 0;
    const { wrapper } = customerTab(latest, true, { adjust: () => { count++; return new Promise(resolve => { complete = resolve; }); } });
    await flushPromises();
    wrapper.vm.addAmount = 10;
    const first = wrapper.vm.addCredit();
    await wrapper.vm.addCredit();
    assert.equal(count, 1);
    complete();
    await first;
    assert.equal(wrapper.vm.isSaving, false);
});

test('an old customer balance response cannot overwrite a newly selected customer', async () => {
    let finishOld;
    const { wrapper } = customerTab(latest, true, { balance: id => id === 'customer-a' ? new Promise(resolve => { finishOld = resolve; }) : Promise.resolve({ balance: 7, currencyId: 'currency' }) });
    await wrapper.setProps({ customer: { id: 'customer-b' } });
    await flushPromises();
    finishOld({ balance: 100, currencyId: 'currency' });
    await flushPromises();
    assert.equal(wrapper.vm.balance, 7);
});

test('API service uses relative URLs, fresh authentication headers and currency', async () => {
    let Service;
    const source = fs.readFileSync(path.join(admin, 'service/store-credit-api.service.js'), 'utf8').replace('export default class StoreCreditApiService', 'class StoreCreditApiService') + '\nService = StoreCreditApiService;';
    const box = { Service: null, Shopware: { Classes: { ApiService: class {
        constructor(client, login, endpoint) { this.httpClient = client; this.loginService = login; this.endpoint = endpoint; }
        getApiBasePath() { return this.endpoint; }
        getBasicHeaders() { return { Authorization: `Bearer ${this.loginService.getToken()}` }; }
        static handleResponse(response) { return response.data; }
    } } } };
    vm.runInNewContext(source, box);
    Service = box.Service;
    const calls = [];
    let token = 'old';
    const client = { get: async (...args) => { calls.push(args); return { data: { success: true } }; }, post: async (...args) => { calls.push(args); return { data: { success: true } }; } };
    const api = new Service(client, { getToken: () => token });
    await api.balance('customer');
    token = 'new';
    await api.adjust('deduct', 'customer', 1.5, 'test', 'currency');
    assert.equal(calls[0][0], 'store-credit/balance');
    assert.equal(calls[1][0], 'store-credit/deduct');
    assert.equal(calls[1][2].headers.Authorization, 'Bearer new');
    assert.equal(calls[1][1].currencyId, 'currency');
});

test('customer route middleware registers its tab only once', () => {
    let middleware;
    vm.runInNewContext(fs.readFileSync(path.join(admin, 'module/sw-customer/index.js'), 'utf8').replace(/^import[^;]*;\s*/gm, ''), {
        Shopware: { Module: { register: (_name, value) => { middleware = value.routeMiddleware; } } },
    });
    const route = { name: 'sw.customer.detail', children: [] };
    middleware(() => {}, route);
    middleware(() => {}, route);
    assert.equal(route.children.length, 1);
    assert.equal(route.children[0].meta.privilege, 'store_credit.viewer');
});


test('a failed customer switch cannot reuse the previous wallet currency', async () => {
    const { wrapper, events } = customerTab(latest, true, { balance: id => id === 'customer-a'
        ? Promise.resolve({ balance: 7, currencyId: 'old-currency' })
        : Promise.reject(new Error('Request failed')) });
    await flushPromises();
    assert.equal(wrapper.vm.currencyId, 'old-currency');
    await wrapper.setProps({ customer: { id: 'customer-b' } });
    await flushPromises();
    assert.equal(wrapper.vm.currencyId, null);
    assert.equal(wrapper.vm.currencyIsoCode, 'EUR');
    assert.equal(wrapper.vm.balance, 0);
    assert.equal(events.at(-1)[0], 'error');
});
