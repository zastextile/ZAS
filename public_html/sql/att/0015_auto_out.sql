-- ZAS Attendance — automatic OUT for a forgotten exit scan.
--
-- Owner's rule (12 Nov 2026): someone who leaves without scanning OUT is
-- closed by the system at the end of their shift — or at shift end plus
-- their APPROVED overtime — once a waiting time after that has passed with
-- still no OUT. The entry is marked for ever as closed by the system, so a
-- report, the HR screen and the next morning's gate all say "not scanned
-- out", and HR can put in the real time as a correction.
--
-- No gate event is invented: att_events stays exactly what the gate saw.
-- auto_closed_at is when the system did it; it stays even if a real OUT,
-- scanned earlier on a phone with no signal, arrives later and replaces it.
ALTER TABLE att_entry_cycles
    MODIFY COLUMN close_source ENUM('gate','hr_correction','auto_shift_end') NOT NULL DEFAULT 'gate',
    ADD COLUMN auto_closed_at DATETIME(6) NULL,
    ADD KEY idx_att_cycle_auto (auto_closed_at);

INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('auto_out_enabled',       '1',   'bool', 'Close a forgotten exit automatically at shift end (plus approved overtime).'),
  ('auto_out_after_minutes', '240', 'int',  'How long after shift end (plus approved overtime) to wait for a real OUT before the system closes the entry.');
