-- WHEN A LOAN STARTS COMING BACK (owner, 19 Nov 2026: "100,000 for his
-- sister's wedding … he will start to adjust 10,000 from two months"). Empty =
-- from the next pay sheet, as before. A pay sheet whose last day is before
-- this date takes nothing for it. Can be moved later (more time), with a reason.
ALTER TABLE att_fin_advances ADD COLUMN recover_from DATE NULL;
