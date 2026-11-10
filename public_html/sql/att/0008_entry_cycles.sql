-- ZAS Attendance M2 — entry cycles.
--
-- Invariant 1: "Each Gate IN opens exactly one entry cycle; every cycle
-- requires its own HOD confirmation, including all returns."
-- Invariant 2: "Multiple IN/OUT cycles within a shift are allowed; no double
-- IN or OUT without IN."
--
-- THE ONE-OPEN-CYCLE RULE IS KEPT BY THE DATABASE.
-- open_employee_id is the employee while the cycle is open and NULL once it
-- closes; a UNIQUE key on it therefore means "an employee can be inside at
-- most once", and NULLs do not collide so any number of closed cycles coexist.
--
-- This is not decoration. Two gate handsets scanning the same person at the
-- same instant, or one handset replaying a queue while another is live, both
-- reach the INSERT together. A PHP check — "is there an open cycle? no? then
-- insert" — passes on both connections before either commits, and the factory
-- gets an employee who is inside twice and whose hours cannot be computed.
-- Here the second INSERT fails with 1062 and is answered REJECTED, which is
-- the correct outcome and needs no lock held across the application.
--
-- Verified on MySQL 8.0.46 before any of M2 was written: a second open cycle
-- is refused, a re-entry after closing is allowed, and three IN/OUT pairs
-- leave three cycles with exactly one open (AT01).

CREATE TABLE att_entry_cycles (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id        BIGINT UNSIGNED NOT NULL,
    shift_instance_id  BIGINT UNSIGNED NOT NULL,
    entry_sequence     INT UNSIGNED    NOT NULL,
    gate_in_event_id   BIGINT UNSIGNED NOT NULL,
    gate_out_event_id  BIGINT UNSIGNED NULL,
    opened_at          DATETIME(6)     NOT NULL,
    closed_at          DATETIME(6)     NULL,

    -- Minutes inside, filled in when the cycle closes. Blueprint §5 counts
    -- factory presence from CLOSED intervals only, so an open cycle
    -- contributes nothing and cannot inflate anyone's hours.
    present_minutes    INT UNSIGNED    NULL,

    created_at         DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    open_employee_id   BIGINT UNSIGNED GENERATED ALWAYS AS
                       (CASE WHEN closed_at IS NULL THEN employee_id ELSE NULL END) STORED,

    UNIQUE KEY uniq_att_open_employee (open_employee_id),
    UNIQUE KEY uniq_att_cycle_in  (gate_in_event_id),
    UNIQUE KEY uniq_att_cycle_out (gate_out_event_id),
    UNIQUE KEY uniq_att_shift_sequence (shift_instance_id, entry_sequence),
    KEY idx_att_cycle_emp_open (employee_id, opened_at),
    CONSTRAINT fk_att_cycle_emp   FOREIGN KEY (employee_id)       REFERENCES att_employees (id),
    CONSTRAINT fk_att_cycle_si    FOREIGN KEY (shift_instance_id) REFERENCES att_shift_instances (id),
    CONSTRAINT fk_att_cycle_in    FOREIGN KEY (gate_in_event_id)  REFERENCES att_events (id),
    CONSTRAINT fk_att_cycle_out   FOREIGN KEY (gate_out_event_id) REFERENCES att_events (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
