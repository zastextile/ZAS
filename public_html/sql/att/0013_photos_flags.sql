-- ZAS Attendance M5 — employee reference photos and flagged entries.
--
-- CLAUDE.md rule 6: "Normal attendance never captures a new photo. Only
-- flagged attendance can upload a still to private R2." So there is no photo
-- column anywhere near att_events or att_entry_cycles. A still exists only
-- on a row of att_flags, which a gatekeeper creates deliberately.
--
-- Nothing here holds an image. Images live in the private bucket; these
-- columns hold the object key, which is random, never leaves the server and
-- is useless without the server's credentials.

ALTER TABLE att_employees
    -- ETag of the file in the bucket's hand-over folder that the current
    -- photo was made from, so "bring in photos" can tell new from done.
    ADD COLUMN reference_photo_etag VARCHAR(80) NULL AFTER reference_photo_key,
    ADD COLUMN reference_photo_at   DATETIME(6) NULL AFTER reference_photo_etag;

CREATE TABLE att_flags (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Made on the phone. Unique here so a flag replayed after a lost
    -- response is the same flag, not a second one.
    flag_uuid       CHAR(36)        NOT NULL,
    employee_id     BIGINT UNSIGNED NOT NULL,
    -- NULL when the flagged scan was one the server refused (already inside,
    -- duplicate): there is no entry to hang it on, but the gatekeeper still
    -- saw something worth recording.
    entry_cycle_id  BIGINT UNSIGNED NULL,
    gate_event_uuid CHAR(36)        NULL,
    reason_code     VARCHAR(40)     NOT NULL,
    note            VARCHAR(500)    NULL,
    flagged_by      BIGINT UNSIGNED NOT NULL,
    device_id       BIGINT UNSIGNED NULL,
    occurred_at     DATETIME(6)     NOT NULL,
    received_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    -- The still. One per flag, optional (a camera can be refused).
    photo_key        VARCHAR(500)    NULL,
    photo_sha256     CHAR(64)        NULL,      -- of the bytes the phone sent, for replay detection
    photo_bytes      INT UNSIGNED    NULL,      -- of what was stored
    photo_by         BIGINT UNSIGNED NULL,
    photo_stored_at  DATETIME(6)     NULL,
    -- Retention (blueprint §6): the image is deleted, the flag is kept.
    photo_purged_at  DATETIME(6)     NULL,

    status          ENUM('open','reviewed') NOT NULL DEFAULT 'open',
    reviewed_by     BIGINT UNSIGNED NULL,
    reviewed_at     DATETIME(6)     NULL,
    review_note     VARCHAR(500)    NULL,

    UNIQUE KEY uniq_att_flag_uuid (flag_uuid),
    KEY idx_att_flag_status (status, received_at),
    KEY idx_att_flag_emp (employee_id, occurred_at),
    KEY idx_att_flag_cycle (entry_cycle_id),
    KEY idx_att_flag_retention (photo_stored_at, photo_purged_at),
    CONSTRAINT fk_att_flag_emp   FOREIGN KEY (employee_id)    REFERENCES att_employees (id),
    CONSTRAINT fk_att_flag_cycle FOREIGN KEY (entry_cycle_id) REFERENCES att_entry_cycles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO att_policies (policy_key, value, value_type, description) VALUES
  ('photo_import_prefix', 'employee-photos/', 'string', 'Folder in the bucket where employee photos are dropped for bringing in, each file named by employee code.'),
  ('photo_purge_last_run', '', 'string', 'When flagged photos past their retention were last cleared.');
