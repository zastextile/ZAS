-- People Finance: ENTRY SHEETS — Add earning, Add deduction and Record payment
-- from the Employee Ledger, for one person or many. An entry sheet goes the
-- same road as a pay sheet (waiting, then a second person approves). An entry
-- of earnings or deductions is 'posted' once approved: it is in the ledger and
-- there is nothing to hand over. A payment entry is approved and then paid.
ALTER TABLE att_fin_sheets
    MODIFY COLUMN kind   ENUM('salary','daily','contract','entry') NOT NULL,
    MODIFY COLUMN status ENUM('waiting','approved','paid','returned','cancelled','posted') NOT NULL DEFAULT 'waiting';
