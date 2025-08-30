# Developer Changelog (engineering history)

Entries reference session logs under `docs/sessions/`.

- 2025-08-25 09:51 +07 — Add documentation workflow (PROCESS.md), templates, and first session log scaffold. See `docs/sessions/session-2025-08-25T0951+07.md`.
- 2025-08-25 10:20 +07 — QR Generate: add Postgres upsert for `payment_sessions` after EMV build, route flow through DB node, and enrich 200 response with session fields. Fixed JSON quoting in workflow using dollar-quoted SQL strings.
- 2025-08-25 11:50 +07 — Order Status: fix JSON lint error by replacing escaped JSON string in `Respond Pending/Expired` node with `JSON.stringify({ status })`.
- 2025-08-26 — n8n Tasker Ingest: Early 200 ACK, exact-match 10m session lookup, DB UPSERT idempotency (`approvals` table), duplicate callback suppression, structured logging with correlation_id. No contract changes to existing endpoints.
- 2025-08-27 — Add source/idempotency columns with helper UPSERT for approvals table.
- 2025-08-28 — Tasker ingest v5.4 adds error paths and structured metrics; workflow bumped to ai-openrouter v3.
- 2025-08-28 — Add atomic session match function and consolidate Tasker ingest to single DB call (v5.5 ai-openrouter v4).
- 2025-08-29 — Fix fn_match_and_approve_session to use status instead of non-existent approved_at column.
