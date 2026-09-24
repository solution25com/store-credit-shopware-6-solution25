# Store Credit: Shopware 6.7 fixes and verification

Date: 24 September 2026. Branch: `main-6.7`. Plugin: `solution25/store-credit`, version `1.2.0`.

The changes address the findings in [SHOPWARE_6.7_COMPATIBILITY_REVIEW.md](SHOPWARE_6.7_COMPATIBILITY_REVIEW.md). `main` remains at `868a5aa5ac44e2cbd211c4a122430eb3b2c51aad`. Nothing was committed or pushed.

## Implemented changes

### Shopware 6.7 and Administration

- Composer requires Shopware Core/Storefront `~6.7.0` and PHP `^8.2`; this branch does not retain 6.6 compatibility.
- Rebuilt the Administration with Vite, its manifest and hashed assets, and rebuilt the storefront JavaScript. Removed the old Webpack Administration bundle and duplicate CSS.
- Replaced removed Administration components with Meteor components and corrected Vue 3 bindings, slots, component registration, route middleware and translations.
- Corrected customer selection, DAL Criteria, current balance/history loading and per-wallet currency display. Older async responses cannot replace a newly selected customer's balance; a failed customer switch cannot reuse the previous wallet currency.
- Added a Shopware API service using relative URLs and fresh authentication headers, aligned server ACL rules and Administration privileges, and blocked duplicate pending adjustments.
- Updated deprecated DAL search-result calls. Narrowly documented PHPStan compatibility exceptions remain for the early-6.7 DBAL foreign-key name API and primary-order-transaction feature detection.

### Ledger, checkout and currency

- Customer row locking serializes first-wallet creation and all balance changes. Locking balance reads avoid stale values under concurrent transactions. A unique customer constraint enforces one wallet per customer.
- Amount validation rejects negative, non-finite, excessive and sub-cent amounts. Changes use integer cents and preserve currency. Order links must belong to the customer; generic Admin DAL writes cannot bypass the ledger service.
- Persistent operation keys prevent repeat order/return ledger transactions. History gets normal DAL timestamps.
- The cart collector/processor revalidates balance, currency, sales-channel limits and restricted products on each calculation. Multiple credit lines share one bounded allowance; shipping, cash rounding and zero-decimal rounding are accounted for.
- Order persistence and its credit deduction share one transaction. Stale balances stop order persistence; a deduction error rolls the order back instead of leaving an unpaid discount.
- Storefront application is an authenticated POST using the session customer. Account history has login/no-cache metadata, pagination, correct currencies, signed amounts and sanitized output.
- Checkout page loading is read-only. The template uses current blocks and actual page extensions; one scoped JavaScript plugin validates input and removes its own listeners.

### Commercial returns and lifecycle

- Commercial is optional for ordinary balances, Administration and checkout.
- Refunds use the transitioned return, its configured refund total and the order currency, and require a paid customer order.
- Return state/history and wallet credit share a transaction. Reopening and refunding the same return cannot issue another credit. Older return refund history without a matching ledger key is blocked for reconciliation.
- State installation is scoped to the return machine. Disabling refunds preserves historical states and unrelated transitions.
- Deactivation/uninstallation removes the refund entry action. Reactivation restores configured refunds. No action remains available while the credit subscriber is inactive.

### Data migration and cleanup

- Legacy table migration preserves source data and fails explicitly on conflicting balances instead of ignoring errors or deleting the source.
- New migrations add wallet/operation uniqueness, backfill missing currency, and repair mixed legacy/new history foreign keys.
- Order links include the live order version. Deleting a draft version preserves live history; deleting the live order clears its reference without deleting the ledger entry.
- Currency deletion cannot cascade into deleting balances/history.
- Removed unregistered order recreation/payment code, unused order overrides, duplicate storefront code and nonexistent template overrides.
- Removed broken Composer Git-hook installation scripts. Added maintained PHPUnit/frontend tests and corrected the README's outdated 6.6, API and scheduled-task instructions.

## Verification

Runtime testing used an isolated Shopware **6.7.13.1** installation, PHP **8.3.30**, and Commercial **7.13.1** where enabled. Its database was `store_credit_67_test`; no existing shop database was used for installation or fixtures. Migration tests created separate disposable databases.

| Check | Result |
| --- | --- |
| PHP integration suite with Commercial | 34 tests, 122 assertions passed; three upstream Commercial PHP deprecation notices |
| PHP suite with Commercial deactivated | 32 passed, 111 assertions; two Commercial-specific tests intentionally skipped |
| Frontend tests | 11 passed, including real Meteor 4.12.2 and 5.2.0 components, bindings, permissions, duplicate submission protection, async customer changes and storefront validation |
| PHPStan, ESLint, Stylelint against CLI `lowest` | No findings |
| PHPStan, ESLint, Stylelint against CLI `highest` | No findings |
| Asset build targeting Shopware 6.7.0.0 | Successful Vite Administration and webpack storefront build |
| Administration entry points | Shopware's Vite accessor discovers the manifest and existing JS/CSS assets |
| Container and storefront Twig lint | Passed |
| Theme compilation in the isolated 6.7.13.1 shop | Passed |
| PHP syntax and Git whitespace checks | Passed |

The PHP tests exercise actual DAL repositories and routes, real order persistence, authenticated storefront account/checkout rendering, Admin API permissions/malformed requests, failed deductions, currency preservation, configuration limits, invalid values, two-process concurrency, old/new table migrations, order/currency foreign keys and real Commercial return transitions.

## Remaining limitations and release checks

- **Full metadata validation still fails on the original plugin icon:** `src/Resources/config/plugin.png` is 671 × 528 and larger than 30 KB; the validator requires at most 256 × 256 and 30 KB. The existing artwork was retained. This is a packaging issue, not a 6.7 runtime failure. The existing CI workflow runs full validation and will remain red until a compliant icon is supplied.
- Commercial 7.13.1 emits three PHP 8.3 dynamic-property deprecations for its own return entity version fields. No vendor files were changed or notices suppressed. These are separate from plugin test failures.
- The production build emits upstream Browserslist/chunk warnings. They do not prevent asset generation.
- Runtime integration was executed on 6.7.13.1. The lowest/highest checks are static compatibility checks, not full runtime installations of every 6.7 patch. Meteor tests cover early/current component contracts; they are not a complete browser UI acceptance test.
- CodeRabbit was unavailable because its CLI was not authenticated. Manual source/diff review, static analysis and regression tests were used instead.
- No external payment provider or every third-party theme/plugin combination was tested. A staging checkout/refund check with the actual payment method and theme remains necessary before release. Credits are cart discounts, not a new payment handler; cancellation and later order edits require explicit ledger reconciliation as documented in the README.
- Previously corrupted balances or source tables already deleted by an older release cannot be inferred or restored automatically. Migration conflicts deliberately require data reconciliation.

## Reproduction

Frontend/static/build commands and disposable test-installation requirements are in [README.md](README.md#development-verification). The isolated test database, temporary installation, test dependencies and stray build artifacts were removed after verification. The final compiled plugin assets remain in the repository.
