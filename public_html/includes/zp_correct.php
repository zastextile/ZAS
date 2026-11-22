<?php
/*
  BOOKED WORK — the helpers Daily Production Entry and Correct an Entry share
  ==========================================================================
  (moved here 22 Nov 2026 so both pages merge, group, edit and cancel by
  exactly the same rules — two copies would drift, and wages would differ by
  which screen was used.)

  Needs includes/zprod.php loaded first.
*/

/* customer, size, description and who booked it, for a list of entries —
   one small query for all of them */
function ze_entries_more(array $rows): array {
    if (!$rows) return $rows;
    try {
        $tid = array_map(fn($t) => (int)$t['id'], $rows);
        $st = db()->prepare('SELECT e.id, pf.customer_name, pi.size, pi.description, u.name uname, u2.name cname
                             FROM zp_entries e
                             LEFT JOIN proforma_items pi ON pi.id = e.proforma_item_id
                             LEFT JOIN proforma_invoices pf ON pf.id = e.proforma_id
                             LEFT JOIN users u ON u.id = e.created_by
                             LEFT JOIN users u2 ON u2.id = e.cancelled_by
                             WHERE e.id IN (' . implode(',', array_fill(0, count($tid), '?')) . ')');
        $st->execute($tid);
        $more = [];
        foreach ($st->fetchAll() as $m) $more[(int)$m['id']] = $m;
        foreach ($rows as &$t) {
            $m = $more[(int)$t['id']] ?? [];
            $t['customer'] = (string)($m['customer_name'] ?? ''); $t['size'] = (string)($m['size'] ?? '');
            $t['descr'] = trim((string)($m['description'] ?? '')); $t['uname'] = (string)($m['uname'] ?? '');
            $t['cname'] = (string)($m['cname'] ?? '');
        }
        unset($t);
    } catch (Throwable $e) {}
    return $rows;
}

/* HOW EACH WORKER IS PAID (owner, 19 Nov 2026): fixed salary, daily wages or
   contractor, from People Finance — one small query, read only. */
function ze_pay_types(): array {
    $out = [];
    try {
        foreach (db()->query("SELECT w.id, f.pay_type FROM zp_workers w JOIN att_fin_profiles f ON f.employee_id = w.att_employee_id WHERE f.pay_type IS NOT NULL")->fetchAll() as $r)
            $out[(int)$r['id']] = (string)$r['pay_type'];
    } catch (Throwable $e) { $out = []; }
    return $out;
}

function ze_post_ids(): array {
    return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['entry_id'] ?? ''))))));
}

/* put cancelled lines back to active — only ever lines this request itself cancelled */
function ze_restore(array $ids): void {
    if (!$ids) return;
    db()->prepare('UPDATE zp_entries SET status=\'active\', cancelled_by=NULL, cancelled_at=NULL, cancel_reason=NULL WHERE status=\'cancelled\' AND id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')')->execute(array_values($ids));
}

function ze_cancel_all(array $ids, string $reason, int $userId): array {
    if (!$ids) return ['ok' => false, 'error' => 'Nothing to cancel.'];
    $done = [];
    foreach ($ids as $eid) {
        $r = zp_cancel_entry($eid, $reason, $userId);
        if (!$r['ok']) { ze_restore($done); return ['ok' => false, 'error' => $r['error'] . ' Nothing was cancelled.']; }
        $done[] = $eid;
    }
    return ['ok' => true, 'error' => ''];
}

/* ============================================================
   SPLITTING ONE BOOKING ACROSS THE SIZES — BY ORDERED %
   ============================================================
   (owner, 22 Nov 2026: "use the qty % same as ordered %")
   $mem: the merged row's members [order line, operation, left, need], in
   operation order. Operations are filled first-operation-first (as before);
   inside one operation the quantity is shared by each size's share of what
   the order needs of that part: Single 1,080 / Double 2,700 / King 1,620
   covers → 20% / 50% / 30%.
   $open (cutting): no limit — the last operation takes whatever is beyond the
   plan. Otherwise a size never gets more than it has left; what it cannot
   take is shared among the others by the same %.
   $taken: [ "line:op" => qty ] already on the sheet, not free again.
   Returns [ [ "line:op" => qty ], what could not be placed ].
   assets: the browser runs the same rules (zeAlloc in production_entry.php). */
