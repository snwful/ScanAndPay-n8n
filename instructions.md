Instructions for Codex

This file outlines the tasks Codex must perform to refactor the Scan & Pay (n8n) WooCommerce gateway according to the new requirements. Follow these steps carefully and ensure the final code compiles without syntax errors or undefined variables.

1. Update the Settings Form

Remove the PromptPay ID/payload field. In class‑san8n‑gateway.php the init_form_fields() method currently defines a 'promptpay_payload' text field
GitHub
. Delete this entry.

Add a new Media Library field. Introduce a field named qr_image_id that allows the administrator to select an image from the WordPress Media Library. Use a type of 'text' (to store the attachment ID) and add a custom button that opens the media uploader. You may refer to WooCommerce’s approach in other plugins or create a custom render callback.

Remove unnecessary settings. Because the price is no longer dynamic, fields like amount_tolerance, promptpay_payload and time‐window calculations can be removed or hidden. Keep the webhook URL, shared secret and other operational settings.

Load the selected image. In the gateway constructor (__construct), retrieve the option qr_image_id and store it in a property (e.g., $this->qr_image_id). Use wp_get_attachment_url($this->qr_image_id) later to obtain the URL.

Bump the plugin version and changelog. The plugin defines a version constant (e.g., SAN8N_VERSION) in the main file header. Whenever you make changes, increment this version (e.g. from 1.0.0 to 1.1.0) in both the header comment and any constants. Append a new entry under the == Changelog == section in readme.txt describing the changes in this update (e.g. “Added QR image field, removed PromptPay payload, simplified slip verification”). Ensure the changelog entry matches the new version number and includes the date.

Create or update plan.md. Generate a markdown file called plan.md in the root of the project (or update it if it exists). This file should outline the high‑level plan and rationale for the current set of changes, including tasks completed, tasks deferred, and any follow‑up work (e.g., integration with the real bank verification API). Keep this document concise but informative.

2. Update the Checkout Display

Replace dynamic QR generation. In the payment_fields() method, remove calls to WC()->cart->get_total() and $this->generate_qr_payload(). Instead, retrieve the QR image URL via wp_get_attachment_url($this->qr_image_id) and echo an <img> tag showing that image. Provide instructions to the customer to scan the QR and enter the amount manually.

Retain the slip upload UI. The file input (#san8n-slip-upload), preview area and verify button can remain. Ensure the data attribute data-max-size still reflects the configured file size limit.

Remove hidden inputs related to dynamic price, such as any hidden fields storing san8n-approved-amount or san8n-session-cart-total that are no longer needed.

3. Modify the Checkout JavaScript

Edit assets/js/checkout-inline.js to simplify the payload sent to the REST API:

Prepare order metadata. When the page loads (within SAN8N_Checkout.init), obtain the order’s total and ID from localized parameters. You can add these values via wp_localize_script in PHP when enqueuing the script; for example:

wp_localize_script('san8n-checkout-inline', 'san8n_params', array(
    'order_id'   => $order_id,
    'order_total' => wc_format_localized_price($order_total),
    // ... existing params
));


Build FormData. In performVerification(), append order_id and order_total to the FormData instance along with the image and session token. Remove cart_total and cart_hash
GitHub
.

Remove update‑checkout logic. Delete or disable the handleUpdateCheckout() function that resets approval when the cart total changes
GitHub
. This logic was needed only for dynamic price calculations.

Update error messages and labels to reflect that customers must enter the amount themselves.

4. Adjust the REST API

In class‑san8n‑rest‑api.php, modify register_routes() and verify_slip():

Route parameters. Update the route definition for /verify-slip so that cart_total and cart_hash are no longer required. Introduce optional/required parameters order_id and order_total.

Process the request. In verify_slip(), after handling the file upload, retrieve order_id and order_total from the request. You may still look up the order with wc_get_order($order_id) to ensure it exists and to record meta data.

Call n8n. Build a request body containing the slip image and an order object with id and total. Use mock logic at this stage; you can simulate a status => approved response for testing.

Handle the response. Based on n8n’s returned status (approved or rejected), update the order’s meta (_san8n_status, _san8n_reference_id, etc.), call $order->payment_complete() where appropriate, and return a JSON response to the client.

5. Clean Up Unused Code

Remove the generate_qr_payload() method entirely if it is no longer referenced
GitHub
.

Search for and delete any uses of promptpay_payload in the repository, including in the REST API call to n8n
GitHub
.

Remove any CSS or JavaScript that references the QR placeholder image or dynamic calculations.

6. Ensure Functionality and Compatibility

After modifications, verify that the plugin activates without errors and the settings page loads correctly.

On the checkout page, the QR image should display, slip upload should function, and the mock verification should update the order status as expected.

Maintain backwards‑compatible hooks and filters where possible. If you remove an option, consider cleaning up its value on plugin activation or migration.

7. Responsive QR

- Replace any raw `<img>` output with `wp_get_attachment_image()` using `srcset` and `sizes` filters.
- Create `assets/css/frontend.css` to constrain the QR and prevent overflow.
- Enqueue the stylesheet only on checkout or order-pay pages.
- Run PHPCS, bump the plugin version to 1.1.1, and append the changelog.
