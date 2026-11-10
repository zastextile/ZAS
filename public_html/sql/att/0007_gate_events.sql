-- ZAS Attendance M2 — the immutable record of what happened at the gate.
--
-- Blueprint §5: "Maintain immutable gate movement events". Nothing in the
-- module ever UPDATEs a row here. A mistake is corrected by appending a
-- correction and an audit entry (att_corrections, M4), never by editing the
-- original — otherwise the movement history stops being evidence.
--
-- IDEMPOTENCY IS ENFORCED BY THE ENGINE, NOT BY APPLICATION CHECKS.
-- Two unique keys do it:
--   event_uuid              — the same event replayed from a phone's outbox
--                             cannot be stored twice, however many times the
--                             connection drops mid-reply.
--   (device_id, device_sequence) — a device cannot reuse a sequence number,
--                             which catches a reinstalled app replaying an
--                             old queue under new UUIDs.
-- A PHP "SELECT then INSERT if absent" would lose both races under the
-- thundering-herd replay that happens when a gate comes back online.

CREATE TABLE att_events (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_uuid           CHAR(36)        NOT NULL,
    employee_id          BIGINT UNSIGNED NOT NULL,
    device_id            BIGINT UNSIGNED NOT NULL,
    operator_id          BIGINT UNSIGNED NOT NULL,
    event_type           ENUM('GATE_IN','GATE_OUT') NOT NULL,

    -- occurred_at is canonical and UTC: what the server decided the event
    -- happened at. device_time is what the handset claimed, kept beside it
    -- rather than instead of it, because a gate device with a wrong clock
    -- must be visible rather than quietly authoritative.
    occurred_at          DATETIME(6)     NOT NULL,
    device_time          DATETIME(6)     NULL,
    timezone             VARCHAR(64)     NULL,
    clock_skew_ms        INT             NULL,

    received_at          DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    device_sequence      BIGINT UNSIGNED NOT NULL,

    -- Blueprint §3: provisional offline data must be distinguishable from
    -- server-verified records.
    source               ENUM('online','offline_replay') NOT NULL DEFAULT 'online',
    provisional          TINYINT(1)      NOT NULL DEFAULT 0,

    reconciliation_status ENUM('accepted','needs_review','rejected') NOT NULL DEFAULT 'accepted',
    reason_code          VARCHAR(40)     NULL,

    UNIQUE KEY uniq_att_event_uuid (event_uuid),
    UNIQUE KEY uniq_att_device_sequence (device_id, device_sequence),
    KEY idx_att_event_emp_time (employee_id, occurred_at),
    KEY idx_att_event_type_time (employee_id, event_type, occurred_at),
    KEY idx_att_event_review (reconciliation_status, received_at),
    CONSTRAINT fk_att_event_emp    FOREIGN KEY (employee_id) REFERENCES att_employees (id),
    CONSTRAINT fk_att_event_device FOREIGN KEY (device_id)   REFERENCES att_devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
