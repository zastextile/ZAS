-- ZAS People Finance — pay for three kinds of employee, one pipeline.
--
--   fixed salary   prepared at month end (or custom dates: salary / days in month x days)
--   daily wages    prepared weekly: days present (from attendance) x daily rate
--   contractor     owed as soon as production books it; nothing to "generate"
--
-- Every payment goes PREPARE -> APPROVE -> PAY on a pay sheet. Each person on a
-- sheet has LINES (basic, allowance, overtime, leave, advance recovery, bonus,
-- deduction ...). A line keeps the figure the system worked out beside the
-- figure a person changed it to, with the reason, so an amendment is always
-- readable afterwards.
--
-- THE LEDGER IS THE ONE BALANCE. Earnings and deductions are posted when a
-- sheet is approved, the payment when it is paid. Nothing is ever deleted: a
-- wrong line is cancelled with a reason and stays visible.
--
-- Money is whole paisa in BIGINT. No floats, so no rounding drift.
ALTER TABLE att_user_roles
    MODIFY COLUMN role ENUM('gatekeeper','hod','hr','accounts','admin','audit') NOT NULL;

CREATE TABLE att_fin_profiles (
    employee_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    pay_type         ENUM('salary','daily','contract') NOT NULL,
    basic_paisa      BIGINT NOT NULL DEFAULT 0,      -- per month, fixed salary
    allowance_paisa  BIGINT NOT NULL DEFAULT 0,      -- per month, fixed salary
    daily_rate_paisa BIGINT NOT NULL DEFAULT 0,      -- per day present, daily wages
    ot_rate_paisa    BIGINT NOT NULL DEFAULT 0,      -- per hour; used only where the overtime payroll has no typed rate
    pay_method       ENUM('bank','cash') NOT NULL DEFAULT 'cash',
    bank_account     VARCHAR(60) NULL,
    updated_by       BIGINT UNSIGNED NULL,
    updated_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_att_fin_prof_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A raise must not rewrite the past: every change of pay is kept.
CREATE TABLE att_fin_profile_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    old_data    JSON NULL,
    new_data    JSON NOT NULL,
    reason      VARCHAR(255) NULL,
    actor_id    BIGINT UNSIGNED NULL,
    created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_fin_ph_emp (employee_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Money the employee owes back. Not wages: it never adds to what they are owed.
CREATE TABLE att_fin_advances (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id     BIGINT UNSIGNED NOT NULL,
    kind            ENUM('advance','loan') NOT NULL DEFAULT 'advance',
    amount_paisa    BIGINT NOT NULL,
    per_pay_paisa   BIGINT NOT NULL,                 -- the plan: recovered from each pay sheet
    recovered_paisa BIGINT NOT NULL DEFAULT 0,
    given_on        DATE NOT NULL,
    method          ENUM('bank','cash') NOT NULL DEFAULT 'cash',
    note            VARCHAR(255) NULL,
    status          ENUM('active','closed','cancelled') NOT NULL DEFAULT 'active',
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    cancelled_by    BIGINT UNSIGNED NULL,
    cancelled_at    DATETIME(6) NULL,
    cancel_reason   VARCHAR(255) NULL,
    KEY idx_att_fin_adv_emp (employee_id, status),
    CONSTRAINT fk_att_fin_adv_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_fin_sheets (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ref            VARCHAR(20) NOT NULL,
    kind           ENUM('salary','daily','contract') NOT NULL,
    period_from    DATE NOT NULL,
    period_to      DATE NOT NULL,
    status         ENUM('waiting','approved','paid','returned','cancelled') NOT NULL DEFAULT 'waiting',
    prepared_by    BIGINT UNSIGNED NOT NULL,
    prepared_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    approved_by    BIGINT UNSIGNED NULL,
    approved_at    DATETIME(6) NULL,
    paid_by        BIGINT UNSIGNED NULL,
    paid_at        DATETIME(6) NULL,
    pay_date       DATE NULL,
    off_day_reason VARCHAR(255) NULL,                -- why it was paid on a day that is not the pay day
    returned_by    BIGINT UNSIGNED NULL,           -- also who cancelled it, when status is 'cancelled'
    returned_at    DATETIME(6) NULL,
    return_reason  VARCHAR(255) NULL,
    UNIQUE KEY uniq_att_fin_sheet_ref (ref),
    KEY idx_att_fin_sheet_status (status, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_fin_sheet_items (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sheet_id     BIGINT UNSIGNED NOT NULL,
    employee_id  BIGINT UNSIGNED NOT NULL,
    calc_paisa   BIGINT NOT NULL,                    -- what the system worked out, net
    amount_paisa BIGINT NOT NULL,                    -- what will be paid, net, after amendments
    method       ENUM('bank','cash') NOT NULL DEFAULT 'cash',
    UNIQUE KEY uniq_att_fin_item (sheet_id, employee_id),
    KEY idx_att_fin_item_emp (employee_id),
    CONSTRAINT fk_att_fin_item_sheet FOREIGN KEY (sheet_id) REFERENCES att_fin_sheets (id),
    CONSTRAINT fk_att_fin_item_emp   FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- side: 'earn' adds, 'deduct' takes away, 'carry' is money already owed
-- from before the sheet (a contractor's production, an unpaid balance) that
-- this sheet pays out — it is paid, but not posted again as an earning.
CREATE TABLE att_fin_item_lines (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id      BIGINT UNSIGNED NOT NULL,
    line_key     VARCHAR(30) NOT NULL,               -- basic, allowance, daily, overtime, leave, carry, adv:<id>, add:<n>
    kind         VARCHAR(20) NOT NULL,
    side         ENUM('earn','deduct','carry') NOT NULL,
    label        VARCHAR(160) NOT NULL,
    detail       VARCHAR(255) NULL,
    calc_paisa   BIGINT NOT NULL DEFAULT 0,
    amount_paisa BIGINT NOT NULL,
    reason       VARCHAR(255) NULL,
    advance_id   BIGINT UNSIGNED NULL,
    amended_by   BIGINT UNSIGNED NULL,
    sort         INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_att_fin_line (item_id, line_key),
    CONSTRAINT fk_att_fin_line_item FOREIGN KEY (item_id) REFERENCES att_fin_sheet_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- amount_paisa is always positive; kind gives the direction:
-- opening and earning add to what the person is owed, deduction and payment
-- take away. source_key makes the copy from production repeatable.
CREATE TABLE att_fin_ledger (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id   BIGINT UNSIGNED NOT NULL,
    entry_date    DATE NOT NULL,
    kind          ENUM('opening','earning','deduction','payment') NOT NULL,
    line_kind     VARCHAR(20) NULL,
    label         VARCHAR(200) NOT NULL,
    amount_paisa  BIGINT NOT NULL,
    method        ENUM('bank','cash') NULL,
    sheet_id      BIGINT UNSIGNED NULL,
    advance_id    BIGINT UNSIGNED NULL,
    source        VARCHAR(20) NOT NULL DEFAULT 'sheet',
    source_key    VARCHAR(60) NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    cancelled_at  DATETIME(6) NULL,
    cancelled_by  BIGINT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    UNIQUE KEY uniq_att_fin_ledger_source (source_key),
    KEY idx_att_fin_ledger_emp (employee_id, entry_date),
    KEY idx_att_fin_ledger_sheet (sheet_id),
    CONSTRAINT fk_att_fin_ledger_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('fin_pay_weekday',      '4',   'int', 'Usual pay day: 1 Monday ... 7 Sunday. Another day needs a reason.'),
  ('fin_half_day_minutes', '240', 'int', 'Daily wages: present for less than this many minutes counts as half a day.');
