# ChargX Payment Gateway plugin for WooCommerce

ChargX payment gateway for WooCommerce (Credit Cards + Pay By Bank).

Documentation https://docs.chargx.io/woocommerce

## How to install

1. Open WordPress Admin Dashboard

2. Install ChargX plugin
- Download plugin zip https://github.com/chargx/chargx-woocommerce-plugin/archive/refs/heads/main.zip
- Navigate to **Plugins → Add Plugin → Upload Plugin**
- Upload the zip file `chargx-woocommerce-plugin-main.zip`
- Click **Install Now**.
- After installation, click **Activate**.

3. Enable ChargX payments
- Go to **WooCommerce → Settings → Payments**
- Locate **ChargX - ...** in the payments list.
- Toggle the switch to **Enabled** for required payment methods (e.g. Credit Card, Pay By Bank)

4. Configure API keys

Click **Manage** on each of the ChargX payment methods to open configuration settings.

You'll need to provide credentials from your ChargX Dashboard:

| Setting | Description |
|--------|-------------|
| **Live Publishable API Key** | Used for generating secure tokens in Production mode |
| **Test Publishable API Key** | Used for generating secure tokens in Test mode |
| **Live Secret API Key (Admin API)** | Used for server-side API calls in Production mode|
| **Test Secret API Key (Admin API)** | Used for server-side API calls in Test mode|
| **Sandbox / Test Mode** | Sandbox or Production mode |

## Updating to 0.27.0

No new settings. After you install this version:

- A signed `payment.succeeded` event marks the order paid only when the amount, currency, and test/live mode match the checkout, and the ChargX transaction has not already paid another order.
- `payment.failed` marks the order failed. A later successful payment for the same order can still move it from Failed to paid.
- Partial refunds send the WooCommerce amount to ChargX.
- The card and bank return URLs require the order key.
- Pay-By-Bank accepts the bank widget message only from `cabbagepay.com`.

Orders created before 0.26.0 have no stored payment snapshot, so a webhook will not complete them.

## Updating to 0.26.0+

Open **WooCommerce → Settings → Payments → ChargX – Credit Card** and click **Save** once (or wait for the next storefront request). The plugin stores the ChargX webhook signing secret so only signed `payment.succeeded` events mark an order as paid. The customer return URL only opens the thank-you page.

## Local development

1. run WooCommerce instance locally

```
docker compose up
```

2. go to http://localhost:8080, register and login to your WordPress Admin Dashboard

3. install WooCommerce plugin
- Navigate to **Plugins → Add Plugin**
- Find `WooCommerce` in search
- Click **Install Now**.
- After installation, click **Activate**.

4. install ChargX plugin as usual
