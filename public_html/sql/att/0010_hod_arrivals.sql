-- ZAS Attendance M3 — HOD arrival confirmations.
--
-- Invariant 1: every entry cycle requires its own HOD confirmation, including
-- every return. Blueprint §5: "Link each HOD confirmation to exactly one
-- entry cycle" and "Never treat HOD arrival as second payroll IN" — which is
-- why this is its own table and not a second row in att_events. Presence
-- minutes are computed from gate events only; nothing here feeds payroll time.
--
-- ONE CONFIRMATION PER CYCLE IS KEPT BY THE DATABASE.
-- UNIQUE(entry_cycle_id): two HODs tapping the same cycle at once, or a bulk
-- confirm racing a single one, cannot both land. NULLs do not collide, so any
-- number of not-yet-matched confirmations (a HOD whose phone synced before
-- the gate's did) can wait here with entry_cycle_id NULL.
--
-- NOTE ON hod_user_id: references users.id logically, no foreign key, for the
-- same reason as att_user_roles.user_id (see 0002) — users.id is a signed INT.

CREATE TABLE att_hod_arrivals (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_uuid       CHAR(36)        NOT NULL,
    employee_id      BIGINT UNSIGNED NOT NULL,
    entry_cycle_id   BIGINT UNSIGNED NULL,
    hod_user_id      BIGINT UNSIGNED NOT NULL,
    department_id    BIGINT UNSIGNED NOT NULL,
    device_id        BIGINT UNSIGNED NULL,
    device_sequence  BIGINT UNSIGNED NULL,

    -- When the HOD saw the employee arrive. Distinct from the gate IN time by
    -- design (blueprint §2) and never substituted for it.
    occurred_at      DATETIME(6)     NOT NULL,
    device_time      DATETIME(6)     NULL,
    clock_skew_ms    INT             NULL,
    received_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    status           ENUM('pending_match','confirmed','ambiguous','disputed') NOT NULL DEFAULT 'pending_match',

    -- How the cycle was identified, kept as evidence. 'sequence' means the
    -- server found exactly one candidate; an ambiguous case is never given a
    -- method because it is never matched by the system (AT05).
    match_method     ENUM('explicit_cycle','gate_event_uuid','sequence','hr_manual') NULL,
    matched_at       DATETIME(6)     NULL,
    gate_event_uuid  CHAR(36)        NULL,
    ambiguity_reason VARCHAR(255)    NULL,

    source           ENUM('online','offline_replay') NOT NULL DEFAULT 'online',
    provisional      TINYINT(1)      NOT NULL DEFAULT 0,

    UNIQUE KEY uniq_att_hod_uuid (event_uuid),
    UNIQUE KEY uniq_att_hod_cycle (entry_cycle_id),
    UNIQUE KEY uniq_att_hod_device_seq (device_id, device_sequence),
    KEY idx_att_hod_status (status, received_at),
    KEY idx_att_hod_emp_time (employee_id, occurred_at),
    KEY idx_att_hod_dept (department_id, occurred_at),
    CONSTRAINT fk_att_hod_emp    FOREIGN KEY (employee_id)    REFERENCES att_employees (id),
    CONSTRAINT fk_att_hod_cycle  FOREIGN KEY (entry_cycle_id) REFERENCES att_entry_cycles (id),
    CONSTRAINT fk_att_hod_dept   FOREIGN KEY (department_id)  REFERENCES att_departments (id),
    CONSTRAINT fk_att_hod_device FOREIGN KEY (device_id)      REFERENCES att_devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
