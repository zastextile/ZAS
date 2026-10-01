<?php
/*
  FX rates (base = PKR), entered manually by an admin on the Settings page
  and cached in a small DB table. No external API, no outbound HTTP calls
  of any kind — every other page (Dashboard, Costing Print, AI Check) just
  reads whatever was last saved here. If nothing has been saved yet, it
  falls back to config/config.php's fx_per_pkr.
*/

function fx_ensure_table(): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS fx_rates (
            currency VARCHAR(10) NOT NULL PRIMARY KEY,
            rate_per_pkr DECIMAL(18,10) NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/* Currencies we care about, excluding PKR itself (always 1). */
function fx_symbols(array $config): array {
    $list = array_map('strtoupper', $config['costing_currencies'] ?? ['PKR', 'USD', 'EUR']);
    return array_values(array_diff(array_unique($list), ['PKR']));
}

/* Main entry point: returns a ['PKR'=>1, 'USD'=>..., ...] map (values are
   "units of currency per 1 PKR"), read from the DB only. Rates only change
   when an admin saves new values on the Settings page. */
function fx_get_rates(): array {
    global $config;
    $fallback = $config['fx_per_pkr'] ?? ['PKR' => 1];
    $rates = ['PKR' => 1.0];
    $needed = fx_symbols($config);

    try {
        $rows = db()->query("SELECT currency, rate_per_pkr, updated_at FROM fx_rates")->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        $rates[$r['currency']] = (float)$r['rate_per_pkr'];
    }

    // Fill anything not yet saved from the config fallback so nothing breaks.
    foreach ($needed as $cur) {
        if (!isset($rates[$cur]) && isset($fallback[$cur])) $rates[$cur] = (float)$fallback[$cur];
    }
    return $rates;
}

function fx_last_synced_at(): ?string {
    try {
        $ts = db()->query("SELECT MAX(updated_at) FROM fx_rates")->fetchColumn();
        return $ts ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* Save admin-entered rates. $pkrPerUnit is ['USD'=>275.0, 'EUR'=>320.0, ...]
   i.e. "1 <currency> = X PKR" — the natural way a human types a rate.
   Internally stored/used as rate_per_pkr = 1/X (units of currency per 1
   PKR), matching config.php's fx_per_pkr format. Returns
   ['ok'=>bool,'message'=>string]. */
function fx_save_rates(array $pkrPerUnit): array {
    global $config;
    $allowed = fx_symbols($config);
    $saved = [];
    try {
        $stmt = db()->prepare("INSERT INTO fx_rates (currency, rate_per_pkr, updated_at) VALUES (?, ?, NOW())
                                ON DUPLICATE KEY UPDATE rate_per_pkr = VALUES(rate_per_pkr), updated_at = VALUES(updated_at)");
        foreach ($pkrPerUnit as $cur => $pkrValue) {
            $cur = strtoupper(trim((string)$cur));
            if (!in_array($cur, $allowed, true)) continue;
            $pkrValue = (float)$pkrValue;
            if ($pkrValue <= 0) continue;
            $stmt->execute([$cur, 1 / $pkrValue]);
            $saved[] = $cur;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not save rates: ' . $e->getMessage()];
    }
    if (!$saved) return ['ok' => false, 'message' => 'No valid rates were submitted (values must be greater than 0).'];
    return ['ok' => true, 'message' => 'Saved rates for ' . implode(', ', $saved) . '.'];
}
