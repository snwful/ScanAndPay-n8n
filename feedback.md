# Feedback

## Summary of Changes
- Implemented static QR image selection via Media Library (`qr_image_id`) and removed PromptPay payload settings.
- Checkout now renders the chosen QR image and sends `{slip_image, session_token, order_id, order_total, customer_email}` to the REST API.
- REST endpoint forwards data to n8n (mock) and updates orders based on approved/rejected status.
- Removed cart-hash and dynamic-price logic across PHP and JS; added version bump and changelog.

## Potential Issues or Blind Spots
- Blocks checkout path was updated but hasn't been fully tested; further QA is recommended.
- Media Library picker relies on `wp_enqueue_media` and may need cross-version testing.
- Responsive QR tested on 360×800, 375×812, 412×915 — no overflow observed.

## Future Improvements
- Integrate real bank verification API in n8n flow.
- Add automated end-to-end tests for slip upload and approval.
- Consider additional validation for order context when verifying slips.
