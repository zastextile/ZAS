-- Reverses 0015. Entries the system closed stay closed, at the same time,
-- and are marked as HR corrections — the nearest older meaning — so nobody
-- is suddenly "inside" again.
UPDATE att_entry_cycles SET close_source = 'hr_correction' WHERE close_source = 'auto_shift_end';
ALTER TABLE att_entry_cycles
    DROP KEY idx_att_cycle_auto,
    DROP COLUMN auto_closed_at,
    MODIFY COLUMN close_source ENUM('gate','hr_correction') NOT NULL DEFAULT 'gate';
DELETE FROM att_policies WHERE policy_key IN ('auto_out_enabled', 'auto_out_after_minutes', 'auto_out_last_run');
