-- Reverses 0007. Cycles reference events, so they must already be gone;
-- 0008_down runs first because rollback walks newest to oldest.
DROP TABLE IF EXISTS att_events;
