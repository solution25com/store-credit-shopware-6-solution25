# Changelog

All notable changes to the Store Credit plugin for Shopware 6 are documented in this file.

| Shopware | Branch | Plugin version |
|---|---|---|
| 6.6.x | `main` | 1.x |
| 6.7.x | `main-6.7` | 2.x |

## [1.2.0] - 2026-10-12

### Added
- Store credit can be used in any checkout currency. The available credit is converted with Shopware's currency factors;
the wallet and its history stay in the wallet currency.
- Credit applied to unpaid orders is reserved and no longer counts as available. Reserved and available credit are shown
in the Administration, the Admin API balance endpoint and the customer account page.
- Setting *Reservation age limit (days)* (default 14, 0 = hold indefinitely) after which unpaid orders stop reserving
credit.
- Deducting reserved credit in the Administration requires a confirmation.
- Refunds to store credit are capped at what the order may still refund.
- Extension point `CardRefundedAmountProviderInterface` (service tag `solu1_store_credit.card_refunded_provider`) so
refunds made through a payment provider also count towards the cap.
- Setting *Blocked line item reference IDs*: store credit cannot be applied while one of these line items is in the cart.
- Checkout notice when applied store credit is reduced or removed because the available balance dropped.
- Orders whose store credit could not be deducted when paid are tagged `store-credit-issue` for review.
- Administration permissions for Store Credit (viewer, editor).
- German texts for the Administration, the storefront and the plugin configuration.

### Changed
- Store credit is reserved when the order is placed and deducted when the payment is paid, instead of being deducted at
placement. Failed or cancelled payments no longer use credit. Orders placed on 1.1.x are not deducted again.
- Applied credit never exceeds the amount still to be paid.
- Deleting an order no longer deletes its store credit history.
- Line items that block store credit are no longer hard-coded. Shops that need a line item to block store credit must add
its reference ID to *Blocked line item reference IDs*.
- Storefront snippet files renamed to `store-credit.*.json`.
- Checkout CSS classes renamed to `store-credit-checkout*`. Themes that style the previous classes must be updated.

### Fixed
- Error handling, icons and duplicate submissions in the Administration store credit pages.
- Loading of the customer account store credit page.

### Security
- General security and stability improvements. Updating is recommended.
