<?php
declare(strict_types=1);

namespace Att;

/**
 * Every tunable the module has.
 *
 * Blueprint §10 lists ten things IT must confirm before production and says
 * the implementation "should make these configurable rather than hard-code
 * assumptions". So none of them are constants in PHP: they are rows, with
 * defaults that are safe on installation day and an admin screen to change
 * them.
 *
 * The defaults below are also the answer to "what happens if the policy row is
 * missing" — a half-installed system falls back to the documented default
 * rather than to zero, which for duplicate_window_seconds would mean no
 * duplicate protection at all.
 */
final class Policy
{
    /** @var array<string,string> */
    private const DEFAULTS = [
        'duplicate_window_seconds'  => '60',
        'default_shift_id'          => '',
        'auto_shift_on_gate_in'     => '1',
        'hod_match_window_minutes'  => '240',
        'sync_backlog_warn_minutes' => '120',
        'photo_retention_days'      => '90',
        'photo_max_bytes'           => '5242880',
        'gatekeeper_may_verify_ot'  => '0',
        'clock_skew_warn_ms'        => '120000',
        'device_self_register'      => '1',
        'stale_open_cycle_hours'    => '16',
        'sync_batch_max'            => '200',
        'ot_request_days_ahead'     => '14',
        'ot_request_days_back'      => '1',
        'ot_max_minutes'            => '720',
        'ot_decision_window_days'   => '0',
        'ot_verify_from_next_day'   => '1',
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, ?string $default = null): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT policy_key, value FROM att_policies') as $r) {
                    self::$cache[(string) $r['policy_key']] = (string) $r['value'];
                }
            } catch (\Throwable) {
                // Table not migrated yet: fall through to defaults rather than
                // taking the whole request down.
                self::$cache = [];
            }
        }
        $v = self::$cache[$key] ?? null;
        if ($v === null || $v === '') {
            return $v === '' && array_key_exists($key, self::$cache)
                ? ''                                   // explicitly blank, e.g. no default shift chosen yet
                : (string) ($default ?? self::DEFAULTS[$key] ?? '');
        }
        return $v;
    }

    public static function int(string $key, ?int $default = null): int
    {
        $raw = self::get($key, $default === null ? null : (string) $default);
        return $raw === '' ? (int) ($default ?? 0) : (int) $raw;
    }

    public static function bool(string $key, ?bool $default = null): bool
    {
        $raw = self::get($key, $default === null ? null : ($default ? '1' : '0'));
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value, ?int $actorId = null, string $type = 'string'): void
    {
        $before = self::get($key);
        Db::run(
            'INSERT INTO att_policies (policy_key, value, value_type, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
            [$key, $value, $type, $actorId]
        );
        self::flush();
        Audit::record('policy.update', 'att_policy', 0, ['key' => $key, 'value' => $before], ['key' => $key, 'value' => $value], $actorId);
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        self::get('duplicate_window_seconds'); // warms the cache
        return array_merge(self::DEFAULTS, self::$cache ?? []);
    }
}
