-- 1. "REMEMBER THIS PHONE". A gate or HOD phone signed in once gets a key, so
--    it can sign itself back in when the server's sign-in has expired (the
--    app being closed, or eight quiet hours on a night shift) instead of
--    asking for the email and password again. Only a SHA-256 of the key is
--    stored. A key stops working when it expires, when the person's password
--    changes (pw_fp), when they are deactivated or lose the gate/HOD job,
--    when the phone is withdrawn in Setup, and when the phone signs out.
CREATE TABLE att_device_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    device_uuid  CHAR(36)        NOT NULL,
    token_hash   CHAR(64)        NOT NULL,
    pw_fp        CHAR(32)        NOT NULL,
    created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_used_at DATETIME(6)     NULL,
    expires_at   DATETIME(6)     NOT NULL,
    revoked_at   DATETIME(6)     NULL,
    UNIQUE KEY uniq_att_devtok_hash (token_hash),
    KEY idx_att_devtok_dev (device_uuid, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. A REGULAR SHIFT FOR A DEPARTMENT. When someone reaches the gate on a day
--    nobody gave them a shift, their department's regular shift is used
--    before the factory default — so a night-guard department needs no
--    renewing every 92 days. Removing the shift clears it here.
ALTER TABLE att_departments ADD COLUMN regular_shift_id BIGINT UNSIGNED NULL;
ALTER TABLE att_departments ADD CONSTRAINT fk_att_dept_regshift FOREIGN KEY (regular_shift_id) REFERENCES att_shifts (id) ON DELETE SET NULL;