function ze_alloc(array $mem, float $qty, bool $open, array $taken = []): array {
    $unit = abs($qty - round($qty)) < 0.00001 ? 1.0 : 0.01;
    $rem = (int)round($qty / $unit);
    $groups = [];
    foreach ($mem as $m) $groups[(int)$m[1]][] = $m;
    $out = []; $gi = 0; $ng = count($groups);
    foreach ($groups as $ms) {
        $gi++;
        if ($rem <= 0) break;
        $caps = []; $w = []; $plan = [];
        foreach ($ms as $m) {
            $k = $m[0] . ':' . $m[1];
            $free = max(0.0, (float)$m[2] - (float)($taken[$k] ?? 0));
            $caps[$k] = $open ? PHP_INT_MAX : (int)floor($free / $unit + 0.00001);
            $w[$k] = max(0.0, (float)($m[3] ?? 0));
            $plan[$k] = (int)floor($free / $unit + 0.00001);
        }
        /* how much this operation takes: open — its plan, the last one the rest */
        $room = $open ? ($gi === $ng ? $rem : array_sum($plan)) : array_sum($caps);
        $give = min($rem, $room);
        $act = array_keys(array_filter($caps, fn($c) => $c > 0));
        $left = $give;
        while ($left > 0 && $act) {
            $W = 0.0; foreach ($act as $k) $W += $w[$k];
            $share = [];
            foreach ($act as $k) $share[$k] = $W > 0 ? $left * $w[$k] / $W : $left / count($act);
            $base = []; $sum = 0;
            foreach ($share as $k => $x) { $base[$k] = (int)floor($x + 0.000001); $sum += $base[$k]; }
            /* largest remainder: whole pieces, nothing lost to rounding */
            $order = array_keys($share);
            usort($order, fn($a, $b) => ($share[$b] - $base[$b]) <=> ($share[$a] - $base[$a]));
            for ($i = 0; $sum < $left && $i < count($order); $i++) { $base[$order[$i]]++; $sum++; }
            $next = [];
            foreach ($base as $k => $n) {
                $n = min($n, $caps[$k]);
                if ($n > 0) { $out[$k] = ($out[$k] ?? 0) + $n; $caps[$k] -= $n; $left -= $n; }
                if ($caps[$k] > 0) $next[] = $k;
            }
            if (count($next) === count($act) && $sum === 0) break;
            $act = $next;
        }
        $rem -= ($give - $left);
    }
    foreach ($out as $k => $n) $out[$k] = round($n * $unit, 2);
    return [$out, round($rem * $unit, 2)];
}

