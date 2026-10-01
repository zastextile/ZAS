<?php
/*
  FX rates (base = PKR) via the free Frankfurter API, cached in a small DB
  table. Sync is MANUAL ONLY — an admin clicks "Refresh Now" on the
  Settings page (rates only move a little every few weeks, so there's no
  automatic background sync). If a sync ever fails (no internet, API down,
  host blocks outbound HTTPS, etc.) everything silently falls back to the
  last good rate in the DB, and if the DB has never synced yet, to
  config/config.php's fx_per_pkr — the app never breaks because of this.
*/

const FX_API_URL = 'https://api.frankfurter.dev/v1/latest?base=PKR&symbols=%s';

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

/* Fetch live rates from Frankfurter and store them. Never throws — always
   returns ['ok'=>bool,'message'=>string,'rates'=>array|null]. */
function fx_sync_live(array $config): array {
    $symbols = fx_symbols($config);
    if (!$symbols) return ['ok' => false, 'message' => 'No currencies configured.', 'rates' => null];

    $url = sprintf(FX_API_URL, urlencode(implode(',', $symbols)));
    try {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'curl extension not available on this server.', 'rates' => null];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ZAS-Textile-Dashboard/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err) return ['ok' => false, 'message' => 'Network error: ' . $err, 'rates' => null];
        if ($code !== 200) return ['ok' => false, 'message' => "FX API returned HTTP $code.", 'rates' => null];

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['rates']) || !is_array($data['rates'])) {
            return ['ok' => false, 'message' => 'Unexpected FX API response.', 'rates' => null];
        }

        $rates = [];
        $stmt = db()->prepare("INSERT INTO fx_rates (currency, rate_per_pkr, updated_at) VALUES (?, ?, NOW())
                                ON DUPLICATE KEY UPDATE rate_per_pkr = VALUES(rate_per_pkr), updated_at = VALUES(updated_at)");
        foreach ($symbols as $cur) {
            if (!isset($data['rates'][$cur]) || !is_numeric($data['rates'][$cur])) continue;
            $rate = (float)$data['rates'][$cur];
            if ($rate <= 0) continue;
            $stmt->execute([$cur, $rate]);
            $rates[$cur] = $rate;
        }
        if (!$rates) return ['ok' => false, 'message' => 'FX API response had no usable rates.', 'rates' => null];
        return ['ok' => true, 'message' => 'Synced ' . implode(', ', array_keys($rates)) . ' from Frankfurter.', 'rates' => $rates];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'FX sync error: ' . $e->getMessage(), 'rates' => null];
    }
}

/* Main entry point: returns a ['PKR'=>1, 'USD'=>..., ...] map, read from the
   DB cache only — never triggers a live API call by itself. Rates only
   change when an admin clicks "Refresh Now" on the Settings page. */
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

    // Fill anything not yet synced from the config fallback so nothing breaks.
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

/* Used by the manual "Refresh Now" button — always attempts a live fetch
   regardless of how fresh the cache is. */
function fx_force_refresh(): array {
    global $config;
    return fx_sync_live($config);
}
