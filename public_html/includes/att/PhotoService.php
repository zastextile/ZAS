<?php
declare(strict_types=1);

namespace Att;

/**
 * Employee reference photos, and Capture & Flag.
 *
 * TWO KINDS OF PICTURE, AND ONLY TWO.
 *
 *   REFERENCE  One per employee, loaded by the office. Shown to the
 *              gatekeeper and the HOD when a code is typed or scanned, so
 *              they can see that the person in front of them is the person
 *              the code belongs to.
 *
 *   FLAG       A still taken at the gate, on purpose, when the gatekeeper
 *              marks an entry as needing a second look. CLAUDE.md rule 6:
 *              "Normal attendance never captures a new photo." Nothing in
 *              the ordinary IN/OUT path can reach storeFlagPhoto(); a still
 *              needs a flag row first, and a flag row is only ever made by
 *              createFlag().
 *
 * WHAT IS TRUE OF BOTH.
 *   - Private. Held in the ERP's private bucket under a random key; served
 *     only by this server, to a signed-in user who passed the permission and
 *     department checks, and never by a link (see Att\Storage).
 *   - Re-made, never stored as sent: a fixed size, WebP, about 30 KB for a
 *     reference photo. No original is kept (see Att\Images).
 *   - Every view is written to the audit log — who looked at whose picture,
 *     and from which screen (blueprint §6, "audit access to images").
 *   - A picture that fails to store is reported and logged; it never blocks
 *     the entry it belongs to (blueprint §7, "avoid blocking entry on photo
 *     upload"). The flag stands without it.
 */
final class PhotoService
{
    /** Why a gatekeeper may flag an entry. The code is stored; the words are shown. */
    public const REASONS = [
        'FACE_MISMATCH'      => 'Face does not match the photo',
        'OTHER_PERSONS_CARD' => 'Using another person\'s card or code',
        'NO_CARD'            => 'No card',
        'OTHER'              => 'Other (write a note)',
    ];

    // ------------------------------------------------------------------ status

    public static function status(): array
    {
        $fail = Db::one(
            "SELECT reason, created_at FROM att_audit_logs
              WHERE action = 'photo.store_failed' AND created_at > (UTC_TIMESTAMP(6) - INTERVAL 7 DAY)
              ORDER BY id DESC LIMIT 1"
        );
        return [
            'ok'                => true,
            'storage_connected' => Storage::configured(),
            'image_tools'       => Images::available(),
            'employees'         => (int) Db::value('SELECT COUNT(*) FROM att_employees WHERE active = 1'),
            'with_photo'        => (int) Db::value('SELECT COUNT(*) FROM att_employees WHERE active = 1 AND reference_photo_key IS NOT NULL'),
            'import_prefix'     => self::importPrefix(),
            'retention_days'    => Policy::int('photo_retention_days', 90),
            'failures_7_days'   => (int) Db::value(
                "SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.store_failed' AND created_at > (UTC_TIMESTAMP(6) - INTERVAL 7 DAY)"),
            'last_failure'      => $fail === null ? null : ['at' => Http::iso((string) $fail['created_at']), 'why' => (string) $fail['reason']],
        ];
    }

    /**
     * Write, read back, list and delete one tiny object, and say which step
     * failed. The error text comes from the storage layer and never carries
     * a credential.
     */
    public static function selfTest(int $actorId): array
    {
        if (!Storage::configured()) {
            return ['ok' => false, 'reason_code' => 'STORAGE_NOT_CONNECTED',
                    'error' => 'Photo storage is not connected. The four r2_ lines in config.php on the server are missing or blank.'];
        }
        $key   = Storage::PREFIX . 'selftest/' . bin2hex(random_bytes(8)) . '.txt';
        $body  = 'zas attendance storage test ' . gmdate('c');
        $steps = [];

        [$ok, $err] = Storage::put($key, $body, 'text/plain');
        $steps[] = ['step' => 'Write a test file', 'ok' => $ok, 'error' => $err];
        if ($ok) {
            [$ok2, $err2, $got] = Storage::get($key);
            $same = $ok2 && $got === $body;
            $steps[] = ['step' => 'Read it back', 'ok' => $same, 'error' => $ok2 ? ($same ? '' : 'What came back was not what was written.') : $err2];
            [$ok3, $err3, $rows] = Storage::list(Storage::PREFIX . 'selftest/');
            $seen = $ok3 && in_array($key, array_column($rows, 'key'), true);
            $steps[] = ['step' => 'List the folder', 'ok' => $seen, 'error' => $ok3 ? ($seen ? '' : 'The test file was not in the listing.') : $err3];
            [$ok4, $err4] = Storage::delete($key);
            $steps[] = ['step' => 'Delete it', 'ok' => $ok4, 'error' => $err4];
        }
        $all = !in_array(false, array_column($steps, 'ok'), true);
        Audit::record('photo.selftest', 'att_storage', 0, null, ['passed' => $all], $actorId);
        return ['ok' => true, 'passed' => $all, 'steps' => $steps];
    }

