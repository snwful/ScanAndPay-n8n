Instructions for Codex

This file outlines the tasks Codex must perform to refactor the Scan & Pay (n8n) WooCommerce gateway according to the new requirements. Follow these steps carefully and ensure the final code compiles without syntax errors or undefined variables.

Status: As of v1.1.1, the gateway uses a static QR placeholder image configured via the WordPress Media Library and displays it in both Classic and Blocks checkout. Slip verification posts to `/wp-json/wc-scanandpay/v1/verify-slip` and forwards to a backend verifier (n8n currently supported; Laravel under consideration). Keep this file as a maintenance guide.

1. Settings: Media Library Picker

Add/maintain a Media Library field for the QR placeholder image in `includes/class-san8n-gateway.php` (`init_form_fields()`), stored as `qr_image_url`.

Show a live preview on the settings screen (see `assets/js/settings.js`). Persist the value and ensure proper sanitization/escaping.

Keep operational settings (webhook URL, shared secret, limits/messages). Remove any legacy options related to dynamic QR generation.

When shipping changes, bump `SAN8N_VERSION` and update `readme.txt` changelog.

2. Checkout Display (Static Placeholder)

Render the configured static QR image in `payment_fields()` for Classic checkout. If `qr_image_url` is empty, fall back to `assets/images/qr-placeholder.svg`.

Blocks checkout should receive the same URL via `includes/class-san8n-blocks-integration.php` (exposed as `qr_placeholder`) for a consistent UI.

Retain the slip upload UI: `#san8n-slip-upload`, preview area, and verify button. Ensure file size limits are honored (data attributes) and layout is responsive on small screens.

3. Modify the Checkout JavaScript

Edit `assets/js/checkout-inline.js` to simplify the payload sent to the REST API:

Build FormData. In `performVerification()`, append `order_id`, `order_total` (as a numeric string), the slip image, and `session_token`. Remove `cart_total` and `cart_hash`.

Remove legacy update-checkout logic. Delete code that reset approval or recalculated QR payloads on `update_checkout`. The QR is provided by the shortcode; medium-term re-render will be handled separately.

4. Adjust the REST API

In class‑san8n‑rest‑api.php, modify register_routes() and verify_slip():

Route parameters. Update the route definition for /verify-slip so that `cart_total` and `cart_hash` are no longer accepted. Introduce required parameters `order_id` and `order_total`.

Process the request. In verify_slip(), after handling the file upload, retrieve order_id and order_total from the request. You may still look up the order with wc_get_order($order_id) to ensure it exists and to record meta data.

Call n8n. Build a request body containing the slip image and an order object with id and total. Use mock logic at this stage; you can simulate a status => approved response for testing.

Handle the response. Based on n8n’s returned status (approved or rejected), update the order’s meta (_san8n_status, _san8n_reference_id, etc.), call $order->payment_complete() where appropriate, and return a JSON response to the client.

5. Clean Up Unused Code

Remove the `generate_qr_payload()` method entirely and any code branches referencing it.

Search for and delete any uses of `promptpay_payload` in the repository, including in REST calls.

Keep the SVG placeholder as a graceful fallback only (when the PromptPay shortcode is unavailable). Remove any dynamic QR payload/calculation code that is no longer used.

6. Ensure Functionality and Compatibility

After modifications, verify that the plugin activates without errors and the settings page loads correctly.

On the checkout page, the static QR image should display in both Classic and Blocks checkout. If `qr_image_url` is empty, the bundled SVG should display. Slip upload should function, and verification should update the order status as expected.

Maintain backwards‑compatible hooks and filters where possible. If you remove an option, consider cleaning up its value on plugin activation or migration.

7. Next Iteration — SlipOK-inspired Admin + Scheduler (Plan)

- Admin Metabox (`includes/class-san8n-admin.php`):
  - Show slip thumbnail, status badge, approved amount/reference, last checked time, and logs.
  - Add a “Re-verify” button with nonce + capability checks.
- Order List Column (HPOS-compatible):
  - Add a “Scan&Pay” status column with concise badges.
- Admin JS (`assets/js/admin.js`):
  - Bind click to re-verify button → `wp_ajax_san8n_verify_again`.
  - Update status/logs inline on success.
- AJAX Endpoint:
  - Add `wp_ajax_san8n_verify_again` handler that calls the verification backend (n8n for now) using `wp_remote_post()` over HTTPS with SSL verification.
  - Update order meta: `_san8n_status`, `_san8n_status_message`, `_san8n_reference_id`, `_san8n_verification_log[]`, `_san8n_checked_at`.
- Scheduler:
  - Register action `san8n_verify_uploaded_slip`.
  - If backend returns `pending` with `delay` (minutes), call `wp_schedule_single_event( time() + delay*60, 'san8n_verify_uploaded_slip', [$order_id] )`.
- Backend Adapter (n8n/Laravel):
  - Standardize response schema `{ status: approved|pending|rejected, message?, approved_amount?, reference_id?, delay? }`.
  - Keep HMAC signing and HTTPS with SSL verification; configurable timeout/retry.
- Anti-reuse & File Types:
  - Optional: compute and store slip hash to prevent reuse across orders.
  - Optional: add safe support for `webp/jfif` with strict server-side validation.
- Tests:
  - Unit/integration tests for AJAX, scheduler enqueue, and meta updates.
  - Manual: verify metabox actions, list column, and pending→recheck flow.

Notes / Roadmap (do not implement in this iteration):
- Short term: Use n8n IMAP/email alert parsing to verify incoming funds before relying on slips; document the flow and security controls.
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.