const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../src/Resources/app/storefront/src/store-credit/store-credit.plugin.js'), 'utf8').replace('export default class StoreCreditPlugin', 'class StoreCreditPlugin') + '\nPlugin = StoreCreditPlugin;';
function fixture(html = '<input id="storeCreditAmount" type="number" min="0.01" max="20" step="0.01"><button id="applyCreditButton"></button><small id="exceedCreditMessage"></small>') {
    const dom = new JSDOM(`<div data-storecredit-plugin>${html}</div>`);
    const box = { Plugin: null, window: { PluginBaseClass: class {} } };
    vm.runInNewContext(source, box);
    const plugin = new box.Plugin();
    plugin.el = dom.window.document.querySelector('div');
    plugin.init();
    return { plugin, window: dom.window };
}
test('storefront validates empty, negative, oversized and sub-cent amounts', () => {
    const { plugin, window } = fixture();
    assert.equal(plugin.button.disabled, true);
    for (const [amount, valid] of [['-1', false], ['0', false], ['21', false], ['1.001', false], ['0.01', true], ['20', true]]) {
        plugin.input.value = amount;
        plugin.input.dispatchEvent(new window.Event('input'));
        assert.equal(plugin.button.disabled, !valid, amount);
    }
    plugin.input.value = '21';
    plugin.checkAmountValidity();
    assert.equal(plugin.message.hidden, false);
    plugin.destroy();
    plugin.input.value = '2';
    plugin.input.dispatchEvent(new window.Event('input'));
    assert.equal(plugin.button.disabled, true, 'listener was removed');
});
test('storefront accepts a missing form and scopes separate instances', () => {
    const { plugin: empty } = fixture('');
    assert.doesNotThrow(() => empty.destroy());
    const { plugin: first } = fixture();
    const { plugin: second } = fixture();
    first.input.value = '10'; first.checkAmountValidity();
    assert.equal(first.button.disabled, false);
    assert.equal(second.button.disabled, true);
});
