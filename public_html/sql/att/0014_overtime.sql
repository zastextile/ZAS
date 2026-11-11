-- ZAS Attendance M6 — overtime: request, decision, next-day verification,
-- rate, payroll.
--
-- CLAUDE.md rule 7: "Overtime is never inferred from late checkout." Nothing
-- in this file is joined to att_events or att_entry_cycles. A gate record can
-- be LOOKED AT while verifying (Att\OvertimeService::verification), but no
-- column here is ever filled from one; every minute below was typed by a
-- person whose name is beside it.
--
-- The tables mirror schema.sql's overtime_requests, overtime_request_employees
-- and overtime_payroll (with the att_ prefix, D1), plus the history the spec
-- asks for in words: "later changes require audited amendment", "payroll is
-- locked after finalization except audited correction".

CREATE TABLE att_ot_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Made by the HOD's screen. Unique so a request sent twice (a retry after
    -- a lost answer) is one request.
    request_uuid    CHAR(36)        NOT NULL,
    department_id   BIGINT UNSIGNED NOT NULL,
    requested_by    BIGINT UNSIGNED NOT NULL,
    work_date       DATE            NOT NULL,
    reason          TEXT            NOT NULL,
    planned_start   TIME            NULL,
    planned_end     TIME            NULL,
    created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_att_ot_req_uuid (request_uuid),
    KEY idx_att_ot_req_date (work_date, department_id),
    CONSTRAINT fk_att_ot_req_dept FOREIGN KEY (department_id) REFERENCES att_departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_ot_request_employees (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id          BIGINT UNSIGNED NOT NULL,
    employee_id         BIGINT UNSIGNED NOT NULL,
    -- Copied from the request so the rule below can be a key.
    work_date           DATE            NOT NULL,
    estimated_minutes   INT UNSIGNED    NOT NULL,

    -- The decision. First committed decision wins: the UPDATE that records it
    -- only matches a row still 'pending', inside a transaction holding the
    -- row lock (AT09).
    approval_status     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_minutes    INT UNSIGNED    NULL,     -- set to the estimate on approval; changed only by amendment
    decided_by          BIGINT UNSIGNED NULL,
    decided_at          DATETIME(6)     NULL,
    decision_note       TEXT            NULL,

    -- The next-day check.
    verification_status ENUM('pending','verified','disputed') NOT NULL DEFAULT 'pending',
    actual_minutes      INT UNSIGNED    NULL,
    verified_by         BIGINT UNSIGNED NULL,
    verified_at         DATETIME(6)     NULL,
    verification_note   TEXT            NULL,
    evidence_refs       JSON            NULL,

    -- AT MOST ONE LIVE LINE PER EMPLOYEE PER WORK DATE. NULL once rejected,
    -- and MySQL lets NULLs repeat, so a rejected line does not block a new
    -- request; two pending or approved ones cannot coexist. Without this the
    -- same evening could be requested by two HODs, approved twice and paid
    -- twice. More time on the same evening is an amendment, not a second line.
    live_employee_id    BIGINT UNSIGNED GENERATED ALWAYS AS
                        (CASE WHEN approval_status <> 'rejected' THEN employee_id ELSE NULL END) STORED,

    UNIQUE KEY uniq_att_ot_line (request_id, employee_id),
    UNIQUE KEY uniq_att_ot_live_day (live_employee_id, work_date),
    KEY idx_att_ot_line_emp (employee_id, work_date),
    KEY idx_att_ot_line_state (approval_status, verification_status, work_date),
    CONSTRAINT fk_att_ot_line_req FOREIGN KEY (request_id)  REFERENCES att_ot_requests (id),
    CONSTRAINT fk_att_ot_line_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A decision request, answered once. Replaying the same decision_uuid returns
-- the stored answer and changes nothing — the officer's screen can retry a
-- lost response without deciding twice.
CREATE TABLE att_ot_decision_batches (
    decision_uuid   CHAR(36)        NOT NULL PRIMARY KEY,
    request_id      BIGINT UNSIGNED NOT NULL,
    actor_id        BIGINT UNSIGNED NOT NULL,
    response_json   JSON            NULL,
    created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_ot_batch_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that ever happened to a line, appended, never edited: the
-- decision, each amendment, the verification, a dispute. The line holds the
-- current state; this holds how it got there and who said so.
CREATE TABLE att_ot_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_id         BIGINT UNSIGNED NOT NULL,
    kind            ENUM('request','decision','amendment','verification','dispute','rate','finalize','correction') NOT NULL,
    field           VARCHAR(40)     NULL,
    old_value       JSON            NULL,
    new_value       JSON            NULL,
    remarks         TEXT            NULL,
    actor_id        BIGINT UNSIGNED NULL,
    created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_ot_hist_line (line_id, id),
    CONSTRAINT fk_att_ot_hist_line FOREIGN KEY (line_id) REFERENCES att_ot_request_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_ot_payroll_runs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_uuid        CHAR(36)        NOT NULL,
    period_from     DATE            NOT NULL,
    period_to       DATE            NOT NULL,
    line_count      INT UNSIGNED    NOT NULL,
    total_amount    DECIMAL(16,2)   NOT NULL,
    note            VARCHAR(500)    NULL,
    finalized_by    BIGINT UNSIGNED NOT NULL,
    finalized_at    DATETIME(6)     NOT NULL,
    UNIQUE KEY uniq_att_ot_run_uuid (run_uuid),
    KEY idx_att_ot_run_period (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_ot_payroll (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_employee_id BIGINT UNSIGNED NOT NULL,
    -- 'none' only for a verified line with nothing to pay (0 minutes), which
    -- still has to be locked by the run so it cannot be amended afterwards.
    rate_type           ENUM('hourly','fixed','none') NOT NULL,
    -- ENTERED BY A PERSON. There is no wage column anywhere in this module
    -- for a rate to be worked out from (spec: "never automatically derive
    -- overtime rate from daily wage").
    manual_rate         DECIMAL(14,2)   NOT NULL,
    payable_minutes     INT UNSIGNED    NOT NULL,
    payable_amount      DECIMAL(14,2)   NOT NULL,
    status              ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    rated_by            BIGINT UNSIGNED NULL,
    rated_at            DATETIME(6)     NULL,
    run_id              BIGINT UNSIGNED NULL,
    finalized_by        BIGINT UNSIGNED NULL,
    finalized_at        DATETIME(6)     NULL,
    -- After an audited correction: what is now owed. payable_* above stay as
    -- they were finalized; the corrections table says how it moved.
    effective_minutes   INT UNSIGNED    NULL,
    effective_amount    DECIMAL(14,2)   NULL,
    UNIQUE KEY uniq_att_ot_pay_line (request_employee_id),
    KEY idx_att_ot_pay_run (run_id),
    CONSTRAINT fk_att_ot_pay_line FOREIGN KEY (request_employee_id) REFERENCES att_ot_request_employees (id),
    CONSTRAINT fk_att_ot_pay_run  FOREIGN KEY (run_id) REFERENCES att_ot_payroll_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_ot_payroll_corrections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payroll_id      BIGINT UNSIGNED NOT NULL,
    old_minutes     INT UNSIGNED    NOT NULL,
    new_minutes     INT UNSIGNED    NOT NULL,
    old_rate_type   VARCHAR(10)     NOT NULL,
    new_rate_type   VARCHAR(10)     NOT NULL,
    old_rate        DECIMAL(14,2)   NOT NULL,
    new_rate        DECIMAL(14,2)   NOT NULL,
    old_amount      DECIMAL(14,2)   NOT NULL,
    new_amount      DECIMAL(14,2)   NOT NULL,
    delta_amount    DECIMAL(14,2)   NOT NULL,
    reason          TEXT            NOT NULL,
    actor_id        BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_ot_corr_pay (payroll_id, id),
    CONSTRAINT fk_att_ot_corr_pay FOREIGN KEY (payroll_id) REFERENCES att_ot_payroll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blueprint §10: overtime approval time windows and who may enter next-day
-- hours are for IT to confirm. Defaults that are safe on day one; all on the
-- Setup › Settings tab.
INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('ot_request_days_ahead',     '14',  'int',  'How many days ahead a HOD may request overtime.'),
  ('ot_request_days_back',      '1',   'int',  'How many days back a HOD may still request overtime (0 = today and later only).'),
  ('ot_max_minutes',            '720', 'int',  'Most overtime minutes one employee can be requested or approved for in one day.'),
  ('ot_decision_window_days',   '0',   'int',  'Days after the work date that a request can still be approved or rejected (0 = no limit).'),
  ('ot_verify_from_next_day',   '1',   'bool', 'Actual minutes can be entered only from the day after the work date (blueprint §4: "on next day").');
