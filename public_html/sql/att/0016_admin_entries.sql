-- ZAS Attendance — the administrator may add, edit and delete entries
-- (owner's decision, 12 Nov 2026).
--
-- ADD: an entry for someone who was never scanned has no gate event behind
-- it, so gate_in_event_id may now be empty; in_source says who made it.
-- EDIT: the entry's times change; the gate scans keep the times they were
-- scanned at (att_events is never edited), and the report shows both.
-- DELETE: the entry is removed from attendance and every report; a full
-- copy, with the reason and the administrator, is kept in att_corrections,
-- and its scans stay on record marked as deleted (DELETED_BY_ADMIN).
ALTER TABLE att_entry_cycles DROP FOREIGN KEY fk_att_cycle_in;
ALTER TABLE att_entry_cycles
    MODIFY COLUMN gate_in_event_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN close_source ENUM('gate','hr_correction','auto_shift_end','admin') NOT NULL DEFAULT 'gate',
    ADD COLUMN in_source ENUM('gate','admin') NOT NULL DEFAULT 'gate',
    ADD COLUMN edited_at DATETIME(6) NULL,
    ADD COLUMN edited_by BIGINT UNSIGNED NULL;
ALTER TABLE att_entry_cycles
    ADD CONSTRAINT fk_att_cycle_in FOREIGN KEY (gate_in_event_id) REFERENCES att_events (id);
