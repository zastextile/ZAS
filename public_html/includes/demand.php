<?php
/*
  DEMAND SHEET — what one order needs, item by item, before anything is bought.
  ============================================================================

  Owner, 18 Nov 2026: "total items as demand sheet … for my order … calculating
  the total need as we do in final costing … the formula shared as ctn or
  polybag in kg but used for many qty … allow amendment as very fast way …
  bottom make summary … where product, size, color, design is matching for
  purchase … partially generate … purchase order … gate in use ref of that PO".

  ONE SHEET PER PROFORMA, made from what is already in the app:
    order line (product, size, pieces)  ×  that size's Product Costing lines
    (fabric, accessories, packing — never workmanship).
  The costing is picked the way Final Costing picks it: the product's costing
  whose size matches the order line, else its latest. Every line is then the
  owner's to amend; the costing is never changed from here.

  THE FORMULA, ONE PLACE (dm_need, and the same few lines in demand.php's JS):
    normal line   need = pieces × per piece × (1 + extra%)
    shared line   need = ⌈ pieces ÷ N × (1 + extra%) ⌉   — one carton or polybag
                  holds N pieces; whole cartons are bought, so it rounds UP
    kg            need × kg of ONE unit (the costing's weight column, which
                  Product Costing reads the same way: shared w ÷ q a piece)
    TO BUY        in the line's own unit — and a line whose unit is kg is
                  bought by the kg: 380 master polybags of 0.06 kg are bought
                  as 22.8 kg (dm_buy). With no kg per unit, kg is the need.

  THE SUMMARY adds lines that are the same purchase — the same material (or,
  with no material linked, the same item name and the same colour / size /
  design) — across every size of the order. Against each it shows what the
  order's purchase contracts already hold, what Gate Inward has received on
  them (posted passes only), and what is still to order.

  PURCHASE ORDERS ARE THE EXISTING PURCHASE CONTRACTS (Inventory › Contracts),
  made from ticked summary lines with the quantity the purchaser chooses, so
  one demand can be bought in parts from different suppliers. Gate Inward
  receives against that contract exactly as it always has. They are matched
  back to the demand by ORDER + MATERIAL (or description), never by a hidden
  tag: the Contracts screen rewrites its lines on every save, and a tag on a
  line would vanish the first time somebody corrected a rate.
*/
require_once __DIR__ . '/inventory.php';

const DM_GROUPS = ['Fabric', 'Accessories', 'Packing'];

function dm_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (zas_schema_fresh('demand', [__FILE__])) return;
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_demand (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        status ENUM('draft','final') NOT NULL DEFAULT 'draft',
        created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL, updated_at DATETIME NULL,
        final_by INT NULL, final_at DATETIME NULL,
        UNIQUE KEY uniq_dm_pf (proforma_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS inv_demand_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        demand_id INT NOT NULL,
        proforma_item_id INT NULL,
        product VARCHAR(200) NULL,
        size VARCHAR(80) NULL,
        pcs DECIMAL(14,3) NOT NULL DEFAULT 0,
        costing_version_id INT NULL,
        line_group VARCHAR(40) NOT NULL DEFAULT 'Fabric',
        material_id INT NULL,
        item_name VARCHAR(190) NULL,
        spec VARCHAR(190) NULL,
        unit VARCHAR(20) NULL,
        per_pc DECIMAL(14,4) NOT NULL DEFAULT 0,
        shared TINYINT(1) NOT NULL DEFAULT 0,
        kg_per DECIMAL(14,4) NOT NULL DEFAULT 0,
        extra_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX(demand_id), INDEX(material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    zas_schema_mark('demand', [__FILE__]);
}

/** [need, kg] for one line — THE formula. */
function dm_need(float $pcs, float $perPc, bool $shared, float $kgPer, float $extraPct): array
{
    $f = 1 + max(0, $extraPct) / 100;
    if ($shared) {
        $need = $perPc > 0 ? ceil(round($pcs / $perPc * $f, 6)) : 0.0;
    } else {
        $need = round($pcs * $perPc * $f, 3);
    }
    return [(float) $need, round($need * $kgPer, 3)];
}

/** [quantity to buy in the line's unit, kg] — a kg line is bought by its kg. */
function dm_buy(string $unit, float $need, float $kg, float $kgPer): array
{
    if (strtolower(trim($unit)) === 'kg') {
        $q = $kgPer > 0 ? $kg : $need;
        return [$q, $q];
    }
    return [$need, $kg];
}

function dm_norm(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($s)));
}

/** The purchase a line belongs to: its material (else its item name) WITH its colour / size / design —
    a size label is one material, but "Size Single" and "Size King" are two different things to buy. */
