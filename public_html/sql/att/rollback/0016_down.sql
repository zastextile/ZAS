-- Reverses 0016. Entries an administrator ADDED have no gate scan and cannot
-- exist under the older layout, so they are removed (each is on record in
-- att_corrections); edited and HR-closed entries keep their times.
UPDATE att_hod_arrivals SET entry_cycle_id = NULL, status = 'dismissed'
 WHERE entry_cycle_id IN (SELECT id FROM (SELECT id FROM att_entry_cycles WHERE gate_in_event_id IS NULL) x);
UPDATE att_flags SET entry_cycle_id = NULL
 WHERE entry_cycle_id IN (SELECT id FROM (SELECT id FROM att_entry_cycles WHERE gate_in_event_id IS NULL) x);
DELETE FROM att_entry_cycles WHERE gate_in_event_id IS NULL;
UPDATE att_entry_cycles SET close_source = 'hr_correction' WHERE close_source = 'admin';
ALTER TABLE att_entry_cycles DROP FOREIGN KEY fk_att_cycle_in;
ALTER TABLE att_entry_cycles
    DROP COLUMN edited_by,
    DROP COLUMN edited_at,
    DROP COLUMN in_source,
    MODIFY COLUMN close_source ENUM('gate','hr_correction','auto_shift_end') NOT NULL DEFAULT 'gate',
    MODIFY COLUMN gate_in_event_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE att_entry_cycles
    ADD CONSTRAINT fk_att_cycle_in FOREIGN KEY (gate_in_event_id) REFERENCES att_events (id);
