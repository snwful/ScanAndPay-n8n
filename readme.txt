=== Scan & Pay (n8n) ===
Contributors: scanandpay
Tags: woocommerce, payment gateway, promptpay, qr code, thailand
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.1.1
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
* Verification backend service (n8n currently supported; Laravel planned)
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

* **Webhook URL** - Your verification service endpoint (n8n supported; Laravel planned)
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

== Developer Information ==

= Hooks and Filters =

* `san8n_before_verify_payment` - Before payment verification
* `san8n_after_verify_payment` - After payment verification  
* `san8n_payment_approved` - When payment is approved
* `san8n_payment_rejected` - When payment is rejected
* `san8n_file_upload_args` - Modify file upload parameters
* `san8n_webhook_timeout` - Adjust webhook timeout

= REST API Endpoints =

* `POST /wp-json/wc-scanandpay/v1/verify-slip` - Verify payment slip
 

= Constants =

* `SAN8N_VERSION` - Plugin version
* `SAN8N_GATEWAY_ID` - Payment gateway ID
* `SAN8N_OPTIONS_KEY` - Settings option key
* `SAN8N_SESSION_FLAG` - Session approval flag
* `SAN8N_LOGGER_SOURCE` - Logger source identifier

== Verification Backends (Adapter) ==

Supported today: n8n (webhook)
Planned: Laravel service with the same contract

Unified response contract expected from any backend:

```
{
  "status": "approved|rejected",
  "message": "optional",
  "approved_amount": 1499.00,
  "reference_id": "abc123"
}
```

== Optional Enhancements ==

- Progress UI and retry hints during verification
- Optional anti-reuse via slip hash; optional support for `webp/jfif` with strict validation
- Laravel adapter as an alternative backend using the same contract

== Laravel Adapter Quickstart (Planned) ==

- Endpoint: `POST /api/verify` with HMAC-signed JSON body matching the contract above
- Timeouts/retries configurable via WordPress filters (to be documented)

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

- Short term: Use n8n IMAP/email alert parsing to verify incoming funds before relying on slips; document the flow and security controls.
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

== Changelog ==

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
