=== Scan & Pay (n8n) ===
Contributors: scanandpay
Tags: woocommerce, payment gateway, promptpay, qr code, thailand
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.1.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce payment gateway that shows a static QR placeholder image (configurable via WordPress Media Library) and verifies uploaded payment slips inline via a backend service.

== Description ==

Scan & Pay (n8n) is a WooCommerce payment gateway plugin that enables customers to pay using QR codes and verify their payment inline during checkout without leaving the page.

= Key Features =

* **Static QR Placeholder** - Select a QR image via the WordPress Media Library (with default fallback SVG)
* **Inline Payment Verification** - Customers verify payment without leaving checkout
* **Two Checkout Modes** - Support for both Classic and Blocks checkout (both display the static placeholder)
* **Secure Integration** - HMAC-signed communication with your verification backend
* **Admin Management** - Complete order management with slip preview and actions
* **File Security** - EXIF data stripping and randomized filenames
* **Rate Limiting** - Built-in protection against abuse
* **Accessibility** - Full a11y support with ARIA labels
* **Internationalization** - Translation-ready with RTL support

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* Verification backend service (n8n or Laravel)
* GD or Imagick PHP extension for image processing

== Installation ==

1. Upload the `scanandpay-n8n` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Scan & Pay (n8n)" payment method
5. Configure your settings:
   - Select your QR placeholder image via the Media Library
   - Set your verification backend webhook URL and shared secret (n8n currently supported; Laravel planned)
   - Configure file upload limits and retention
   - Enable desired checkout modes

== Configuration ==

= Basic Settings =

* **Title** - Payment method title shown to customers
* **Description** - Payment method description
* **QR Image** - Select a QR placeholder image via the Media Library; falls back to bundled SVG

= Verification Backend =

* **Webhook URL** - Your verification service endpoint (n8n or Laravel)
* **Webhook Secret** - Shared secret for HMAC signing
* **Amount Tolerance** - Acceptable payment difference (THB)
* **Time Window** - Payment verification time limit (minutes)

= Checkout Modes =

* **Classic Mode** - Auto-submit after approval option
* **Blocks Mode** - Express button or experimental auto-submit
* **Debounce Time** - Prevent double-submit delay (ms)

= File Upload =

* **Max File Size** - Maximum slip image size (MB)
* **Allowed Formats** - JPG, PNG
* **Retention Days** - Auto-cleanup old files
* **EXIF Stripping** - Automatic privacy protection

== Admin Features ==

= Order Management =

* View payment slip thumbnail
* Check verification status
* See approved amount and reference ID
* Access rejection reasons
* View timestamps and audit log

 

== Security Features ==

* HMAC signature verification on all API calls
* Rate limiting (5 requests per minute per IP)
* File type and size validation
* EXIF data stripping for privacy
* Randomized file names
* Nonce verification on all AJAX calls
* Capability checks for admin actions
* PII masking in logs

== Slipless Mode (Preview) ==

The next release is pivoting to a slipless flow (no bank/PSP APIs or fees). WordPress will securely proxy requests to n8n, which generates EMV PromptPay with unique cents (0–99 satang) and a 10‑minute TTL. Checkout will poll order status and auto-complete on confirmation. Slip upload remains as fallback.

Planned endpoints (WordPress):

* `POST /wp-json/san8n/v1/qr/generate` — signs (server-side HMAC) and forwards to n8n; response includes `{ emv, amount_to_pay, amount_variant?, currency, expires_epoch, session_token }`
* `GET /wp-json/san8n/v1/order/status?order_id&session_token` — polls n8n session store and returns `{ status: pending|paid|expired, ... }`
* `POST /wp-json/san8n/v1/order/paid` — optional callback from n8n to update order state

Headers (slipless): use `X-San8n-*` where `X-San8n-Signature = HMAC_SHA256(secret, `${timestamp}\n${sha256(rawBody)}`)`

Matching engine: Android Tasker forwards bank notifications to n8n, which extracts amount/time and exact-matches the `amount_variant` within TTL; sessions are marked `used=true`.

== Developer Information ==

= Hooks and Filters =

* `san8n_before_verify_payment` - Before payment verification
* `san8n_after_verify_payment` - After payment verification  
* `san8n_payment_approved` - When payment is approved
* `san8n_payment_rejected` - When payment is rejected
* `san8n_file_upload_args` - Modify file upload parameters
* `san8n_verifier_timeout` - Adjust verifier HTTP timeout (seconds). Args: (int $timeout, string $backend)
* `san8n_verifier_retries` - Adjust verifier retry attempts. Args: (int $retries, string $backend)

= REST API Endpoints =

* `POST /wp-json/wc-scanandpay/v1/verify-slip` - Verify payment slip
 

= Constants =

* `SAN8N_VERSION` - Plugin version
* `SAN8N_GATEWAY_ID` - Payment gateway ID
* `SAN8N_OPTIONS_KEY` - Settings option key
* `SAN8N_SESSION_FLAG` - Session approval flag
* `SAN8N_LOGGER_SOURCE` - Logger source identifier

== Verification Backends (Adapter) ==

Supported: n8n and Laravel (optional) using the same contract

Unified response contract expected from any backend:

```
{
  "status": "approved|rejected",
  "message": "optional",
  "approved_amount": 1499.00,
  "reference_id": "abc123"
}
```

= Verification Contract =

