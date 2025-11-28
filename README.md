# ChargX Payment Gateway plugin for WooCommerce

ChargX payment gateway for WooCommerce (Credit Cards + Apple Pay + Google Pay, refunds, recurring).

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
- Toggle the switch to **Enabled** for required payment methods (e.g. Credit Card, Apple Pay, Google Pay)

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
