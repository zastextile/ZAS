-- ID CARD PRINTING (owner, 24 Nov 2026): the job title printed on the employee
-- card ("Machine Operator"). On the card table, not att_employees, so the gate
-- and HOD phones do not re-sync when it is typed.
ALTER TABLE att_emp_cards ADD COLUMN designation VARCHAR(80) NULL;
