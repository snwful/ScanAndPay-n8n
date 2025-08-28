-- 2025-08-27 Helper function for approvals UPSERT
-- Optional convenience wrapper.

CREATE OR REPLACE FUNCTION fn_upsert_approval(
  p_source text,
  p_idem_key text,
  p_session_token text
) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO approvals (source, idempotency_key, session_token, approved_amount, matched_at, last_seen_at)
  VALUES (p_source, p_idem_key, p_session_token, 0, now(), now())
  ON CONFLICT (source, idempotency_key) DO UPDATE
    SET last_seen_at = EXCLUDED.last_seen_at;
END;
$$;
