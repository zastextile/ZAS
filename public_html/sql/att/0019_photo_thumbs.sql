-- SMALL FACES for the Live tab. One small copy (about 120 × 150, ~4 KB) of
-- each employee's reference photo, made the first time it is shown, so a
-- screen full of faces does not fetch every full photo from the bucket again.
-- Valid only while photo_key equals the employee's current reference key: a
-- new photo makes it stale, a removed photo deletes it, and deleting the
-- employee deletes it. Served only through the same checked, audited route.
CREATE TABLE att_photo_thumbs (
    employee_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    photo_key   VARCHAR(255)    NOT NULL,
    mime        VARCHAR(40)     NOT NULL,
    bytes       MEDIUMBLOB      NOT NULL,
    made_at     DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_att_thumb_emp FOREIGN KEY (employee_id) REFERENCES att_employees (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
