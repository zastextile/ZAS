-- A PERSON'S OWN WEEKLY OFF DAY (owner, 19 Nov 2026: "more than 90% are off on
-- Friday … just few staff are a different day off"). Empty = the factory's day
-- (Settings › Weekly off day, Friday). 1 = Monday … 7 = Sunday, 0 = no weekly
-- off. On that day no scan is a day off, never "did not come", and a salary
-- is never cut for it; coming in that day is counted as on any day.
ALTER TABLE att_employees ADD COLUMN weekly_off_day TINYINT UNSIGNED NULL;