function ze_edit_row(array $ids, string $date, float $newQty, int $newWorker, string $why, int $userId, ?string $newDate = null, bool $exact = false): array {
    $why = trim($why);
    /* THE DAY CAN MOVE TOO (owner, 22 Nov 2026: Correct an Entry edits qty,
       worker and date). The new lines go on $bookOn; never a future day. */
    $bookOn = ($newDate !== null && trim($newDate) !== '') ? trim($newDate) : $date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookOn) || strtotime($bookOn) === false) return ['ok' => false, 'error' => 'That date is not a real date.'];
    if ($bookOn > date('Y-m-d')) return ['ok' => false, 'error' => 'That date is in the future — work cannot be booked before it happens.'];
    if (!$ids)          return ['ok' => false, 'error' => 'Nothing to edit.'];
    /* EXACT: one saved line, re-booked on the SAME order line and operation
       (Correct an Entry, "every line" view) — a King line stays King instead
       of being shared down the merged row first-size-first. */
    if ($exact && count($ids) !== 1) return ['ok' => false, 'error' => 'Edit one line at a time in this view.'];
    if ($newQty <= 0)   return ['ok' => false, 'error' => 'The new quantity must be more than 0. To remove the line, use Cancel.'];
    if ($why === '')    return ['ok' => false, 'error' => 'Give a reason for the edit — it is kept against the old line.'];
    $st = db()->prepare('SELECT * FROM zp_entries WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $st->execute($ids);
    $old = $st->fetchAll();
    if (count($old) !== count($ids)) return ['ok' => false, 'error' => 'Some of those lines no longer exist. Reload the page.'];
    $f = $old[0];
    foreach ($old as $o) {
        /* the row must be ONE booked row of THIS day: same worker, stage, rate, still active */
        if ($o['status'] !== 'active' || $o['entry_date'] !== $date || (int)$o['worker_id'] !== (int)$f['worker_id']
            || $o['stage'] !== $f['stage'] || (string)$o['rate_applied'] !== (string)$f['rate_applied']
            || (string)$o['op_kind'] !== (string)$f['op_kind'] || (int)$o['proforma_id'] !== (int)$f['proforma_id']) {
            return ['ok' => false, 'error' => 'Those lines changed since the page opened. Reload and try again.'];
        }
    }
    $wasQty = array_sum(array_map(fn($o) => (float)$o['qty'], $old));
    $worker = $newWorker ?: (int)$f['worker_id'];
    $qn = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
    if (abs($wasQty - $newQty) < 0.0001 && $worker === (int)$f['worker_id'] && $bookOn === $date) return ['ok' => false, 'error' => 'Nothing changed.'];

    $reason = 'Edited: was ' . $qn($wasQty) . ', now ' . $qn($newQty) . ($worker !== (int)$f['worker_id'] ? ', worker changed' : '')
            . ($bookOn !== $date ? ', moved to ' . date('j M', strtotime($bookOn)) : '') . ' — ' . $why;
    /* THE RESULT IS CHECKED, NOT THE HALF-WAY POINT. Cancelling 900 of cutting
       on its own would leave stitching above the cut for a moment, and the
       cancel guard would refuse it — though 900 → 800 is a perfectly good
       correction. So the old lines are marked cancelled here directly, the new
       quantity is booked, and only then is the whole flow checked; anything
       wrong and both steps are undone. */
    $prog0 = zp_progress_map();          // how the flow stood before, so old problems do not block a new fix
    db()->prepare('UPDATE zp_entries SET status=\'cancelled\', cancelled_by=?, cancelled_at=NOW(), cancel_reason=? WHERE status=\'active\' AND id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')')->execute(array_merge([$userId ?: null, mb_substr($reason, 0, 250)], $ids));

    /* share the new quantity down the same merged row the list shows, first
       operation first, oldest line first — with the old lines already freed */
    $kind = (string)($f['op_kind'] ?: 'part');
    $tag = 'edit ' . bin2hex(random_bytes(4)) . ' of #' . mb_substr(implode(',', $ids), 0, 60);
    if ($exact) {
        $rows = [['item_id' => (int)$f['proforma_item_id'], 'op_id' => (int)$f['op_id'], 'op_kind' => $kind, 'worker_id' => $worker, 'qty' => round($newQty, 2), 'note' => $tag]];
    } else {
        /* KEEP WHAT WAS REALLY BOOKED. The old lines (oldest first) are
           re-booked as they were — same size, same operation — with the new
           worker / date. A lower quantity comes off the LAST lines first (the
           booking filled them first-come-first, so this undoes it in
           reverse). Only a HIGHER quantity looks for room: the extra is shared
           down the same merged row the list shows, first operation first,
           oldest line first. (Re-sharing everything moved a worker's Hem onto
           Overlock — found in testing, 22 Nov 2026.) */
        $rows = []; $rem = round($newQty, 2); $keep = [];
        foreach ($old as $o) {
            if ($rem <= 0.0001) break;
            $take = min((float)$o['qty'], $rem);
            $k = (int)$o['proforma_item_id'] . ':' . (int)$o['op_id'];
            $keep[$k] = ($keep[$k] ?? 0) + $take;
            $rem = round($rem - $take, 2);
        }
        if ($rem > 0.0001) {
            $grp = null;
            foreach (ze_merge_work(zp_work_index()) as $g) {
                if (($g['kind'] ?? 'part') !== $kind) continue;
                foreach ($g['mem'] as $m) if ($m[0] === (int)$f['proforma_item_id'] && $m[1] === (int)$f['op_id']) { $grp = $g; break 2; }
            }
            if (!$grp) { ze_restore($ids); return ['ok' => false, 'error' => 'That job is no longer on the list (switched off or removed). Nothing was changed.']; }
            /* the extra is split by the ORDERED % of each size, the same as a new
               booking; the old lines are already freed, so what they take back
               is not free for the extra. Cutting is open (no limit). */
            [$add, $rest] = ze_alloc($grp['mem'], $rem, !empty($grp['open']), $keep);
            foreach ($add as $k => $q) $keep[$k] = ($keep[$k] ?? 0) + $q;
            if ($rest > 0.0001) { ze_restore($ids); return ['ok' => false, 'error' => 'Only ' . $qn($newQty - $rest) . ' can be booked on that row. Nothing was changed.']; }
        }
        foreach ($keep as $k => $q) {
            [$it, $op] = array_map('intval', explode(':', $k));
            $rows[] = ['item_id' => $it, 'op_id' => $op, 'op_kind' => $kind, 'worker_id' => $worker, 'qty' => round($q, 2), 'note' => $tag];
        }
    }
    $b = zp_book($bookOn, $rows, $userId);
    if (!$b['ok']) { ze_restore($ids); return ['ok' => false, 'error' => implode(' ', $b['errors']) . ' Nothing was changed.']; }
    /* now the whole flow of every line this edit touched */
    $st = db()->prepare('SELECT id, proforma_item_id, part_id FROM zp_entries WHERE note=? AND status=\'active\'');
    $st->execute([$tag]);
    $new = $st->fetchAll();
    $touch = [];
    foreach (array_merge($old, $new) as $x) if ($x['part_id']) $touch[(int)$x['proforma_item_id'] . ':' . (int)$x['part_id']] = [(int)$x['proforma_item_id'], (int)$x['part_id']];
    $prog = zp_progress_map();
    foreach ($touch as [$ti, $tp]) {
        $why2 = zp_flow_problem($ti, $tp, $prog);
        /* refused only when THIS edit breaks the order; a line that was
           already out of order (booked under the old rule) is left to be fixed */
        if ($why2 !== '' && zp_flow_problem($ti, $tp, $prog0) === '') {
            /* the new lines were made by this request a moment ago and nobody
               has seen them: they are removed, and the old ones put back */
            $nid = array_map(fn($x) => (int)$x['id'], $new);
            if ($nid) db()->prepare('DELETE FROM zp_entries WHERE id IN (' . implode(',', array_fill(0, count($nid), '?')) . ')')->execute($nid);
            ze_restore($ids);
            return ['ok' => false, 'error' => $why2 . ' Reduce the later stage first. Nothing was changed.'];
        }
    }
    return ['ok' => true, 'msg' => 'Edited: ' . $qn($wasQty) . ' → ' . $qn($newQty)
        . ($worker !== (int)$f['worker_id'] ? ', new worker' : '') . ($bookOn !== $date ? ', moved to ' . date('j M Y', strtotime($bookOn)) : '')
        . '. The old line stays on the list, marked, with the reason.'];
}

