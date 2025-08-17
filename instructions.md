Instructions for Codex

This file outlines the tasks Codex must perform to refactor the Scan & Pay (n8n) WooCommerce gateway according to the new requirements. Follow these steps carefully and ensure the final code compiles without syntax errors or undefined variables.

Status: As of v1.1.0, these changes are largely implemented. Keep this file as reference for maintenance and future iterations.

1. Update the Settings Form

Remove the gateway’s custom PromptPay payload/ID field. In `includes/class-san8n-gateway.php`, delete any `promptpay_payload` form field from `init_form_fields()`.

Do not add a media field. We will not use a static image. The PromptPay ID will come from the PromptPay plugin’s settings.

Remove unnecessary settings tied to dynamic price logic. If present, remove/hide items such as amount tolerance and time-window specific to price resets. Keep operational settings (webhook URL, shared secret, size limits, messages).

Bump the plugin version and changelog. Increment `SAN8N_VERSION` and the plugin header version. Add a readme.txt changelog entry describing: “Use PromptPay shortcode for QR, remove custom payload logic, simplify slip verification.” Include the date.

Update `plan.md` to capture goals, tasks, risks, acceptance criteria, and roadmap.

2. Update the Checkout Display

Render the PromptPay shortcode. In `payment_fields()`, remove any calls to `generate_qr_payload()` and output:

`echo do_shortcode( sprintf('[promptpayqr amount="%s"]', esc_attr( (float) WC()->cart->get_total('edit') )) );`

Notes:
- Do not pass `id` to the shortcode. Rely on PromptPay plugin settings for the ID.
- Ensure the amount is a plain float (no currency symbols/formatting).

Provide a graceful fallback. If the shortcode is not registered (PromptPay plugin inactive), render a small notice and show the existing SVG placeholder at `assets/images/qr-placeholder.svg` so checkout remains usable.

Retain the slip upload UI. The file input (#san8n-slip-upload), preview area and verify button can remain. Ensure the data attribute data-max-size still reflects the configured file size limit.

Remove hidden inputs related to the removed dynamic payload/price logic.

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

On the checkout page, the PromptPay shortcode QR should render the code with the locked amount. If the shortcode is unavailable, the SVG fallback should display. Slip upload should function, and the mock verification should update the order status as expected.

Maintain backwards‑compatible hooks and filters where possible. If you remove an option, consider cleaning up its value on plugin activation or migration.

Notes / Roadmap (do not implement in this iteration):
- Medium term: Re-render the shortcode HTML via AJAX on WooCommerce `update_checkout` to keep the amount in sync.
- Long term: Add WooCommerce Blocks support with a dedicated Blocks payment method integration.