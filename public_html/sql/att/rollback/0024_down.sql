-- Reverses 0024: the canteen tables and the weekly limit column go.
DROP TABLE IF EXISTS att_canteen_calls;
DROP TABLE IF EXISTS att_canteen_rows;
ALTER TABLE att_emp_cards DROP COLUMN weekly_limit_paisa;