/* STAGE COLOURS, FIXED (owner, 21 Nov 2026): Cutting amber, Stitching blue,
   Packing green; any other stage its own colour, the same every day. */
function ze_stage_style(string $st): string {
    $l = mb_strtolower($st);
    if (str_contains($l, 'cut'))    return 'background:#fff4e2;color:#9a5710;border:1px solid #f1cf97';
    if (str_contains($l, 'stitch') || str_contains($l, 'sew') || str_contains($l, 'singer') || str_contains($l, 'overlock')) return 'background:#e8f1fc;color:#0a4f96;border:1px solid #b9d3f1';
    if (str_contains($l, 'pack'))   return 'background:#e8f6ec;color:#1f6b35;border:1px solid #bfe3ca';
    $pal = [['#f1ecfb','#5a3fa8','#d6cbf2'], ['#e3f5f7','#0e6170','#b3e0e6'], ['#fbe9f1','#9d174d','#f2c4d8'], ['#eef2f6','#33465f','#cfd8e3'], ['#fdeee8','#9a3412','#f6cdb9']];
    $c = $pal[abs(crc32($l)) % count($pal)];
    return 'background:' . $c[0] . ';color:' . $c[1] . ';border:1px solid ' . $c[2];
}

/* the page skin sets its own chip colours with !important; the stage colour must win */
function ze_stage_css(string $st): string { return str_replace(';', ' !important;', ze_stage_style($st)) . ' !important'; }

