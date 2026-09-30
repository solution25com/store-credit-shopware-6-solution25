 # Changelog

  All notable changes to the Store Credit plugin for Shopware 6 are documented in this file.

  | Shopware | Branch | Plugin version |
  |---|---|---|
  | 6.6.x | `main` | 1.1.x |
  | 6.7.x | `main-6.7` | 2.x |

  ## [2.0.0] - 2026-09-30

  ### Added
  - Shopware 6.7 support. This release line supports Shopware 6.7 only; Shopware 6.6 remains supported by 1.1.x on the `main`
  branch.
  - Store credit can be used in any checkout currency. The available credit is converted with Shopware's currency factors;
  the wallet and its history stay in the wallet currency, and rounding can never spend more than the wallet balance.
  - Administration permissions for Store Credit (viewer, editor, deleter) under Customers, enforced by the Admin API.
  - Paginated store credit history in the customer account, with signed amounts in the wallet currency.

  ### Changed
  - Requires Shopware 6.7 (`~6.7.0`) and PHP 8.2 or newer.
  - The Administration was rebuilt for Shopware 6.7 (Vite build, Meteor components).
  - The setting *Run Order State Installer* is now *Enable store credit refunds*. It is a global setting (All Sales Channels)
  and requires Commercial Return Management. Disabling it removes the refund action and keeps previous refunds.
  - Store credit is deducted together with saving the order. If the credit can no longer be deducted, the order is not
  placed.
  - The per-order limit, restricted products and available balance are rechecked on every cart calculation.
  - Commercial return refunds credit the selected return's refund total in the order currency, and each return is credited
  once.
  - Commercial Return Management is optional and only required for refunds to store credit.
  - The storefront apply-credit route (`/store-credit-apply`) accepts authenticated POST requests only and always uses the
  logged-in customer. Custom themes or integrations that call it must be updated.
  - Each customer has exactly one wallet. Deleting an order or a currency no longer deletes store credit history.

  ### Fixed
  - Customer selection and balance and history loading in the Administration.
  - Currency display of wallet balances.
  - The customer account store credit page now requires login and shows correct totals and currencies.
  - Deactivating the plugin left the Commercial refund action available.
  - The order state installer changed transitions in unrelated state machines.
  - The legacy table migration now keeps the source data and stops with an error on conflicting balances instead of
  discarding data.

  ### Security
  - Hardened store credit balance changes, checkout deduction, return refunds and the Administration API.

  ### Removed
  - The Shopware 6.6 (Webpack) administration build.
  - Unused order recreation code and obsolete cart and order template overrides.

  ## [1.1.2] - 2026-06-03
  ### Added
  - Customer search in the Store Credits overview by name, email address and customer number.

  ## [1.1.1] - 2026-06-02
  ### Fixed
  - Refunds to store credit with Shopware Commercial were not processed correctly.   

  ## [1.1.0] - 2026-05-29
  ### Changed
  - Stability, security and UI/UX improvements.

  ## [1.0.5] - 2026-02-18
  ### Fixed
  - Currency display of the store credit balance.

  ## [1.0.4] - 2026-02-02
  ### Changed
  - Improved store credit handling at checkout.

  ## [1.0.3] - 2025-12-12
  ### Added
  - Configuration options for the maximum credit per order and for expanding the store credit section by default.
  ### Changed
  - Improved checkout layout and styling of the store credit section.
  - Settings are read per sales channel.

  ## [1.0.2] - 2025-09-30
  ### Fixed
  - Maximum credit per order at checkout.

  ## [1.0.1] - 2025-09-29
  ### Fixed
  - Hotfix release.

  ## [1.0.0] - 2025-09-29
  ### Added
  - Initial release: store credit balances per customer, managed in the Administration and usable at checkout.
  - Maximum credit amount and maximum credit percentage per order.

  ---
