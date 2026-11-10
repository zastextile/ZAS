<?php
declare(strict_types=1);

namespace Att;

use Throwable;

/**
 * The one door every event comes through.
 *
 * Gate scans and HOD confirmations reach the server the same way whether the
 * phone was online or had been offline for six hours: as an envelope
 *
 *   {event_uuid, device_id, device_sequence, employee_code, event_type,
 *    occurred_at, timezone, payload}
 *
 * and each gets a receipt. One path means an offline replay is judged by
 * exactly the same rules as a live scan — there is no second, laxer code path
 * for "catching up" that could let something through.
 *
 * WHO DID IT. The operator recorded on an event is always the signed-in user
 * making the request (API_CONTRACT: "Never trust client operator/role IDs").
 * A phone also sends who was signed in when the event was captured; if that
 * differs — a shift changed hands on a shared gate phone before it synced —
 * the event is still accepted, the difference is kept in the stored envelope
 * and the audit log, and the receipt says actor_mismatch so nobody is misled
 * about who was at the gate.
 */
final class SyncService
{
    public const TYPES = ['GATE_IN', 'GATE_OUT', 'HOD_CONFIRM'];

    // ------------------------------------------------------------- devices

    /** Accepts the numeric id or the device's own UUID. */
    public static function resolveDevice(mixed $ref): ?array
    {
        $ref = trim((string) ($ref ?? ''));
        if ($ref === '' || $ref === '0') {
            return null;
        }
        if (ctype_digit($ref)) {
            return Db::one('SELECT * FROM att_devices WHERE id = ?', [(int) $ref]);
        }
        return Receipts::validUuid($ref)
            ? Db::one('SELECT * FROM att_devices WHERE device_uuid = ?', [strtolower($ref)])
            : null;
    }

