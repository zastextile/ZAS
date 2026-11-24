-- CANTEEN CHARGES ON PAY (owner, 24 Nov 2026).
--
-- The canteen app (its own database, its own Cloudways application) offers
-- four private addresses: charges, reserve, settle, release. Accounts press
-- "Get canteen charges" on Prepare pay; what the canteen says is owed is kept
-- here as ZAS's own copy, one row per canteen order or refund, and becomes ONE
-- "Canteen …" deduction line per person on the pay sheet.
--
-- Two states per row, kept apart on purpose:
--   zas_state      where the row is on OUR side
--                    free      waiting for a pay sheet
--                    on_sheet  on a sheet that waits for approval
--                    charged   on an approved sheet: in the ledger
--                    gone      the canteen no longer lists it (cancelled)
--   canteen_state  what the CANTEEN holds for it
--                    unsettled / reserved (on canteen_ref) / settled
-- A row reaches a pay sheet only when it is free and the canteen side is
-- known (unsettled, or settled but not charged here: owed again).
--
-- att_canteen_calls holds settle / release messages until the canteen has
-- answered, so a dropped connection never loses one; each is safe to repeat.
CREATE TABLE att_canteen_rows (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind          ENUM('order','refund') NOT NULL,
    ext_id        BIGINT UNSIGNED NOT NULL,           -- the canteen's order_id / refund_id
    employee_id   BIGINT UNSIGNED NOT NULL,
    at            DATETIME NOT NULL,                  -- ordered_at / created_at, Pakistan time, as the canteen sent it
    week_from     DATE NULL,
    amount_paisa  BIGINT NOT NULL,
    items         TEXT NULL,                          -- JSON [{name, qty, amount}] of an order
    order_ext_id  BIGINT UNSIGNED NULL,               -- the order a refund belongs to
    note          VARCHAR(255) NULL,                  -- a refund's reason
    zas_state     ENUM('free','on_sheet','charged','gone') NOT NULL DEFAULT 'free',
    sheet_id      BIGINT UNSIGNED NULL,
    canteen_state ENUM('unsettled','reserved','settled') NOT NULL DEFAULT 'unsettled',
    canteen_ref   VARCHAR(40) NULL,
    fetched_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_att_canteen_row (kind, ext_id),
    KEY idx_att_canteen_emp (employee_id, zas_state),
    KEY idx_att_canteen_sheet (sheet_id),
    KEY idx_att_canteen_ref (canteen_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE att_canteen_calls (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action     ENUM('settle','release') NOT NULL,
    sheet_ref  VARCHAR(40) NOT NULL,
    sheet_id   BIGINT UNSIGNED NULL,
    status     ENUM('pending','done') NOT NULL DEFAULT 'pending',
    attempts   INT NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    answer     VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    done_at    DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY idx_att_canteen_call_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each person's weekly canteen limit, sent to the canteen with every card
-- check. On the card table, not att_employees, so the phones do not re-sync.
ALTER TABLE att_emp_cards ADD COLUMN weekly_limit_paisa BIGINT NOT NULL DEFAULT 100000;
