Feedback Instructions for Codex

After completing the code refactor, provide a concise report covering the following points:

Summary of Changes – Describe the major modifications you made (e.g., implemented Media Library picker for QR image, switched to static QR placeholder in both Classic and Blocks, removed PromptPay shortcode/dynamic QR logic, fixed responsive layout, simplified verification payload) and how they satisfy the requirements.

Potential Issues or Blind Spots – Note any assumptions or areas that might need further clarification. For example: which verification backend will be used (n8n vs Laravel), maximum allowed file sizes/types across hosts, and any caching that could interfere with image rendering.

Future Improvements – Suggest enhancements such as progress UI for verification, retries, async status polling, choosing and integrating the final verification backend (n8n or Laravel), and possible future dynamic QR generation if requirements change.

Alignment With Matching Rules & Coverage – Ensure any future improvements stay consistent with the documented Matching Rules (time window, exact vs tolerant amount, idempotency via email Message-ID and `X-Correlation-ID`) and expand supported bank email parser coverage. Keep the unified adapter contract and outbound headers (`X-PromptPay-*`) intact across n8n/Laravel.

Structure the feedback as bullet points or short paragraphs. Aim to be honest and constructive, highlighting strengths and weaknesses of the solution.
 
 Roadmap Alignment (Short/Mid/Long)
 
 - Short term: Use n8n IMAP/email alert parsing to verify incoming funds; finalize checkout-only verification UX and security.
 - Medium term: Add an optional external API adapter (Laravel) selectable in settings; keep a unified checkout-only contract.
 - Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency and a manual review queue.

Slipless Mode (Preview) Notes

- WordPress adds proxy endpoints to n8n: `POST /san8n/v1/qr/generate`, `GET /san8n/v1/order/status`, and optional `POST /san8n/v1/order/paid` callback.
- Headers for slipless use `X-San8n-*` (`X-San8n-Timestamp`, `X-San8n-Signature = HMAC_SHA256(secret, `${timestamp}\n${sha256(rawBody)}`)`).
- n8n issues EMV PromptPay with unique cents and 10‑min TTL; stores session with `session_token`, `amount_variant`, and `used` flag.
- Checkout polls `/order/status` to transition UI to paid/expired automatically; slip upload remains fallback.

Additional Focus Areas (Upcoming Work)

- Checkout UX:
  - Verify button clarity, error handling, and translated messaging.
  - Auto-submit on approval (Classic) behavior and debounce to prevent double submit.
- Backend Adapter Decision:
  - Compare n8n vs Laravel using unified contract `{ status, message?, approved_amount?, reference_id? }`.
  - HMAC signing, HTTPS with SSL verification, and reasonable timeouts/retries on both.

Tasker Tester Checklist (Field Tests)

- Device prep: disable battery optimizations for Tasker, allow background activity, grant Notification Listener/SMS read (if used), and ensure background data is not restricted.
- Forwarding/HMAC: trigger a real or test bank notification; confirm Tasker fires and POSTs over HTTPS with `X-Secret` or `X-Signature` and timestamp.
- Offline/retry: enable airplane mode, generate an event, then go online; verify queued delivery, successful retry, and no duplicate records on backend.
- De-dup: send the same notification twice; backend caches once (e.g., by `nid+posted_at` or content hash) and responds idempotently.
- Parsing: verify amount/reference extraction from common Thai bank messages; include edge cases like commas/decimals and localized currency.
- Time window: ensure matches only within configured window (e.g., 10–15 minutes); outside window returns no match message.
- Error handling surfaced to admin Test Backend: invalid/missing signature (401/403), parse failure, no matching transaction, backend timeout, 4xx/5xx.
- PII masking: confirm logs and error messages redact names/account numbers; avoid storing raw SMS bodies long-term.
- Correlation: `X-Correlation-ID` propagates from plugin to backend logs for traceability.

- Security & Anti-reuse:
  - Optional slip hash policy prevents reuse across orders without false positives.
  - PII masking in logs and strict server-side file validation.