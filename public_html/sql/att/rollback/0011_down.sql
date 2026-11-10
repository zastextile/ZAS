-- Reverses 0011.
DELETE FROM att_policies WHERE policy_key IN ('device_self_register','stale_open_cycle_hours','sync_batch_max');
DROP TABLE IF EXISTS att_corrections;
ALTER TABLE att_devices DROP COLUMN registered_by, DROP COLUMN revoked_by, DROP COLUMN revoke_reason;
ALTER TABLE att_entry_cycles DROP COLUMN close_source, DROP COLUMN correction_id;
UPDATE att_hod_arrivals SET status = 'disputed' WHERE status = 'dismissed';
ALTER TABLE att_hod_arrivals
    DROP COLUMN resolved_by, DROP COLUMN resolved_at, DROP COLUMN resolution_note,
    MODIFY COLUMN status ENUM('pending_match','confirmed','ambiguous','disputed') NOT NULL DEFAULT 'pending_match';
ALTER TABLE att_sync_receipts
    DROP KEY idx_att_receipt_review,
    DROP COLUMN request_json, DROP COLUMN operator_id, DROP COLUMN review_state,
    DROP COLUMN reviewed_by, DROP COLUMN reviewed_at, DROP COLUMN review_note;
