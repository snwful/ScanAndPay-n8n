# Scan & Pay (n8n) — WooCommerce Payment Gateway

Static QR checkout for WooCommerce with inline slip verification.

## Overview
- Displays a static QR placeholder image configured via the WordPress Media Library (with a bundled SVG fallback).
- Customers upload a payment slip; the plugin verifies it via a backend service (n8n supported; Laravel planned) and updates the order on approval.

## Requirements
- WordPress 6.0+ and PHP 8.0+
- WooCommerce 7.0+

## Installation
1) Upload the plugin to `wp-content/plugins/` and activate it.
2) In WooCommerce → Settings → Payments → Scan & Pay (n8n), configure the QR image and verification backend.

## Configuration
- Select a QR placeholder image via the Media Library (or use the default SVG).
- Set your verification backend webhook URL and shared secret (n8n supported; Laravel planned).

## Usage
- Classic and Blocks checkout both render the configured static QR placeholder image.
- Customers upload a payment slip and verify inline; on approval, the order is updated automatically.

## Troubleshooting
- If the QR doesn’t appear: ensure a QR image is selected in settings or that the fallback exists at `assets/images/qr-placeholder.svg`.
- Clear browser and site caches; verify no console errors from checkout scripts.
- Confirm REST endpoint is reachable: `POST /wp-json/wc-scanandpay/v1/verify-slip` should return validation errors without a file.

## WordPress Directory Readme
See `readme.txt` for changelog and detailed instructions.

## Roadmap

- Short term: Use n8n IMAP/email alert parsing to verify incoming funds before relying on slips; document the flow and security controls.
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.