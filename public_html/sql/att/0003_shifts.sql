-- ZAS Attendance M1 — shifts and per-employee shift instances.
--
-- Blueprint §10 says shift and break policy must be configurable rather than
-- hard-coded, so grace and break minutes are columns, not constants in PHP.

CREATE TABLE att_shifts (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(100)    NOT NULL,
    start_time         TIME            NOT NULL,
    end_time           TIME            NOT NULL,
    timezone           VARCHAR(64)     NOT NULL DEFAULT 'Asia/Karachi',
    break_minutes      INT UNSIGNED    NOT NULL DEFAULT 0,
    grace_in_minutes   INT UNSIGNED    NOT NULL DEFAULT 0,
    grace_out_minutes  INT UNSIGNED    NOT NULL DEFAULT 0,
    active             TINYINT(1)      NOT NULL DEFAULT 1,
    created_at         DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    -- A night shift is one whose end time is not after its start time. Derived
    -- rather than typed, because a human ticking "crosses midnight" wrongly on
    -- a 19:00–03:00 shift would mis-date every entry cycle on it.
    crosses_midnight   TINYINT(1) GENERATED ALWAYS AS (end_time <= start_time) STORED,

    UNIQUE KEY uniq_att_shift_name (name),
    KEY idx_att_shift_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per employee per working day. starts_at/ends_at are absolute UTC
-- instants, already resolved for midnight-spanning shifts, so no downstream
-- query has to reason about whether 02:00 belongs to yesterday.
CREATE TABLE att_shift_instances (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id   BIGINT UNSIGNED NOT NULL,
    shift_id      BIGINT UNSIGNED NOT NULL,
    work_date     DATE            NOT NULL,
    starts_at     DATETIME(6)     NOT NULL,
    ends_at       DATETIME(6)     NOT NULL,

    -- D4: an employee may reach the gate with no shift assigned. They are let
    -- in and a default shift is attached, recorded here as 'default_auto' so
    -- HR can see exactly which days were filled in by the system rather than
    -- planned by a person.
    source        ENUM('assigned','default_auto') NOT NULL DEFAULT 'assigned',
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    -- The starter schema had no uniqueness here. Without it a double
    -- assignment creates two instances for one day, and an employee ends up
    -- with two parallel streams of entry cycles that no report can add up.
    UNIQUE KEY uniq_att_si_emp_date (employee_id, work_date),
    KEY idx_att_si_emp_start (employee_id, starts_at),
    KEY idx_att_si_window (starts_at, ends_at),
    CONSTRAINT fk_att_si_emp   FOREIGN KEY (employee_id) REFERENCES att_employees (id),
    CONSTRAINT fk_att_si_shift FOREIGN KEY (shift_id)    REFERENCES att_shifts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
