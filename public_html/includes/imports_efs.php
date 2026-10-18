<?php
/*
  IMPORTS — PHASE 3: EFS RECONCILIATION.

  afnan locked the design question by question ("1 yes … 12 yes"):

    - Every EFS obligation in one list: each EFS line of an EFS import (kg =
      the GD's assessed kg, valuation = the GD's assessed value per kg) and
      each opening balance (old EFS still open on the go-live date).
    - An EXPORT ALLOCATION is one shipment's claim. Its lines are the
      shipment's CUSTOMS invoice lines (the Customs tab on the shipment), or
      its sale invoice lines while it has no customs invoice. For each line:
      the EFS value of that line (all of it, or only the part that used EFS
      material), and under it the import GD lines it used — kg typed by hand,
      value split by kg × valuation unless typed. Everything outside (the
      allocation's summary, each obligation's discharged figures, the
      reconciliation page) is calculated from that detail and never typed.
    - Nothing here touches any invoice, stock or weight. Consumption already
      took the material out of stock once; an allocation only clears the
      obligation.

  THE CHECKS
    - kg: never more than an import line still has (imported − discharged −
      approved reductions).
    - value: for the import material used, at least kg × assessed value per kg
      (+ value addition % for its HS code). Higher is normal (the product has
      local material too); below needs a reason, kept on the record.
    - received: more kg exported than Gate In received — highlighted, needs a
      reason.
    - the export GD's net weight and FOB: the difference is shown, never blocked.
    - one allocation per shipment; a customs-invoice line never counts more
      than its own value; a customs invoice changed afterwards → "recheck".
    - Filed with customs (date, reference) locks it; after that only a
      reversal with a reason changes the figures.
    - Oldest first: open obligations are listed by deadline, and a newer GD
      line picked while an older one of the same HS still has kg is pointed out.
*/

/* =========================================================== obligations */
/* Every EFS obligation, keyed "L<line id>" or "O<opening id>", oldest
   deadline first. $skipAlloc leaves one allocation out of "discharged" — the
   one being edited, so its own kg do not count against itself. */
