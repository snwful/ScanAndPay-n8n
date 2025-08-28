-- 2025-08-27 Backfill source/idempotency_key for approvals
-- Safe, idempotent backfill for legacy rows.

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns WHERE table_name='approvals' AND column_name='source'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns WHERE table_name='approvals' AND column_name='idempotency_key'
  ) THEN
    UPDATE approvals
    SET
      source = COALESCE(source, 'legacy'),
      idempotency_key = COALESCE(
        idempotency_key,
        COALESCE(message_id,
          md5(session_token || '|' || COALESCE(ref_code,'') || '|' || approved_amount::text || '|' || EXTRACT(EPOCH FROM matched_at)::text)
        )
      ),
      last_seen_at = created_at
    WHERE source IS NULL OR idempotency_key IS NULL;
  END IF;
END$$;
