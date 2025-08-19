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