function dm_key(array $l): string
{
    $mid = (int) ($l['material_id'] ?? 0);
    $spec = dm_norm((string) ($l['spec'] ?? ''));
    return $mid > 0 ? 'm:' . $mid . '|' . $spec : 't:' . dm_norm((string) ($l['item_name'] ?? '')) . '|' . $spec;
}

/** What a purchase-contract line without a material is matched on. */
function dm_desc(string $item, string $spec): string
{
    return trim($item . ($spec !== '' ? ' — ' . $spec : ''));
}

function dm_proforma(int $pfId): ?array
{
    try {
        $s = db()->prepare("SELECT * FROM proforma_invoices WHERE id = ?");
        $s->execute([$pfId]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function dm_get(int $pfId): ?array
{
    dm_ensure_schema();
    $s = db()->prepare("SELECT * FROM inv_demand WHERE proforma_id = ?");
    $s->execute([$pfId]);
    return $s->fetch() ?: null;
}

/**
 * The costing for one order line: the product's costing whose size matches,
 * else its latest. @return array{0:?int,1:bool} [costing_version_id, size matched]
 */
function dm_pick_costing(?int $productId, string $size, ?int $sizeId): array
{
    if (!$productId) return [null, false];
    try {
        $s = db()->prepare("SELECT cv.id, GROUP_CONCAT(ps.size_label SEPARATOR '\x1f') labels, GROUP_CONCAT(ps.id) size_ids
                              FROM costing_versions cv
                              LEFT JOIN costing_version_sizes cvs ON cvs.costing_version_id = cv.id
                              LEFT JOIN product_sizes ps ON ps.id = cvs.product_size_id
                             WHERE cv.product_id = ? GROUP BY cv.id ORDER BY cv.id DESC");
        $s->execute([$productId]);
        $vs = $s->fetchAll();
    } catch (Throwable $e) { return [null, false]; }
    if (!$vs) return [null, false];
    if ($sizeId) {
        foreach ($vs as $v) if (in_array((string) $sizeId, explode(',', (string) $v['size_ids']), true)) return [(int) $v['id'], true];
    }
    $want = dm_norm($size);
    if ($want !== '') {
        foreach ($vs as $v) foreach (explode("\x1f", (string) $v['labels']) as $lbl) {
            $l = dm_norm($lbl);
            if ($l !== '' && ($l === $want || str_contains($want, $l) || str_contains($l, $want))) return [(int) $v['id'], true];
        }
    }
    return [(int) $vs[0]['id'], false];
}

/** The product an order line is: its stored id, else the product of that exact name. */
function dm_product_id(array $pi): ?int
{
    if (!empty($pi['product_id'])) return (int) $pi['product_id'];
    $name = trim((string) ($pi['product_name'] ?? ''));
    if ($name === '') return null;
    try {
        $s = db()->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $s->execute([$name]);
        $id = $s->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Throwable $e) { return null; }
}

/**
 * Make (or remake) the sheet's lines from the order and its costings.
 * @return array{ok:bool,lines?:int,missing?:list<string>,error?:string}
 */
function dm_build(int $pfId, int $actorId): array
{
    dm_ensure_schema();
    $pf = dm_proforma($pfId);
    if (!$pf) return ['ok' => false, 'error' => 'No such proforma.'];
    $d = dm_get($pfId);
    if ($d && $d['status'] === 'final') return ['ok' => false, 'error' => 'This demand is the final plan. Reopen it first.'];
    try {
        $s = db()->prepare("SELECT * FROM proforma_items WHERE proforma_id = ? ORDER BY sort_order, id");
        $s->execute([$pfId]);
        $items = $s->fetchAll();
    } catch (Throwable $e) { $items = []; }

    $mats = [];
    try { foreach (db()->query("SELECT id, name, colour, uom FROM inv_materials")->fetchAll() as $m) $mats[(int) $m['id']] = $m; } catch (Throwable $e) {}

    db()->beginTransaction();
    try {
        if (!$d) {
            db()->prepare("INSERT INTO inv_demand (proforma_id, created_by) VALUES (?, ?)")->execute([$pfId, $actorId]);
            $did = (int) db()->lastInsertId();
        } else {
            $did = (int) $d['id'];
            db()->prepare("DELETE FROM inv_demand_lines WHERE demand_id = ?")->execute([$did]);
            db()->prepare("UPDATE inv_demand SET updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$actorId, $did]);
        }
        $ins = db()->prepare("INSERT INTO inv_demand_lines (demand_id, proforma_item_id, product, size, pcs, costing_version_id, line_group, material_id, item_name, spec, unit, per_pc, shared, kg_per, extra_pct, sort_order)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)");
        $n = 0; $missing = [];
        foreach ($items as $pi) {
            $pcs = (float) $pi['qty'];
            if ($pcs <= 0) continue;
            [$cv] = dm_pick_costing(dm_product_id($pi), (string) ($pi['size'] ?? ''), !empty($pi['product_size_id']) ? (int) $pi['product_size_id'] : null);
            $lines = [];
            if ($cv) {
                $s = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id = ? ORDER BY sort_order, id");
                $s->execute([$cv]);
                $lines = array_filter($s->fetchAll(), fn($l) => in_array($l['line_group'], DM_GROUPS, true));
            }
            if (!$lines) {
                $missing[] = trim(($pi['product_name'] ?? '') . ' ' . ($pi['size'] ?? ''));
                /* kept as one empty line, so the order line is on the sheet and can be filled by hand */
                $ins->execute([$did, (int) $pi['id'], $pi['product_name'], $pi['size'], $pcs, null, 'Fabric', null, null, null, null, 0, 0, 0, $n++]);
                continue;
            }
            foreach ($lines as $l) {
                $mid = (int) ($l['material_id'] ?? 0) ?: null;
                $m = $mid ? ($mats[$mid] ?? null) : null;
                $spec = trim((string) ($l['description'] ?? ''));
                if ($spec === '' && $m && !empty($m['colour'])) $spec = (string) $m['colour'];
                $ins->execute([$did, (int) $pi['id'], $pi['product_name'], $pi['size'], $pcs, $cv, $l['line_group'], $mid,
                    $m ? $m['name'] : $l['item_name'], mb_substr($spec, 0, 190), $l['unit'] ?: ($m['uom'] ?? null),
                    (float) $l['quantity'], !empty($l['shared']) ? 1 : 0, (float) $l['weight_kg'], $n++]);
            }
        }
        db()->commit();
        inv_audit('demand_build', $pfId, ['lines' => $n, 'missing' => $missing], 'Demand sheet made from costing');
        return ['ok' => true, 'lines' => $n, 'missing' => $missing];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Could not make the demand sheet: ' . $e->getMessage()];
    }
}

/** Every line, with need and kg worked out. */
function dm_lines(int $demandId): array
{
    $s = db()->prepare("SELECT l.*, m.code AS material_code FROM inv_demand_lines l LEFT JOIN inv_materials m ON m.id = l.material_id
                         WHERE l.demand_id = ? ORDER BY l.sort_order, l.id");
    $s->execute([$demandId]);
    $out = [];
    foreach ($s->fetchAll() as $l) {
        [$units, $kg0] = dm_need((float) $l['pcs'], (float) $l['per_pc'], (bool) $l['shared'], (float) $l['kg_per'], (float) $l['extra_pct']);
        [$need, $kg] = dm_buy((string) $l['unit'], $units, $kg0, (float) $l['kg_per']);
        $l['units'] = $units; $l['need'] = $need; $l['kg'] = $kg;
        foreach (['id', 'proforma_item_id', 'costing_version_id', 'material_id', 'shared', 'sort_order'] as $k) $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        foreach (['pcs', 'per_pc', 'kg_per', 'extra_pct'] as $k) $l[$k] = (float) $l[$k];
        $out[] = $l;
    }
    return $out;
}

/**
 * Save the whole grid at once. Lines without an id are new; lines missing
 * from the list are removed. @param list<array<string,mixed>> $rows
 */
function dm_save(int $pfId, array $rows, int $actorId): array
{
    $d = dm_get($pfId);
    if (!$d) return ['ok' => false, 'error' => 'Make the demand sheet first.'];
    if ($d['status'] === 'final') return ['ok' => false, 'error' => 'This demand is the final plan. Reopen it to change it.'];
    $did = (int) $d['id'];
    $have = array_flip(array_map('intval', db()->query("SELECT id FROM inv_demand_lines WHERE demand_id = " . $did)->fetchAll(PDO::FETCH_COLUMN)));
    $num = fn($v) => is_numeric($v) ? (float) $v : (float) str_replace(',', '', (string) $v);
    $str = fn($v, $n) => mb_substr(trim((string) ($v ?? '')), 0, $n);
    db()->beginTransaction();
    try {
        $upd = db()->prepare("UPDATE inv_demand_lines SET product=?, size=?, pcs=?, line_group=?, material_id=?, item_name=?, spec=?, unit=?, per_pc=?, shared=?, kg_per=?, extra_pct=?, sort_order=? WHERE id=? AND demand_id=?");
        $ins = db()->prepare("INSERT INTO inv_demand_lines (demand_id, proforma_item_id, product, size, pcs, line_group, material_id, item_name, spec, unit, per_pc, shared, kg_per, extra_pct, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $keep = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) continue;
            $grp = in_array($r['line_group'] ?? '', DM_GROUPS, true) ? $r['line_group'] : 'Accessories';
            $mid = (int) ($r['material_id'] ?? 0) ?: null;
            $vals = [$str($r['product'] ?? '', 200), $str($r['size'] ?? '', 80), max(0, $num($r['pcs'] ?? 0)), $grp, $mid, $str($r['item_name'] ?? '', 190), $str($r['spec'] ?? '', 190),
                     $str($r['unit'] ?? '', 20), max(0, $num($r['per_pc'] ?? 0)), !empty($r['shared']) ? 1 : 0, max(0, $num($r['kg_per'] ?? 0)), max(0, min(100, $num($r['extra_pct'] ?? 0))), $i];
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0 && isset($have[$id])) { $upd->execute(array_merge($vals, [$id, $did])); $keep[$id] = 1; }
            else { $ins->execute(array_merge([$did, (int) ($r['proforma_item_id'] ?? 0) ?: null], $vals)); }
        }
        foreach (array_keys($have) as $id) if (!isset($keep[$id])) db()->prepare("DELETE FROM inv_demand_lines WHERE id = ? AND demand_id = ?")->execute([$id, $did]);
        db()->prepare("UPDATE inv_demand SET updated_by = ?, updated_at = NOW() WHERE id = ?")->execute([$actorId, $did]);
        db()->commit();
        inv_audit('demand_save', $pfId, count($rows), 'Demand sheet amended');
        return ['ok' => true];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()];
    }
}

function dm_set_status(int $pfId, string $to, int $actorId): array
{
    $d = dm_get($pfId);
    if (!$d) return ['ok' => false, 'error' => 'No demand sheet.'];
    $to = $to === 'final' ? 'final' : 'draft';
    db()->prepare("UPDATE inv_demand SET status = ?, final_by = ?, final_at = " . ($to === 'final' ? 'NOW()' : 'NULL') . " WHERE id = ?")
        ->execute([$to, $to === 'final' ? $actorId : null, (int) $d['id']]);
    inv_audit('demand_' . $to, $pfId, $to, $to === 'final' ? 'Demand locked as the final plan' : 'Demand reopened');
    return ['ok' => true];
}

/**
 * One line per purchase: the same material (or item + colour / size / design)
 * added up across the order, with what the order's purchase contracts hold and
 * what has been received on them.
 */
function dm_summary(int $pfId, array $lines): array
{
    $sum = [];
    foreach ($lines as $l) {
        if ((string) $l['item_name'] === '' && !$l['material_id']) continue;
        $k = dm_key($l);
        $sum[$k] ??= ['key' => $k, 'line_group' => $l['line_group'], 'material_id' => $l['material_id'], 'material_code' => $l['material_code'] ?? null,
                      'item_name' => (string) $l['item_name'], 'spec' => (string) $l['spec'], 'unit' => (string) $l['unit'],
                      'need' => 0.0, 'kg' => 0.0, 'sizes' => [], 'ordered' => 0.0, 'received' => 0.0, 'pos' => []];
        $sum[$k]['need'] += $l['need'];
        $sum[$k]['kg'] += $l['kg'];
        $sum[$k]['sizes'][trim($l['product'] . ' ' . $l['size'])] = 1;
    }
    /* the order's purchase contracts (not cancelled), and what was received on them */
    try {
        $s = db()->prepare("SELECT c.id AS contract_id, c.contract_no, c.status, ci.id AS item_id, ci.material_id, ci.description, ci.qty
                              FROM inv_contracts c JOIN inv_contract_items ci ON ci.contract_id = c.id
                             WHERE c.proforma_id = ? AND c.contract_type = 'purchase' AND c.status <> 'cancelled'");
        $s->execute([$pfId]);
        $ci = $s->fetchAll();
    } catch (Throwable $e) { $ci = []; }
    $rec = [];
    if ($ci) {
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r['contract_id'], $ci)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = db()->prepare("SELECT gi.contract_item_id, COALESCE(SUM(gi.qty), 0) q FROM inv_gate_items gi JOIN inv_gate g ON g.id = gi.gate_id
                                 WHERE g.status = 'posted' AND g.direction = 'in' AND gi.contract_item_id IS NOT NULL
                                   AND (gi.contract_id IN ($ph) OR (gi.contract_id IS NULL AND g.contract_id IN ($ph))) GROUP BY gi.contract_item_id");
            $s->execute(array_merge($ids, $ids));
            foreach ($s->fetchAll() as $r) $rec[(int) $r['contract_item_id']] = (float) $r['q'];
        } catch (Throwable $e) {}
    }
    /* a contract line is matched by its description (item — colour / size / design, as dm_make_po writes it),
       else by its material when the order buys that material in only one form */
    $byDesc = []; $byMat = [];
    foreach ($sum as $k => $x) {
        $byDesc[dm_norm(dm_desc($x['item_name'], $x['spec']))] = $k;
        if ($x['material_id']) $byMat[(int) $x['material_id']][] = $k;
    }
    foreach ($ci as $r) {
        $mid = (int) $r['material_id'];
        $k = $byDesc[dm_norm((string) $r['description'])] ?? (($mid > 0 && count($byMat[$mid] ?? []) === 1) ? $byMat[$mid][0] : null);
        if ($k === null || !isset($sum[$k])) continue;
        $sum[$k]['ordered'] += (float) $r['qty'];
        $sum[$k]['received'] += $rec[(int) $r['item_id']] ?? 0.0;
        $sum[$k]['pos'][(int) $r['contract_id']] = $r['contract_no'];
    }
    $order = array_flip(DM_GROUPS);
    $out = array_values($sum);
    usort($out, fn($a, $b) => (($order[$a['line_group']] ?? 9) <=> ($order[$b['line_group']] ?? 9)) ?: strcasecmp($a['item_name'], $b['item_name']));
    foreach ($out as &$x) {
        $x['need'] = round($x['need'], 3); $x['kg'] = round($x['kg'], 3);
        $x['to_order'] = max(0, round($x['need'] - $x['ordered'], 3));
        $x['sizes'] = array_keys($x['sizes']);
        $x['pos'] = array_map(fn($id, $no) => ['id' => $id, 'no' => $no], array_keys($x['pos']), array_values($x['pos']));
    }
    unset($x);
    return $out;
}

/**
 * A purchase contract for some of the demand: one supplier, the ticked
 * summary lines, the quantity the purchaser chose (part of the balance is
 * fine). It is an ordinary Purchase Contract — Gate Inward receives on it.
 * @param list<array{key:string,qty:mixed,rate?:mixed}> $picks
 */
function dm_make_po(int $pfId, int $partyId, array $picks, string $expected, int $actorId): array
{
    $d = dm_get($pfId);
    if (!$d) return ['ok' => false, 'error' => 'No demand sheet.'];
    $pf = dm_proforma($pfId);
    if ($partyId <= 0) return ['ok' => false, 'error' => 'Choose the supplier.'];
    $sum = [];
    foreach (dm_summary($pfId, dm_lines((int) $d['id'])) as $x) $sum[$x['key']] = $x;
    $lines = [];
    foreach ($picks as $p) {
        $k = (string) ($p['key'] ?? '');
        $q = round((float) ($p['qty'] ?? 0), 3);
        if (!isset($sum[$k]) || $q <= 0) continue;
        $lines[] = [$sum[$k], $q, max(0, (float) ($p['rate'] ?? 0))];
    }
    if (!$lines) return ['ok' => false, 'error' => 'Tick at least one item and give a quantity.'];
    db()->beginTransaction();
    try {
        $no = inv_next_no('prefix_contract_pur', 'inv_contracts', 'contract_no');
        db()->prepare("INSERT INTO inv_contracts (contract_no, contract_type, contract_date, party_id, proforma_id, currency, expected_date, remarks, status, created_by)
                       VALUES (?, 'purchase', CURDATE(), ?, ?, 'PKR', ?, ?, 'active', ?)")
            ->execute([$no, $partyId, $pfId, preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) ? $expected : null,
                       'From the demand sheet of ' . ($pf['pi_no'] ?? ('proforma ' . $pfId)), $actorId]);
        $cid = (int) db()->lastInsertId();
        $ins = db()->prepare("INSERT INTO inv_contract_items (contract_id, material_id, description, qty, uom, rate, amount, sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($lines as $i => [$x, $q, $rate]) {
            $ins->execute([$cid, $x['material_id'] ?: null, dm_desc($x['item_name'], $x['spec']), $q, $x['unit'] ?: null, $rate, round($q * $rate, 2), $i]);
        }
        db()->commit();
        inv_audit('demand_po', $pfId, ['contract' => $no, 'lines' => count($lines)], 'Purchase contract made from the demand sheet');
        return ['ok' => true, 'contract_id' => $cid, 'contract_no' => $no];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Could not make the purchase order: ' . $e->getMessage()];
    }
}
