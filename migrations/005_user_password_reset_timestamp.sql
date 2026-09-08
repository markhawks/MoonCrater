ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_password_reset_at TIMESTAMP NULL;
