-- Reverses 0020: phones go back to asking for the email and password when the
-- server's sign-in expires; departments lose their regular shift (the shifts
-- already given to people are kept).
ALTER TABLE att_departments DROP FOREIGN KEY fk_att_dept_regshift;
ALTER TABLE att_departments DROP COLUMN regular_shift_id;
DROP TABLE IF EXISTS att_device_tokens;
