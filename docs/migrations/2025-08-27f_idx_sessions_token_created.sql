-- 2025-08-27 Optional index for session lookups
CREATE INDEX IF NOT EXISTS ix_sessions_token_created
  ON payment_sessions (session_token, created_at);
