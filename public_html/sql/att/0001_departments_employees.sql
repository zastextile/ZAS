-- ZAS Attendance M1 — department and employee masters.
--
-- Prefixed att_ because `departments` and `employees` are generic names in a
-- database that already holds 128 tables, and because inv_departments already
-- means "a department for inventory purposes". Keeping attendance scoping
-- separate means an edit made for inventory cannot silently change who a HOD
-- is allowed to confirm.
--
-- All timestamps are UTC. The application converts for display; nothing here
-- stores local time.

CREATE TABLE att_departments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)    NOT NULL,
    active      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_att_dept_name (name),
    KEY idx_att_dept_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The attendance roll. Wider than zp_workers on purpose: zp_workers holds the
-- people on the machines, and an office clerk who walks through the same gate
-- every morning is not on it. worker_code is the optional bridge to that
-- table, so production and attendance can agree on one person without either
-- owning the other.
CREATE TABLE att_employees (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                 VARCHAR(32)     NOT NULL,
    full_name            VARCHAR(160)    NOT NULL,
    department_id        BIGINT UNSIGNED NOT NULL,
    worker_code          VARCHAR(20)     NULL,
    reference_photo_key  VARCHAR(500)    NULL,
    qr_payload           VARCHAR(190)    NULL,
    -- Blueprint §6 asks for an employee notice before any image is held.
    -- Recorded per person so it can be shown, and proved, one by one.
    notice_ack_at        DATETIME(6)     NULL,
    active               TINYINT(1)      NOT NULL DEFAULT 1,
    created_at           DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at           DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_att_emp_code (code),
    UNIQUE KEY uniq_att_emp_qr (qr_payload),
    KEY idx_att_emp_dept_active (department_id, active),
    KEY idx_att_emp_name (full_name),
    KEY idx_att_emp_worker (worker_code),
    CONSTRAINT fk_att_emp_dept FOREIGN KEY (department_id) REFERENCES att_departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
