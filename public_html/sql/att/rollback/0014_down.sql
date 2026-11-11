-- Reverses 0014. Overtime records are deleted with their tables: take a
-- backup first if any overtime has been entered.
DELETE FROM att_policies WHERE policy_key IN ('ot_request_days_ahead','ot_request_days_back','ot_max_minutes','ot_decision_window_days','ot_verify_from_next_day');
DROP TABLE IF EXISTS att_ot_payroll_corrections;
DROP TABLE IF EXISTS att_ot_payroll;
DROP TABLE IF EXISTS att_ot_payroll_runs;
DROP TABLE IF EXISTS att_ot_history;
DROP TABLE IF EXISTS att_ot_decision_batches;
DROP TABLE IF EXISTS att_ot_request_employees;
DROP TABLE IF EXISTS att_ot_requests;
