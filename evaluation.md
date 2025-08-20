Evaluation Guidelines

Use this checklist to verify that Codex’s modifications meet the project requirements and maintain plugin quality.

Functional Tests

Settings page:

- The gateway includes a Media Library field for selecting the QR placeholder image (`qr_image_url`).
- Preview of the selected image renders correctly and persists after save.

- Legacy PromptPay-related fields and validators are removed.

- Backend toggle is present (n8n default; Laravel optional). Switching backends shows the correct URL/Secret fields, and the “Test Backend” button relabels/moves next to the active backend field.
- Test backend AJAX returns clear success or error messages without exposing secrets or URLs; client enforces HTTPS for endpoint input.

Checkout page:

- A static QR placeholder image is displayed in both Classic and Blocks checkout. If `qr_image_url` is unset, the fallback `assets/images/qr-placeholder.svg` is used.

- Customers can upload a slip file (JPG/PNG) and see a preview. The verify button triggers a request containing `order_id`, `order_total`, `session_token` and the image file; no `cart_hash` is sent.

- No JavaScript errors appear in the console; all legacy PromptPay/shortcode logic is removed.

- Classic vs Blocks: Both show the same placeholder; layout is responsive with no overflow or distortion on small screens.

REST API:

POST requests to `/wp-json/wc-scanandpay/v1/verify-slip` accept `order_id`, `order_total`, `slip_image` and `session_token` (no `cart_total`/`cart_hash`).

- Upon receiving a mock “approved” response, the order status changes to “processing” or “completed”, meta fields are updated, and a success message is returned. A mock “rejected” response should result in an appropriate error message.

- Filters respected for outbound requests: `san8n_verifier_timeout` and `san8n_verifier_retries` (args include backend id: `n8n` or `laravel`).

Outbound verifier request (adapter):

- REST handler uses `SAN8N_Verifier_Factory` from `includes/class-san8n-verifier.php` to call the selected backend (n8n or Laravel).
- Outbound headers include `X-PromptPay-Timestamp`, `X-PromptPay-Signature` (HMAC-SHA256 of `${timestamp}\n${sha256(body)}`), `X-PromptPay-Version: 1.0`, and `X-Correlation-ID`.
- HTTPS required with SSL verification; request body is multipart with `slip_image`, `order` JSON, and `session_token`.

Tasker Forwarder Acceptance (Backend):

- n8n (or Laravel) can receive HTTPS POSTs from Android Tasker containing minimal notification/SMS fields (`app`, `title`, `text`, `posted_at`, `nid`).
- If enabled, HMAC or shared secret verification is performed on incoming Tasker requests (e.g., `X-Secret` or `X-Signature` based on `${timestamp}\n${sha256(body)}`).
- Backend parses amount/reference/timestamp via regex and stores a recent cache (e.g., 10–15 minutes) for matching.
- Backend de-duplicates forwarded alerts (e.g., `nid+posted_at` or content hash).
- When `/verify-slip` calls the backend, it responds using the unified contract `{ status: approved|rejected, message?, approved_amount?, reference_id? }` based on the cached alerts and matching rules.
- Failure cases return informative errors: missing/invalid signature, no matching transaction in window, parse failure, backend timeout.

Code Quality

The code compiles without syntax or fatal errors. All referenced functions, classes and variables exist.

No unused imports or variables remain (e.g., `promptpay_payload`, `generate_qr_payload()` etc.).

Comments and docblocks are updated to reflect the new behaviour.

The plugin version constant and header are bumped to a new version, and the readme.txt file contains a matching changelog entry with the date and summary of changes.

A plan.md file exists and describes the current update’s goals, tasks, and outstanding work.

Translation functions (__() and _e()) are used consistently for any new strings.

Security & Standards

Uploaded files are sanitized and validated for size and MIME type as before.

Nonces and capability checks remain in place for admin actions.

The plugin continues to conform to WordPress coding standards (indentation, naming conventions, escaping output).

User Experience

The admin interface remains intuitive; the QR image setting is clearly labeled and includes a preview.

Customers are clearly instructed to scan the displayed QR placeholder and upload their slip; verification feedback is clear.

Error messages remain informative and are translated via language files where possible.

Roadmap Alignment

- Short term: Use n8n IMAP/email alert parsing or Android (Tasker) notification/SMS forwarding to verify incoming funds; document flow, security (HTTPS/HMAC), and reliability (battery, retries, de-dup).
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

Planned Checks (Checkout-only)

- Checkout Verification UX:
  - Verify button sends slip to `/verify-slip` with minimal payload.
  - On `approved`, Place order becomes enabled; auto-submit triggers if configured.
  - On `rejected`, clear approval flag and show translated error; Place order remains disabled.
- Backend Adapter:
  - REST handler calls n8n (and optionally Laravel later) using unified contract `{ status, message?, approved_amount?, reference_id? }`.
  - HMAC signing enforced; HTTPS/SSL verification enabled; timeouts/retries reasonable via `san8n_verifier_timeout`/`san8n_verifier_retries`.
- Classic/Blocks Parity:
  - Both checkouts render static QR placeholder and use the same verify flow without console errors.
- Anti-reuse & File Types (optional):
  - Optional slip hash to block reuse across orders.
  - Optional safe support for `webp/jfif` with strict server-side validation.