/* THE ROW TINT (owner, 21 Nov 2026: "background colour different for each
   stage"): a very light shade of the stage colour and a bar on the left. */
function ze_stage_tint(string $st): string {
    if ($st === '') return '';
    preg_match('/background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})/', ze_stage_style($st), $m);
    return 'background:' . $m[1] . ';box-shadow:inset 4px 0 0 ' . $m[2];
}

function ze_stage_pill(string $st): string {
    return $st === '' ? '' : '<span class="pill" style="' . ze_stage_css($st) . ';font-weight:600">' . e($st) . '</span>';
}

/* THE SUMMARISED LIST (owner, 21 Nov 2026): "sum qty where the proforma is the
   same and part, stage and rate / pc match — forget sizes", and then "if stage
   and rate are the same but it is two lines, sum it and show the operations
   with commas: Overlock, Hem, Flat lock".
   So one row per proforma + part + stage + rate. Its LEFT is every size and
   every operation of that stage added up, and its name lists the operations.
   A different rate or a different stage stays its own row.
   Each row keeps its members (order line, operation, what is left there),
   first operation first; inside one operation a booking is shared by the
   ORDERED % of each size (ze_alloc, since 22 Nov 2026). The pay is the same
   whichever member takes it, because the rate is the same; the server still
   checks and prices every member line exactly as before. */
