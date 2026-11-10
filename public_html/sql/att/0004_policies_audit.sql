-- ZAS Attendance M1 — configurable policy and the module's audit trail.

-- Blueprint §10: "Default implementation should make these configurable
-- rather than hard-code assumptions." Every tunable the module has lives in
-- this table, is editable by an admin, and has a default that is safe on the
-- day of installation.
CREATE TABLE att_policies (
    policy_key  VARCHAR(64)  NOT NULL PRIMARY KEY,
    value       TEXT         NOT NULL,
    value_type  ENUM('int','bool','string','json') NOT NULL DEFAULT 'string',
    description VARCHAR(255) NOT NULL DEFAULT '',
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('duplicate_window_seconds', '60',   'int',  'Reject the same employee + same action inside this many seconds (blueprint §2).'),
  ('default_shift_id',         '',     'string','D4: shift attached when an employee reaches the gate with none assigned. Empty = none configured yet.'),
  ('auto_shift_on_gate_in',    '1',    'bool', 'D4: admit the employee and attach the default shift rather than refusing entry.'),
  ('hod_match_window_minutes', '240',  'int',  'How far from a gate event an unmatched HOD confirmation may be reconciled (blueprint §3).'),
  ('sync_backlog_warn_minutes','120',  'int',  'Warn on a device whose oldest unsynced event is older than this.'),
  ('photo_retention_days',     '90',   'int',  'Flagged attendance photo retention (blueprint §6). Confirm with IT before production.'),
  ('photo_max_bytes',          '5242880','int','Upload size cap for a flagged still.'),
  ('gatekeeper_may_verify_ot', '0',    'bool', 'Blueprint §4 allows an explicitly permitted gatekeeper to enter next-day actual OT minutes. Off by default.'),
  ('clock_skew_warn_ms',       '120000','int', 'Flag a device whose clock differs from the server by more than this.');

-- The spec's audit_logs, under a name that does not collide. The live
-- audit_logs table is the shipment change trail — different columns entirely,
-- and breaking it would take the export module's history with it.
CREATE TABLE att_audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_id     BIGINT UNSIGNED NULL,
    action       VARCHAR(100)    NOT NULL,
    subject_type VARCHAR(80)     NOT NULL,
    subject_id   BIGINT UNSIGNED NOT NULL,
    old_data     JSON            NULL,
    new_data     JSON            NULL,
    reason       TEXT            NULL,
    ip_address   VARCHAR(80)     NULL,
    created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_att_audit_subject (subject_type, subject_id, created_at),
    KEY idx_att_audit_actor (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
