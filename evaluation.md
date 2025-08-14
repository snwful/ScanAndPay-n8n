Evaluation Guidelines

Use this checklist to verify that Codex’s modifications meet the project requirements and maintain plugin quality.

Functional Tests

Settings page:

A new “QR Image” field appears in the Scan & Pay (n8n) gateway settings. The field allows selecting an image from the WordPress Media Library.

The old “PromptPay Payload/ID” field and related validators are removed.

Checkout page:

The chosen QR image is displayed to customers in place of the dynamically generated QR code.

Customers can upload a slip file (JPG/PNG) and see a preview. The verify button triggers a request containing order_id, order_total, session_token and the image file; no cart_hash or cart_total are sent
GitHub
.

No JavaScript errors appear in the console; the previous logic for resetting approval on cart updates is absent.

REST API:

POST requests to /verify-slip accept order_id, order_total, slip_image and session_token but not cart_total/cart_hash
GitHub
.

Upon receiving a mock “approved” response, the order status changes to “processing” or “completed”, meta fields are updated, and a success message is returned. A mock “rejected” response should result in an appropriate error message.

Code Quality

The code compiles without syntax or fatal errors. All referenced functions, classes and variables exist.

No unused imports or variables remain (e.g., promptpay_payload, generate_qr_payload() etc.).

Comments and docblocks are updated to reflect the new behaviour.

The plugin version constant and header are bumped to a new version, and the readme.txt file contains a matching changelog entry with the date and summary of changes.

A plan.md file exists and describes the current update’s goals, tasks, and outstanding work.

Translation functions (__() and _e()) are used consistently for any new strings.

Security & Standards

Uploaded files are sanitized and validated for size and MIME type as before.

Nonces and capability checks remain in place for admin actions.

The plugin continues to conform to WordPress coding standards (indentation, naming conventions, escaping output).

User Experience

The admin interface remains intuitive; selecting a QR image should not require manual URL entry.

Customers are clearly instructed to scan the QR and enter the correct amount manually.

Error messages remain informative and are translated via language files where possible.