- Request (multipart/form-data): `slip_image` (file), `order` (JSON with `id`, `total`, `currency`), `session_token`.
- Headers: `X-PromptPay-Timestamp` (unix), `X-PromptPay-Signature` (HMAC-SHA256 of `${timestamp}\n${sha256(body)}`), `X-PromptPay-Version: 1.0`, `X-Correlation-ID`.
- Adapter implementation reference: `includes/class-san8n-verifier.php` (factory + n8n/Laravel adapters).

= Matching Rules (Informational) =

- Time window: match bank email alerts within a configurable window (e.g., 10–15 minutes) around checkout.
- Amount: Pro (Laravel) requires exact match using a unique-amount suffix; Standard (n8n) may allow a small tolerance if enabled.
- Idempotency: deduplicate by email Message-ID/reference; propagate `X-Correlation-ID` for tracing.
- Outcome: backend returns `approved` or `rejected` only, with optional `reference_id`, `approved_amount`, `message`.

== Android Forwarder (Tasker) ==

Android Tasker can forward bank/Stripe notifications or SMS to your backend (n8n/Laravel) over HTTPS. The WooCommerce plugin remains unchanged and still calls `/verify-slip`; the backend uses recent forwarded alerts to decide `approved|rejected` per the unified contract.

- Headers from Tasker → backend: `X-Device-Id`, `X-Secret` or `X-Signature` (HMAC of `${timestamp}\n${sha256(body)}`), optional `X-Timestamp`.
- Payload example (JSON): `{"source":"android-tasker","app":"%an","title":"%ntitle","text":"%ntext","posted_at":"%DATE %TIME","nid":"%nid"}`
- Backend responsibilities: verify secret/HMAC, parse amount/reference via regex, de-duplicate (nid+timestamp or content hash), cache recent transactions (10–15 minutes), and respond via the unified contract when called by `/verify-slip`.
- Reliability: disable battery optimizations for Tasker, allow background activity, implement retries/backoff and offline queue, mask PII in logs, enforce HTTPS with SSL verification.

== Optional Enhancements ==

- Progress UI and retry hints during verification
- Optional anti-reuse via slip hash; optional support for `webp/jfif` with strict validation
- Laravel adapter as an alternative backend using the same contract

== Laravel Adapter Quickstart ==

- Endpoint: `POST /api/verify` using multipart/form-data identical to n8n (fields: slip_image, order JSON, session_token)
- Headers: `X-PromptPay-*` (Timestamp, Signature, Version) and `X-Correlation-ID` with HMAC-SHA256 signature
- Timeouts/retries configurable via WordPress filters: `san8n_verifier_timeout`, `san8n_verifier_retries`

== Troubleshooting ==

= Common Issues =

**Payment not verifying:**
- Check verification backend URL is correct
- Verify webhook secret matches
- Ensure verification service is running
- Check server time synchronization

**File upload errors:**
- Verify GD/Imagick is installed
- Check upload directory permissions
- Ensure file size limits are appropriate
- Confirm PHP memory limit is sufficient

**Auto-submit not working:**
- Enable Classic auto-submit in settings
- Check JavaScript console for errors
- Verify no checkout validation errors
- Ensure cart hasn't changed after approval

== Frequently Asked Questions ==

= Does this plugin support WooCommerce Blocks? =

Yes. Both Classic and Blocks checkout display the configured static QR placeholder image.

= Can I use this without n8n? =

You need a verification backend. n8n is supported today; Laravel support is planned. Configure your webhook URL and secret in settings.

= Is the QR code generated dynamically? =

No. The plugin displays a static QR placeholder image selected in settings. Dynamic QR generation may be considered in the future if requirements change.

= Can customers pay multiple times? =

No, once a payment is approved, the session is locked to prevent duplicate payments.

= How long are payment slips stored? =

Configurable retention period (default 30 days) with automatic cleanup.

= Can I manually approve payments? =

Yes, administrators can manually approve or reject payments from the order edit page.

== Roadmap ==

- Short term: Use n8n IMAP/email alert parsing or Android (Tasker) notification/SMS forwarding to verify incoming funds; document flow, security (HTTPS/HMAC), and reliability (battery, retries, de-dup).
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

== Changelog ==

= 1.1.4 - 2025-08-30 =
* Tune async callback retry/backoff to 1s, 2s, 4s (default 4 attempts)
* Add retry_backoff structured log with delay/jitter; keep idempotent headers on retries
* Honor SAN8N_CALLBACK_ASYNC toggle for retries; filters `san8n_verifier_timeout` and `san8n_verifier_retries`

= 1.1.3 - 2025-08-29 =
* Add structured logging hooks and retry/backoff policy for callbacks

= 1.1.1 - 2025-08-25 =
* Ensure PromptPay shortcode and assets load on checkout
* Fallback SVG shown when PromptPay plugin inactive
* Optional filter to enable live QR in Blocks checkout

= 1.1.0 - 2025-08-21 =
* Use PromptPay shortcode for QR (amount-only)
* Remove custom payload logic and simplify slip verification

= 1.0.1 - 2025-08-16 =
* Improved small-screen responsive layout for Scan & Pay checkout

= 1.0.0 =
* Initial release
* Classic checkout support with auto-submit
* Blocks checkout with Express button
* n8n webhook integration
* Admin order management
* File upload with EXIF stripping
* Rate limiting and security features
* Full i18n and RTL support

== Upgrade Notice ==

= 1.0.1 =
Improved small-screen responsive layout for Scan & Pay checkout.

= 1.0.0 =
Initial release of Scan & Pay (n8n) payment gateway for WooCommerce.
