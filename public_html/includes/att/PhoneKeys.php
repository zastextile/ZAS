<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;

/**
 * "REMEMBER THIS PHONE" — a gate or HOD phone signs itself back in.
 *
 * The phone app is used all day (and all night at the gate). The server's
 * sign-in ends when the app is closed or after eight quiet hours, and the
 * guard then had to type the email and password again — again and again.
 *
 * After a normal sign-in the phone now asks for a KEY: 32 random bytes,
 * shown to the phone once. Only its SHA-256 is stored. When the server says
 * "signed out", the phone sends the key and is signed in again, silently.
 * The PIN on the phone still locks the app; this only replaces retyping the
 * password.
 *
 * A key is refused — and the phone asks for a full sign-in — when:
 *   · it has expired (Settings: phone_remember_days after it was LAST used);
 *   · the person's password has changed since it was made;
 *   · the person is deactivated, or no longer holds the gate or HOD job;
 *   · the phone has been withdrawn in Setup › Phones;
 *   · the phone signed out (its keys are revoked).
 * Every use is rate limited by phone and by address, like a password.
 */
final class PhoneKeys
{
    /** The password fingerprint, the same one includes/auth.php keeps on a session. */
    public static function pwFp(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    private static function days(): int
    {
        return max(1, min(90, Policy::int('phone_remember_days', 30)));
    }

    /** A new key for this person on this phone; the phone's older keys for them stop working. */
    public static function issue(int $userId, string $deviceUuid): array
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $deviceUuid)) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'This phone has no valid id.'];
        }
        $user = Db::one('SELECT id, password_hash FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if ($user === null) {
            return ['ok' => false, 'reason_code' => 'NOT_FOUND', 'error' => 'No such active user.'];
        }
        $now = Time::nowUtc();
        $token = bin2hex(random_bytes(32));
        $expires = $now->modify('+' . self::days() . ' days');
        Db::tx(static function () use ($userId, $deviceUuid, $token, $user, $now, $expires): void {
            Db::run('UPDATE att_device_tokens SET revoked_at = ? WHERE device_uuid = ? AND user_id = ? AND revoked_at IS NULL',
                [Time::sql($now), $deviceUuid, $userId]);
            Db::run('INSERT INTO att_device_tokens (user_id, device_uuid, token_hash, pw_fp, expires_at) VALUES (?, ?, ?, ?, ?)',
                [$userId, $deviceUuid, hash('sha256', $token), self::pwFp((string) $user['password_hash']), Time::sql($expires)]);
        });
        Audit::record('auth.phone_key_issued', 'user', $userId, null, ['device_uuid' => $deviceUuid, 'expires_at' => Time::sql($expires)], $userId);
        return ['ok' => true, 'token' => $token, 'expires_at' => Time::iso($expires), 'days' => self::days()];
    }

    /**
     * The user a key signs in, or a refusal. On success the key's expiry moves
     * on (it lasts phone_remember_days from its last use).
     *
     * @return array{ok:bool,user_id?:int,reason_code?:string,error?:string}
     */
    public static function check(string $deviceUuid, string $token): array
    {
        $no = ['ok' => false, 'reason_code' => 'KEY_REFUSED', 'error' => 'This phone needs a full sign-in.'];
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $deviceUuid) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return $no;
        }
        $row = Db::one('SELECT * FROM att_device_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        $now = Time::nowUtc();
        if ($row === null || !hash_equals((string) $row['device_uuid'], $deviceUuid) || $row['revoked_at'] !== null
            || new DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')) <= $now) {
            return $no;
        }
        $uid = (int) $row['user_id'];
        $revoke = static function (string $why) use ($row, $now, $uid, $no): array {
            Db::run('UPDATE att_device_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Time::sql($now), (int) $row['id']]);
            Audit::record('auth.phone_key_refused', 'user', $uid, null, ['device_uuid' => $row['device_uuid'], 'why' => $why], null);
            return $no;
        };
        $user = Db::one('SELECT id, password_hash FROM users WHERE id = ? AND is_active = 1', [$uid]);
        if ($user === null) {
            return $revoke('user not active');
        }
        if (!hash_equals((string) $row['pw_fp'], self::pwFp((string) $user['password_hash']))) {
            return $revoke('password changed');
        }
        $dev = Db::one('SELECT revoked_at FROM att_devices WHERE device_uuid = ?', [$deviceUuid]);
        if ($dev !== null && $dev['revoked_at'] !== null) {
            return $revoke('phone withdrawn');
        }
        if (!Roles::can('attgate', 'c', $uid) && !Roles::can('atthod', 'c', $uid)) {
            return $revoke('no gate or HOD job');
        }
        Db::run('UPDATE att_device_tokens SET last_used_at = ?, expires_at = ? WHERE id = ?',
            [Time::sql($now), Time::sql($now->modify('+' . self::days() . ' days')), (int) $row['id']]);
        return ['ok' => true, 'user_id' => $uid];
    }

    /** The phone signed out, or was withdrawn: its keys stop working. */
    public static function revokeDevice(string $deviceUuid, ?int $userId = null): int
    {
        $sql = 'UPDATE att_device_tokens SET revoked_at = ? WHERE device_uuid = ? AND revoked_at IS NULL';
        $p = [Time::sql(Time::nowUtc()), $deviceUuid];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $p[] = $userId;
        }
        return Db::run($sql, $p)->rowCount();
    }
}