    // --------------------------------------------------------------- reference

    public static function setReference(int $employeeId, string $bytes, bool $noticeGiven, int $actorId, ?string $sourceEtag = null): array
    {
        $e = Db::one('SELECT id, code, full_name, reference_photo_key, notice_ack_at FROM att_employees WHERE id = ?', [$employeeId]);
        if ($e === null) {
            return self::no('NOT_FOUND', 'That employee no longer exists.');
        }
        /* Blueprint §6: an employee is told before a picture of them is held.
           Recorded per person, with the date, so it can be shown one by one. */
        if ($e['notice_ack_at'] === null && !$noticeGiven) {
            return self::no('NOTICE_REQUIRED', 'Confirm that ' . $e['full_name'] . ' has been told their photo will be kept for attendance.');
        }
        if (!Storage::configured()) {
            return self::no('STORAGE_NOT_CONNECTED', 'Photo storage is not connected, so the photo was not saved.');
        }
        $img = Images::normalize($bytes, Images::REFERENCE);
        if (!$img['ok']) {
            return $img;
        }

        $key = Storage::newKey('ref', $img['ext']);
        [$ok, $err] = Storage::put($key, $img['bytes'], $img['mime']);
        if (!$ok) {
            self::storeFailed('att_employee', $employeeId, $err, $actorId);
            return self::no('STORAGE_FAILED', 'The photo storage did not accept the photo. Nothing was changed. ' . $err);
        }

        Db::run(
            'UPDATE att_employees
                SET reference_photo_key = ?, reference_photo_etag = ?, reference_photo_at = ?,
                    notice_ack_at = COALESCE(notice_ack_at, ?)
              WHERE id = ?',
            [$key, $sourceEtag, Time::sql(), Time::sql(), $employeeId]
        );
        // The old picture goes only after the new one is in place and recorded.
        $old = (string) ($e['reference_photo_key'] ?? '');
        if ($old !== '') {
            [$gone, $why] = Storage::delete($old);
            if (!$gone) {
                error_log('[att-photo] could not delete a replaced reference photo: ' . $why);
            }
        }
        Audit::record('photo.reference_set', 'att_employee', $employeeId,
            ['had_photo' => $old !== ''],
            ['bytes' => strlen($img['bytes']), 'width' => $img['width'], 'height' => $img['height'], 'from_bucket' => $sourceEtag !== null],
            $actorId);

        return ['ok' => true, 'employee_id' => $employeeId, 'bytes' => strlen($img['bytes']),
                'width' => $img['width'], 'height' => $img['height'], 'format' => $img['ext']];
    }