function imp_efs_obligations(?int $importId = null, ?int $skipAlloc = null): array
{
    $out = [];
    $today = imp_today();
    /* what allocations and reductions have already used */
    $used = []; $red = [];
    try {
        $s = db()->query("SELECT u.target_kind, u.target_id, SUM(u.kg) kg, SUM(u.value_target) v, COUNT(DISTINCT u.alloc_id) n
            FROM imp_efs_alloc_uses u JOIN imp_efs_alloc a ON a.id = u.alloc_id
            WHERE a.status <> 'reversed'" . ($skipAlloc ? " AND a.id <> " . (int)$skipAlloc : "") . " GROUP BY u.target_kind, u.target_id");
        foreach ($s->fetchAll() as $r) $used[$r['target_kind'] . (int)$r['target_id']] = ['kg' => (float)$r['kg'], 'value' => (float)$r['v'], 'n' => (int)$r['n']];
        foreach (db()->query("SELECT target_kind, target_id, SUM(kg) kg FROM imp_efs_reductions WHERE is_void=0 GROUP BY target_kind, target_id")->fetchAll() as $r)
            $red[$r['target_kind'] . (int)$r['target_id']] = (float)$r['kg'];
    } catch (Throwable $e) {}

    /* EFS lines of EFS imports */
    $where = "i.import_type='efs' AND COALESCE(i.manual_status,'') <> 'cancelled'" . ($importId ? " AND i.id=" . (int)$importId : "");
    $imps = [];
    try { foreach (db()->query("SELECT * FROM imp_imports i WHERE $where")->fetchAll() as $i) $imps[(int)$i['id']] = $i; } catch (Throwable $e) {}
    foreach ($imps as $iid => $imp) {
        $lines = imp_lines($iid);
        if (!$lines) continue;
        $invoices = imp_invoices($iid);
        $basis = imp_gd_basis($lines);
        $got = imp_receipt_totals($iid);
        $dl = imp_efs_deadline($imp);
        $gdOf = [];
        foreach ($invoices as $iv) $gdOf[(int)$iv['id']] = [$iv['gd_no'], $iv['gd_date']];
        $first = (int)($invoices[0]['id'] ?? 0);
        foreach (array_values($lines) as $n => $l) {
            if (isset($l['efs_eligible']) && (int)$l['efs_eligible'] === 0) continue;
            $b = $basis[(int)$l['id']] ?? ['kg' => null, 'per_kg' => null];
            $x = imp_efs_line($l, $b['per_kg'], $b['kg']);
            [$gdNo, $gdDate] = $gdOf[(int)($l['invoice_id'] ?: $first)] ?? [null, null];
            $ext = !empty($l['efs_ext_date']) ? (string)$l['efs_ext_date'] : null;
            $out['L' . (int)$l['id']] = imp_efs_shape([
                'key' => 'L' . (int)$l['id'], 'kind' => 'line', 'id' => (int)$l['id'], 'import_id' => $iid,
                'ref' => $imp['ref'] . ' · line ' . ($n + 1), 'gd_no' => (string)($gdNo ?: ''), 'gd_date' => $gdDate,
                'hs' => (string)$l['hs_code'], 'product' => (string)$l['description'], 'currency' => (string)$imp['currency'],
                'kg' => $x['kg'], 'kg_source' => $x['kg_source'], 'valuation' => $x['valuation'], 'val_source' => $x['source'],
                'waste' => $x['waste'], 'va' => $x['va'],
                'deadline' => $ext ?: $dl['date'], 'deadline_from' => $ext ? 'extended — ' . (string)$l['efs_ext_ref'] : $dl['from'], 'ext_ref' => (string)($l['efs_ext_ref'] ?? ''),
                'received_kg' => (float)($got[(int)$l['id']]['kg_posted'] ?? 0), 'pre_kg' => 0.0, 'pre_value' => 0.0,
            ], $used['line' . (int)$l['id']] ?? null, $red['line' . (int)$l['id']] ?? 0.0, $today);
        }
    }
    /* opening balances — old EFS still open on the go-live date */
    if (!$importId) {
        try {
            foreach (db()->query("SELECT * FROM imp_opening_efs ORDER BY gd_date, id")->fetchAll() as $r) {
                $months = (int)$r['months'] ?: IMP_EFS_MONTHS;
                $ext = !empty($r['ext_date']) ? (string)$r['ext_date'] : null;
                $out['O' . (int)$r['id']] = imp_efs_shape([
                    'key' => 'O' . (int)$r['id'], 'kind' => 'opening', 'id' => (int)$r['id'], 'import_id' => 0,
                    'ref' => 'Opening balance', 'gd_no' => (string)$r['gd_no'], 'gd_date' => $r['gd_date'],
                    'hs' => (string)$r['hs_code'], 'product' => (string)$r['product'], 'currency' => (string)($r['currency'] ?? 'USD') ?: 'USD',
                    'kg' => (float)$r['imported_kg'], 'kg_source' => 'opening', 'valuation' => (float)$r['valuation'], 'val_source' => 'opening',
                    'waste' => (float)$r['waste_pct'], 'va' => (float)$r['va_pct'],
                    'deadline' => $ext ?: (!empty($r['gd_date']) ? imp_add_months($r['gd_date'], $months) : null),
                    'deadline_from' => $ext ? 'extended — ' . (string)$r['ext_ref'] : 'GD date + ' . $months . ' months', 'ext_ref' => (string)($r['ext_ref'] ?? ''),
                    'received_kg' => null, 'pre_kg' => (float)$r['exported_kg'], 'pre_value' => (float)$r['exported_value'],
                ], $used['opening' . (int)$r['id']] ?? null, $red['opening' . (int)$r['id']] ?? 0.0, $today);
            }
        } catch (Throwable $e) {}
    }
    uasort($out, static fn($a, $b) => strcmp((string)($a['deadline'] ?? '9999'), (string)($b['deadline'] ?? '9999')) ?: strcmp($a['key'], $b['key']));
    return $out;
}
/* The figures of one obligation. afnan's rule: export at least (kg − wastage
   %) of material, in value at least kg × valuation (+ value addition %),
   never reduced by the wastage. An approved reduction lowers the kg the
   obligation is based on. Discharged = before go-live + allocations. */
function imp_efs_shape(array $o, ?array $used, float $reduced, string $today): array
{
    $o['reduced_kg'] = $reduced;
    $base = max(0.0, $o['kg'] - $reduced);
    $o['imported_value'] = round($base * $o['valuation'], 2);
    $o['need_kg'] = round($base * (1 - $o['waste'] / 100), 3);
    $o['need_value'] = round($o['imported_value'] * (1 + $o['va'] / 100), 2);
    $o['done_kg'] = round($o['pre_kg'] + ($used['kg'] ?? 0), 3);
    $o['done_value'] = round($o['pre_value'] + ($used['value'] ?? 0), 2);
    $o['allocs'] = $used['n'] ?? 0;
    $o['kg_open'] = max(0.0, round($o['need_kg'] - $o['done_kg'], 3));
    $o['value_open'] = max(0.0, round($o['need_value'] - $o['done_value'], 2));
    /* what an allocation may still take: the full (reduced) kg, not just the 95% */
    $o['max_kg'] = max(0.0, round($base - $o['done_kg'], 3));
    $o['met'] = $o['kg_open'] <= 0.0005 && $o['value_open'] <= 0.005;
    $o['left'] = $o['deadline'] ? (int)round((strtotime($o['deadline']) - strtotime($today)) / 86400) : null;
    $o['pill'] = $o['met'] ? ['g', 'Discharged'] : ($o['left'] === null ? ['n', 'no deadline yet']
        : ($o['left'] < 0 ? ['r', 'Overdue ' . (-$o['left']) . ' d'] : ($o['left'] <= 30 ? ['r', $o['left'] . ' days left'] : ($o['left'] <= 60 ? ['o', $o['left'] . ' days left'] : ['n', $o['left'] . ' days left']))));
    /* the floor per kg the value check uses */
    $o['floor_per_kg'] = $o['valuation'] * (1 + $o['va'] / 100);
    return $o;
}

/* ====================================================== the export side */
/* Shipments that can be allocated: newest first, with any allocation they already have. */
function imp_efs_shipments(int $limit = 400): array
{
    try {
        $s = db()->query("SELECT s.id, s.invoice_no, s.invoice_date, s.buyer_name, s.currency, s.total_amount,
                (SELECT a.id FROM imp_efs_alloc a WHERE a.shipment_id = s.id AND a.status <> 'reversed' ORDER BY a.id DESC LIMIT 1) alloc_id
            FROM shipments s ORDER BY s.id DESC LIMIT " . (int)$limit);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}
function imp_efs_shipment(int $id): ?array
{
    try { $s = db()->prepare("SELECT * FROM shipments WHERE id=?"); $s->execute([$id]); return $s->fetch() ?: null; }
    catch (Throwable $e) { return null; }
}
/* The lines an allocation is built from: the shipment's CUSTOMS invoice
   (exp_doc_lines, view 'customs') when it has one, else its sale invoice
   lines. The hash lets a later change to them be noticed ("recheck"). */
function imp_efs_source(int $shipmentId): array
{
    $lines = []; $kind = 'customs';
    try {
        $s = db()->prepare("SELECT id, line_no, description, hs_code, unit, qty, amount FROM exp_doc_lines WHERE shipment_id=? AND view='customs' ORDER BY line_no, id");
        $s->execute([$shipmentId]);
        foreach ($s->fetchAll() as $r) $lines[] = ['id' => (int)$r['id'], 'description' => (string)$r['description'], 'hs' => (string)$r['hs_code'],
            'qty' => (float)$r['qty'], 'unit' => (string)$r['unit'], 'amount' => round((float)$r['amount'], 2)];
    } catch (Throwable $e) {}
    if (!$lines) {
        $kind = 'invoice';
        try {
            $s = db()->prepare("SELECT id, line_no, product_name, qty, unit, amount FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
            $s->execute([$shipmentId]);
            foreach ($s->fetchAll() as $r) $lines[] = ['id' => (int)$r['id'], 'description' => (string)$r['product_name'], 'hs' => '',
                'qty' => (float)$r['qty'], 'unit' => (string)$r['unit'], 'amount' => round((float)$r['amount'], 2)];
        } catch (Throwable $e) {}
    }
    $h = [];
    foreach ($lines as $l) $h[] = $l['id'] . '|' . rtrim(rtrim(number_format($l['qty'], 3, '.', ''), '0'), '.') . '|' . number_format($l['amount'], 2, '.', '');
    return ['kind' => $kind, 'lines' => $lines, 'hash' => sha1($kind . ':' . implode(';', $h))];
}

/* ===================================================== allocations: read */
function imp_efs_alloc(int $id): ?array
{
    try {
        $s = db()->prepare("SELECT * FROM imp_efs_alloc WHERE id=?"); $s->execute([$id]);
        $a = $s->fetch();
        if (!$a) return null;
        $s = db()->prepare("SELECT * FROM imp_efs_alloc_lines WHERE alloc_id=? ORDER BY sort_order, id"); $s->execute([$id]);
        $a['lines'] = $s->fetchAll();
        $s = db()->prepare("SELECT * FROM imp_efs_alloc_uses WHERE alloc_id=? ORDER BY id"); $s->execute([$id]);
        $uses = $s->fetchAll();
        foreach ($a['lines'] as &$l) $l['uses'] = array_values(array_filter($uses, fn($u) => (int)$u['alloc_line_id'] === (int)$l['id']));
        unset($l);
        $a['rates_arr'] = json_decode((string)$a['rates'], true) ?: [];
        return $a;
    } catch (Throwable $e) { return null; }
}
/* every allocation with its totals per obligation — the summary is always the sum of the uses */
function imp_efs_allocs(?string $targetKey = null): array
{
    $out = [];
    try {
        $rows = db()->query("SELECT a.*, s.invoice_no, s.invoice_date, s.buyer_name, s.total_amount FROM imp_efs_alloc a LEFT JOIN shipments s ON s.id = a.shipment_id ORDER BY a.id DESC")->fetchAll();
        $uses = db()->query("SELECT alloc_id, target_kind, target_id, SUM(kg) kg, SUM(value_export) ve, SUM(value_target) vt, SUM(floor_target) ft FROM imp_efs_alloc_uses GROUP BY alloc_id, target_kind, target_id")->fetchAll();
        $lines = db()->query("SELECT alloc_id, COUNT(*) n, SUM(efs_value) v FROM imp_efs_alloc_lines GROUP BY alloc_id")->fetchAll();
    } catch (Throwable $e) { return []; }
    $per = []; foreach ($uses as $u) $per[(int)$u['alloc_id']][$u['target_kind'] === 'line' ? 'L' . (int)$u['target_id'] : 'O' . (int)$u['target_id']] = $u;
    $ln = []; foreach ($lines as $l) $ln[(int)$l['alloc_id']] = $l;
    foreach ($rows as $a) {
        $p = $per[(int)$a['id']] ?? [];
        if ($targetKey !== null && !isset($p[$targetKey])) continue;
        $a['per'] = $p;
        $a['n_lines'] = (int)($ln[(int)$a['id']]['n'] ?? 0);
        $a['counted'] = round((float)($ln[(int)$a['id']]['v'] ?? 0), 2);
        $a['kg'] = array_sum(array_map(fn($u) => (float)$u['kg'], $p));
        $a['recheck'] = imp_efs_recheck($a);
        $out[] = $a;
    }
    return $out;
}
/* Has the customs invoice (or sale invoice) changed since the allocation was saved? */
function imp_efs_recheck(array $a): string
{
    if ($a['status'] === 'reversed') return '';
    $src = imp_efs_source((int)$a['shipment_id']);
    if (!$src['lines']) return 'the shipment has no invoice lines any more';
    if ($src['kind'] !== $a['source']) return $src['kind'] === 'customs' ? 'a customs invoice was made after this was saved' : 'the customs invoice was removed';
    if ($src['hash'] !== (string)$a['source_hash']) return 'the ' . ($src['kind'] === 'customs' ? 'customs' : 'sale') . ' invoice changed after this was saved';
    return '';
}

/* ================================================= allocations: the maths */
/* One allocation form, worked out — the same rules the page shows live.
   $form = ['shipment_id', 'rates' => [cur => rate], 'export_gd_no', 'export_gd_date', 'gd_net_kg', 'gd_fob',
            'below_reason', 'warn_reason', 'lines' => [['src_id', 'efs_value', 'manual', 'uses' => [['key', 'kg', 'value']]]]]
   rate = how many units of the IMPORT's currency one unit of the shipment's currency is worth.
   Returns the worked lines, the summary per obligation, errors (block saving) and notes (shown). */
function imp_efs_compute(array $form, ?int $allocId = null): array
{
    $ship = imp_efs_shipment((int)($form['shipment_id'] ?? 0));
    $res = ['lines' => [], 'per' => [], 'errors' => [], 'notes' => [], 'counted' => 0.0, 'kg' => 0.0, 'below' => 0.0, 'warn' => [], 'rates' => [], 'source' => null];
    if (!$ship) { $res['errors'][] = 'Pick the export shipment.'; return $res; }
    $cur = (string)($ship['currency'] ?: 'USD');
    $src = imp_efs_source((int)$ship['id']);
    $res['source'] = $src;
    $srcById = []; foreach ($src['lines'] as $l) $srcById[(int)$l['id']] = $l;
    $obl = imp_efs_obligations(null, $allocId);
    $rateOf = function (string $tc) use ($cur, $form, &$res): ?float {
        if ($tc === $cur) return 1.0;
        $r = (float)($form['rates'][$tc] ?? 0);
        if ($r > 0) { $res['rates'][$tc] = $r; return $r; }
        return null;
    };
    $missingRate = [];
    foreach ((array)($form['lines'] ?? []) as $li => $fl) {
        $sl = $srcById[(int)($fl['src_id'] ?? 0)] ?? null;
        if (!$sl) { $res['errors'][] = 'An export line is no longer on the shipment — open it again.'; continue; }
        $efs = round((float)($fl['efs_value'] ?? 0), 2);
        $name = mb_substr($sl['description'], 0, 60);
        if ($efs <= 0) $res['errors'][] = $name . ': type the EFS value of this line.';
        if ($efs > $sl['amount'] + 0.005) $res['errors'][] = $name . ': the EFS value (' . number_format($efs, 2) . ') is more than the line value (' . number_format($sl['amount'], 2) . ').';
        $uses = [];
        foreach ((array)($fl['uses'] ?? []) as $u) {
            $key = (string)($u['key'] ?? ''); $kg = round((float)($u['kg'] ?? 0), 3);
            if ($key === '' && $kg == 0) continue;                     /* an empty row */
            if (!isset($obl[$key])) { $res['errors'][] = $name . ': pick the import GD line for every row.'; continue; }
            if ($kg <= 0) { $res['errors'][] = $name . ': type the kg used from ' . $obl[$key]['gd_no'] . '.'; continue; }
            $o = $obl[$key];
            $rate = $rateOf($o['currency']);
            if ($rate === null) { $missingRate[$o['currency']] = true; $rate = 0.0; }
            $uses[] = ['key' => $key, 'kg' => $kg, 'typed' => round((float)($u['value'] ?? 0), 2), 'rate' => $rate, 'o' => $o];
        }
        if (!$uses) $res['errors'][] = $name . ': add the import GD line it used, with kg.';
        /* value per use, in the shipment's currency */
        $manual = !empty($fl['manual']);
        if ($manual) {
            foreach ($uses as &$u) $u['value_export'] = $u['typed'];
            unset($u);
            $sum = array_sum(array_column($uses, 'value_export'));
            if ($uses && abs($sum - $efs) > 0.005) $res['errors'][] = $name . ': the values typed add up to ' . number_format($sum, 2) . ', not the line\'s ' . number_format($efs, 2) . '.';
        } else {
            /* split by material value: kg × valuation, brought to the shipment's currency */
            $w = array_map(fn($u) => $u['rate'] > 0 ? $u['kg'] * $u['o']['valuation'] / $u['rate'] : $u['kg'] * $u['o']['valuation'], $uses);
            $sw = array_sum($w); $left = $efs;
            foreach ($uses as $i => &$u) {
                $v = $i === count($uses) - 1 ? $left : ($sw > 0 ? round($efs * $w[$i] / $sw, 2) : 0.0);
                $left = round($left - $v, 2);
                $u['value_export'] = $v;
            }
            unset($u);
        }
        foreach ($uses as &$u) {
            $u['value_target'] = round($u['value_export'] * $u['rate'], 2);
            $u['floor_target'] = round($u['kg'] * $u['o']['floor_per_kg'], 2);
            $u['diff'] = round($u['value_target'] - $u['floor_target'], 2);
            $k = $u['key'];
            $res['per'][$k] = $res['per'][$k] ?? ['kg' => 0.0, 'value_target' => 0.0, 'value_export' => 0.0, 'floor_target' => 0.0, 'o' => $u['o']];
            $res['per'][$k]['kg'] += $u['kg']; $res['per'][$k]['value_target'] += $u['value_target'];
            $res['per'][$k]['value_export'] += $u['value_export']; $res['per'][$k]['floor_target'] += $u['floor_target'];
            $res['kg'] += $u['kg'];
        }
        unset($u);
        $res['counted'] += $efs;
        $res['lines'][] = ['src' => $sl, 'efs_value' => $efs, 'manual' => $manual, 'uses' => $uses];
    }
    $res['counted'] = round($res['counted'], 2);
    foreach (array_keys($missingRate) as $c) $res['errors'][] = 'Type the exchange rate from the export GD: 1 ' . $cur . ' = how many ' . $c . '?';
    if (!$res['lines']) $res['errors'][] = 'Tick at least one export line that used EFS material.';
    /* per obligation: never more kg than it has left; value below the floor; more than received */
    foreach ($res['per'] as $k => &$p) {
        $o = $p['o'];
        if ($p['kg'] > $o['max_kg'] + 0.0005)
            $res['errors'][] = $o['gd_no'] . ' (' . $o['ref'] . '): ' . imp_efs_q($p['kg']) . ' kg is more than the ' . imp_efs_q($o['max_kg']) . ' kg it has left.';
        $p['diff'] = round($p['value_target'] - $p['floor_target'], 2);
        if ($o['kind'] === 'line' && $o['received_kg'] !== null && $o['done_kg'] + $p['kg'] > $o['received_kg'] + 0.0005)
            $res['warn'][] = $o['gd_no'] . ' (' . $o['ref'] . '): ' . imp_efs_q($o['done_kg'] + $p['kg']) . ' kg exported against ' . imp_efs_q($o['received_kg']) . ' kg received at Gate In';
        /* oldest first: an older GD line of the same HS that still has kg */
        foreach ($obl as $k2 => $o2) {
            if ($k2 === $k || $o2['hs'] === '' || $o2['hs'] !== $o['hs'] || $o2['max_kg'] <= 0.0005 || isset($res['per'][$k2])) continue;
            if (($o2['deadline'] ?? '9999') < ($o['deadline'] ?? '9999')) {
                $res['notes'][] = 'Older pending for HS ' . $o['hs'] . ': ' . $o2['gd_no'] . ' (' . $o2['ref'] . ') still has ' . imp_efs_q($o2['max_kg']) . ' kg, due ' . imp_dmy($o2['deadline']) . ' — use it first?';
                break;
            }
        }
    }
    unset($p);
    /* below the assessed value: per USE (one line above never hides another below) */
    foreach ($res['lines'] as $l) foreach ($l['uses'] as $u) if ($u['diff'] < -0.005) $res['below'] += -$u['diff'];
    $res['below'] = round($res['below'], 2);
    if ($res['below'] > 0 && trim((string)($form['below_reason'] ?? '')) === '')
        $res['errors'][] = 'The value counted is ' . number_format($res['below'], 2) . ' below the assessed value of the import material — correct it or give the reason.';
    if ($res['warn'] && trim((string)($form['warn_reason'] ?? '')) === '')
        $res['errors'][] = 'More kg exported than received at Gate In — give the reason.';
    /* the export GD: differences shown, never blocked */
    if ((float)($form['gd_net_kg'] ?? 0) > 0 && abs($res['kg'] - (float)$form['gd_net_kg']) > 0.0005)
        $res['notes'][] = 'Allocated ' . imp_efs_q($res['kg']) . ' kg of import material; the export GD net weight is ' . imp_efs_q((float)$form['gd_net_kg']) . ' kg.';
    if ((float)($form['gd_fob'] ?? 0) > 0 && $res['counted'] > (float)$form['gd_fob'] + 0.005)
        $res['notes'][] = 'EFS value counted ' . number_format($res['counted'], 2) . ' is more than the export GD FOB ' . number_format((float)$form['gd_fob'], 2) . '.';
    return $res;
}
function imp_efs_q(float $v): string { return rtrim(rtrim(number_format($v, 3), '0'), '.'); }

/* ================================================ allocations: write */
/* Save a new allocation or change an open one. One per shipment; a filed or
   reversed one is never changed here. */
function imp_efs_save(array $form, ?int $allocId = null): array
{
    $shipId = (int)($form['shipment_id'] ?? 0);
    if ($allocId) {
        $old = imp_efs_alloc($allocId);
        if (!$old) return ['ok' => false, 'error' => 'That allocation is not there.'];
        if ($old['status'] !== 'open') return ['ok' => false, 'error' => 'This allocation is ' . $old['status'] . ' — it can only be reversed.'];
        $shipId = (int)$old['shipment_id']; $form['shipment_id'] = $shipId;
    }
    $c = db()->prepare("SELECT id FROM imp_efs_alloc WHERE shipment_id=? AND status <> 'reversed'" . ($allocId ? " AND id <> " . (int)$allocId : ""));
    $c->execute([$shipId]);
    if ($c->fetchColumn()) return ['ok' => false, 'error' => 'This shipment already has an allocation. Open it to change it.'];
    $res = imp_efs_compute($form, $allocId);
    if ($res['errors']) return ['ok' => false, 'error' => implode(' ', $res['errors']), 'res' => $res];
    $ship = imp_efs_shipment($shipId);
    $uid = current_user()['id'] ?? null;
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $head = [(string)($ship['currency'] ?: 'USD'), json_encode($res['rates']), $res['source']['kind'], $res['source']['hash'],
                 mb_substr(trim((string)($form['export_gd_no'] ?? '')), 0, 80) ?: null, imp_date($form['export_gd_date'] ?? ''),
                 (float)($form['gd_net_kg'] ?? 0) > 0 ? round((float)$form['gd_net_kg'], 3) : null, (float)($form['gd_fob'] ?? 0) > 0 ? round((float)$form['gd_fob'], 2) : null,
                 $res['below'] > 0 ? mb_substr(trim((string)$form['below_reason']), 0, 300) : null, $res['warn'] ? mb_substr(trim((string)$form['warn_reason']), 0, 300) : null];
        if ($allocId) {
            db()->prepare("UPDATE imp_efs_alloc SET currency=?, rates=?, source=?, source_hash=?, export_gd_no=?, export_gd_date=?, gd_net_kg=?, gd_fob=?, below_reason=?, warn_reason=?, updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute(array_merge($head, [$uid, $allocId]));
            db()->prepare("DELETE FROM imp_efs_alloc_uses WHERE alloc_id=?")->execute([$allocId]);
            db()->prepare("DELETE FROM imp_efs_alloc_lines WHERE alloc_id=?")->execute([$allocId]);
            $id = $allocId;
        } else {
            db()->prepare("INSERT INTO imp_efs_alloc (currency, rates, source, source_hash, export_gd_no, export_gd_date, gd_net_kg, gd_fob, below_reason, warn_reason, shipment_id, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'open',?,NOW())")
                ->execute(array_merge($head, [$shipId, $uid]));
            $id = (int)db()->lastInsertId();
        }
        $insL = db()->prepare("INSERT INTO imp_efs_alloc_lines (alloc_id, src_kind, src_id, description, hs_code, line_value, efs_value, manual, sort_order) VALUES (?,?,?,?,?,?,?,?,?)");
        $insU = db()->prepare("INSERT INTO imp_efs_alloc_uses (alloc_id, alloc_line_id, target_kind, target_id, kg, value_export, value_target, floor_target) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($res['lines'] as $n => $l) {
            $insL->execute([$id, $res['source']['kind'], $l['src']['id'], mb_substr($l['src']['description'], 0, 300), $l['src']['hs'] ?: null, $l['src']['amount'], $l['efs_value'], $l['manual'] ? 1 : 0, $n]);
            $lid = (int)db()->lastInsertId();
            foreach ($l['uses'] as $u) $insU->execute([$id, $lid, $u['o']['kind'], $u['o']['id'], $u['kg'], $u['value_export'], $u['value_target'], $u['floor_target']]);
        }
        imp_efs_log($res, ($allocId ? 'EFS allocation changed' : 'EFS allocation saved') . ' · ' . ($ship['invoice_no'] ?? ''), $res['below'] > 0 ? (string)$form['below_reason'] : '');
        if ($own) db()->commit();
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Could not save: ' . $e->getMessage()];
    }
    return ['ok' => true, 'id' => $id, 'res' => $res];
}
/* on the timeline of every import it touched (opening balances under 0) */
function imp_efs_log(array $res, string $what, string $reason = ''): void
{
    $by = [];
    foreach ($res['per'] as $p) $by[(int)$p['o']['import_id']][] = $p['o']['ref'] . ': ' . imp_efs_q($p['kg']) . ' kg · ' . $p['o']['currency'] . ' ' . number_format($p['value_target'], 2);
    foreach ($by as $iid => $parts) imp_log($iid, mb_substr($what, 0, 60), implode(' · ', $parts), $reason);
}
function imp_efs_file(int $id, ?string $date, string $ref): array
{
    $a = imp_efs_alloc($id);
    if (!$a) return ['ok' => false, 'error' => 'That allocation is not there.'];
    if ($a['status'] !== 'open') return ['ok' => false, 'error' => 'Only an open allocation can be filed.'];
    if (!$date || trim($ref) === '') return ['ok' => false, 'error' => 'Give the date and the reference it was filed under.'];
    if ($why = imp_efs_recheck($a)) return ['ok' => false, 'error' => 'Recheck first: ' . $why . '. Open it, check the figures and save.'];
    db()->prepare("UPDATE imp_efs_alloc SET status='filed', filed_on=?, filed_ref=?, filed_by=? WHERE id=? AND status='open'")
        ->execute([$date, mb_substr(trim($ref), 0, 120), current_user()['id'] ?? null, $id]);
    foreach (imp_efs_alloc_imports($a) as $iid) imp_log($iid, 'EFS allocation filed', 'Filed with customs ' . imp_dmy($date) . ' · ' . trim($ref));
    return ['ok' => true];
}
function imp_efs_reverse(int $id, string $reason): array
{
    $a = imp_efs_alloc($id);
    if (!$a) return ['ok' => false, 'error' => 'That allocation is not there.'];
    if ($a['status'] === 'reversed') return ['ok' => false, 'error' => 'It is already reversed.'];
    if (mb_strlen(trim($reason)) < 5) return ['ok' => false, 'error' => 'Give the reason (at least 5 characters) — it stays on the record.'];
    db()->prepare("UPDATE imp_efs_alloc SET status='reversed', reversed_by=?, reversed_at=NOW(), reverse_reason=? WHERE id=?")
        ->execute([current_user()['id'] ?? null, mb_substr(trim($reason), 0, 300), $id]);
    foreach (imp_efs_alloc_imports($a) as $iid) imp_log($iid, 'EFS allocation reversed', 'Was ' . $a['status'] . '; its kg and value go back to the import lines', $reason);
    return ['ok' => true];
}
function imp_efs_alloc_imports(array $a): array
{
    $ids = [];
    foreach ($a['lines'] as $l) foreach ($l['uses'] as $u) {
        if ($u['target_kind'] === 'opening') { $ids[0] = 0; continue; }
        try { $s = db()->prepare("SELECT import_id FROM imp_lines WHERE id=?"); $s->execute([(int)$u['target_id']]); $v = (int)$s->fetchColumn(); if ($v) $ids[$v] = $v; } catch (Throwable $e) {}
    }
    return array_keys($ids);
}

/* ===================================== one obligation: extension, reduction */
function imp_efs_set_extension(string $kind, int $id, ?string $date, string $ref): array
{
    if ($date !== null && trim($ref) === '') return ['ok' => false, 'error' => 'An extended deadline needs the customs reference.'];
    if ($kind === 'line') {
        db()->prepare("UPDATE imp_lines SET efs_ext_date=?, efs_ext_ref=? WHERE id=?")->execute([$date, $date ? mb_substr(trim($ref), 0, 160) : null, $id]);
        $s = db()->prepare("SELECT import_id FROM imp_lines WHERE id=?"); $s->execute([$id]); $iid = (int)$s->fetchColumn();
    } else {
        db()->prepare("UPDATE imp_opening_efs SET ext_date=?, ext_ref=? WHERE id=?")->execute([$date, $date ? mb_substr(trim($ref), 0, 160) : null, $id]);
        $iid = 0;
    }
    imp_log($iid, $date ? 'EFS deadline extended' : 'EFS extension removed', ($kind === 'line' ? 'line ' : 'opening ') . $id . ($date ? ' → ' . imp_dmy($date) . ' · ' . trim($ref) : ''));
    return ['ok' => true];
}
function imp_efs_add_reduction(string $kind, int $id, float $kg, string $ref, string $reason): array
{
    if ($kg <= 0) return ['ok' => false, 'error' => 'Type the kg customs approved to reduce.'];
    if (trim($ref) === '' || trim($reason) === '') return ['ok' => false, 'error' => 'An approved reduction needs the customs reference and the reason.'];
    $o = imp_efs_obligations()[($kind === 'line' ? 'L' : 'O') . $id] ?? null;
    if (!$o) return ['ok' => false, 'error' => 'That EFS line is not there.'];
    if ($kg > $o['max_kg'] + 0.0005) return ['ok' => false, 'error' => 'More than the ' . imp_efs_q($o['max_kg']) . ' kg not yet exported.'];
    db()->prepare("INSERT INTO imp_efs_reductions (target_kind, target_id, kg, ref, reason, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())")
        ->execute([$kind, $id, round($kg, 3), mb_substr(trim($ref), 0, 160), mb_substr(trim($reason), 0, 300), current_user()['id'] ?? null]);
    imp_log((int)$o['import_id'], 'EFS approved reduction', $o['ref'] . ': ' . imp_efs_q($kg) . ' kg · ' . trim($ref), $reason);
    return ['ok' => true];
}
function imp_efs_void_reduction(int $rid, string $reason): array
{
    if (mb_strlen(trim($reason)) < 5) return ['ok' => false, 'error' => 'Give the reason (at least 5 characters).'];
    db()->prepare("UPDATE imp_efs_reductions SET is_void=1, void_reason=?, voided_by=?, voided_at=NOW() WHERE id=? AND is_void=0")
        ->execute([mb_substr(trim($reason), 0, 300), current_user()['id'] ?? null, $rid]);
    return ['ok' => true];
}
function imp_efs_reductions(string $kind, int $id): array
{
    try { $s = db()->prepare("SELECT * FROM imp_efs_reductions WHERE target_kind=? AND target_id=? ORDER BY id"); $s->execute([$kind, $id]); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/* ===================================================================== CSV */
/* The reconciliation as a spreadsheet — afnan's own format for now, to be
   matched to the customs system's later. */
function imp_efs_csv(array $obl): string
{
    $f = fopen('php://temp', 'r+');
    /* the escape character given explicitly: PHP 8.4 warns otherwise, and the warning would land in the file */
    fputcsv($f, ['Import GD', 'GD date', 'Reference', 'HS', 'Product', 'Currency', 'Imported kg', 'kg source', 'Valuation / kg', 'Valuation source', 'Wastage %', 'Value add %',
                 'Approved reduction kg', 'Must export kg', 'Must export value', 'Exported kg', 'Exported value', 'kg left', 'Value left', 'Deadline', 'Deadline basis', 'Status'], ',', '"', '');
    foreach ($obl as $o) fputcsv($f, [$o['gd_no'], $o['gd_date'], $o['ref'], $o['hs'], $o['product'], $o['currency'], round($o['kg'], 3), $o['kg_source'], round($o['valuation'], 4), $o['val_source'],
        $o['waste'], $o['va'], round($o['reduced_kg'], 3), $o['need_kg'], $o['need_value'], $o['done_kg'], $o['done_value'], $o['kg_open'], $o['value_open'], $o['deadline'], $o['deadline_from'], $o['pill'][1]], ',', '"', '');
    rewind($f);
    return (string)stream_get_contents($f);
}
