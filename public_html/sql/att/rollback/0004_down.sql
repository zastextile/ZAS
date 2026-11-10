-- Reverses 0004. The audit trail goes with it; take a dump first if the
-- rollback is happening on a system that has been in real use.
DROP TABLE IF EXISTS att_audit_logs;
DROP TABLE IF EXISTS att_policies;
