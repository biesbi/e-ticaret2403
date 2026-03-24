ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS email_verification_expires_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL;

UPDATE users
SET email_verified = CASE WHEN email_verified_at IS NULL THEN 0 ELSE 1 END
WHERE email_verified IS NULL OR email_verified NOT IN (0, 1);
