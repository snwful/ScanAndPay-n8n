# Scan & Pay (n8n) — WooCommerce Payment Gateway

PromptPay QR checkout for WooCommerce with inline slip verification via n8n.

## Overview
- Renders a PromptPay QR using the `[promptpayqr]` shortcode. Amount is locked to the cart/order total.
- Customers upload a payment slip; the plugin verifies it via an n8n webhook and updates the order on approval.

## Requirements
- WordPress 6.0+ and PHP 8.0+
- WooCommerce 7.0+
- PromptPay plugin (external) or the bundled `promptpay/` module. If the shortcode is missing, the bundled module is auto-bootstrapped.

## Installation
1) Upload the plugin to `wp-content/plugins/` and activate it.
2) Ensure the PromptPay plugin is active/configured, or rely on the bundled module (auto-loaded if the shortcode is missing).

## Configuration
- WooCommerce → Settings → Payments → Scan & Pay (n8n)
- Set n8n webhook URL and shared secret.
- Configure PromptPay ID in the PromptPay plugin settings.

## Usage
- Classic checkout renders the QR via `[promptpayqr amount="{float}"]` in `payment_fields()`.
- If the shortcode is unavailable, a notice and an SVG placeholder are shown.
- Blocks checkout currently shows a placeholder image (roadmap to render live QR).

## Troubleshooting
- If the QR doesn’t appear: confirm `promptpay/css/main.css` and `promptpay/js/main.min.js` load on checkout.
- Test the shortcode on a regular page: `[promptpayqr amount="50.00"]`.
- Check that the PromptPay ID is configured in its settings.

## WordPress Directory Readme
See `readme.txt` for changelog and detailed instructions.