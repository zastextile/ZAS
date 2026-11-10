-- ZAS Attendance M1 — attendance roles and registered devices.
--
-- D3: the live `users.role` enum is NOT widened. It gates 56 existing write
-- screens in the ERP, and adding 'gatekeeper' to it would put a value in a
-- column that a lot of old code compares by hand. Attendance roles live here
-- instead, and authorisation rides the zu_perm module system the app already
-- has.

-- NOTE ON user_id: it references users.id logically, but carries no foreign
-- key. users.id is a signed INT in this database and every attendance key is
-- BIGINT UNSIGNED; MySQL requires an exact type match for an FK, so declaring
-- one would mean either narrowing the attendance keys or widening a column
-- that 56 live ERP screens join against. The reference is enforced in
-- Att\Roles instead, and a role row for a deleted user is harmless — it grants
-- nothing, because every check starts from a live session.
CREATE TABLE att_user_roles (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    role          ENUM('gatekeeper','hod','hr','accounts','admin') NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    created_at    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    -- A HOD is scoped to a department; HR/Accounts/Admin usually are not, and
    -- carry NULL. MySQL lets duplicate rows coexist when part of a unique key
    -- is NULL, so "HOD of nothing, twice" would be allowed. The generated
    -- column folds NULL to 0 and makes the uniqueness real.
    dept_key      BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(department_id, 0)) STORED,
    UNIQUE KEY uniq_att_role (user_id, role, dept_key),
    KEY idx_att_role_user (user_id),
    KEY idx_att_role_dept (department_id),
    CONSTRAINT fk_att_role_dept FOREIGN KEY (department_id) REFERENCES att_departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gate and HOD handsets. Blueprint §6: registered devices, device PIN, remote
-- revoke. revoked_at is checked on every request, not only at login, so taking
-- a lost handset out of service does not wait for its session to expire.
CREATE TABLE att_devices (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    device_uuid  CHAR(36)        NOT NULL,
    label        VARCHAR(100)    NULL,
    role_scope   ENUM('gate','hod','both') NOT NULL DEFAULT 'gate',
    secret_hash  VARCHAR(255)    NULL,
    pin_hash     VARCHAR(255)    NULL,
    app_version  VARCHAR(40)     NULL,
    last_seen_at DATETIME(6)     NULL,
    revoked_at   DATETIME(6)     NULL,
    created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_att_device_uuid (device_uuid),
    KEY idx_att_device_live (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
