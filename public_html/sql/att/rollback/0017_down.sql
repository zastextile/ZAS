-- Reverses 0017. The finance tables go, with everything in them: take an
-- attendance backup first (php att_backup.php create) if any of it matters.
DELETE FROM att_policies WHERE policy_key IN ('fin_pay_weekday', 'fin_half_day_minutes');
DROP TABLE IF EXISTS att_fin_ledger;
DROP TABLE IF EXISTS att_fin_item_lines;
DROP TABLE IF EXISTS att_fin_sheet_items;
DROP TABLE IF EXISTS att_fin_sheets;
DROP TABLE IF EXISTS att_fin_advances;
DROP TABLE IF EXISTS att_fin_profile_history;
DROP TABLE IF EXISTS att_fin_profiles;
DELETE FROM att_user_roles WHERE role = 'audit';
ALTER TABLE att_user_roles
    MODIFY COLUMN role ENUM('gatekeeper','hod','hr','accounts','admin') NOT NULL;
