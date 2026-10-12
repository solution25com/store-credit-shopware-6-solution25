import './acl';
import './service';
import './module/store-credits';
import './module/sw-customer';
import './module/sw-order/component/sw-order-line-items-grid-sales-channel';
import './module/sw-order/component/sw-order-address-selection';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

Shopware.Snippet.extend('en-GB', enGB);
Shopware.Snippet.extend('de-DE', deDE);
