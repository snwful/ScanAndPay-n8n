# FIRST-RUN IDE Setup Prompt (SOP)

Use this prompt as the very first message to your AI agent when opening the project on a new machine. It bootstraps context from the repo itself (not from per-machine memory).

---

```markdown
You are my coding copilot for the ScanAndPay-n8n project. Please bootstrap context and align the environment on this new machine by following this exact checklist.

Project root: C:\\Users\\snwfu\\Local Sites\\0001\\ScanAndPay-n8n

# 1) Read core docs (strict)
- Read these files fully before any action:
  - AGENTS.md
  - context.md
  - evaluation.md
  - feedback.md
  - instructions.md
  - plan.md
  - readme.txt
  - ScanAndPay_Overview.txt
  - docs/postgres-schema.sql
  - docs/CHANGELOG.md (for latest changes)
- Summarize key policies, scope, goals, non-goals, definition of done.

# 2) Project summary (confirm)
- Architecture:
  - WordPress plugin (WooCommerce gateway) proxies to n8n via HMAC.
  - n8n workflows handle: QR generate, order status polling, slip OCR verify, and Tasker/IMAP ingest.
  - Postgres tables: payments, payment_sessions (see docs/postgres-schema.sql).
- Security:
  - HMAC headers: accept both X-San8n-* and legacy X-PromptPay-*.
  - Signature format: HMAC_SHA256(timestamp + "\n" + sha256(rawBody)).
- Matching policy:
  - Exact-match amounts across system (tolerance = 0).
  - Time windows still apply per workflow policy.

# 3) Files and directories to know
- json/ (n8n workflows)
  - WP QR Generate.v1.json (webhook: wp/qr-generate; issues EMV QR and upserts payment_sessions)
  - WP Order Status.v1.json (webhook: wp/order-status; session lookup + match/approve)
  - WP Verify Slip.v2.ocr.json (webhook: wp/verify-slip-ocr; OCR + match)
  - Tasker Ingest.v5.3.ai-openrouter.v2.json (Android Tasker/email ingest)
- includes/ (WordPress plugin PHP)
  - class-san8n-gateway.php and related helpers
- docs/ (project documentation and schema)

# 4) Mandatory first-run checks (no edits yet)
- Verify amount tolerance = 0 (exact-match):
  - json/WP Order Status.v1.json → node `Set Policy` → amount_tol == 0
  - json/WP Verify Slip.v2.ocr.json → node `Set Policy` → amount_tol == 0
  - Confirm SQL still uses BETWEEN want±tol but with tol=0 (effectively equality). Do NOT change unless asked.
- Grep scan:
  - Search for: "amount_tol", "tolerance", "±0.50", "0.5" in json/**/*.json and includes/**/*.php
  - Report any active occurrences (ignore old backup json/).
- HMAC policy:
  - Confirm verification code accepts X-San8n-* and legacy X-PromptPay-* in all active workflows.
- Postgres schema confirmation:
  - amounts are numeric(12,2) in payments.amount and payment_sessions.amount_variant → safe for exact equality.
- WordPress plugin:
  - Confirm there is NO admin amount tolerance setting; plugin defers to n8n result.

# 5) Environment and secrets (report-only)
- List placeholders to be filled (do not write them): 
  - REPLACE_WP_SHARED_SECRET in workflows
  - OCR/AI keys in WP Verify Slip.v2.ocr.json (if OCR is used)
- Confirm these are read from node Set/headers, not hardcoded globally.

# 6) Active TODO setup (create or update a working list)
Create a concise TODO list for this machine:
- Verify exact-match policy status (amount_tol=0): done/not yet
- Confirm HMAC policy on all endpoints: done/not yet
- Run quick tests via cURL (order-status approved/pending, off-by-0.01 rejection, time-window miss): pending
- If any changes are needed, prepare change plan and update docs/CHANGELOG.md accordingly

# 7) Test plan (dry-run)
- Prepare minimal cURL examples to hit:
  - /wp/order-status (HMAC headers set; body with session_token or order_id)
  - /wp/verify-slip-ocr (multipart with slip_base64; only if OCR path is used)
- Ensure tests demonstrate:
  - exact-match success
  - off-by-0.01 fails
  - already-approved cache path
  - time-window miss

# 8) Change management rules (strict)
- Before editing: re-read plan.md and readme.txt.
- After any code edit:
  - Produce a short change document in the reply: what/why/files/tests/next steps.
  - Update docs/CHANGELOG.md and plan.md if applicable.
- Keep edits minimal and reversible. Ask before making schema or contract changes.

# 9) Output now
- Provide:
  - A 10–15 line synopsis of the project and policies.
  - Results of the mandatory first-run checks (scans + file confirmations).
  - A current TODO list for this machine.
  - The ready-to-run cURL templates (with placeholders, no secrets).
Do not make edits unless I explicitly say so after reviewing your report.
```

---

How to use:
- Copy the block above into the chat as the first message whenever you open the IDE on a new machine. It forces the agent to read the repo docs and verify the exact-match policy and HMAC settings before any changes.
