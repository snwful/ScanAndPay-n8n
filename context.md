Plugin Context and Rationale

This plugin provides a WooCommerce payment gateway that allows customers to scan a static QR code and upload a payment slip. Version 1.1.0 replaces the previous dynamic PromptPay payload logic with a QR image selected from the Media Library and a simplified slip-verification flow via n8n.

Current Flow

1. Merchant selects a QR image in gateway settings.
2. Checkout displays that image; customer scans and pays the order total manually.
3. Customer uploads a slip; the frontend sends {slip_image, session_token, order_id, order_total, customer_email} to the REST endpoint.
4. The REST endpoint forwards the data to n8n (mockable) and trusts the approved/rejected response to update the order and session.

Security considerations include nonce checks, file type/size validation, EXIF stripping and rate limiting.

### Responsive QR
Mobile users reported QR images overflowing on narrow screens. To ensure consistent sizing across themes, responsiveness is now handled inside the plugin via a container and wrapper around the image.

Future Work

Integrate real bank verification via n8n and expand automated tests.