    public static function removeReference(int $employeeId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why the photo is being removed. It is kept on the record.');
        }
        $e = Db::one('SELECT id, reference_photo_key FROM att_employees WHERE id = ?', [$employeeId]);
        if ($e === null) {
            return self::no('NOT_FOUND', 'That employee no longer exists.');
        }
        $key = (string) ($e['reference_photo_key'] ?? '');
        if ($key === '') {
            return ['ok' => true, 'employee_id' => $employeeId, 'removed' => false];
        }
        [$ok, $err] = Storage::delete($key);
        if (!$ok) {
            // Not cleared from the record: a row saying "no photo" while the
            // picture is still in the bucket is the wrong way round.
            return self::no('STORAGE_FAILED', 'The photo could not be deleted from storage, so it is still on record. ' . $err);
        }
        Db::run('UPDATE att_employees SET reference_photo_key = NULL, reference_photo_etag = NULL, reference_photo_at = NULL WHERE id = ?', [$employeeId]);
        Audit::record('photo.reference_removed', 'att_employee', $employeeId, ['had_photo' => true], ['had_photo' => false], $actorId, $reason);
        return ['ok' => true, 'employee_id' => $employeeId, 'removed' => true];
    }

    /**
     * The picture itself, for a caller who has already passed the permission
     * and department checks. Every call is one line in the audit log.
     *
     * @return array{ok:true,bytes:string,mime:string}|array{ok:false,reason_code:string,error:string}
     */
    public static function reference(int $employeeId, int $actorId, string $purpose): array
    {
        $key = (string) (Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$employeeId]) ?? '');
        if ($key === '') {
            return self::no('NO_PHOTO', 'There is no photo on file for this employee.');
        }
        [$ok, $err, $bytes] = Storage::get($key);
        if (!$ok || $bytes === '') {
            error_log('[att-photo] reference photo could not be read: ' . $err);
            return self::no('STORAGE_FAILED', 'The photo could not be loaded just now.');
        }
        Audit::record('photo.view', 'att_employee', $employeeId, null, ['kind' => 'reference', 'purpose' => $purpose], $actorId);
        return ['ok' => true, 'bytes' => $bytes, 'mime' => Images::mimeOf($bytes)];
    }

    /**
     * Bring in photos that were dropped into the bucket's hand-over folder,
     * each named by employee code (E001.jpg).
     *
     * Done a few at a time: every picture is fetched, checked, re-made and
     * stored again under a random key, and a web request cannot do five
     * hundred of those. The screen calls this repeatedly and passes back the
     * files it has already seen fail, so one bad file cannot stall the rest.
     *
     * The hand-over files themselves are left exactly where they are. They
     * were put there by a person; deleting them is that person's decision.
     *
     * @param list<string> $skipKeys
     */
    public static function importBatch(int $limit, array $skipKeys, bool $noticeGiven, int $actorId): array
    {
        if (!Storage::configured()) {
            return self::no('STORAGE_NOT_CONNECTED', 'Photo storage is not connected.');
        }
        if (!Images::available()) {
            return self::no('IMAGE_TOOLS_MISSING', 'This server cannot process pictures (the PHP GD extension is missing).');
        }
        if (!$noticeGiven) {
            return self::no('NOTICE_REQUIRED', 'Confirm that these employees have been told their photo will be kept for attendance.');
        }
        $prefix = self::importPrefix();
        [$ok, $err, $files] = Storage::list($prefix);
        if (!$ok) {
            return self::no('STORAGE_FAILED', $err);
        }

        $emps = [];
        foreach (Db::all('SELECT id, code, full_name, reference_photo_etag FROM att_employees') as $e) {
            $emps[mb_strtoupper((string) $e['code'])] = $e;
        }

        $skip = array_flip(array_map('strval', $skipKeys));
        $seenCode = [];
        $todo = [];
        $out = ['ok' => true, 'folder' => $prefix, 'files' => 0, 'matched' => 0, 'already' => 0,
                'unmatched' => [], 'done' => [], 'failed' => [], 'remaining' => 0];

        foreach ($files as $f) {
            $name = substr((string) $f['key'], strlen($prefix));
            if ($name === '' || str_ends_with($name, '/') || (int) $f['size'] === 0) {
                continue;                       // the folder marker itself
            }
            $out['files']++;
            $base = pathinfo($name, PATHINFO_FILENAME);
            $code = mb_strtoupper(trim($base));
            if (str_contains($name, '/') || !isset($emps[$code])) {
                if (count($out['unmatched']) < 200) { $out['unmatched'][] = $name; }
                continue;
            }
            if (isset($seenCode[$code])) {
                $out['failed'][] = ['file' => $name, 'error' => 'A second file for ' . $emps[$code]['code'] . ' — "' . $seenCode[$code] . '" was used.'];
                continue;
            }
            $seenCode[$code] = $name;
            $out['matched']++;
            if ((string) $emps[$code]['reference_photo_etag'] !== '' && (string) $emps[$code]['reference_photo_etag'] === (string) $f['etag']) {
                $out['already']++;
                continue;
            }
            if (isset($skip[(string) $f['key']])) {
                continue;
            }
            $todo[] = [$f, $emps[$code], $name];
        }

        $limit = max(1, min(25, $limit));
        foreach (array_slice($todo, 0, $limit) as [$f, $e, $name]) {
            if ((int) $f['size'] > Images::MAX_SOURCE) {
                $out['failed'][] = ['file' => $name, 'key' => $f['key'], 'error' => 'Larger than 10 MB.'];
                continue;
            }
            [$got, $why, $bytes] = Storage::get((string) $f['key']);
            if (!$got) {
                $out['failed'][] = ['file' => $name, 'key' => $f['key'], 'error' => 'Could not be read from storage. ' . $why];
                continue;
            }
            $r = self::setReference((int) $e['id'], $bytes, true, $actorId, (string) $f['etag']);
            if ($r['ok']) {
                $out['done'][] = ['file' => $name, 'code' => $e['code'], 'full_name' => $e['full_name']];
            } else {
                $out['failed'][] = ['file' => $name, 'key' => $f['key'], 'error' => (string) $r['error']];
            }
        }
        $out['remaining'] = max(0, count($todo) - $limit);
        return $out;
    }

    // ------------------------------------------------------------------- flags

    /**
     * Record that an entry needs a second look.
     *
     * The phone names the scan it is about by that scan's own id, because
     * while offline it cannot know the entry number the server will give it.
     *
     * @param array<string,mixed> $in
     */
    public static function createFlag(array $in, int $actorId): array
    {
        $uuid = strtolower(trim((string) ($in['flag_uuid'] ?? '')));
        if (!Receipts::validUuid($uuid)) {
            return self::no('VALIDATION', 'The flag has no valid id.');
        }
        $reason = strtoupper(trim((string) ($in['reason_code'] ?? '')));
        if (!isset(self::REASONS[$reason])) {
            return self::no('VALIDATION', 'Choose why this entry is being flagged.');
        }
        $note = trim(preg_replace('/\s+/u', ' ', (string) ($in['note'] ?? '')) ?? '');
        if (mb_strlen($note) > 500) {
            return self::no('VALIDATION', 'The note can be at most 500 characters.');
        }
        if ($reason === 'OTHER' && $note === '') {
            return self::no('VALIDATION', 'Write a short note saying what was wrong.');
        }

        $existing = Db::one('SELECT id, entry_cycle_id, photo_key, photo_purged_at FROM att_flags WHERE flag_uuid = ?', [$uuid]);
        if ($existing !== null) {
            return self::flagAnswer($existing, $uuid, true);
        }

        // Which entry?
        $cycleId    = (int) ($in['entry_cycle_id'] ?? 0);
        $gateUuid   = strtolower(trim((string) ($in['gate_event_uuid'] ?? '')));
        $employeeId = 0;
        $cycle      = null;

        if ($cycleId > 0) {
            $cycle = Db::one('SELECT id, employee_id FROM att_entry_cycles WHERE id = ?', [$cycleId]);
            if ($cycle === null) {
                return self::no('CYCLE_NOT_FOUND', 'That entry does not exist.');
            }
            $employeeId = (int) $cycle['employee_id'];
        } elseif (Receipts::validUuid($gateUuid)) {
            $ev = Db::one('SELECT id, employee_id FROM att_events WHERE event_uuid = ?', [$gateUuid]);
            if ($ev !== null) {
                $employeeId = (int) $ev['employee_id'];
                $cycle = Db::one('SELECT id, employee_id FROM att_entry_cycles WHERE gate_in_event_id = ? OR gate_out_event_id = ?',
                    [(int) $ev['id'], (int) $ev['id']]);
            } else {
                $rc = Db::one('SELECT request_json FROM att_sync_receipts WHERE event_uuid = ?', [$gateUuid]);
                if ($rc === null) {
                    /* The scan this flag is about has not reached the server.
                       Not a refusal: the phone keeps the flag and tries again
                       after its scans have gone up. */
                    return ['ok' => false, 'retry' => true, 'reason_code' => 'GATE_EVENT_NOT_RECEIVED',
                            'error' => 'The scan this flag belongs to has not reached the server yet.'];
                }
                $req  = json_decode((string) ($rc['request_json'] ?? ''), true);
                $code = is_array($req) ? trim((string) ($req['employee_code'] ?? '')) : '';
                $employeeId = $code !== '' ? (int) (Employees::findByCodeOrQr($code)['id'] ?? 0) : 0;
            }
        } else {
            return self::no('VALIDATION', 'The flag does not say which entry it is about.');
        }
        if ($employeeId <= 0) {
            return self::no('EMPLOYEE_NOT_FOUND', 'The flagged scan was not for a known employee.');
        }

        $deviceId = null;
        if (trim((string) ($in['device_id'] ?? '')) !== '') {
            $dev = SyncService::resolveDevice($in['device_id']);
            if ($dev !== null) {
                if (!empty($dev['revoked_at'])) {
                    return self::no('DEVICE_REVOKED', 'This phone has been withdrawn from service.');
                }
                $deviceId = (int) $dev['id'];
            }
        }

        $occurred = Time::parse((string) ($in['occurred_at'] ?? ''), (string) ($in['timezone'] ?? '')) ?? Time::nowUtc();

        try {
            Db::run(
                'INSERT INTO att_flags (flag_uuid, employee_id, entry_cycle_id, gate_event_uuid, reason_code, note,
                                        flagged_by, device_id, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid, $employeeId, $cycle !== null ? (int) $cycle['id'] : null, $gateUuid !== '' ? $gateUuid : null,
                 $reason, $note !== '' ? $note : null, $actorId, $deviceId, Time::sql($occurred)]
            );
        } catch (\Throwable $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }
            // Two copies of the same upload arrived together. One flag.
            $row = Db::one('SELECT id, entry_cycle_id, photo_key, photo_purged_at FROM att_flags WHERE flag_uuid = ?', [$uuid]);
            return self::flagAnswer($row ?? [], $uuid, true);
        }
        $id = Db::lastId();
        Audit::record('flag.create', 'att_flag', $id, null,
            ['employee_id' => $employeeId, 'entry_cycle_id' => $cycle !== null ? (int) $cycle['id'] : null, 'reason_code' => $reason],
            $actorId, $note !== '' ? $note : null);

        return ['ok' => true, 'flag_id' => $id, 'flag_uuid' => $uuid, 'already' => false, 'has_photo' => false,
                'entry_cycle_id' => $cycle !== null ? (int) $cycle['id'] : null];
    }

    /** Attach the still to a flag. One per flag; a replay of the same upload is not an error. */
    public static function storeFlagPhoto(string $flagUuid, string $bytes, int $actorId): array
    {
        $flag = Db::one('SELECT id, photo_key, photo_sha256, photo_purged_at FROM att_flags WHERE flag_uuid = ?', [strtolower(trim($flagUuid))]);
        if ($flag === null) {
            return self::no('FLAG_NOT_FOUND', 'That flag does not exist.');
        }
        $sha = hash('sha256', $bytes);
        if ($flag['photo_key'] !== null || $flag['photo_purged_at'] !== null) {
            if (hash_equals((string) $flag['photo_sha256'], $sha)) {
                return ['ok' => true, 'flag_id' => (int) $flag['id'], 'already' => true];
            }
            return self::no('PHOTO_EXISTS', 'This flag already has a photo.');
        }
        if (!Storage::configured()) {
            return ['ok' => false, 'retry' => true, 'reason_code' => 'STORAGE_NOT_CONNECTED', 'error' => 'Photo storage is not connected.'];
        }
        $max = max(65536, Policy::int('photo_max_bytes', 5242880));
        $img = Images::normalize($bytes, Images::FLAG, $max);
        if (!$img['ok']) {
            return $img;
        }
        $key = Storage::newKey('flag', $img['ext']);
        [$ok, $err] = Storage::put($key, $img['bytes'], $img['mime']);
        if (!$ok) {
            self::storeFailed('att_flag', (int) $flag['id'], $err, $actorId);
            // The flag stands; the phone keeps the picture and tries again.
            return ['ok' => false, 'retry' => true, 'reason_code' => 'STORAGE_FAILED', 'error' => 'The photo storage did not accept the photo. It will be tried again.'];
        }
        $st = Db::run(
            'UPDATE att_flags SET photo_key = ?, photo_sha256 = ?, photo_bytes = ?, photo_by = ?, photo_stored_at = ?
              WHERE id = ? AND photo_key IS NULL AND photo_purged_at IS NULL',
            [$key, $sha, strlen($img['bytes']), $actorId, Time::sql(), (int) $flag['id']]
        );
        if ($st->rowCount() === 0) {
            Storage::delete($key);      // another copy of this upload won the race
            return ['ok' => true, 'flag_id' => (int) $flag['id'], 'already' => true];
        }
        Audit::record('flag.photo_stored', 'att_flag', (int) $flag['id'], null,
            ['bytes' => strlen($img['bytes']), 'width' => $img['width'], 'height' => $img['height']], $actorId);
        return ['ok' => true, 'flag_id' => (int) $flag['id'], 'already' => false, 'sha256' => $sha];
    }

    /** @param 'flag'|'reference' $which */
    public static function flagPhoto(int $flagId, string $which, int $actorId): array
    {
        $flag = Db::one('SELECT id, employee_id, photo_key, photo_purged_at FROM att_flags WHERE id = ?', [$flagId]);
        if ($flag === null) {
            return self::no('NOT_FOUND', 'That flag does not exist.');
        }
        if ($which === 'reference') {
            return self::reference((int) $flag['employee_id'], $actorId, 'flag review');
        }
        if ($flag['photo_key'] === null) {
            return self::no('NO_PHOTO', $flag['photo_purged_at'] !== null
                ? 'The photo was removed when it passed the retention period.' : 'This flag has no photo.');
        }
        [$ok, $err, $bytes] = Storage::get((string) $flag['photo_key']);
        if (!$ok || $bytes === '') {
            error_log('[att-photo] flag photo could not be read: ' . $err);
            return self::no('STORAGE_FAILED', 'The photo could not be loaded just now.');
        }
        Audit::record('photo.view', 'att_flag', $flagId, null, ['kind' => 'flag', 'purpose' => 'flag review'], $actorId);
        return ['ok' => true, 'bytes' => $bytes, 'mime' => Images::mimeOf($bytes)];
    }

    /** The HR list: everything open, and what was reviewed in the last week. */
    public static function flags(): array
    {
        $users = Db::tableExists('users');
        $sql = 'SELECT f.id, f.flag_uuid, f.reason_code, f.note, f.occurred_at, f.received_at, f.status,
                       f.reviewed_at, f.review_note, f.entry_cycle_id,
                       (f.photo_key IS NOT NULL) AS has_photo, (f.photo_purged_at IS NOT NULL) AS photo_purged,
                       e.id AS employee_id, e.code AS employee_code, e.full_name, d.name AS department_name,
                       (e.reference_photo_key IS NOT NULL) AS has_reference,
                       c.opened_at, c.closed_at, ' . ($users ? 'u.name' : 'NULL') . ' AS flagged_by_name
                  FROM att_flags f
                  JOIN att_employees e ON e.id = f.employee_id
                  JOIN att_departments d ON d.id = e.department_id
                  LEFT JOIN att_entry_cycles c ON c.id = f.entry_cycle_id '
             . ($users ? 'LEFT JOIN users u ON u.id = f.flagged_by ' : '');
        $open = Db::all($sql . "WHERE f.status = 'open' ORDER BY f.occurred_at DESC LIMIT 300");
        $done = Db::all($sql . "WHERE f.status = 'reviewed' AND f.reviewed_at > (UTC_TIMESTAMP(6) - INTERVAL 7 DAY) ORDER BY f.reviewed_at DESC LIMIT 100");
        foreach ([&$open, &$done] as &$list) {
            foreach ($list as &$r) {
                $r['reason'] = self::REASONS[(string) $r['reason_code']] ?? (string) $r['reason_code'];
            }
            unset($r);
        }
        unset($list);
        return ['ok' => true, 'open' => $open, 'reviewed' => $done, 'retention_days' => Policy::int('photo_retention_days', 90)];
    }

    public static function reviewFlag(int $flagId, string $note, int $actorId): array
    {
        $note = trim($note);
        if ($note === '') {
            return self::no('REASON_REQUIRED', 'Write what was found. It is kept with the flag.');
        }
        if (mb_strlen($note) > 500) {
            return self::no('VALIDATION', 'The note can be at most 500 characters.');
        }
        $st = Db::run(
            "UPDATE att_flags SET status = 'reviewed', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ? AND status = 'open'",
            [$actorId, Time::sql(), $note, $flagId]
        );
        if ($st->rowCount() === 0) {
            return Db::one('SELECT id FROM att_flags WHERE id = ?', [$flagId]) === null
                ? self::no('NOT_FOUND', 'That flag does not exist.')
                : self::no('ALREADY_REVIEWED', 'That flag has already been reviewed.');
        }
        Audit::record('flag.review', 'att_flag', $flagId, ['status' => 'open'], ['status' => 'reviewed'], $actorId, $note);
        return ['ok' => true, 'flag_id' => $flagId];
    }

    // --------------------------------------------------------------- retention

    /**
     * Delete flagged stills older than the retention period. The flag, its
     * reason and its review stay; only the image goes.
     */
    public static function purgeExpired(int $limit = 200): array
    {
        $days = max(1, Policy::int('photo_retention_days', 90));
        $rows = Db::all(
            'SELECT id, photo_key FROM att_flags
              WHERE photo_key IS NOT NULL AND photo_stored_at < (UTC_TIMESTAMP(6) - INTERVAL ' . $days . ' DAY)
              ORDER BY photo_stored_at LIMIT ' . max(1, min(1000, $limit))
        );
        $purged = 0;
        $failed = 0;
        foreach ($rows as $r) {
            [$ok, $err] = Storage::delete((string) $r['photo_key']);
            if (!$ok) {
                $failed++;
                error_log('[att-photo] retention delete failed: ' . $err);
                continue;       // stays on record until it really is gone
            }
            Db::run('UPDATE att_flags SET photo_key = NULL, photo_purged_at = ? WHERE id = ?', [Time::sql(), (int) $r['id']]);
            $purged++;
        }
        Db::run(
            "INSERT INTO att_policies (policy_key, value, value_type, description) VALUES ('photo_purge_last_run', ?, 'string', '')
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [gmdate('Y-m-d H:i:s')]
        );
        if ($purged > 0 || $failed > 0) {
            Audit::record('photo.retention_purge', 'att_flag', 0, null,
                ['purged' => $purged, 'failed' => $failed, 'retention_days' => $days], null);
        }
        return ['ok' => true, 'purged' => $purged, 'failed' => $failed, 'retention_days' => $days];
    }

    /**
     * Run the purge if it has not run in the last day. Called when HR opens
     * the flag list, so retention is kept even where nobody set up a timed
     * job; att_cron.php does the same on a timer where one exists.
     */
    public static function purgeIfDue(): ?array
    {
        if (!Storage::configured()) {
            return null;
        }
        $last = (string) (Db::value("SELECT value FROM att_policies WHERE policy_key = 'photo_purge_last_run'") ?? '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 86400) {
            return null;
        }
        Db::run(
            "INSERT INTO att_policies (policy_key, value, value_type, description) VALUES ('photo_purge_last_run', ?, 'string', '')
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [gmdate('Y-m-d H:i:s')]
        );
        Policy::flush();
        return self::purgeExpired();
    }

    // ------------------------------------------------------------------ helpers

    public static function importPrefix(): string
    {
        $p = trim(Policy::get('photo_import_prefix', 'employee-photos/'));
        $p = ltrim($p, '/');
        if ($p === '' || str_starts_with($p, Storage::PREFIX)) {
            $p = 'employee-photos/';      // never the folder the system itself writes to
        }
        return rtrim($p, '/') . '/';
    }

    /** @param array<string,mixed> $row */
    private static function flagAnswer(array $row, string $uuid, bool $already): array
    {
        return ['ok' => true, 'flag_id' => (int) ($row['id'] ?? 0), 'flag_uuid' => $uuid, 'already' => $already,
                'has_photo' => ($row['photo_key'] ?? null) !== null || ($row['photo_purged_at'] ?? null) !== null,
                'entry_cycle_id' => isset($row['entry_cycle_id']) ? (int) $row['entry_cycle_id'] : null];
    }

    /** Blueprint §7: monitor failed R2 uploads. Counted on the Setup screen. */
    private static function storeFailed(string $type, int $id, string $why, int $actorId): void
    {
        error_log('[att-photo] store failed: ' . $why);
        Audit::record('photo.store_failed', $type, $id, null, null, $actorId, mb_substr($why, 0, 300));
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
