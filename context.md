Plugin Context and Rationale

This document provides background on the existing Scan & Pay (n8n) payment gateway plugin and the rationale for the upcoming refactor. It is intended to give Codex full context before modifying the code.

## Status (v1.1.1) — Current Architecture
**QR Rendering (Static Placeholder)**: Both Classic and Blocks checkout render a static QR placeholder image. The image source is configured via a WordPress Media Library picker (`qr_image_url` setting) in `includes/class-san8n-gateway.php`. If unset, we fall back to `assets/images/qr-placeholder.svg`.

**No PromptPay Shortcode**: The legacy PromptPay shortcode and dynamic QR generation are removed. There is no dependency on any external PromptPay plugin or its assets.

**Blocks vs Classic**: Classic renders the placeholder inside `payment_fields()`; Blocks receives the placeholder URL via `includes/class-san8n-blocks-integration.php` (exposed as `qr_placeholder`) and renders the same image. Responsive display issues have been fixed.

**Slip Verification**: Frontend JS posts `order_id`, `order_total`, `session_token`, and `slip_image` to `/wp-json/wc-scanandpay/v1/verify-slip`. The REST handler forwards to a verification backend. The backend choice (n8n or Laravel) is pending; n8n is currently supported.

Existing Functionality

The plugin registers a WooCommerce payment gateway that presents a QR placeholder and lets customers upload a payment slip for verification. Key behaviours include:

- Static QR placeholder configured via Media Library (`qr_image_url`) with default fallback SVG.
- Slip upload & verification via REST to `wc-scanandpay/v1` with minimal payload.
- Admin settings for gateway basics and verification backend (webhook URL, secret, limits).

Desired Changes

- Decide verification backend (n8n vs Laravel) and standardize response schema: `{ status, reference_id?, approved_amount?, reason? }`.
- Optional UX: progress indicators, retry, and async status polling via `/status/{token}`.
- Continue maintenance of responsive layout and Blocks parity.

Roadmap

- Short term: Use n8n IMAP/email alert parsing to verify incoming funds before relying on slips; document the flow and security controls.
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

## SlipOK-inspired Admin Features to Adopt
- Metabox on order edit: slip thumbnail, status, approved amount/reference, logs, and a “Re-verify” button.
- Order list column “Scan&Pay” (HPOS-safe) with concise status badge.
- AJAX endpoint `wp_ajax_san8n_verify_again` with nonce + caps; calls verifier and updates order meta/status immediately.
- Scheduler: if response is `pending` with optional `delay`, schedule `san8n_verify_uploaded_slip` to re-check later.
- Optional auto‑status update: move to Processing/Completed on approval (setting-driven).
- Anti-reuse: compute/store slip hash to prevent reuse across orders.

## Verification Backend Adapter Contract
Any backend (n8n/Laravel) must return a uniform JSON object:
```
{
  "status": "approved|pending|rejected",
  "message": "optional human readable",
  "approved_amount": 1499.00,
  "reference_id": "abc123",
  "delay": 10
}
```
- WordPress will treat `pending` as re-checkable and may schedule a retry using `delay` (minutes).
- All requests are HMAC-signed; HTTPS required; SSL verification ON.

## Phased Plan
- Short term (Now): Use n8n IMAP/email alert parsing to confirm incoming funds; document flow/security; add admin “Re-verify” button + metabox + column.
- Mid term: Add Laravel adapter as selectable backend in settings; keep the same response contract; implement scheduler and optional auto‑status.
- Long term: Slipless unique-amount + alert + webhook matching; idempotency; manual review queue; richer bank parsers.