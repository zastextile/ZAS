<?php
/*
  WEIGHT — a module of its own.

  afnan's plan: "weight separately … profile name and number, linked with
  each line of every shipment, summed up automatically from what is already
  packed." Built from afnan's Weight Profiles demo.

  A PROFILE is the weight of ONE finished unit (a piece, a set, a metre…),
  made of material types — Fabric, PVC / Polybag, Polyester, Accessories,
  Miscellaneous — in whole grams. An optional unit target is a fixed limit
  the types must add up to exactly; a weighed sample carton can set that
  target instead (net kg × 1,000 ÷ pieces), and its gross − net is the
  empty package, offered later as the empty package weight.

  A SHIPMENT gets one profile per invoice line, linked once however many
  serial ranges the line has. The quantity is what Smart Packing (and the
  desktop packing list) has already packed — never typed again.

    net   = profile grams × packed quantity ÷ 1,000
    gross = net + empty package weight × packages, per kind of package

  APPLY writes the result: one figure per invoice line, the shipment's
  totals, and each packing row's share, so every print and screen that
  already reads packing rows or shipment totals shows the same numbers.
  The final weight may be overruled within ±10% of what was worked out.

  NOTHING CHANGES BY ITSELF. A profile edited later, or packing changed
  after Apply, is flagged "apply again"; the shipment keeps its applied
  figures until somebody presses Apply.
*/

const WT_SCHEMA_VERSION = '1';
const WT_TYPES = ['Fabric', 'PVC / Polybag', 'Polyester', 'Accessories', 'Miscellaneous'];
const WT_UNITS = ['pcs', 'sets', 'metres', 'rolls', 'units'];
const WT_PKG_TYPES = ['Carton', 'Bale', 'Roll', 'Bag', 'Other'];
const WT_TOLERANCE_PCT = 10;

