Plugin Context and Rationale

This document provides background on the existing Scan & Pay (n8n) payment gateway plugin and the rationale for the upcoming refactor. It is intended to give Codex full context before modifying the code.

## Status (v1.1.0) — Current Architecture
**QR Rendering**: Classic checkout renders `[promptpayqr amount="{float_cart_total}"]` inside `payment_fields()` in `includes/class-san8n-gateway.php`. If the shortcode is missing, we show a notice and fallback SVG at `assets/images/qr-placeholder.svg`.

**PromptPay Dependency**: The PromptPay shortcode and its assets (CSS/JS) are provided by the bundled `promptpay/` plugin or an external PromptPay plugin. If the shortcode isn’t registered, `scanandpay-n8n.php` auto-bootstraps `promptpay/promptpay.php`.

**Assets**: The PromptPay CSS/JS (`promptpay/css/main.css`, `promptpay/js/main.min.js`) must load on checkout to render the QR inside `.ppy-card`. If they don’t load, the QR won’t appear even without console errors.

**Blocks vs Classic**: Blocks currently shows a static placeholder image (no live QR). Classic shows a live QR via shortcode. Enhancing Blocks to render a `.ppy-card` div and initialize PromptPay JS is on the roadmap.

**Slip Verification**: Frontend JS sends only `order_id`, `order_total`, `session_token`, and `slip_image` to `/wp-json/wc-scanandpay/v1/verify-slip`. The REST endpoint forwards to n8n and trusts its decision (approved/rejected), updating the order accordingly.

Existing Functionality

The plugin registers a WooCommerce payment gateway that lets customers pay by scanning a PromptPay QR code and uploading their payment slip. Key behaviours include:

Dynamic QR generation – In class‑san8n‑gateway.php, the gateway stores a promptpay_payload and uses it with the current cart total via generate_qr_payload($amount) to build a QR payload string
GitHub
. The QR code shown to customers is therefore tied to the exact order amount.

Slip upload & verification – In the checkout JavaScript (checkout‑inline.js), customers upload an image of their payment slip. The script packages slip_image, a session_token, the cart total and a cart hash into a FormData object and posts it to the REST endpoint /verify‑slip
GitHub
. On the server, verify_slip() receives these parameters, uploads the file and forwards it (plus the promptpay_payload) to an n8n webhook
GitHub
.

Admin settings – Administrators can configure the gateway in WooCommerce settings. Fields include enabling/disabling the gateway, setting the title and description, entering the PromptPay ID, n8n webhook URL and shared secret, and adjusting the amount tolerance and time window
GitHub
.

Desired Changes

We will render the PromptPay QR using the existing PromptPay plugin’s shortcode and lock the amount only, while loading the PromptPay ID from the PromptPay plugin settings. Customers will scan the QR in their banking app with the amount prefilled (locked by QR). The plugin should therefore:

- Replace any custom QR generation with `echo do_shortcode('[promptpayqr amount="{numeric_cart_total}"]')` inside `payment_fields()` of `includes/class-san8n-gateway.php`.
- Do not pass `id` to the shortcode; rely on the ID configured in `promptpay/promptpay.php` settings.
- Remove any use of `promptpay_payload` and `generate_qr_payload()` across PHP/JS.
- Simplify slip verification data – send the slip image and identifiers such as `order_id`, `order_total`, and `session_token` to n8n. No need to send `cart_total` or a cart hash.
- Continue to display the upload and verification UI and update order status based on n8n’s response.

These changes require coordinated modifications across PHP (gateway settings cleanup, checkout rendering, REST API) and JavaScript (checkout behaviour and payload simplification).

Roadmap

- Medium term: Re-render the QR when WooCommerce triggers `update_checkout` by fetching fresh shortcode HTML via AJAX so the locked amount always reflects the latest total.
- Long term: Implement WooCommerce Blocks support with a separate integration (React-based), since Blocks do not render `payment_fields()`.