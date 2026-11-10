<?php
declare(strict_types=1);

namespace Att;

use Throwable;

/**
 * The answer a handset gets for every event it sends, and the memory of it.
 *
 * API_CONTRACT: {event_uuid, receipt_status, server_event_id, reason_code}.
 * Shared by gate events (M2) and HOD confirmations (M3), and later by the
 * sync endpoint (M4) — one implementation, so the three can never disagree
 * about what "already received" means.
 *
 * Every receipt is stored verbatim in att_sync_receipts. A phone whose
 * connection drops after the server commits WILL send the event again, and it
 * must get the same answer back — including the same rejection. Re-evaluating
 * the event fresh could now give the opposite answer and tell the phone that
 * a refused scan worked, or that a successful one failed.
 */
final class Receipts
{
    public const ACCEPTED         = 'ACCEPTED';
    public const ALREADY_RECEIVED = 'ALREADY_RECEIVED';
    public const PENDING_MATCH    = 'PENDING_MATCH';
    public const REJECTED         = 'REJECTED';
    public const NEEDS_REVIEW     = 'NEEDS_REVIEW';

    /** The stored answer for a UUID, re-labelled as a replay. */
    public static function find(string $uuid): ?array
    {
        $row = Db::one('SELECT response_json FROM att_sync_receipts WHERE event_uuid = ?', [$uuid]);
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) $row['response_json'], true);
        if (!is_array($decoded)) {
            return null;
        }
        $decoded['receipt_status'] = self::ALREADY_RECEIVED;
        return $decoded;
    }

    public static function accept(string $uuid, string $kind, ?int $serverId, ?int $deviceId, array $extra = []): array
    {
        return self::store(self::shape($uuid, $kind, self::ACCEPTED, $serverId, null, true, $extra), $deviceId);
    }

    public static function pending(string $uuid, string $kind, ?int $serverId, string $reason, string $message, ?int $deviceId, array $extra = []): array
    {
        return self::store(self::shape($uuid, $kind, self::PENDING_MATCH, $serverId, $reason, true,
            array_merge(['message' => $message], $extra)), $deviceId);
    }

    public static function reject(string $uuid, string $kind, string $reason, string $message, ?int $deviceId = null, array $extra = []): array
    {
        return self::store(self::shape($uuid, $kind, self::REJECTED, null, $reason, false,
            array_merge(['message' => $message], $extra)), $deviceId);
    }

    public static function review(string $uuid, string $kind, string $reason, string $message, ?int $deviceId = null, array $extra = []): array
    {
        return self::store(self::shape($uuid, $kind, self::NEEDS_REVIEW, null, $reason, true,
            array_merge(['message' => $message], $extra)), $deviceId);
    }

    /** For the race where the duplicate insert lost and no receipt exists yet. */
    public static function alreadyReceived(string $uuid, string $kind): array
    {
        return self::shape($uuid, $kind, self::ALREADY_RECEIVED, null, null, true);
    }

    private static function shape(string $uuid, string $kind, string $status, ?int $serverId, ?string $reason, bool $ok, array $extra = []): array
    {
        return array_merge([
            'ok'              => $ok,
            'event_uuid'      => $uuid,
            'event_kind'      => $kind,
            'receipt_status'  => $status,
            'server_event_id' => $serverId,
            'reason_code'     => $reason,
        ], $extra);
    }

    private static function store(array $receipt, ?int $deviceId): array
    {
        /* A malformed UUID is answered but not stored: it cannot be the key of
           a later replay, and storing it would let garbage occupy the table. */
        if (!self::validUuid((string) $receipt['event_uuid'])) {
            return $receipt;
        }
        try {
            Db::run(
                'INSERT INTO att_sync_receipts
                   (event_uuid, device_id, event_kind, receipt_status, reason_code, server_event_id, response_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE event_uuid = event_uuid',
                [
                    $receipt['event_uuid'],
                    $deviceId ?: null,
                    $receipt['event_kind'] ?? '',
                    $receipt['receipt_status'],
                    $receipt['reason_code'] ?? null,
                    $receipt['server_event_id'] ?? null,
                    json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (Throwable $e) {
            error_log('[att-receipt] ' . $e->getMessage());
        }
        return $receipt;
    }

    public static function validUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid);
    }

    /**
     * Shared device gate for every event kind.
     *
     * Returns null when the device may send this kind of event, or a rejection
     * receipt when it may not. role_scope is enforced: a handset registered for
     * the gate cannot post HOD confirmations, and a HOD handset cannot record
     * gate movements — a stolen HOD phone must not be able to clock people in.
     *
     * @param 'gate'|'hod' $needs
     */
    public static function checkDevice(?int $deviceId, string $needs, string $uuid, string $kind, bool $required): ?array
    {
        if ($deviceId === null || $deviceId <= 0) {
            return $required
                ? self::reject($uuid, $kind, 'VALIDATION', 'A registered device is required.', null)
                : null;
        }
        $device = Db::one('SELECT id, role_scope, revoked_at FROM att_devices WHERE id = ?', [$deviceId]);
        if ($device === null) {
            return self::reject($uuid, $kind, 'UNKNOWN_DEVICE', 'This device is not registered.', null);
        }
        /* Checked on every event, not only at sign-in: a handset reported lost
           at 10am must stop being able to post at 10:01, without waiting for
           its session to expire. */
        if ($device['revoked_at'] !== null) {
            return self::reject($uuid, $kind, 'DEVICE_REVOKED', 'This device has been withdrawn from service.', $deviceId);
        }
        $scope = (string) $device['role_scope'];
        if ($scope !== 'both' && $scope !== $needs) {
            return self::reject($uuid, $kind, 'DEVICE_ROLE_MISMATCH',
                sprintf('This device is registered for %s use, not %s.', $scope, $needs), $deviceId);
        }
        return null;
    }
}
