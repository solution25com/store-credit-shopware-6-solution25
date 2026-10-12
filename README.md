![1](https://github.com/user-attachments/assets/bb12d407-d013-41dd-b97e-c3d08112c054)

  # Store Credit

  ## Introduction

  The Store Credit Plugin allows administrators to manage store credit for customers. Instead of issuing refunds, the admin can add store credit to the
  customer's account, which can be used for future purchases. The store credit can be applied over multiple orders until the balance is fully used.

  ### Key Features
  - **Store Credit Management**: Admins can add and deduct store credit for customers.
  - **Partial Usage**: Customers can use store credit across multiple orders until it is used up.
  - **Smooth Checkout Integration**: Customers can apply store credit at checkout alongside any payment method.
  - **Multi-Currency**: Store credit can be used in any checkout currency.
  - **Reserved Credit**: Credit applied to unpaid orders is reserved and only deducted when the order is paid.
  - **Refunds as Store Credit**: Returns can be refunded as store credit (requires Commercial Return Management).
  - **Transaction History**: Customers and admins can view a history of store credit transactions.

  ## Compatibility
  - ✅ Shopware 6.6.x

  | Shopware | Branch | Plugin version |
  |---|---|---|
  | 6.6.x | `main` | 1.x |
  | 6.7.x | `main-6.7` | 2.x |

  ## Get Started

  ### Installation & Activation

  1. **Download**

  - Open your terminal and run the following command in your Shopware 6 custom plugins directory (usually `custom/plugins/`):
    ```
    git clone https://github.com/solution25com/store-credit-shopware-6-solution25.git
    ```
  - For Shopware 6.7, clone the `main-6.7` branch instead:
    ```
    git clone -b main-6.7 https://github.com/solution25com/store-credit-shopware-6-solution25.git
    ```

  2. **Install the Plugin in Shopware 6**
  
  - Log in to your Shopware 6 Administration.
  - Navigate to **Extensions > My extensions**.
  - Locate **Store Credit** and click **Install**.

  3. **Activate the Plugin**

  - After installation, click **Activate** to enable the plugin.
  - Alternatively, run from the Shopware root:
    ```sh
    bin/console plugin:refresh
    bin/console plugin:install --activate StoreCredit
    bin/console cache:clear
    bin/console theme:compile
    ```

  4. **Verify Installation**

  - After activation, Store Credit appears in the list of installed extensions.
  - The plugin name, version and installation date are shown as in the screenshot below.
  ![2](https://github.com/user-attachments/assets/ee52304c-21f8-42a8-9f12-a90287bc1327)

  ### Updating

  Back up your database, then run:
  ```sh
  bin/console plugin:update StoreCredit
  bin/console cache:clear
  bin/console theme:compile
  ```
  Check the [CHANGELOG](CHANGELOG.md) for changes that need action, such as new permissions or theme updates.

  ## Plugin Configuration
  1. **Access Plugin Settings**
  - Go to **Extensions > My extensions**.
  - Locate **Store Credit**, click the three dots (...) and choose **Configure**.

  2. **Settings**

  ![3](https://github.com/user-attachments/assets/1b9a2531-5ce2-4c69-95ff-bcf934d5375f)

  **1. Store Credit Limits**: **Maximum credit per order** limits how much store credit can be used on one order (`0` = no limit).

  **2. Pending Order Reservations**: **Reservation age limit (days)** sets how long an unpaid order keeps its credit reserved (default 14, `0` = no limit).

  **3. Product Restrictions**: **Restricted products** blocks store credit while one of the selected products is in the cart.
  ![4](https://github.com/user-attachments/assets/6c0da891-9f8d-4f72-bb91-1379a1862491)

  **4. Checkout Behaviour**:
  - **Blocked line item reference IDs**: store credit cannot be applied while a line item with one of these reference IDs is in the cart. Empty = no restriction.
  - **Expand store credit section by default**: shows the store credit section expanded at checkout.

  **5. Store Credit Add State**: **Run Order State Installer** adds the **Refund as Store Credits** return state. Enable it to refund returns as store credit.
  Requires Commercial Return Management.

  3. **Permissions**
  - Assign the **Store Credit** permissions (viewer, editor) to your Administration roles under **Settings > System > Users & permissions**. Users without them
  cannot see or change store credit.

  ## How it works
  After installing and enabling the plugin, customers can view their balance, reserved credit and transaction history in their account on the storefront.
  ![5](https://github.com/user-attachments/assets/5005aaea-4109-4b3f-bff8-b1bf9b268b3c)

  Admins can add or deduct store credit in **Customers > Store Credits** or in the customer's **Store Credits** tab. The tab shows the balance, the credit
  reserved by unpaid orders and the available credit. Deducting reserved credit requires a confirmation.
  ![6](https://github.com/user-attachments/assets/1486cb7b-b82f-4817-b958-038283332661)
  ![7](https://github.com/user-attachments/assets/ec63a465-a4e7-49b5-a5e4-985c95676857)

  ### Checkout and Payment
  - Customers apply store credit at checkout. It is added as a discount and the remaining amount is paid with the selected payment method.
  ![9](https://github.com/user-attachments/assets/02ec29e2-17b6-4f25-9b9c-fd8a387c2b7b)

  - When the order is placed, the applied credit is **reserved** and cannot be spent on another order.
  - When the payment is marked **paid**, the credit is **deducted** from the balance. Failed or cancelled payments do not use credit.
  - If the credit cannot be deducted when the order is paid, the order stays paid and is tagged `store-credit-issue` for review. Marking the payment as paid
  again retries the deduction.
  - If the available balance drops after the customer applied credit, the credit is reduced or removed and the customer sees a notice.
  - Cancelling an order does not restore credit automatically. Use a manual adjustment or a return refund.
  - When an admin creates an order, store credit can be used by adding a credit line item named exactly `Store credit discount`.

  ### Currencies
  Each customer's store credit is held in one currency, the wallet currency (the shop's default currency for new wallets). When a customer checks out in another
  currency, the available credit is converted with the currency factors configured in Shopware. Reservations, deductions and the history always stay in the
  wallet currency. A return on an order placed in a different currency than the wallet is not credited automatically; credit the customer manually.

  ### Refunds as Store Credit
  - Enable **Run Order State Installer** (requires Commercial Return Management).
  - When processing a return of a paid or partially refunded order, move it to **Refund as Store Credits**.
  - The return's refund total is added to the customer's store credit balance. Each return is credited once.
  - Refunds are capped at what the order may still refund: the order total plus store credit spent on it, minus store credit already refunded for it.
  - All changes are logged in the store credit history.
  ![8](https://github.com/user-attachments/assets/1e6d6251-8579-4d66-9dfe-466993b49583)

  Shops that also refund through a payment provider can register a service implementing `Solu1StoreCredit\Service\Refund\CardRefundedAmountProviderInterface`,
  tagged `solu1_store_credit.card_refunded_provider`, so those refunds count towards the cap.

  # Store Credit Plugin - API Documentation

  The plugin provides **Admin API** endpoints to manage store credit. They require a Bearer token obtained through the Shopware Admin API and the Store Credit
  permissions.

  | Method and path | Purpose |
  |---|---|
  | `GET /api/store-credit/balance?customerId=<id>` | Get the balance, reserved and available credit |
  | `POST /api/store-credit/add` | Add store credit |
  | `POST /api/store-credit/deduct` | Deduct store credit |

  ---

  ## Add Store Credit

  **Endpoint**
  `POST /api/store-credit/add`

  ### Request Headers

  ```
  Authorization: Bearer <your-access-token>
  Content-Type: application/json
  ```

  ### Example Request Body

  ```json
  {
    "customerId": "3c2a178f96b7345ad27051c34609e52a",
    "amount": 50.0,
    "reason": "Manual admin adjustment"
  }
  ```

  `orderId` and `currencyId` are optional. The amount must be positive.
  ### Successful Response

  ```json
  {
    "success": true,
    "historyId": "f84b5a7a4fce4a1db9d693c8be304a1e",
    "balance": 80.0
  }
  ```

  ---

  ## Deduct Store Credit

  **Endpoint**
  `POST /api/store-credit/deduct`

  ### Example Request Body

  ```json
  {
    "customerId": "3c2a178f96b7345ad27051c34609e52a",
    "amount": 20.0,
    "reason": "Refund adjustment"
  }
  ```

  A deduction that would use credit reserved by unpaid orders is rejected unless `"allowReserved": true` is sent.

  ### Successful Response

  ```json
  {
    "success": true,
    "historyId": "a9d2554b0ce847cd82f3ac9bd1c0aa2f",
    "balance": 60.0
  }
  ```

  ---

  ## Get Store Credit Balance

  **Endpoint**
  `GET /api/store-credit/balance?customerId=3c2a178f96b7345ad27051c34609e52a`

  ### Successful Response

  ```json
  {
    "success": true,
    "balance": 80.0,
    "currencyId": "b7d2554b0ce847cd82f3ac9bd1c0dfca",
    "reserved": 20.0,
    "available": 60.0,
    "reservedOrders": [],
    "expiredOrders": []
  }
  ```
  
  ---

  ### Error Response

  All endpoints return HTTP 400 with a message when the request is invalid:

  ```json
  {
    "success": false,
    "message": "A customer is required."
  }
  ```
  
  ## Best Practices

  - **Enable Store Credit Refunds**
    - Enable **Run Order State Installer** in the plugin settings to refund returns as store credit.

  - **Set Per-Order Credit Limits**
    - Define a maximum store credit amount per order (e.g., 50 euros).

  - **Assign Permissions**
    - Give only the right Administration roles the Store Credit editor permission.

  - **Monitor Store Credit Transactions**
    - Check the store credit history regularly, and review orders tagged `store-credit-issue`.

  - **Restrict Store Credit for Certain Products**
    - Use **Restricted products** and **Blocked line item reference IDs** where store credit should not be used.

  - **Test Before Going Live**
    - Test refunds, partial payments and store credit at checkout before launching.

  ## Troubleshooting

  - **Store credit is not appearing at checkout**
    - Check that the customer is logged in and has available credit (balance minus reserved credit).
    - Check **Restricted products** and **Blocked line item reference IDs**.

  - **Less credit can be applied than the balance**
    - Part of the balance may be reserved by unpaid orders, or limited by **Maximum credit per order**.

  - **Refunds not issued as store credit**
    - Check that Commercial Return Management is installed and **Run Order State Installer** is enabled.
    - The return must be moved to **Refund as Store Credits**, and the order must be paid or partially refunded.

  - **Admins cannot see or modify store credit**
    - Assign the Store Credit permissions to their role.

  - **An order is tagged `store-credit-issue`**
    - The credit could not be deducted when the order was paid. Check the customer's balance, then mark the payment as paid again.

  ## FAQ

  
  - **Can I restrict store credit usage to specific products?**
    - Yes, with **Restricted products** and **Blocked line item reference IDs**.
  
  - **How do I add store credit to a customer's account?**
    - In **Customers > Store Credits**, in the customer's **Store Credits** tab, or through the Admin API.
  
  - **When is store credit deducted?**
    - When the order's payment is marked paid. Until then it is reserved.
  
  - **Can store credit expire?**
    - No, the plugin does not expire store credit.
  
  - **Can customers see their store credit balance?** 
    - Yes, customers can view their balance, reserved credit and history in their account.
