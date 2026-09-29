# Store Credit — Shopware 6.7

This branch (`main-6.7`) contains Store Credit 1.2.0 for **Shopware 6.7.x only**, with PHP 8.2 or newer as permitted by the installed Shopware release. The separate `main` branch retains the previous version.

Administrators can grant or deduct customer credit and inspect its history. Customers can view their balance in their account and apply credit during checkout. Commercial Return Management is optional; it is required only for the return-to-credit workflow.

See [the compatibility audit](SHOPWARE_6.7_COMPATIBILITY_REVIEW.md) and [the fixes and verification report](SHOPWARE_6.7_FIXES.md).

## Installation and upgrades

From the Shopware project root, after placing the plugin in `custom/plugins/store-credit-shopware-6-solution25`:

```sh
bin/console plugin:refresh
bin/console plugin:install --activate StoreCredit
bin/console cache:clear
bin/console theme:compile
```

For an existing installation use `bin/console plugin:update StoreCredit` instead of installing it again. The repository includes compiled administration and storefront JavaScript. After changing source assets, rebuild from the plugin directory with `shopware-cli extension build .` and redeploy the assets/theme.

Back up the database before upgrading. Migrations retain conflicting legacy data and stop with a reconciliation error instead of guessing balances or deleting money. Duplicate customer wallets must be reconciled together with their history before retrying. Where both legacy and namespaced tables exist, legacy source tables are retained as recovery copies. Tables already dropped by an older plugin release cannot be recovered by this upgrade.

## Using credit

Use **Store Credits** in Administration or the customer's **Store Credits** tab. Assign the Store Credit viewer/editor/deleter privileges to the relevant Administration roles. Deleting an account deletes its balance and history; ordinary balance changes should use add/deduct so an audit trail remains.

Each customer has one wallet, in one currency, with two decimal places. New manual wallets default to the shop's system currency. Existing wallet currency is preserved and cannot be changed through an adjustment. Checkout converts the available credit into the selected currency using Shopware's configured currency factors (checkout factor / wallet factor). Applied credit converts again when the customer changes currency. The wallet and account history stay in the wallet currency. Discounts round down to the checkout currency's supported rounding interval; the total order deduction converts back and rounds up to the nearest wallet cent, so credit cannot be overspent through rounding. The per-order limit remains denominated in the selected checkout currency.

At checkout, credit is a negative cart line item. The remaining order total uses the selected Shopware payment method. The wallet is deducted when the order is persisted, in the same database transaction. Concurrent orders cannot spend the same funds. Removing an applied cart credit before ordering does not change the wallet. Order cancellation alone does not automatically restore credit; issue an explicit adjustment or a supported Commercial return refund.

For a new Administration order, the existing convention remains supported: create a credit line item named exactly `Store credit discount`. It is checked against the customer's balance and the cart restrictions. Editing an already placed order is not a new wallet transaction; reconcile any later changes through the credit adjustment workflow.

## Configuration

| Setting | Behaviour |
| --- | --- |
| Maximum credit per order | Sales-channel-aware limit; `0` means no configured limit. Balance and order total still apply. |
| Restricted products | Credit is unavailable if the cart contains a selected product or a variant of a selected parent. The existing premium-protection-fee exclusion remains. |
| Enable store credit refunds | Global setting under **All Sales Channels**. Requires installed Commercial Return Management. |
| Expand store credit section by default | Controls the initial checkout panel state. |

Restrictions and available balance are rechecked on every cart calculation. Multiple legacy credit lines share one aggregate limit. Cash and zero-decimal rounding cannot increase the credit beyond the wallet balance.

## Commercial return refunds

Enable the global refund setting, then transition an eligible paid order's return from **Open** to **Refund as Store Credits**. The selected return's refund total is added to the order customer's wallet in the order currency. A conflicting wallet currency rejects the refund. The return state and credit either both persist or both roll back.

A persistent return identifier prevents another credit when the same return is reopened and marked as refunded again. A return with older refund state history but no new ledger key is blocked for manual reconciliation, including cases where an old wallet was deleted. Partial and full refunds use the return's configured refund amounts, including its calculated taxes/shipping total where Commercial provides it.

Disabling the setting or deactivating/uninstalling Store Credit removes the entry action while retaining historical states. Reactivating restores the action when the global setting remains enabled. The command below can also install the action; if using the command without the configuration setting, run it again after reactivation:

```sh
bin/console store-credit:install-order-state
```

Credit changes are synchronous; no plugin scheduled task or queue consumer is required.

## Admin API

These are authenticated **Admin API** endpoints, not public Store API endpoints:

| Method and path | Required privileges |
| --- | --- |
| `GET /api/store-credit/balance?customerId=<uuid>` | `solu1_store_credit:read` |
| `POST /api/store-credit/add` | `solu1_store_credit:create`, `solu1_store_credit:update` |
| `POST /api/store-credit/deduct` | `solu1_store_credit:update` |

POST body:

```json
{
  "customerId": "0123456789abcdef0123456789abcdef",
  "amount": 12.50,
  "reason": "Manual adjustment"
}
```

`currencyId` and `orderId` are optional UUID fields. An order must belong to the customer. Amounts must be positive, finite, at most `99999999.99`, and have at most two decimal places. Deductions cannot overdraw the wallet. Success returns `{"success": true, "historyId": "..."}`; invalid adjustments return HTTP 400. Balance responses contain `success`, `balance` and `currencyId`. Generic DAL writes cannot replace the validated ledger operations.

Storefront routes are `GET /account/store-credit` for registered customers and authenticated `POST /store-credit-apply` with form field `amount`. The POST always uses the logged-in customer, not a customer ID from the form.

## Development verification

```sh
npm ci --ignore-scripts
npm test
shopware-cli extension build .
shopware-cli extension validate . --full --check-against lowest --only phpstan,eslint,stylelint
shopware-cli extension validate . --full --check-against highest --only phpstan,eslint,stylelint
```

PHP integration tests require a **disposable Shopware test installation and database**, PHPUnit 11.5, and Symfony BrowserKit/CssSelector matching the installed Symfony version. Set `APP_ENV=test`, `PROJECT_ROOT` and `DATABASE_URL` to that installation before running `vendor/bin/phpunit -c phpunit.xml` from this directory. `STORE_CREDIT_TEST_AUTOLOAD` can point to its Shopware `vendor/autoload.php`; `STORE_CREDIT_TEST_EXTRA_AUTOLOAD` can load separate test dependencies if needed.

The test database name must contain `test` for concurrent-process tests. Migration tests create and drop additional databases named `store_credit_migration_test_*`, so the test database user needs those privileges. The default suite covers standalone Store Credit; set `STORE_CREDIT_TEST_COMMERCIAL=1` only in a test installation with Commercial installed to include its return and lifecycle tests. Never point this suite at a live shop database.
