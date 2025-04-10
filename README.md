![Store Credit](https://github.com/user-attachments/assets/7f150cc7-e70d-418f-8d5e-519d254f44ac)

# Store Credit

## Introduction

The Store Credit Plugin allows administrators to manage store credits for customers who return products. Instead of issuing refunds, the admin can allocate store credit to the customer's account , which can be used for future purchases. The store credit can be applied over multiple orders until the allocated amount is fully utilized.

### Key Features
- **Store Credit Management**:Admins can add, remove, and adjust store credit for customers.
- **Partial Usage**: Customers can use store credit across multiple orders until exhausted.
- **Smooth Checkout Integration** Customers can apply store credit at checkout alongside other payment methods.
- **Transaction History**: Customers and admins can view a history of store credit transactions.

## Get Started

### Installation & Activation

1. **Download**

- Clone the Plugin Repository:
- Open your terminal and run the following command in your Shopware 6 custom plugins directory (usually located at custom/plugins/):
  ```
  git clone https://github.com/solution25com/store-credit-shopware-6-solution25.git
  ```

2. **Install the Plugin in Shopware 6**

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the newly cloned plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > System > Plugins.
- Upload or install the “Store Credit” plugin.
- Once installed, toggle the plugin to activate it.

4. **Verify Installation**

- After activation, you will see Store Credit in the list of installed plugins.
- The plugin name, version, and installation date should appear as shown in the screenshot below.
![Group 7983](https://github.com/user-attachments/assets/a1ac0256-5393-4f18-8fa4-858d9b8cdf92)

## Plugin Configuration
1. **Access Plugin Settings**
- Go to Settings > System > Plugins.
- Locate Store Credit and click the three dots (...) icon or the plugin name to open its settings.

2. **General Settings**
<br>**1. Minimal Configuration**: After installing the Store Credit Plugin, you need to enable the **Store Credit Refund Type** in the configuration. Since this plugin is related to **Swag Commercial**, the toggle should be enabled for the **Store Credit Refund Type** option to activate store credit refunds.  
![Group 7996](https://github.com/user-attachments/assets/85e69485-dda4-4435-ad96-f947333c736d)
**2. Store Credit Add State**: Toggle to enable or disable the feature.  
**3. Post Purchase Features**: Restrict store credit usage to specific products.   
![Group 7989](https://github.com/user-attachments/assets/de7bf485-e029-4d2f-a5bf-f7f8c0573bc6)

## How it works
After installing and enabling the plugin in the admin panel, customers can view their available balance and transaction history in their profile account settings on the storefront.
![Group 7991](https://github.com/user-attachments/assets/090960b8-2756-4b59-8b65-440e11073479)

Admins can manually add or deduct store credit from a customer’s account.
![Group 7992](https://github.com/user-attachments/assets/7bbed30d-f251-48d1-8af3-b5f1ea7b3f27)
![Group 7993](https://github.com/user-attachments/assets/30fb4e3b-a585-4245-a99d-f7c9e4ecf931)

### Refunds & Credit Memo Workflow
- When processing a return, select the "Refund as Store Credit" option.
- The refunded amount is added to the customer's store credit balance.
- Customers can use the store credit for future purchases.
- All transactions are logged in the store_credit_history table for tracking.
![Group 7994](https://github.com/user-attachments/assets/43b0c962-9eb8-4570-8e23-38671214c006)

### Refunds & Store Credit Management
#### Partial & Full Refunds:
- When issuing a refund, choose between a partial or full refund to store credit.
- The refunded amount is automatically added to the customer's store credit balance.
- The order status updates accordingly.

### Project Structure
**Storefront - Key Files/Folders**
- Controller Folder: Defines the store credit balance table and store credit history with API.
- CartSubscriber.php: Checks if the user has used the store credits.
- OrderRefundSubscriber.php: It handles the refund as store credit option
- CustomCheckoutController.php: Defines store credit as a payment option.

**Core - Key Files/Folders:**
- Entities/StoreCredit.php: Manages store credit data model.
- Entities/StoreCreditHistory.php: Logs store credit transactions.

**Resources - Key Files/Folders:**
- services.xml: Registers services for dependency injection.
- routes.xml: Declares API routes.

**Admin Panel - Key Features:**
- View history, add, and deduct store credit in customer details.
- Generate reports on credit usage.

### API Endpoints
**Get Store Credit Balance**
<br> - **Path**: /store-api/store-credit/balance/{customer_id}
<br> - **Method**: GET
<br> - **Purpose**: Fetches the current store credit balance of a customer.
<br> - **Response**: Returns the available credit balance.

**Add Store Credit**
<br>- **Path**: /store-api/store-credit/add
<br>- **Method**: POST
<br>- **Request Body**:
      <br> - customer_id (string, required)
      <br> - amount (float, required)
      <br> - reason (string, optional) Response: Returns updated store credit details.

**Deduct Store Credit**
<br> - **Path**: /store-api/store-credit/deduct
<br> - **Method**: POST
<br>- **Request Body**:
  <br> - customer_id (string, required)
  <br> - amount (decimal, required)
  <br> - order_id (string, optional) Response: Returns updated store credit details.

<br> - **Refund to Store Credit**
<br> - **Path**: /store-api/store-credit/add
<br> - **Method**: POST
<br> - **Request Body**:
  <br> - order_id (string, required)
  <br> - amount (decimal, required) Response: Refund processed and logged in credit history.
  <br> - reason (string, optional).

### Use from end-users - Checkout Integration
- Customers can choose to apply store credit as payment during checkout.
![Group 7995](https://github.com/user-attachments/assets/dafccdd4-8cf8-4a59-a423-bab2d86feeb8)

- Partial and full store credit application supported.
- Remaining balance is paid via an alternative payment method.
- When an admin creates an order he can use the store credits of the customer he chooses, first you add the product in admin order creation, then you add another line item as credit and you use the specific name “Store credit discount“.

## Best Practices

- **Enable Store Credit Refunds**
  - Ensure the **Store Credit Refund Type** option is activated in **Settings > System > Plugins**.
  - Allows refunds to be issued as store credit instead of cash.
  
- **Set Per-Order Credit Limits**
  - Define a maximum limit for store credit usage per order (e.g., 50 euros).
  - Helps control how much credit can be used during checkout.

- **Monitor Store Credit Transactions**
  - Regularly check the store credit transaction logs to track customer usage.
  - This helps to avoid errors and discrepancies.

- **Inform Customers About Their Credit**
  - Make sure customers can see their available store credit balance in their account.
  - Send notifications when store credit is added or used.

- **Test Before Going Live**
  - Test different scenarios, such as refunds, partial payments, and store credit applications during checkout.
  - Ensure everything functions properly before launching.

- **Use API to Customize for Your Needs**
  - Use API endpoints to customize store credit rules and integrate with other store functions.
  - This allows for flexibility in how store credit is applied.

- **Restrict Store Credit for Certain Products**
  - If necessary, restrict store credit usage for specific products or sales channels.
  - This ensures store credit is used where appropriate.

## Troubleshooting

- **Store credit is not appearing at checkout**
  - Ensure the **Store Credit Refund Type** is activated in the plugin settings.
  - Check if the customer has enough store credit in their account.

- **Refunds not issued as store credit**
  - Verify that the refund method is set to **Store Credit** when processing returns.
  - Check for conflicts with other refund-related plugins.

- **Customer’s store credit balance is not updating**
  - Ensure scheduled tasks are running with the following commands:
    ```sh
    bin/console scheduled-task:register
    bin/console scheduled-task:run
    bin/console messenger:consume
    ```
  - Verify the store credit history is being logged in the database.

- **Admins cannot modify store credit**
  - Ensure the admin has the necessary permissions to edit customer store credit.
  - Double-check that API calls for store credit adjustments are functioning.

- **Orders not using store credit during checkout**
  - Ensure there is no restriction on store credit for the selected products.
  - Verify that the credit amount is within the allowed per-order limit.

## FAQ

- **Can I restrict store credit usage to specific products?**  
  - Yes, you can restrict store credit usage for specific products or categories through the **Post Purchase Features**.

- **How do I add store credit to a customer's account?**  
  - Admins can add store credit manually through the **Customer Details** section in the admin panel.

- **Can store credit expire?**  
  - Currently, the plugin does not support automatic expiration of store credit, but this feature can be customized.

- **Can customers see their store credit balance?**  
  - Yes, customers can view their available balance and transaction history in their account settings.


## Wiki Documentation
Read more about the plugin configuration on our [Wiki]().

