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

## Verification Backends (Adapter)

- Supported today: n8n (webhook)
- Planned: Laravel service with the same contract
- Unified response contract expected from any backend:

```json
{
  "status": "approved|pending|rejected",
  "message": "optional",
  "approved_amount": 1499.00,
  "reference_id": "abc123",
  "delay": 10
}
```

`pending` may include `delay` (minutes) so WordPress can schedule a re-check.

## Admin (SlipOK-inspired) — Roadmap

- Order metabox: slip thumbnail, status badge, approved amount/reference, logs, and a “Re-verify” action
- Order list column “Scan&Pay” (HPOS-safe) with concise status
- AJAX `wp_ajax_san8n_verify_again` (nonce + capability) to re-verify and update UI instantly
- Scheduler: if backend returns `pending`, enqueue a single re-check using WP-Cron
- Optional: auto-update order status to Processing/Completed on approval (setting)
- Anti-reuse (optional): store slip hash to prevent reuse across orders

## Open Tasks

- [ ] Test admin: Select QR image via media picker, preview shows, Save changes, reload confirms persistence (in progress)
- [ ] Test classic checkout: static QR image displays from saved URL; no PromptPay assets/shortcodes used; no 404s
- [ ] Test WooCommerce Blocks checkout: static QR image displays; no PromptPay assets; no console errors
- [ ] Optional cleanup: remove PromptPay wording from gateway title/description defaults in `includes/class-san8n-gateway.php`
- [x] Design Laravel verification service: endpoint contract, security (HMAC), email-check strategy (Gmail API vs IMAP), status model
- [x] Prototype Laravel `/api/verify` endpoint and an IMAP-based email check Job; return approved/pending/rejected JSON
- [x] Update docs across AGENTS, context, evaluation, feedback, instructions, plan, README, readme.txt to reflect SlipOK-inspired admin patterns, backend adapter, scheduler
- [ ] Implement admin metabox on order edit (slip thumbnail, status, ref, logs) and Re-verify button
- [ ] Add HPOS-safe order list column 'Scan&Pay' with concise status
- [ ] Add AJAX endpoint `wp_ajax_san8n_verify_again` with nonce/caps; update UI and order meta on success
- [ ] Add scheduler hook `san8n_verify_uploaded_slip` and enqueue re-check on pending (respect delay)
- [ ] Introduce backend adapter wrapper (n8n default; Laravel optional) with unified response contract
- [ ] Add settings to choose backend (n8n/Laravel) and optional auto-update order status
- [ ] Anti-reuse: compute/store slip hash to prevent reuse across orders
- [ ] Security hardening: enforce HTTPS/SSL verify, HMAC, timeouts/retries; PII masking in logs
- [ ] Tests: unit/integration for AJAX, scheduler, adapter; manual regression on Classic/Blocks

## Laravel Adapter Quickstart (Planned)

- Endpoint: `POST /api/verify` with HMAC-signed JSON body; see contract above
- Poll: `GET /api/status/{reference_id}` for async status
- Security: HTTPS only, SSL verify ON, HMAC via shared secret configured in WordPress settings
- Timeout/retries tunable via WordPress filters (to be documented)