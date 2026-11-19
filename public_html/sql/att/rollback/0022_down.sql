-- Reverses 0022: every advance and loan is recovered from the next pay sheet again.
ALTER TABLE att_fin_advances DROP COLUMN recover_from;
