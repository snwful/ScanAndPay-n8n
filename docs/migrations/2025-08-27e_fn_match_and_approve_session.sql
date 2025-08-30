-- 2025-08-27 Atomic match + approve session
-- Creates fn_match_and_approve_session(p_session_token text, p_txn_time timestamptz, p_window_secs integer)
-- Safe to rerun.

-- correct old signature to ensure prior versions are removed
DROP FUNCTION IF EXISTS fn_match_and_approve_session(text, timestamptz, integer);

CREATE OR REPLACE FUNCTION fn_match_and_approve_session(
  p_session_token text,
  p_txn_time timestamptz,
  p_window_secs integer
) RETURNS TABLE(approved boolean, reason text)
LANGUAGE plpgsql AS $$
DECLARE
  v_created_at timestamptz;
  v_status text;
  v_source text;
  v_idem_key text;
  v_now timestamptz := now();
BEGIN
  SELECT created_at, status INTO v_created_at, v_status
  FROM payment_sessions
  WHERE session_token = p_session_token
  FOR UPDATE;

  IF NOT FOUND THEN
    RETURN QUERY SELECT false, 'not_found';
    RETURN;
  END IF;

  IF abs(EXTRACT(EPOCH FROM (p_txn_time - v_created_at))) > COALESCE(p_window_secs, 600) THEN
    RETURN QUERY SELECT false, 'outside_window';
    RETURN;
  END IF;

  v_source := current_setting('san8n.source', true);
  v_idem_key := current_setting('san8n.idem_key', true);
  IF v_source IS NULL THEN
    v_source := 'tasker';
  END IF;
  IF v_idem_key IS NULL THEN
    v_idem_key := md5(p_session_token || ':' || EXTRACT(EPOCH FROM p_txn_time));
  END IF;

  INSERT INTO approvals (source, idempotency_key, session_token, approved_amount, matched_at, last_seen_at)
  VALUES (v_source, v_idem_key, p_session_token, 0, p_txn_time, v_now)
  ON CONFLICT (source, idempotency_key) DO UPDATE
    SET last_seen_at = EXCLUDED.last_seen_at;

  IF v_status <> 'approved' THEN
    UPDATE payment_sessions
      SET status = 'approved'
      WHERE session_token = p_session_token;
    RETURN QUERY SELECT true, NULL;
  ELSE
    RETURN QUERY SELECT false, 'already_approved';
  END IF;
END;
$$;
