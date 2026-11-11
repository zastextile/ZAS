-- Reverses 0013. The rows go; images already in the bucket are NOT deleted by
-- a rollback (a migration has no business reaching into object storage) and
-- remain private there until removed by hand.
DELETE FROM att_policies WHERE policy_key IN ('photo_import_prefix', 'photo_purge_last_run');
DROP TABLE IF EXISTS att_flags;
ALTER TABLE att_employees
    DROP COLUMN reference_photo_at,
    DROP COLUMN reference_photo_etag;