function ze_merge_work(array $rows): array {
    $desc = [];
    $ids = array_values(array_unique(array_map(fn($r) => (int)$r['item'], $rows)));
    if ($ids) {
        try {
            $st = db()->prepare('SELECT id, description FROM proforma_items WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            foreach ($st->fetchAll() as $d) $desc[(int)$d['id']] = trim((string)($d['description'] ?? ''));
        } catch (Throwable $e) {}
    }
    $out = []; $at = []; $opPos = []; $itPos = [];
    foreach ($rows as $r) {
        $kind = (string)($r['kind'] ?? 'part');
        $opPos[$kind . ':' . (int)$r['op']] ??= count($opPos);    // the part's own order of operations
        $itPos[(int)$r['item']] ??= count($itPos);                 // the order's own order of lines
        $key = $r['pifull'] . '|' . $kind . '|' . (int)$r['part'] . '|' . (int)$r['sid'] . '|' . number_format((float)$r['rate'], 2, '.', '');
        $d = $desc[(int)$r['item']] ?? '';
        if (!isset($at[$key])) {
            $g = $r;
            $g['k'] = 'g' . substr(md5($key), 0, 12);
            $g['mem'] = []; $g['left'] = 0.0; $g['sz'] = []; $g['ons'] = []; $g['desc'] = []; $g['dk'] = []; $g['done'] = 1;
            $at[$key] = count($out); $out[] = $g;
        }
        $g =& $out[$at[$key]];
        $g['mem'][] = [(int)$r['item'], (int)$r['op'], round((float)$r['left'], 2), $opPos[$kind . ':' . (int)$r['op']], $itPos[(int)$r['item']], round((float)($r['need'] ?? 0), 2)];
        $g['open'] = (int)($r['open'] ?? 0);
        $g['left'] = round($g['left'] + (float)$r['left'], 2);
        if (!$r['done']) $g['done'] = 0;
        $g['sz'][(int)$r['item']] = (string)($r['size'] ?? '');
        if (!in_array((string)$r['on'], $g['ons'], true)) $g['ons'][] = (string)$r['on'];
        /* the same words typed twice with different capitals or spaces are one description */
        $dk = mb_strtolower(preg_replace('/\s+/', ' ', $d));
        if ($d !== '' && !isset($g['dk'][$dk])) { $g['dk'][$dk] = 1; $g['desc'][] = $d; }
        $g['hay'] .= ' ' . mb_strtolower((string)$r['on'] . ' ' . (string)($r['size'] ?? ''));
        unset($g);
    }
    foreach ($out as &$g) {
        /* first operation first, then oldest line first */
        usort($g['mem'], fn($a, $b) => [$a[3], $a[4]] <=> [$b[3], $b[4]]);
        /* [order line, operation, left, pieces this line needs] — the need is the
           ORDERED SHARE a booking is split by (owner, 22 Nov 2026) */
        $g['mem'] = array_map(fn($m) => [$m[0], $m[1], $m[2], $m[5]], $g['mem']);
        foreach ($g['mem'] as $m) if ($m[2] > 0.0001) { $g['item'] = $m[0]; $g['op'] = $m[1]; break; }  // the first that still has work
        $g['on'] = implode(', ', $g['ons']);
        $g['nop'] = count($g['ons']);
        $sizes = array_values(array_filter($g['sz'], fn($x) => $x !== ''));
        $g['nsz'] = count($g['sz']);
        $g['size'] = $g['nsz'] === 1 ? ($sizes[0] ?? '') : '';
        $g['sizes'] = implode(', ', $sizes);
        $g['desc'] = mb_substr(implode(' / ', $g['desc']), 0, 90);
        unset($g['dk'], $g['sz'], $g['ons']);
        $g['hay'] .= ' ' . mb_strtolower($g['desc']);
    }
    unset($g);
    return $out;
}

/* THE BOOKED TABLE, SUMMARISED THE SAME WAY: one row per worker + order +
   part + stage + rate + state, the operations listed with commas; a merged
   booking saved as several lines shows as one, and Cancel cancels them all. */
function ze_group_today(array $today, bool $byDate = false): array {
    $out = []; $at = [];
    foreach ($today as $t) {
        $key = ($byDate ? (string)$t['entry_date'] . '|' : '') . implode('|', [(int)($t['worker_id'] ?? 0), (string)($t['pi_no'] ?? ''), (string)($t['product_name'] ?? ''), (int)($t['part_id'] ?? 0),
                             (string)$t['stage'], number_format((float)$t['rate_applied'], 2, '.', ''), (string)$t['status']]);
        if (!isset($at[$key])) { $g0 = $t; $g0['ids'] = []; $g0['qty'] = 0.0; $g0['amount'] = 0.0; $g0['n'] = 0; $g0['ops'] = []; $g0['items'] = []; $at[$key] = count($out); $out[] = $g0; }
        $g =& $out[$at[$key]];
        $g['ids'][] = (int)$t['id']; $g['n']++;
        $on = (string)($t['operation_name'] ?? '(removed operation)');
        /* operations in the order they were booked, oldest first (the list arrives newest first) */
        $g['ops'][$on] = min($g['ops'][$on] ?? PHP_INT_MAX, (int)$t['id']);
        $g['items'][(int)($t['proforma_item_id'] ?? 0)] = (string)($t['size'] ?? '');
        if ((string)($t['created_at'] ?? '') < (string)($g['created_at'] ?? '')) { $g['created_at'] = $t['created_at']; $g['uname'] = $t['uname'] ?? ''; }
        $g['qty'] += (float)$t['qty']; $g['amount'] += (float)$t['amount'];
        unset($g);
    }
    foreach ($out as &$g) { asort($g['ops']); $g['ops'] = array_keys($g['ops']); }
    unset($g);
    return $out;
}

function ze_pay_pill(?string $t): string {
    $w = ['salary' => 'Fixed salary', 'daily' => 'Daily wages', 'contract' => 'Contractor'][$t ?? ''] ?? '';
    return $w === '' ? '' : '<span class="ze-pt pt-' . e($t) . '">' . $w . '</span>';
}
