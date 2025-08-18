=== Scan & Pay (n8n) ===
Contributors: scanandpay
Tags: woocommerce, payment gateway, promptpay, qr code, thailand
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce payment gateway that integrates with n8n for inline PromptPay slip verification on the checkout page.

== Description ==

Scan & Pay (n8n) is a WooCommerce payment gateway plugin that enables customers to pay using PromptPay QR codes and verify their payment inline during checkout without leaving the page.

= Key Features =

* **Inline Payment Verification** - Customers can verify payment without leaving checkout
* **Two Checkout Modes** - Support for both Classic and Blocks checkout
* **Auto-submit Option** - Automatically submit orders after payment approval
* **Express Payment Button** - Quick payment option in Blocks mode
* **Secure Integration** - HMAC-signed communication with n8n webhook
* **Admin Management** - Complete order management with slip preview and actions
* **File Security** - EXIF data stripping and randomized filenames
* **Rate Limiting** - Built-in protection against abuse
* **Accessibility** - Full a11y support with ARIA labels
* **Internationalization** - Translation-ready with RTL support

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 7.0 or higher
* PHP 8.0 or higher
* n8n webhook endpoint configured
* GD or Imagick PHP extension for image processing

== Installation ==

1. Upload the `scanandpay-n8n` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable "Scan & Pay (n8n)" payment method
5. Configure your settings:
   - Enter your PromptPay ID/Phone number
   - Set your n8n webhook URL and secret
   - Configure file upload limits and retention
   - Enable desired checkout modes

== Configuration ==

= Basic Settings =

* **Title** - Payment method title shown to customers
* **Description** - Payment method description
* **PromptPay ID** - Your PromptPay identifier (phone/ID/tax number)

= n8n Integration =

* **Webhook URL** - Your n8n webhook endpoint URL
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

= Admin Actions =

* **Re-verify** - Retry payment verification
* **Approve** - Manually approve payment
* **Reject** - Manually reject with reason
* All actions logged with user and timestamp

= Capabilities =

* Custom capability: `manage_san8n_payments`
* Administrators have this by default
* Can be assigned to other roles as needed

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

* `POST /wp-json/san8n/v1/verify-slip` - Verify payment slip
* `GET /wp-json/san8n/v1/status` - Check verification status

= Constants =

* `SAN8N_VERSION` - Plugin version
* `SAN8N_GATEWAY_ID` - Payment gateway ID
* `SAN8N_OPTIONS_KEY` - Settings option key
* `SAN8N_SESSION_FLAG` - Session approval flag
* `SAN8N_LOGGER_SOURCE` - Logger source identifier

== Troubleshooting ==

= Common Issues =

**Payment not verifying:**
- Check n8n webhook URL is correct
- Verify webhook secret matches
- Ensure n8n service is running
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

Yes, full support for both Classic and Blocks checkout with dedicated UI for each.

= Can I use this without n8n? =

No, this plugin requires n8n webhook integration for payment verification.

= Is the QR code generated dynamically? =

Version 1.0 uses a placeholder QR. Dynamic EMVCo QR generation is planned for v2.

= Can customers pay multiple times? =

No, once a payment is approved, the session is locked to prevent duplicate payments.

= How long are payment slips stored? =

Configurable retention period (default 30 days) with automatic cleanup.

= Can I manually approve payments? =

Yes, administrators can manually approve or reject payments from the order edit page.

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
