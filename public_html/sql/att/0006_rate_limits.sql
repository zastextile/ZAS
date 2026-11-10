-- ZAS Attendance M1 — rate limiting (blueprint §6).
--
-- Counted in fixed windows rather than a rolling log: a gate handset that has
-- been offline all morning replays its whole queue at once, and a rolling
-- per-request log would both grow without bound and throttle exactly the
-- catch-up the design depends on. One row per bucket per window, incremented
-- atomically.

CREATE TABLE att_rate_limits (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket        VARCHAR(120)    NOT NULL,
    window_start  DATETIME(6)     NOT NULL,
    hits          INT UNSIGNED    NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_att_rl (bucket, window_start),
    KEY idx_att_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
