-- ZAS Attendance M4 — what reconciliation and the HR queue need.
--
-- Everything here alters tables the attendance module itself owns. No ERP
-- table is touched.

-- The original envelope, kept beside the answer. Without it an event held for
-- review (no shift, unreadable time) could only ever be looked at: HR could
-- fix the cause but had nothing to re-run. With it, "assign the shift, then
-- retry" works. review_* records what HR did about a held event.
ALTER TABLE att_sync_receipts
    ADD COLUMN request_json  JSON NULL AFTER response_json,
    ADD COLUMN operator_id   BIGINT UNSIGNED NULL AFTER request_json,
    ADD COLUMN review_state  ENUM('retried','dismissed') NULL AFTER operator_id,
    ADD COLUMN reviewed_by   BIGINT UNSIGNED NULL AFTER review_state,
    ADD COLUMN reviewed_at   DATETIME(6) NULL AFTER reviewed_by,
    ADD COLUMN review_note   TEXT NULL AFTER reviewed_at,
    ADD KEY idx_att_receipt_review (receipt_status, review_state, received_at);

-- A confirmation HR has looked at and set aside is 'dismissed' — distinct from
-- 'disputed', which means two confirmations claim one entry and nobody has
-- decided yet.
ALTER TABLE att_hod_arrivals
    MODIFY COLUMN status ENUM('pending_match','confirmed','ambiguous','disputed','dismissed') NOT NULL DEFAULT 'pending_match',
    ADD COLUMN resolved_by     BIGINT UNSIGNED NULL,
    ADD COLUMN resolved_at     DATETIME(6) NULL,
    ADD COLUMN resolution_note TEXT NULL;

-- An employee who leaves without scanning OUT stays "inside" for ever, and
-- the next morning's IN is refused as ALREADY_INSIDE. HR closes the cycle by
-- correction. close_source says so permanently: a report can always tell a
-- gate-recorded exit from one a person typed in afterwards.
ALTER TABLE att_entry_cycles
    ADD COLUMN close_source  ENUM('gate','hr_correction') NOT NULL DEFAULT 'gate',
    ADD COLUMN correction_id BIGINT UNSIGNED NULL;

ALTER TABLE att_devices
    ADD COLUMN registered_by BIGINT UNSIGNED NULL,
    ADD COLUMN revoked_by    BIGINT UNSIGNED NULL,
    ADD COLUMN revoke_reason VARCHAR(255) NULL;

-- Blueprint §5: "Corrections append audit entries, not destructive edits."
-- One row per HR decision, with a mandatory reason. Append-only.
CREATE TABLE att_corrections (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject_type VARCHAR(60)     NOT NULL,
    subject_id   BIGINT UNSIGNED NOT NULL,
    action       VARCHAR(60)     NOT NULL,
    old_data     JSON            NULL,
    new_data     JSON            NULL,
    reason       TEXT            NOT NULL,
    actor_id     BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_corr_subject (subject_type, subject_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('device_self_register',   '1',   'bool', 'A signed-in gatekeeper/HOD may register the phone they are using. Admin can revoke any device at any time.'),
  ('stale_open_cycle_hours', '16',  'int',  'An entry still open this long after it began is listed for HR as a likely missed exit scan.'),
  ('sync_batch_max',         '200', 'int',  'Most events accepted in one /sync/events request.');