function wt_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $v = db()->query("SELECT v FROM exp_meta WHERE k='wt_schema_version'")->fetchColumn();
        if ($v !== false && (string)$v === WT_SCHEMA_VERSION) return;
    } catch (Throwable $e) {}
    $x = function (string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} };

    $x("CREATE TABLE IF NOT EXISTS exp_meta (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NULL, updated_at DATETIME NULL)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* one finished unit */
    $x("CREATE TABLE IF NOT EXISTS weight_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference VARCHAR(20) NOT NULL,
        name VARCHAR(120) NOT NULL,
        unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
        notes VARCHAR(250) NOT NULL DEFAULT '',
        version INT NOT NULL DEFAULT 1,
        unit_net_grams INT NOT NULL DEFAULT 0,
        sample_qty INT NULL, sample_gross_kg DECIMAL(12,3) NULL, sample_net_kg DECIMAL(12,3) NULL,
        sample_pkg_type VARCHAR(40) NOT NULL DEFAULT '',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_at DATETIME NULL, updated_by INT NULL, updated_at DATETIME NULL,
        UNIQUE KEY uq_wp_ref (reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS weight_profile_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id INT NOT NULL,
        line_no INT NOT NULL DEFAULT 0,
        category VARCHAR(40) NOT NULL,
        description VARCHAR(160) NOT NULL DEFAULT '',
        grams INT NOT NULL DEFAULT 0,
        KEY idx_wpm (profile_id, line_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* which profile an invoice line uses; product_key lets the next
       shipment of the same product suggest it */
    $x("CREATE TABLE IF NOT EXISTS weight_line_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        invoice_item_id INT NOT NULL,
        profile_id INT NOT NULL,
        product_key VARCHAR(160) NOT NULL DEFAULT '',
        linked_by INT NULL, linked_at DATETIME NULL,
        UNIQUE KEY uq_wll (shipment_id, invoice_item_id),
        KEY idx_wll_prod (product_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* the empty package, once per kind for a shipment */
    $x("CREATE TABLE IF NOT EXISTS weight_tare (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        pkg_type VARCHAR(60) NOT NULL,
        kg DECIMAL(10,3) NOT NULL DEFAULT 0,
        updated_by INT NULL, updated_at DATETIME NULL,
        UNIQUE KEY uq_wt (shipment_id, pkg_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* what was applied, line by line — the figures documents use */
    $x("CREATE TABLE IF NOT EXISTS weight_applied (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        invoice_item_id INT NOT NULL,
        profile_id INT NOT NULL,
        profile_version INT NOT NULL DEFAULT 1,
        unit_grams INT NOT NULL DEFAULT 0,
        qty DECIMAL(16,3) NOT NULL DEFAULT 0,
        packages INT NOT NULL DEFAULT 0,
        pkg_key VARCHAR(255) NOT NULL DEFAULT '',
        net_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        gross_kg DECIMAL(14,3) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_wa (shipment_id, invoice_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $x("CREATE TABLE IF NOT EXISTS weight_shipment (
        shipment_id INT NOT NULL PRIMARY KEY,
        calc_net DECIMAL(14,3) NOT NULL DEFAULT 0, calc_gross DECIMAL(14,3) NOT NULL DEFAULT 0,
        final_net DECIMAL(14,3) NOT NULL DEFAULT 0, final_gross DECIMAL(14,3) NOT NULL DEFAULT 0,
        tare_key VARCHAR(255) NOT NULL DEFAULT '',
        applied_by INT NULL, applied_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        db()->prepare("INSERT INTO exp_meta (k, v, updated_at) VALUES ('wt_schema_version', ?, NOW())
                       ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()")->execute([WT_SCHEMA_VERSION]);
    } catch (Throwable $e) {}
}

/* ------------------------------------------------------------- permission

   "Allowed for the same users who already have authority for the packing
   list" — profiles and shipment weight alike. A locked shipment is the
   admin's alone, as everywhere. Weight may still be applied after packing
   is finished: it is a separate step. */
function wt_may_use(): bool
{
    return function_exists('pack_may_use') ? pack_may_use() : false;
}
function wt_may_edit(array $shipment): bool
{
    if (function_exists('is_production_staff') && is_production_staff()) return false;
    if (in_array((string)($shipment['status'] ?? ''), ['approved_locked', 'final'], true)) return is_admin();
    return true;
}

/* ----------------------------------------------------------------- units

   The invoice says "Pcs", "PCS", "Set", "Mtr"; a profile is made per pcs,
   sets, metres, rolls or units. Anything else counts as units. */
function wt_unit_of(string $u): string
{
    $k = strtolower(preg_replace('~[^a-z]~i', '', $u));
    if (in_array($k, ['pc', 'pcs', 'piece', 'pieces', 'nos', 'no'], true)) return 'pcs';
    if (in_array($k, ['set', 'sets'], true)) return 'sets';
    if (in_array($k, ['m', 'mtr', 'mtrs', 'meter', 'meters', 'metre', 'metres'], true)) return 'metres';
    if (in_array($k, ['roll', 'rolls'], true)) return 'rolls';
    return $k === '' ? 'pcs' : 'units';
}

/* --------------------------------------------------------------- profiles */
function wt_profiles(bool $withMaterials = true): array
{
    try {
        $rows = db()->query("SELECT * FROM weight_profiles ORDER BY reference")->fetchAll();
    } catch (Throwable $e) { return []; }
    $mats = [];
    if ($withMaterials && $rows) {
        try {
            foreach (db()->query("SELECT * FROM weight_profile_materials ORDER BY profile_id, line_no, id")->fetchAll() as $m)
                $mats[(int)$m['profile_id']][] = $m;
        } catch (Throwable $e) {}
    }
    $used = wt_profiles_in_use();
    return array_map(static fn($p) => wt_profile_out($p, $mats[(int)$p['id']] ?? [], isset($used[(int)$p['id']])), $rows);
}
function wt_profile(int $id): ?array
{
    try {
        $s = db()->prepare("SELECT * FROM weight_profiles WHERE id=?");
        $s->execute([$id]);
        $p = $s->fetch();
        if (!$p) return null;
        $m = db()->prepare("SELECT * FROM weight_profile_materials WHERE profile_id=? ORDER BY line_no, id");
        $m->execute([$id]);
        return wt_profile_out($p, $m->fetchAll(), isset(wt_profiles_in_use()[$id]));
    } catch (Throwable $e) { return null; }
}
function wt_profiles_in_use(): array
{
    $u = [];
    try {
        foreach (db()->query("SELECT DISTINCT profile_id FROM weight_line_links UNION SELECT DISTINCT profile_id FROM weight_applied")->fetchAll() as $r)
            $u[(int)$r['profile_id']] = true;
    } catch (Throwable $e) {}
    return $u;
}
function wt_profile_out(array $p, array $mats, bool $used): array
{
    $cal = null;
    if ($p['sample_qty'] !== null && (int)$p['sample_qty'] > 0) {
        $g = $p['sample_gross_kg'] !== null ? (float)$p['sample_gross_kg'] : null;
        $n = $p['sample_net_kg'] !== null ? (float)$p['sample_net_kg'] : null;
        $cal = ['quantity' => (int)$p['sample_qty'], 'grossKg' => $g, 'netKg' => $n,
                'packageType' => (string)$p['sample_pkg_type'],
                'packageTareGrams' => ($g !== null && $n !== null) ? (int)round(($g - $n) * 1000) : null];
    }
    return [
        'id' => (int)$p['id'], 'reference' => (string)$p['reference'], 'name' => (string)$p['name'],
        'unit' => (string)$p['unit'], 'notes' => (string)$p['notes'], 'version' => (int)$p['version'],
        'unitNetGrams' => (int)$p['unit_net_grams'], 'active' => (bool)$p['active'], 'inUse' => $used,
        'materials' => array_map(static fn($m) => ['category' => (string)$m['category'],
            'description' => (string)$m['description'], 'grams' => (int)$m['grams']], $mats),
        'calibration' => $cal,
    ];
}

/* A whole number of grams, as the demo asks: no commas, no decimals. */
function wt_grams($v): ?int
{
    $t = trim((string)$v);
    if ($t === '') return null;
    if (!preg_match('~^\d+$~', $t)) return -1;
    $n = (int)$t;
    return ($n > 100000000) ? -1 : $n;
}
function wt_kg_in($v): ?float
{
    $t = trim((string)$v);
    if ($t === '') return null;
    if (!preg_match('~^\d*\.?\d{0,3}$~', $t) || !preg_match('~\d~', $t)) return -1.0;
    $n = (float)$t;
    return ($n <= 0 || $n > 100000) ? -1.0 : $n;
}

/* SAVE — checked here exactly as the screen checks it, because the
   screen is a browser and anything can be sent. Returns [ok, msg, profile]. */
function wt_profile_save(array $in): array
{
    $id    = (int)($in['id'] ?? 0);
    $name  = trim((string)($in['name'] ?? ''));
    $unit  = (string)($in['unit'] ?? 'pcs');
    $notes = mb_substr(trim((string)($in['notes'] ?? '')), 0, 250);
    if ($name === '') return [false, 'Enter a profile name.', null];
    if (mb_strlen($name) > 120) return [false, 'Keep the profile name within 120 characters.', null];
    if (!in_array($unit, WT_UNITS, true)) return [false, 'Choose a quantity unit.', null];

    $mats = [];
    foreach ((array)($in['materials'] ?? []) as $m) {
        if (!is_array($m)) continue;
        $cat = (string)($m['category'] ?? '');
        $g = wt_grams($m['grams'] ?? '');
        if (!in_array($cat, WT_TYPES, true)) return [false, 'Choose a valid material type.', null];
        if ($g === -1) return [false, 'Material weight: enter whole grams, without commas or decimals.', null];
        $desc = trim((string)($m['description'] ?? ''));
        if (mb_strlen($desc) > 160) return [false, 'Keep a material description within 160 characters.', null];
        if ($g === null || $g === 0) continue;               // 0 = skip, as on the screen
        $mats[] = ['category' => $cat, 'description' => $desc, 'grams' => $g];
    }
    if (count($mats) > 100) return [false, 'Use up to 100 material lines per profile.', null];
    if (!$mats) return [false, 'Enter at least one material weight.', null];
    $total = array_sum(array_column($mats, 'grams'));

    /* the sample reading, when given, sets the target */
    $sq = trim((string)($in['sampleQty'] ?? ''));
    $sg = wt_kg_in($in['sampleGross'] ?? '');
    $sn = wt_kg_in($in['sampleNet'] ?? '');
    $spt = (string)($in['samplePkgType'] ?? '');
    if ($sg === -1.0) return [false, 'Sample gross: enter a positive kg value with up to 3 decimals.', null];
    if ($sn === -1.0) return [false, 'Sample net: enter a positive kg value with up to 3 decimals.', null];
    if ($spt !== '' && !in_array($spt, WT_PKG_TYPES, true)) return [false, 'Choose the sample package type.', null];
    $target = null; $cal = null;
    if ($sg !== null || $sn !== null) {
        if (!preg_match('~^\d+$~', $sq) || (int)$sq < 1 || (int)$sq > 1000000) return [false, 'Enter a whole quantity from 1 to 1,000,000.', null];
        if ($sg !== null && $sn !== null && $sn > $sg) return [false, 'Sample net cannot exceed gross.', null];
        if ($sn !== null) {
            $ug = round($sn * 1000) / (int)$sq;
            if (abs($ug - round($ug)) > 1e-9) return [false, 'This reading gives a fractional gram per unit. Check quantity, or use a direct whole-gram unit target.', null];
            $target = (int)round($ug);
        }
        $cal = [(int)$sq, $sg, $sn, $spt];
    }
    if ($target === null) {
        $tg = wt_grams($in['target'] ?? '');
        if ($tg === -1) return [false, 'Unit target: enter whole grams, without commas or decimals.', null];
        if ($tg !== null && $tg <= 0) return [false, 'Unit target must be greater than zero.', null];
        $target = $tg;
    }
    if ($target !== null && $target !== $total) {
        $d = $target - $total;
        return [false, $d < 0 ? number_format(-$d) . ' g above target. Correct the materials.'
                              : number_format($d) . ' g remaining. Complete the material total.', null];
    }

    try {
        $dup = db()->prepare("SELECT id FROM weight_profiles WHERE LOWER(TRIM(name))=? AND id<>?");
        $dup->execute([mb_strtolower($name), $id]);
        if ($dup->fetchColumn()) return [false, 'This profile name already exists. Use a different name.', null];

        $uid = (int)(current_user()['id'] ?? 0);
        db()->beginTransaction();
        if ($id > 0) {
            $old = db()->prepare("SELECT id FROM weight_profiles WHERE id=?");
            $old->execute([$id]);
            if (!$old->fetchColumn()) { db()->rollBack(); return [false, 'Profile not found.', null]; }
            db()->prepare("UPDATE weight_profiles SET name=?, unit=?, notes=?, version=version+1, unit_net_grams=?,
                           sample_qty=?, sample_gross_kg=?, sample_net_kg=?, sample_pkg_type=?, updated_by=?, updated_at=NOW()
                           WHERE id=?")
                ->execute([$name, $unit, $notes, $total, $cal[0] ?? null, $cal[1] ?? null, $cal[2] ?? null, $cal[3] ?? '', $uid, $id]);
        } else {
            $max = 0;
            foreach (db()->query("SELECT reference FROM weight_profiles")->fetchAll() as $r)
                if (preg_match('~^WP-(\d+)$~', (string)$r['reference'], $mm)) $max = max($max, (int)$mm[1]);
            $ref = 'WP-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
            db()->prepare("INSERT INTO weight_profiles (reference, name, unit, notes, version, unit_net_grams,
                           sample_qty, sample_gross_kg, sample_net_kg, sample_pkg_type, active, created_by, created_at, updated_by, updated_at)
                           VALUES (?,?,?,?,1,?,?,?,?,?,1,?,NOW(),?,NOW())")
                ->execute([$ref, $name, $unit, $notes, $total, $cal[0] ?? null, $cal[1] ?? null, $cal[2] ?? null, $cal[3] ?? '', $uid, $uid]);
            $id = (int)db()->lastInsertId();
        }
        db()->prepare("DELETE FROM weight_profile_materials WHERE profile_id=?")->execute([$id]);
        $ins = db()->prepare("INSERT INTO weight_profile_materials (profile_id, line_no, category, description, grams) VALUES (?,?,?,?,?)");
        foreach ($mats as $i => $m) $ins->execute([$id, $i + 1, $m['category'], $m['description'], $m['grams']]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('wt_profile_save: ' . $e->getMessage());
        return [false, 'The profile could not be saved. Nothing was changed.', null];
    }
    $p = wt_profile($id);
    return [true, $p['reference'] . ' saved · ' . $p['name'], $p];
}

/* A profile linked to any shipment is never deleted, only taken out of
   use; it then leaves the picker but stays on the shipments that have it. */
function wt_profile_delete(int $id): array
{
    if (isset(wt_profiles_in_use()[$id])) return [false, 'This profile is used on a shipment, so it cannot be deleted. Mark it "Not in use" instead.'];
    try {
        db()->prepare("DELETE FROM weight_profile_materials WHERE profile_id=?")->execute([$id]);
        db()->prepare("DELETE FROM weight_profiles WHERE id=?")->execute([$id]);
        return [true, 'Profile deleted.'];
    } catch (Throwable $e) { return [false, 'The profile could not be deleted.']; }
}
function wt_profile_set_active(int $id, bool $active): array
{
    try {
        db()->prepare("UPDATE weight_profiles SET active=? WHERE id=?")->execute([$active ? 1 : 0, $id]);
        return [true, $active ? 'Profile back in use.' : 'Profile marked "Not in use" — it stays on shipments that have it.'];
    } catch (Throwable $e) { return [false, 'That could not be saved.']; }
}

/* --------------------------------------------------------- the shipment */

/* What each invoice line has packed: quantity, packages by kind, and the
   ranges — from the phone's ranges and the rows typed on the desktop. */
function wt_packed(int $shipmentId): array
{
    $out = [];
    $add = function (int $item, string $kind, string $serial, float $qty, int $pkgs, string $mode) use (&$out) {
        if ($item <= 0) return;
        $k = trim($kind) ?: 'Carton';
        $o = &$out[$item];
        $o['qty'] = ($o['qty'] ?? 0) + $qty;
        $o['packages'] = ($o['packages'] ?? 0) + $pkgs;
        $o['byType'][$k] = ($o['byType'][$k] ?? 0) + $pkgs;
        $o['rows'][] = ['serial' => $serial, 'type' => $k, 'qty' => $qty, 'packages' => $pkgs, 'mode' => $mode];
    };
    if (function_exists('pack_groups')) {
        foreach (pack_groups($shipmentId) as $g) {
            $P = pack_packages($g);
            $q = pack_group_qty($g, pack_sizes((int)$g['id']));
            /* plain numbers, as on the packing screens (no prefix any more) */
            $f = static fn(int $n) => (string)$n;
            $ser = $f((int)$g['serial_from']) . ((int)$g['serial_to'] !== (int)$g['serial_from'] ? ' – ' . $f((int)$g['serial_to']) : '');
            $add((int)$g['invoice_item_id'], (string)$g['unit_title'], $ser, $q, $P, ($g['qty_mode'] ?? 'per') === 'direct' ? 'Direct' : 'Per package');
        }
    }
    try {
        $s = db()->prepare("SELECT invoice_item_id, pack_unit_title, carton_from, carton_to, packages, total_qty
                            FROM packing_items WHERE shipment_id=? AND packing_group_id IS NULL ORDER BY carton_from, id");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) {
            $ser = (int)$r['carton_from'] . ((int)$r['carton_to'] !== (int)$r['carton_from'] ? ' – ' . (int)$r['carton_to'] : '');
            $add((int)$r['invoice_item_id'], (string)$r['pack_unit_title'], $ser, (float)$r['total_qty'], (int)round((float)$r['packages']), 'Desktop');
        }
    } catch (Throwable $e) {}
    return $out;
}

/* The empty package weights. Typed ones are kept; a kind nobody has typed
   is filled from a linked profile's sample (gross − net) as a suggestion. */
function wt_tares(int $shipmentId, array $kinds, array $links): array
{
    $typed = [];
    try {
        $s = db()->prepare("SELECT pkg_type, kg FROM weight_tare WHERE shipment_id=?");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) $typed[mb_strtolower((string)$r['pkg_type'])] = (float)$r['kg'];
    } catch (Throwable $e) {}
    $out = [];
    foreach ($kinds as $kind => $pkgs) {
        $k = mb_strtolower($kind);
        if (array_key_exists($k, $typed)) { $out[$kind] = ['kg' => $typed[$k], 'source' => 'typed', 'packages' => $pkgs]; continue; }
        $sug = null;
        foreach ($links as $p) {
            $c = $p['calibration'] ?? null;
            if ($c && mb_strtolower((string)$c['packageType']) === $k && $c['packageTareGrams'] !== null && $c['packageTareGrams'] > 0) {
                $sug = ['kg' => $c['packageTareGrams'] / 1000, 'source' => $p['reference'] . ' sample', 'packages' => $pkgs];
                break;
            }
        }
        $out[$kind] = $sug ?? ['kg' => 0.0, 'source' => '', 'packages' => $pkgs];
    }
    return $out;
}
function wt_tare_save(int $shipmentId, string $kind, string $kg): array
{
    $kind = trim($kind);
    if ($kind === '' || mb_strlen($kind) > 60) return [false, 'Unknown package type.'];
    try {
        if (trim($kg) === '') {
            db()->prepare("DELETE FROM weight_tare WHERE shipment_id=? AND pkg_type=?")->execute([$shipmentId, $kind]);
            return [true, 'Cleared — a sample figure is used if there is one.'];
        }
        $v = wt_kg_in($kg);
        if ($v === -1.0 || $v === null || $v > 1000) return [false, 'Use kg, like 0.800.'];
        $u = (int)(current_user()['id'] ?? 0);
        $up = db()->prepare("UPDATE weight_tare SET kg=?, updated_by=?, updated_at=NOW() WHERE shipment_id=? AND pkg_type=?");
        $up->execute([$v, $u, $shipmentId, $kind]);
        if ($up->rowCount() === 0) {
            $chk = db()->prepare("SELECT id FROM weight_tare WHERE shipment_id=? AND pkg_type=?");
            $chk->execute([$shipmentId, $kind]);
            if (!$chk->fetchColumn())
                db()->prepare("INSERT INTO weight_tare (shipment_id, pkg_type, kg, updated_by, updated_at) VALUES (?,?,?,?,NOW())")
                    ->execute([$shipmentId, $kind, $v, $u]);
        }
        return [true, 'Saved.'];
    } catch (Throwable $e) { return [false, 'That could not be saved.']; }
}

function wt_links(int $shipmentId): array
{
    $out = [];
    try {
        $s = db()->prepare("SELECT invoice_item_id, profile_id FROM weight_line_links WHERE shipment_id=?");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) $out[(int)$r['invoice_item_id']] = (int)$r['profile_id'];
    } catch (Throwable $e) {}
    return $out;
}
function wt_link(int $shipmentId, array $items, int $itemId, int $profileId): array
{
    $item = null;
    foreach ($items as $it) if ((int)$it['id'] === $itemId) $item = $it;
    if (!$item) return [false, 'That line is not on this invoice.'];
    $p = wt_profile($profileId);
    if (!$p) return [false, 'Profile not found.'];
    if (!$p['active']) return [false, 'That profile is marked "Not in use".'];
    $lu = wt_unit_of((string)($item['unit'] ?? ''));
    if ($p['unit'] !== $lu)
        return [false, 'This profile weighs one ' . $p['unit'] . ', but the line is counted in ' . $lu . '. Pick a profile made for ' . $lu . '.'];
    $key = function_exists('pack_product_key') ? pack_product_key((string)$item['product_name']) : mb_strtolower((string)$item['product_name']);
    try {
        $u = (int)(current_user()['id'] ?? 0);
        $up = db()->prepare("UPDATE weight_line_links SET profile_id=?, product_key=?, linked_by=?, linked_at=NOW() WHERE shipment_id=? AND invoice_item_id=?");
        $up->execute([$profileId, $key, $u, $shipmentId, $itemId]);
        $chk = db()->prepare("SELECT id FROM weight_line_links WHERE shipment_id=? AND invoice_item_id=?");
        $chk->execute([$shipmentId, $itemId]);
        if (!$chk->fetchColumn())
            db()->prepare("INSERT INTO weight_line_links (shipment_id, invoice_item_id, profile_id, product_key, linked_by, linked_at) VALUES (?,?,?,?,?,NOW())")
                ->execute([$shipmentId, $itemId, $profileId, $key, $u]);
        return [true, $p['reference'] . ' linked to ' . $item['product_name'] . '.'];
    } catch (Throwable $e) { return [false, 'That could not be saved.']; }
}
function wt_unlink(int $shipmentId, int $itemId): array
{
    try {
        db()->prepare("DELETE FROM weight_line_links WHERE shipment_id=? AND invoice_item_id=?")->execute([$shipmentId, $itemId]);
        return [true, 'Profile removed from this line.'];
    } catch (Throwable $e) { return [false, 'That could not be saved.']; }
}
/* The profile used last time for the same product, on another shipment —
   offered for one tap, never linked by itself. */
function wt_suggest(int $shipmentId, array $item): ?array
{
    $key = function_exists('pack_product_key') ? pack_product_key((string)$item['product_name']) : '';
    if ($key === '') return null;
    try {
        $s = db()->prepare("SELECT l.profile_id, s.invoice_no FROM weight_line_links l
                            JOIN weight_profiles p ON p.id=l.profile_id AND p.active=1
                            LEFT JOIN shipments s ON s.id=l.shipment_id
                            WHERE l.product_key=? AND l.shipment_id<>? ORDER BY l.linked_at DESC, l.id DESC LIMIT 1");
        $s->execute([$key, $shipmentId]);
        $r = $s->fetch();
        if (!$r) return null;
        $p = wt_profile((int)$r['profile_id']);
        if (!$p || $p['unit'] !== wt_unit_of((string)($item['unit'] ?? ''))) return null;
        return ['profileId' => $p['id'], 'reference' => $p['reference'], 'name' => $p['name'], 'from' => (string)($r['invoice_no'] ?? '')];
    } catch (Throwable $e) { return null; }
}

/* Everything worked out for a shipment, line by line, with what was
   applied and whether it still matches. */
function wt_calc(int $shipmentId, array $items): array
{
    $packed = wt_packed($shipmentId);
    $links = wt_links($shipmentId);
    $profiles = [];
    foreach (array_unique(array_values($links)) as $pid) { $p = wt_profile($pid); if ($p) $profiles[$pid] = $p; }
    $kinds = [];
    foreach ($packed as $pk) foreach ($pk['byType'] as $k => $n) $kinds[$k] = ($kinds[$k] ?? 0) + $n;
    $tares = wt_tares($shipmentId, $kinds, array_values($profiles));
    $applied = [];
    try {
        $s = db()->prepare("SELECT * FROM weight_applied WHERE shipment_id=?");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) $applied[(int)$r['invoice_item_id']] = $r;
    } catch (Throwable $e) {}
    $meta = null;
    try {
        $s = db()->prepare("SELECT * FROM weight_shipment WHERE shipment_id=?");
        $s->execute([$shipmentId]);
        $meta = $s->fetch() ?: null;
    } catch (Throwable $e) {}

    $lines = []; $T = ['net' => 0.0, 'gross' => 0.0, 'packages' => 0, 'linked' => 0, 'lines' => 0, 'pending' => 0, 'changed' => 0];
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $pk = $packed[$iid] ?? ['qty' => 0.0, 'packages' => 0, 'byType' => [], 'rows' => []];
        $p = isset($links[$iid]) ? ($profiles[$links[$iid]] ?? null) : null;
        $unit = wt_unit_of((string)($it['unit'] ?? ''));
        $net = 0.0; $tare = 0.0;
        if ($p && $p['unit'] === $unit) {
            $net = $p['unitNetGrams'] * $pk['qty'] / 1000;
            foreach ($pk['byType'] as $k => $n) $tare += ($tares[$k]['kg'] ?? 0) * $n;
        }
        $pkgKey = json_encode($pk['byType']);
        $a = $applied[$iid] ?? null;
        $state = 'none';
        if ($a) {
            $state = ($p && (int)$a['profile_id'] === $p['id'] && (int)$a['profile_version'] === $p['version']
                      && abs((float)$a['qty'] - $pk['qty']) < 0.0005 && (string)$a['pkg_key'] === $pkgKey) ? 'applied' : 'changed';
            if ($p && (int)$a['profile_id'] === $p['id'] && (int)$a['profile_version'] !== $p['version']) $state = 'profile_updated';
        }
        $lines[] = [
            'id' => $iid, 'no' => '#' . (int)($it['line_no'] ?? 0), 'name' => (string)$it['product_name'],
            'hint' => trim((string)($it['des_col'] ?? '')), 'unit' => $unit, 'invoiceQty' => (float)($it['qty'] ?? 0),
            'qty' => $pk['qty'], 'packages' => $pk['packages'], 'byType' => $pk['byType'], 'rows' => $pk['rows'],
            'profileId' => $p ? $p['id'] : 0, 'net' => round($net, 3), 'gross' => round($net + $tare, 3),
            'applied' => $a ? ['net' => (float)$a['net_kg'], 'gross' => (float)$a['gross_kg'], 'state' => $state] : null,
            'suggest' => $p ? null : wt_suggest($shipmentId, $it),
        ];
        $T['lines']++; $T['packages'] += $pk['packages'];
        if ($p) { $T['linked']++; $T['net'] += $net; $T['gross'] += $net + $tare; }
        elseif ($pk['qty'] > 0) $T['pending']++;
        if ($a && $state !== 'applied') $T['changed']++;
    }
    $tareKey = json_encode(array_map(static fn($t) => $t['kg'], $tares));
    $appliedNow = $meta && $T['changed'] === 0 && (string)$meta['tare_key'] === $tareKey
        && count($applied) === count(array_filter($lines, static fn($l) => $l['profileId'] && $l['qty'] > 0));
    return [
        'lines' => $lines, 'tares' => $tares, 'profiles' => $profiles,
        'totals' => ['net' => round($T['net'], 3), 'gross' => round($T['gross'], 3), 'packages' => $T['packages'],
                     'linked' => $T['linked'], 'lines' => $T['lines'], 'pending' => $T['pending']],
        'applied' => $meta ? ['net' => (float)$meta['final_net'], 'gross' => (float)$meta['final_gross'],
                              'calcNet' => (float)$meta['calc_net'], 'calcGross' => (float)$meta['calc_gross'],
                              'at' => (string)$meta['applied_at'], 'current' => $appliedNow] : null,
    ];
}

/* APPLY — the figures go to the shipment. Final net/gross may overrule the
   worked-out figures within ±10%; each line is then scaled the same way so
   the lines still add up to the totals. */
function wt_apply(int $shipmentId, array $items, $finalNet = null, $finalGross = null): array
{
    $c = wt_calc($shipmentId, $items);
    $use = array_values(array_filter($c['lines'], static fn($l) => $l['profileId'] && $l['qty'] > 0 && $l['net'] > 0));
    if (!$use) return [false, 'Link a profile to at least one packed line first.'];
    $cn = array_sum(array_column($use, 'net'));
    $cg = array_sum(array_column($use, 'gross'));
    $fn = ($finalNet === null || trim((string)$finalNet) === '') ? $cn : wt_kg_in($finalNet);
    $fg = ($finalGross === null || trim((string)$finalGross) === '') ? $cg : wt_kg_in($finalGross);
    if ($fn === -1.0 || $fg === -1.0 || $fn === null || $fg === null) return [false, 'Final weights: enter kg with up to 3 decimals.'];
    foreach ([['net', $cn, $fn], ['gross', $cg, $fg]] as [$what, $calc, $final]) {
        if ($calc > 0 && abs($final - $calc) / $calc * 100 > WT_TOLERANCE_PCT + 1e-9)
            return [false, 'The final ' . $what . ' weight is more than ' . WT_TOLERANCE_PCT . '% away from the worked-out '
                         . number_format($calc, 3) . ' kg. Check the profiles and packages first.'];
    }
    if ($fg < $fn) return [false, 'Gross cannot be less than net.'];
    $sn = $cn > 0 ? $fn / $cn : 1.0;
    $sg = $cg > 0 ? $fg / $cg : 1.0;
    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM weight_applied WHERE shipment_id=?")->execute([$shipmentId]);
        $ins = db()->prepare("INSERT INTO weight_applied (shipment_id, invoice_item_id, profile_id, profile_version, unit_grams, qty,
                              packages, pkg_key, net_kg, gross_kg) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($use as $l) {
            $p = $c['profiles'][$l['profileId']];
            $ins->execute([$shipmentId, $l['id'], $p['id'], $p['version'], $p['unitNetGrams'], $l['qty'], $l['packages'],
                           json_encode($l['byType']), round($l['net'] * $sn, 3), round($l['gross'] * $sg, 3)]);
        }
        $tareKey = json_encode(array_map(static fn($t) => $t['kg'], $c['tares']));
        db()->prepare("DELETE FROM weight_shipment WHERE shipment_id=?")->execute([$shipmentId]);
        db()->prepare("INSERT INTO weight_shipment (shipment_id, calc_net, calc_gross, final_net, final_gross, tare_key, applied_by, applied_at)
                       VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$shipmentId, round($cn, 3), round($cg, 3), round($fn, 3), round($fg, 3), $tareKey, (int)(current_user()['id'] ?? 0)]);
        db()->prepare("UPDATE shipments SET total_net_weight=?, total_gross_weight=? WHERE id=?")
            ->execute([round($fn, 3), round($fg, 3), $shipmentId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('wt_apply: ' . $e->getMessage());
        return [false, 'The weight could not be applied. Nothing was changed.'];
    }
    wt_spread($shipmentId);
    $pend = $c['totals']['pending'];
    return [true, 'Weight applied — ' . number_format($fn, 3) . ' kg net, ' . number_format($fg, 3) . ' kg gross'
                  . ($pend ? '. ' . $pend . ' packed line' . ($pend === 1 ? '' : 's') . ' still without a profile — weight pending.' : '.')];
}

/* Each packing row gets its line's share: net by quantity, the package
   part by packages. Called after Apply, and after the packing rows are
   rewritten (Finish packing, a desktop row saved), so the rows always
   carry the applied weight. A line with no applied weight gets 0. */
function wt_spread(int $shipmentId): void
{
    try {
        $a = db()->prepare("SELECT invoice_item_id, net_kg, gross_kg FROM weight_applied WHERE shipment_id=?");
        $a->execute([$shipmentId]);
        $applied = [];
        foreach ($a->fetchAll() as $r) $applied[(int)$r['invoice_item_id']] = [(float)$r['net_kg'], (float)$r['gross_kg']];
        $s = db()->prepare("SELECT id, invoice_item_id, total_qty, packages FROM packing_items WHERE shipment_id=?");
        $s->execute([$shipmentId]);
        $rows = $s->fetchAll();
        $sum = [];
        foreach ($rows as $r) {
            $i = (int)$r['invoice_item_id'];
            $sum[$i]['q'] = ($sum[$i]['q'] ?? 0) + (float)$r['total_qty'];
            $sum[$i]['p'] = ($sum[$i]['p'] ?? 0) + (float)$r['packages'];
        }
        $up = db()->prepare("UPDATE packing_items SET net_weight=?, gross_weight=? WHERE id=?");
        /* rounded per row, with the last row of each line taking the
           remainder, so the rows add up to the line exactly */
        $left = []; $count = [];
        foreach ($rows as $r) $count[(int)$r['invoice_item_id']] = ($count[(int)$r['invoice_item_id']] ?? 0) + 1;
        foreach ($rows as $r) {
            $i = (int)$r['invoice_item_id'];
            if (!isset($applied[$i])) { $up->execute([0, 0, (int)$r['id']]); continue; }
            [$n, $g] = $applied[$i];
            if (!isset($left[$i])) $left[$i] = [$n, $g];
            if (--$count[$i] === 0) { $up->execute([round($left[$i][0], 3), round($left[$i][1], 3), (int)$r['id']]); continue; }
            $q = $sum[$i]['q'] ?: 1; $p = $sum[$i]['p'];
            $rn = round($n * (float)$r['total_qty'] / $q, 3);
            $rg = round($rn + ($g - $n) * ($p > 0 ? (float)$r['packages'] / $p : (float)$r['total_qty'] / $q), 3);
            $left[$i][0] -= $rn; $left[$i][1] -= $rg;
            $up->execute([$rn, $rg, (int)$r['id']]);
        }
    } catch (Throwable $e) { error_log('wt_spread: ' . $e->getMessage()); }
}

/* For the printed packing list: one row per invoice line, as applied. */
function wt_summary(int $shipmentId): array
{
    $out = ['lines' => [], 'net' => 0.0, 'gross' => 0.0, 'applied' => false];
    try {
        $s = db()->prepare("SELECT a.*, p.reference FROM weight_applied a LEFT JOIN weight_profiles p ON p.id=a.profile_id WHERE a.shipment_id=?");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) $out['lines'][(int)$r['invoice_item_id']] = $r;
        $m = db()->prepare("SELECT final_net, final_gross FROM weight_shipment WHERE shipment_id=?");
        $m->execute([$shipmentId]);
        if ($row = $m->fetch()) { $out['net'] = (float)$row['final_net']; $out['gross'] = (float)$row['final_gross']; $out['applied'] = true; }
    } catch (Throwable $e) {}
    return $out;
}
