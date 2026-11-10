-- ZAS Attendance M2 — sync receipts.
--
-- API_CONTRACT: every event gets {event_uuid, receipt_status, server_event_id,
-- reason_code}. A phone whose connection died after the server committed but
-- before the reply arrived WILL send the same event again, and it must get
-- back the SAME answer — not a second cycle, and not a fresh rejection that
-- makes a successful entry look failed.
--
-- The starter schema had no room for that: it stored a status but not the
-- reason or the body, so a replay could only be answered "already received"
-- with nothing attached. response_json holds the original reply verbatim, so
-- the retry is answered identically, which is what idempotent actually means
-- from the handset's point of view.
CREATE TABLE att_sync_receipts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_uuid     CHAR(36)        NOT NULL,
    device_id      BIGINT UNSIGNED NULL,
    event_kind     VARCHAR(40)     NOT NULL,
    receipt_status VARCHAR(40)     NOT NULL,
    reason_code    VARCHAR(40)     NULL,
    server_event_id BIGINT UNSIGNED NULL,
    response_json  JSON            NULL,
    received_at    DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uniq_att_receipt_uuid (event_uuid),
    KEY idx_att_receipt_device (device_id, received_at),
    CONSTRAINT fk_att_receipt_device FOREIGN KEY (device_id) REFERENCES att_devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
