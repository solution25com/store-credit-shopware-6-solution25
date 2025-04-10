import StoreCreditPlugin from "./store-credit/store-credit.plugin";

const PluginManager = window.PluginManager;
PluginManager.register('StoreCreditPlugin', StoreCreditPlugin, '[data-storecredit-plugin]');