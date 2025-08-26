-- Approvals dedupe table for idempotent webhook/callback processing
-- Creates a single-row record per session_token. Use ON CONFLICT DO NOTHING to gate duplicates.
-- Usage pattern (n8n): INSERT ... ON CONFLICT DO NOTHING RETURNING session_token; only proceed to WP callback if a row is returned.

BEGIN;

CREATE TABLE IF NOT EXISTS approvals (
  session_token    text PRIMARY KEY,
  approved_amount  numeric(12,2) NOT NULL,
  matched_at       timestamptz    NOT NULL,
  ref_code         text,
  message_id       text,
  created_at       timestamptz    NOT NULL DEFAULT now()
);

-- Prevent duplicate use of the same payment message_id across sessions (optional but recommended)
CREATE UNIQUE INDEX IF NOT EXISTS approvals_message_id_uq
  ON approvals (message_id)
  WHERE message_id IS NOT NULL;

-- Helpful index for time-based housekeeping/analytics
CREATE INDEX IF NOT EXISTS approvals_created_at_idx
  ON approvals (created_at);

COMMIT;

-- Example UPSERT (n8n Postgres node → Execute Query):
-- INSERT INTO approvals (session_token, approved_amount, matched_at, ref_code, message_id)
-- VALUES ('{{$json.session_token}}', {{$json.amount}}::numeric, to_timestamp({{$json.matched_at}}), {{$json.ref_code||null}}, {{$json.message_id||null}})
-- ON CONFLICT (session_token) DO NOTHING
-- RETURNING session_token;
-- If no row is returned → duplicate; stop the WP callback path.