    /**
     * Register the phone a signed-in gatekeeper or HOD is holding.
     *
     * $scope is decided by the CALLER from the user's permissions, never taken
     * from the phone. Registering again returns the same record: a browser
     * that reloads must not mint a second device. A revoked device stays
     * revoked — re-registering is not a way back in.
     *
     * @param 'gate'|'hod'|'both' $scope
     */
    public static function registerDevice(string $uuid, string $label, string $scope, int $userId, ?string $appVersion = null): array
    {
        $uuid = strtolower(trim($uuid));
        if (!Receipts::validUuid($uuid)) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'A device UUID is required.'];
        }
        $existing = Db::one('SELECT * FROM att_devices WHERE device_uuid = ?', [$uuid]);
        if ($existing !== null) {
            if ($existing['revoked_at'] !== null) {
                return ['ok' => false, 'reason_code' => 'DEVICE_REVOKED',
                        'error' => 'This device has been withdrawn from service. Ask an admin.'];
            }
            // Widen, never narrow: a phone used by a HOD who is also a
            // gatekeeper becomes 'both'; it does not flip between the two.
            $newScope = ($existing['role_scope'] === $scope || $existing['role_scope'] === 'both') ? $existing['role_scope'] : 'both';
            Db::run('UPDATE att_devices SET role_scope = ?, last_seen_at = ?, app_version = COALESCE(?, app_version) WHERE id = ?',
                [$newScope, Time::sql(), $appVersion, (int) $existing['id']]);
            return ['ok' => true, 'device_id' => (int) $existing['id'], 'device_uuid' => $uuid, 'role_scope' => $newScope, 'created' => false];
        }
        if (!Policy::bool('device_self_register', true)) {
            return ['ok' => false, 'reason_code' => 'DEVICE_NOT_REGISTERED',
                    'error' => 'This phone is not registered. Ask an admin to add it.'];
        }
        Db::run(
            'INSERT INTO att_devices (device_uuid, label, role_scope, app_version, last_seen_at, registered_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$uuid, substr(trim($label) !== '' ? trim($label) : 'Unnamed phone', 0, 100), $scope, $appVersion, Time::sql(), $userId]
        );
        $id = Db::lastId();
        Audit::record('device.register', 'att_device', $id, null,
            ['device_uuid' => $uuid, 'label' => $label, 'role_scope' => $scope], $userId);
        return ['ok' => true, 'device_id' => $id, 'device_uuid' => $uuid, 'role_scope' => $scope, 'created' => true];
    }

    public static function revokeDevice(int $deviceId, int $actorId, string $reason): bool
    {
        $st = Db::run(
            'UPDATE att_devices SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL',
            [Time::sql(), $actorId, substr($reason, 0, 255), $deviceId]
        );
        if ($st->rowCount() > 0) {
            Audit::record('device.revoke', 'att_device', $deviceId, null, ['reason' => $reason], $actorId, $reason);
            return true;
        }
        return false;
    }

    // --------------------------------------------------------------- events

    /**
     * Process one envelope.
     *
     * @param array<string,mixed> $env
     * @param bool $reconcile false while more of the same upload is still to come
     * @return array<string,mixed> receipt
     */
    public static function process(array $env, int $operatorId, bool $reconcile = true): array
    {
        $uuid = trim((string) ($env['event_uuid'] ?? ''));
        $type = strtoupper(trim((string) ($env['event_type'] ?? '')));

        if (!in_array($type, self::TYPES, true)) {
            return Receipts::reject($uuid, $type !== '' ? $type : 'UNKNOWN', 'BAD_EVENT_TYPE',
                'event_type must be GATE_IN, GATE_OUT or HOD_CONFIRM.');
        }

        $device   = self::resolveDevice($env['device_id'] ?? null);
        $deviceId = $device !== null ? (int) $device['id'] : 0;
        if ($device === null && trim((string) ($env['device_id'] ?? '')) !== '') {
            return Receipts::reject($uuid, $type, 'UNKNOWN_DEVICE', 'This device is not registered.');
        }

        $employeeId = (int) ($env['employee_id'] ?? 0);
        $code = trim((string) ($env['employee_code'] ?? ''));
        if ($employeeId <= 0 && $code !== '') {
            $employeeId = (int) (Employees::findByCodeOrQr($code)['id'] ?? 0);
        }

        $payload  = is_array($env['payload'] ?? null) ? $env['payload'] : [];
        $occurred = (string) ($env['occurred_at'] ?? '');
        $tz       = (string) ($env['timezone'] ?? '');

        /* TWO DIFFERENT QUESTIONS, kept apart.
             How wrong is this phone's clock?   device_now (its clock at the
                                                moment of upload) minus ours.
             How long did the event wait?       device_now minus occurred_at,
                                                both on the phone's own clock,
                                                so a wrong clock cancels out.
           An earlier version answered both with "occurred_at minus now". For a
           replayed event that is neither: it called a six-hour queue delay a
           six-hour clock error, and overflowed the column on a phone that had
           been offline for a month. */
        $parsed    = Time::parse($occurred, $tz);
        $deviceNow = Time::parse((string) ($env['device_now'] ?? ''), $tz);
        $skew      = $deviceNow !== null ? Time::skewMs($deviceNow) : null;
        $threshold = max(30000, Policy::int('clock_skew_warn_ms', 120000));
        $source    = 'online';
        if ($parsed !== null) {
            $waitedMs = $deviceNow !== null
                ? (int) round(((float) $deviceNow->format('U.u') - (float) $parsed->format('U.u')) * 1000)
                : -Time::skewMs($parsed);
            if ($waitedMs > $threshold) {
                $source = 'offline_replay';
            } elseif ($skew === null) {
                $skew = Time::skewMs($parsed);     // live scan, no device_now: the scan time IS the phone's clock
            }
        }

        if ($type === 'HOD_CONFIRM') {
            $receipt = HodService::confirm([
                'event_uuid'      => $uuid,
                'hod_user_id'     => $operatorId,
                'entry_cycle_id'  => (int) ($payload['entry_cycle_id'] ?? $env['entry_cycle_id'] ?? 0),
                'employee_id'     => $employeeId,
                'gate_event_uuid' => (string) ($payload['gate_event_uuid'] ?? $env['gate_event_uuid'] ?? ''),
                'device_id'       => $deviceId,
                'device_sequence' => $env['device_sequence'] ?? null,
                'device_time'     => $occurred,
                'timezone'        => $tz,
                'source'          => $source,
                'clock_skew_ms'   => $skew,
            ]);
        } else {
            $in = [
                'employee_id'     => $employeeId,
                'device_id'       => $deviceId,
                'operator_id'     => $operatorId,
                'event_uuid'      => $uuid,
                'device_sequence' => (int) ($env['device_sequence'] ?? 0),
                'device_time'     => $occurred,
                'timezone'        => $tz,
                'source'          => $source,
                'clock_skew_ms'   => $skew,
            ];
            $receipt = $type === 'GATE_IN' ? AttendanceService::gateIn($in) : AttendanceService::gateOut($in);
        }

        $first = ($receipt['receipt_status'] ?? '') !== Receipts::ALREADY_RECEIVED;
        if ($first) {
            self::remember($uuid, $env, $operatorId);
            if ($deviceId > 0) {
                Db::run('UPDATE att_devices SET last_seen_at = ?, app_version = COALESCE(?, app_version) WHERE id = ?',
                    [Time::sql(), isset($env['app_version']) ? substr((string) $env['app_version'], 0, 40) : null, $deviceId]);
            }
            $claimed = (int) ($env['actor_user_id'] ?? 0);
            if ($claimed > 0 && $claimed !== $operatorId) {
                $receipt['actor_mismatch'] = true;
                Audit::record('sync.actor_mismatch', 'att_sync_receipt', 0, null, [
                    'event_uuid' => $uuid, 'captured_by' => $claimed, 'uploaded_by' => $operatorId,
                ], $operatorId, 'event captured under one login and uploaded under another');
            }
        }

        if ($reconcile && $type === 'GATE_IN' && ($receipt['receipt_status'] ?? '') === Receipts::ACCEPTED && $employeeId > 0) {
            $receipt['reconciled'] = Reconciler::run([$employeeId]);
        }

        return $receipt;
    }

    /**
     * Process an upload from a phone's outbox.
     *
     * Events are applied in device_sequence order whatever order the JSON
     * lists them in: an OUT processed before its own IN would be refused as
     * NOT_INSIDE for no reason but array order.
     *
     * $more=true means the phone has further events queued behind this
     * request. Reconciliation waits for the last one — see Att\Reconciler for
     * why running it early produces silent wrong matches.
     *
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    public static function processBatch(array $events, int $operatorId, bool $more = false, ?array $allowedTypes = null, ?string $deviceNow = null): array
    {
        $max = max(1, Policy::int('sync_batch_max', 200));
        if (count($events) > $max) {
            return ['ok' => false, 'reason_code' => 'BATCH_TOO_LARGE',
                    'error' => "Send at most $max events per request.", 'max' => $max];
        }

        $events = array_values(array_filter($events, 'is_array'));
        usort($events, static fn(array $a, array $b) =>
            ((int) ($a['device_sequence'] ?? 0)) <=> ((int) ($b['device_sequence'] ?? 0)));

        $receipts = [];
        $summary  = [];
        foreach ($events as $env) {
            try {
                $t = strtoupper(trim((string) ($env['event_type'] ?? '')));
                if ($allowedTypes !== null && in_array($t, self::TYPES, true) && !in_array($t, $allowedTypes, true)) {
                    /* Not permitted to send this kind of event. Answered but
                       NOT stored as a receipt: a stored REJECTED is final, and
                       a scan must not be lost for good because a permission
                       box was unticked for an hour. The phone keeps it. */
                    $r = ['ok' => false, 'event_uuid' => (string) ($env['event_uuid'] ?? ''), 'event_kind' => $t,
                          'receipt_status' => 'RETRY', 'reason_code' => 'FORBIDDEN', 'server_event_id' => null,
                          'message' => 'This login is not permitted to record that. Ask an admin.'];
                } else {
                    if ($deviceNow !== null && !isset($env['device_now'])) {
                        $env['device_now'] = $deviceNow;
                    }
                    $r = self::process($env, $operatorId, false);
                }
            } catch (Throwable $e) {
                /* One event failing unexpectedly must not lose the answers to
                   the forty before it. Not stored as a receipt: the phone
                   keeps it queued and tries again. */
                error_log('[att-sync] ' . $e->getMessage());
                $r = [
                    'ok' => false, 'event_uuid' => (string) ($env['event_uuid'] ?? ''),
                    'receipt_status' => 'RETRY', 'reason_code' => 'SERVER_ERROR', 'server_event_id' => null,
                ];
            }
            $receipts[] = $r;
            $summary[$r['receipt_status']] = ($summary[$r['receipt_status']] ?? 0) + 1;
        }

        $out = ['ok' => true, 'receipts' => $receipts, 'summary' => $summary, 'server_time' => Time::iso(Time::nowUtc())];
        if (!$more) {
            $out['reconciled'] = Reconciler::run(null);
        }
        return $out;
    }

    private static function remember(string $uuid, array $env, int $operatorId): void
    {
        if (!Receipts::validUuid($uuid)) {
            return;
        }
        try {
            Db::run(
                'UPDATE att_sync_receipts SET request_json = ?, operator_id = ? WHERE event_uuid = ? AND request_json IS NULL',
                [json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $operatorId, $uuid]
            );
        } catch (Throwable $e) {
            error_log('[att-sync] could not keep the envelope: ' . $e->getMessage());
        }
    }

    /**
     * Re-run an event that was held for review, after HR has fixed the cause
     * (typically: assigned the missing shift).
     *
     * Only NEEDS_REVIEW events can be retried. An ACCEPTED or REJECTED event
     * is a settled decision; re-running it would be a way to rewrite history.
     */
    public static function retryReview(string $uuid, int $actorId, string $note): array
    {
        $row = Db::one(
            "SELECT event_uuid, request_json, operator_id, receipt_status, review_state
               FROM att_sync_receipts WHERE event_uuid = ?", [$uuid]
        );
        if ($row === null || $row['receipt_status'] !== Receipts::NEEDS_REVIEW || $row['review_state'] !== null) {
            return ['ok' => false, 'reason_code' => 'NOT_REVIEWABLE', 'error' => 'That event is not waiting for review.'];
        }
        $env = json_decode((string) $row['request_json'], true);
        if (!is_array($env)) {
            return ['ok' => false, 'reason_code' => 'NO_ENVELOPE', 'error' => 'The original event was not kept, so it cannot be re-run.'];
        }

        $original = json_decode((string) Db::value('SELECT response_json FROM att_sync_receipts WHERE event_uuid = ?', [$uuid]), true);
        Db::run('DELETE FROM att_sync_receipts WHERE event_uuid = ?', [$uuid]);
        try {
            // Under the ORIGINAL operator: HR is fixing the paperwork, not
            // claiming to have been at the gate.
            $receipt = self::process($env, (int) ($row['operator_id'] ?: $actorId), true);
        } catch (Throwable $e) {
            $receipt = null;
            error_log('[att-sync] retry failed: ' . $e->getMessage());
        }
        if ($receipt === null || ($receipt['receipt_status'] ?? '') === Receipts::NEEDS_REVIEW) {
            // Still not processable. Put the original answer back untouched so
            // it stays in the HR queue.
            Db::run('DELETE FROM att_sync_receipts WHERE event_uuid = ?', [$uuid]);
            Db::run(
                'INSERT INTO att_sync_receipts (event_uuid, event_kind, receipt_status, reason_code, response_json, request_json, operator_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uuid, (string) ($original['event_kind'] ?? ''), Receipts::NEEDS_REVIEW, $original['reason_code'] ?? null,
                 json_encode($original), json_encode($env), $row['operator_id']]
            );
            return ['ok' => false, 'reason_code' => (string) ($receipt['reason_code'] ?? 'STILL_NEEDS_REVIEW'),
                    'error' => (string) ($receipt['message'] ?? 'The event still cannot be processed.')];
        }

        Db::run('UPDATE att_sync_receipts SET review_state = \'retried\', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE event_uuid = ?',
            [$actorId, Time::sql(), $note, $uuid]);
        Db::run('INSERT INTO att_corrections (subject_type, subject_id, action, old_data, new_data, reason, actor_id) VALUES (?,?,?,?,?,?,?)',
            ['att_sync_receipt', 0, 'event.retry', json_encode($original), json_encode($receipt), $note, $actorId]);
        Audit::record('event.retry', 'att_sync_receipt', 0, $original, $receipt, $actorId, $note);

        return ['ok' => true, 'receipt' => $receipt];
    }

    // --------------------------------------------------------------- status

    /** What a phone needs to know about itself. */
    public static function status(?array $device, int $userId): array
    {
        $waiting = (int) Db::value(
            "SELECT COUNT(*) FROM att_hod_arrivals WHERE hod_user_id = ? AND status = 'pending_match'", [$userId]
        );
        return [
            'ok'          => true,
            'server_time' => Time::iso(Time::nowUtc()),
            'server_epoch_ms' => (int) round(microtime(true) * 1000),
            'device'      => $device === null ? null : [
                'id' => (int) $device['id'], 'label' => $device['label'], 'role_scope' => $device['role_scope'],
                'revoked' => $device['revoked_at'] !== null,
                'last_seen_at' => Time::iso(Time::fromDb($device['last_seen_at'])),
                'last_sequence' => $device === null ? null : (int) Db::value(
                    'SELECT GREATEST(
                        COALESCE((SELECT MAX(device_sequence) FROM att_events WHERE device_id = ?), 0),
                        COALESCE((SELECT MAX(device_sequence) FROM att_hod_arrivals WHERE device_id = ?), 0))',
                    [(int) $device['id'], (int) $device['id']]),
            ],
            'confirmations_waiting_for_gate' => $waiting,
            'policies' => [
                'duplicate_window_seconds'  => Policy::int('duplicate_window_seconds', 60),
                'sync_backlog_warn_minutes' => Policy::int('sync_backlog_warn_minutes', 120),
            ],
        ];
    }

    /**
     * What changed for this user since $cursor.
     *
     * For a HOD: the confirmations of theirs that were waiting and have since
     * been matched, sent to HR, or set aside — so the phone can stop showing
     * "waiting" — and the current list of entries owing confirmation.
     */
    public static function changes(?string $cursor, int $userId): array
    {
        $since = Time::parse($cursor) ?? Time::nowUtc()->modify('-7 days');
        $now   = Time::nowUtc();

        $resolved = Db::all(
            "SELECT event_uuid, status, entry_cycle_id, match_method, matched_at, resolved_at
               FROM att_hod_arrivals
              WHERE hod_user_id = ?
                AND status <> 'pending_match'
                AND COALESCE(resolved_at, matched_at, received_at) >= ?
              ORDER BY id DESC LIMIT 500",
            [$userId, Time::sql($since)]
        );

        return [
            'ok'       => true,
            'cursor'   => $now->format('Y-m-d\TH:i:s.u\Z'),
            'confirmations' => $resolved,
            'pending'  => HodService::pending(Roles::departmentScope($userId, Roles::HOD)),
        ];
    }
}
