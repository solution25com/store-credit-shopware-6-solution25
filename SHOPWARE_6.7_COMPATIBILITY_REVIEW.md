# Store Credit: Shopware 6.7 audit

Scope: the complete plugin on `main-6.7`, starting at `868a5aa5ac44e2cbd211c4a122430eb3b2c51aad`. The `main` branch is retained unchanged. No commits or pushes are part of this work.

The issues below have been addressed in the working tree; see [the fixes and verification report](SHOPWARE_6.7_FIXES.md) for evidence and remaining limitations.

## Findings

| Area | Original problem | Required correction |
| --- | --- | --- |
| Package | Composer permits only Shopware 6.6; administration ships the old build format. | Target only 6.7 and rebuild with Vite. |
| Administration | Removed `sw-*` UI components, duplicate component registration, missing snippet registration, invalid repository criteria, obsolete state access in dormant code. | Use the 6.7 component contracts and validate mounted Vue components. |
| Administration API | Absolute `/api` URLs and manually read auth tokens break subdirectory installations and token handling. Permissions are missing from UI routes and reference the wrong entity names on the server. | A Shopware API service and consistent registered privileges. |
| Customer selection/history | Selection handler expects the wrong grid event payload; history uses a plain object instead of Criteria and trusts stale query-string values. | Correct selection events and fetch current customer/balance associations. |
| Routing/pages | Annotation Route imports, missing account page loader/login metadata, incorrect history totals and currency formatting. | Attribute routes, protected account page, proper page data and pagination. |
| Storefront | Unused template paths, unassigned configuration values, wallet ID confused with cart line-item ID, duplicate JS implementations. | Supported Twig blocks and one scoped storefront plugin. |
| Cart | Validation can run only once, fails to validate customer/currency/balance, and removes discounts after pricing without rebuilding them. | Collector/processor validation on every calculation and bounded credit prices. |
| Checkout | Deduction happens after order persistence; failures are logged and swallowed, and concurrent orders can spend the same balance. | Serialize wallet access and persist order and deduction in one database transaction. |
| Ledger | Read-modify-write races, cached stale balances, currency overwritten with null, invalid currency SQL, sub-cent/overflow amounts. | Locked updates, explicit currency rules and validated amounts. |
| Returns | Chooses the last return for an order instead of the transitioned return, uses calculated price instead of refund amount and can credit repeated transitions repeatedly. | Identify the actual return and use a persistent idempotency key. |
| Migrations | Legacy table merge swallows errors and can drop the source before history migration. Required timestamp fields obstruct normal DAL writes. | Preserve legacy data, fail visibly on ambiguity, and use DAL timestamp fields. |
| Database relations | History references order IDs without versions, and currency deletion cascades into financial records. | Versioned order references and non-destructive currency constraints. |
| Lifecycle | Deactivation leaves the Commercial refund action available without its credit subscriber. | Remove the entry action while inactive and restore configured refunds on activation. |
| State installer | Removes transitions by action name across unrelated state machines and deletes history. | Scope changes to the return machine and preserve historical states. |
| Dormant code | Unregistered order recreation code has an incorrect namespace and unsafe order-copy behavior; unused order UI overrides retain obsolete APIs. | Remove unused, unregistered code rather than enable an unrelated payment/order-recreation feature. |
| Tooling | Composer scripts install a missing Git hook; PHPUnit configuration references an absent bootstrap and old schema. | Remove installation side effects and add meaningful regression tests. |

## Reference basis

The audit compares against installed Shopware 6.7.13.1 and Commercial 7.13.1 source, together with the official [6.7 upgrade guide](https://docs.shopware.com/en/shopware-6-en/update-guides/update-guide-shopware-67), [Vite migration guide](https://developer.shopware.com/docs/guides/upgrades-migrations/administration/vite.html) and [cart collector/processor guide](https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/add-cart-processor-collector.html). Test results and remaining deployment checks are recorded in [SHOPWARE_6.7_FIXES.md](SHOPWARE_6.7_FIXES.md).
