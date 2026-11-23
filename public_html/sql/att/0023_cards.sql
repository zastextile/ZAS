-- EMPLOYEE CARDS (owner, 23 Nov 2026): a 125 kHz card per employee, linked by
-- tapping it on a reader — never typed, never shown. Only a fingerprint of the
-- card number is kept (lowercase hex SHA-256 of the digits as the reader sends
-- them), so the number itself is in no table, screen, export or log.
--
-- A TABLE OF ITS OWN, not columns on att_employees. Changing a column there
-- would touch updated_at, which the gate and HOD phones read to know what to
-- download again; a card linked here must not make every phone re-sync.
--
-- canteen_ok: may this person buy at the canteen (the canteen asks this app
-- on every tap). Off until someone switches it on.
CREATE TABLE att_emp_cards (
    employee_id BIGINT UNSIGNED NOT NULL,
    card_hash   CHAR(64) NULL,
    linked_at   DATETIME(6) NULL,
    canteen_ok  TINYINT(1) NOT NULL DEFAULT 0,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (employee_id),
    UNIQUE KEY uniq_att_card_hash (card_hash),
    CONSTRAINT fk_att_card_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What happened to each person's card, and who did it: linked, replaced,
-- unlinked, canteen on / off. Fingerprints only, never a number.
CREATE TABLE att_card_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    action      VARCHAR(20) NOT NULL,
    old_hash    CHAR(64) NULL,
    new_hash    CHAR(64) NULL,
    reason      VARCHAR(255) NULL,
    actor_id    BIGINT UNSIGNED NULL,
    created_at  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_att_card_log_emp (employee_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
