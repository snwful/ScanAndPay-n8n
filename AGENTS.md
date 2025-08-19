# AGENTS.md — Operational Guide

## Purpose
Rules and map for agents (Codex/Windsurf/Cursor) to work safely on this WooCommerce plugin.

## Key Files
- scanandpay-n8n.php (bootstrap, constants like `SAN8N_REST_NAMESPACE`)
- includes/class-san8n-gateway.php (settings incl. `qr_image_url`, classic checkout UI)
- includes/class-san8n-rest-api.php (POST /verify-slip)
- includes/class-san8n-blocks-integration.php (Blocks checkout integration; passes `qr_placeholder`)
- assets/js/checkout-inline.js (classic checkout logic)
- assets/js/blocks-integration.js (Blocks UI logic)
- assets/js/admin.js and assets/js/settings.js (admin UX incl. Media Library picker)
- assets/images/qr-placeholder.svg (default QR placeholder)
- readme.txt (Changelog)
- context.md / instructions.md / evaluation.md / plan.md

## Additional Key Files
- includes/class-san8n-admin.php (admin metabox, columns, and AJAX re-verify UI)
- includes/class-san8n-helper.php (shared helpers)

## Current Mission
- Use a static QR placeholder image configured via WordPress Media Library (`qr_image_url` setting). If empty, fall back to `assets/images/qr-placeholder.svg`.
- Ensure both Classic and Blocks checkout display the placeholder image consistently and responsively.
- Slip verification is handled via REST `POST /wp-json/wc-scanandpay/v1/verify-slip`, which forwards to an external service. Backend choice (n8n vs Laravel) is pending; currently n8n is supported.
- Maintain security: nonces, file type/size validation, EXIF stripping, capability checks.

## API (internal → verification backend)
POST /wp-json/wc-scanandpay/v1/verify-slip (multipart)
- slip_image (file), session_token, order_id, order_total
Backend (n8n or Laravel TBD) → { status: approved|rejected, reference_id?, approved_amount?, reason? }

## Policies
- Bump plugin header + SAN8N_VERSION every change
- Append readme.txt changelog with date + bullets
- Update plan.md in each PR/iteration
- PHPCS clean; WP/WC compatibility intact

## Do / Don’t
- Do use the `qr_image_url` setting and Media Library picker for the QR placeholder.
- Do localize needed data via `wp_localize_script` (e.g., numeric `order_total`).
- Don’t render PromptPay shortcode or generate custom QR payloads.
- Don’t depend on cart_hash for verification; keep payload minimal (order_id, order_total, session_token, slip_image).
- Don’t add new deps without reason.

## Definition of Done
- Settings page includes a working Media Library picker that saves `qr_image_url` and shows a preview.
- Classic and Blocks checkout show the selected static QR placeholder (or default SVG) responsively without layout bugs.
- REST endpoint `/wp-json/wc-scanandpay/v1/verify-slip` accepts expected params and handles backend (currently n8n) responses.
- Version and changelog are consistent; plan.md updated; evaluation.md checks pass.

## Roadmap
- Short term (Now): Integrate n8n IMAP/email alert parsing as a verification source to confirm incoming funds before relying on slips; document flow and security controls.
- Mid term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both n8n and Laravel backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.
- Additionally: Add progress UI, retry logic, and optional status polling to improve user feedback.

## SlipOK-inspired Admin Patterns to Adopt Next
- Re-verify button in Orders (supports HPOS)
  - Add a button similar to `slipok-verify-again` that calls `wp_ajax_san8n_verify_again` with nonce.
  - Backend: call current verifier (n8n for now, Laravel optional) via `wp_remote_post()` with SSL verify ON.
  - Update order meta: `_san8n_status`, `_san8n_status_message`, `_san8n_verification_log[]`, `_san8n_reference_id`.
- Metabox + Order list column
  - Metabox shows slip thumbnail, status, approved amount/reference, logs, and a Re-verify button.
  - Add an order list column “Scan&Pay” summarizing status; ensure HPOS-compatible hooks.
- Pending → scheduled re-check
  - If backend returns `status: pending` and optional `delay` (minutes), schedule: `wp_schedule_single_event( time() + delay*60, 'san8n_verify_uploaded_slip', [$order_id] )`.
- Auto-update order status (optional setting)
  - When approved, optionally move to Processing/Completed; expose setting in gateway/admin.
- File types and anti-reuse
  - Consider allowing `webp/jfif` in validation (server-side + client hints).
  - Compute and store slip hash to prevent reuse across orders.
- Security
  - Use `wp_remote_post()` (not raw cURL). Enforce HTTPS and SSL verification.
  - HMAC-sign payloads to external verifier; verify signatures on callbacks.

## Backend Options (Adapter Pattern)
- Current: n8n webhook (email/IMAP-based verification).
- Optional: Laravel service with the same contract.
- Standard response schema expected from any adapter:
  - `{ status: approved|pending|rejected, message?, approved_amount?, reference_id?, delay? }`

## Step-by-step (Implementation Order)
1) Admin UI: metabox + column + AJAX re-verify, with nonces and caps.
2) Adapter wrapper in REST handler to call verifier (n8n or Laravel) uniformly.
3) Scheduler hook `san8n_verify_uploaded_slip` and re-dispatch on pending.
4) Settings: toggle for auto-update order status; optional select backend (n8n/Laravel).
5) Add logs and error surfaces in the order UI; mask PII.
6) Optional: extend file types, slip hash anti-reuse, rate limits.
