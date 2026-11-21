<?php
/*
  DAILY PRODUCTION ENTRY — the day sheet.
  =======================================

  This is the screen your data-entry team lives in, so it is built for typing,
  not clicking: one date at the top, then rows of
  ORDER · PART & OPERATION · WORKER · QTY, and the money worked out as you go.

  WHAT THE OPERATION BOX OFFERS IS THE WHOLE DESIGN.
  Choosing an order line tells the page exactly which parts that product is made
  of and which operations each part has — so the second box only ever lists real
  work, with what is left to do printed on every option. There is no way to pick
  a combination that does not exist, which removes most of the mistakes a free
  grid makes possible.

  THREE RULES, ENFORCED ON THE SERVER, NOT JUST HERE.

  1. STITCHING CANNOT EXCEED CUTTING. You cannot stitch pieces that were never
     cut. The number is real, so it refuses.
  2. EACH OPERATION HAS ITS OWN ALLOWANCE. Overlock and Singer are two jobs done
     to every piece; booking 150 on one must not eat the other's allowance.
  3. NOTHING SAVES UNTIL EVERY ROW PASSES. A half-saved day sheet is worse than
     a refused one — you cannot tell which half went in, and re-entering it
     double-books the half that did.

  THE RATE IS NEVER TAKEN FROM THE BROWSER. It is looked up on the server at the
  moment of saving and frozen onto the row.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
/* WHO MAY OPEN THIS SCREEN.
 *
 * production_staff MUST be here. These are the people the screen exists for —
 * the operators booking their own work. I had first guarded it with admin and
 * colleague only, which locked the floor out of the floor's own page. Caught by
 * a test, not by me.
 *
 * can_see_rates() is a SEPARATE question and is answered separately below: an
 * operator books quantities; whether they see the money is a different
 * permission, and conflating the two is how a rate ends up on a screen it
 * should not be on. */
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
/* Same separation as My Work: booking a quantity and seeing the wage are two
   different permissions, and the app already answers the second one. */
$showMoney = can_see_rates();
$date   = trim((string)($_GET['d'] ?? $_POST['entry_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$msg = ''; $errors = [];

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
function ze_edit_row(array $ids, string $date, float $newQty, int $newWorker, string $why, int $userId): array {
    $why = trim($why);
    if (!$ids)          return ['ok' => false, 'error' => 'Nothing to edit.'];
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
    if (abs($wasQty - $newQty) < 0.0001 && $worker === (int)$f['worker_id']) return ['ok' => false, 'error' => 'Nothing changed.'];

    $reason = 'Edited: was ' . $qn($wasQty) . ', now ' . $qn($newQty) . ($worker !== (int)$f['worker_id'] ? ', worker changed' : '') . ' — ' . $why;
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
    $grp = null;
    foreach (ze_merge_work(zp_work_index()) as $g) {
        if (($g['kind'] ?? 'part') !== $kind) continue;
        foreach ($g['mem'] as $m) if ($m[0] === (int)$f['proforma_item_id'] && $m[1] === (int)$f['op_id']) { $grp = $g; break 2; }
    }
    if (!$grp) { ze_restore($ids); return ['ok' => false, 'error' => 'That job is no longer on the list (switched off or removed). Nothing was changed.']; }
    if ($newQty > $grp['left'] + 0.0001) { ze_restore($ids); return ['ok' => false, 'error' => 'Only ' . $qn($grp['left']) . ' can be booked on that row. Nothing was changed.']; }
    $rows = []; $rem = round($newQty, 2);
    $tag = 'edit ' . bin2hex(random_bytes(4)) . ' of #' . mb_substr(implode(',', $ids), 0, 60);
    foreach ($grp['mem'] as $m) {
        if ($rem <= 0.0001) break;
        $take = min($m[2], $rem); if ($take <= 0) continue;
        $rows[] = ['item_id' => $m[0], 'op_id' => $m[1], 'op_kind' => $kind, 'worker_id' => $worker, 'qty' => $take, 'note' => $tag];
        $rem = round($rem - $take, 2);
    }
    $b = zp_book($date, $rows, $userId);
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
    return ['ok' => true, 'msg' => 'Edited: ' . $qn($wasQty) . ' → ' . $qn($newQty) . '. The old line stays on the list, marked, with the reason.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';

    if ($a === 'book') {
        $rows = [];
        $items = $_POST['r_item'] ?? [];
        foreach ($items as $i => $_) {
            $rows[] = [
                'item_id'   => $_POST['r_item'][$i]   ?? 0,
                'op_id'     => $_POST['r_op'][$i]     ?? 0,
                /* WHICH TABLE op_id POINTS AT. A part operation, a product's
                   set job and an order line's own set job each number from 1,
                   so the id alone stopped being unique the moment set work
                   existed. Defaulting to 'part' means an older page left open
                   in a tab still books part work correctly. */
                'op_kind'   => $_POST['r_kind'][$i]   ?? 'part',
                'worker_id' => $_POST['r_worker'][$i] ?? 0,
                'qty'       => $_POST['r_qty'][$i]    ?? 0,
                'note'      => $_POST['r_note'][$i]   ?? '',
            ];
        }
        $r = zp_book($date, $rows, $userId);
        if ($r['ok']) {
            /* how many bookings were TYPED — one booking shared over three sizes
               is saved as three lines; the count is only for this message */
            $nb = max(1, min((int)$r['saved'], (int)($_POST['n_book'] ?? $r['saved'])));
            $_SESSION['zp_msg'] = $nb . ' booking' . ($nb === 1 ? '' : 's') . ' saved'
                . ($nb < (int)$r['saved'] ? ' (' . (int)$r['saved'] . ' lines)' : '')
                . ($showMoney ? ' — PKR ' . number_format($r['amount'], 2) . ' in wages.' : '.');
            redirect('production_entry.php?d=' . urlencode($date));
        }
        $errors = $r['errors'];

    } elseif ($a === 'cancel_entry') {
        /* a summarised row carries every line it stands for: "12,13,14".
           ALL OR NOTHING: if one line refuses (cutting that stitching stands
           on), the ones already cancelled are put back, so a row is never
           left half cancelled. */
        $ids = ze_post_ids();
        $r = ze_cancel_all($ids, (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = 'Entry cancelled' . (count($ids) > 1 ? ' (' . count($ids) . ' lines)' : '') . '. It stays on the list, marked, so the correction is visible.';
        else          $_SESSION['zp_err'] = $r['error'];
        redirect('production_entry.php?d=' . urlencode($date));

    } elseif ($a === 'edit_entry') {
        /* EDIT A BOOKED ROW (owner, 21 Nov 2026: "make it easy to edit").
           Nothing is overwritten: the old lines are cancelled with the reason
           "Edited: was 500, now 450 — <why>", and the new quantity / worker is
           booked through zp_book(), which checks and prices it like any other
           line. If the new booking is refused, the old lines are put back
           exactly as they were. */
        $r = ze_edit_row(ze_post_ids(), $date, (float)($_POST['new_qty'] ?? 0), (int)($_POST['new_worker'] ?? 0), (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = $r['msg'];
        else          $_SESSION['zp_err'] = $r['error'];
        redirect('production_entry.php?d=' . urlencode($date));
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['zp_err'])) { $errors[] = $_SESSION['zp_err']; unset($_SESSION['zp_err']); }

/* ------------------------------------------------------------------
   WHAT CAN BE BOOKED — built once, handed to the browser as data.
   ------------------------------------------------------------------
   The whole picker runs from this, so the options can never disagree with what
   the server will accept: both are computed from the same functions.
------------------------------------------------------------------- */
$lines   = zp_open_lines();
$prog    = zp_progress_map();
$workers = zp_workers(true);

/* AN ORDER LINE WHOSE SIZE DOES NOT RESOLVE IS NAMED, NOT HIDDEN.
   Its operations would all read "0 left", which looks exactly like finished
   work, and the day's wages for it would simply never be bookable with nothing
   on screen to explain it. Collected below and shown as a red banner. */
$SIZE_PROBLEMS = [];
/* ------------------------------------------------------------------
   THE WORK INDEX AND THE LEARNED DEFAULTS
   ------------------------------------------------------------------
   Both are built once here and handed to the browser as data, so the picker
   never makes a round trip. At 200-300 lines a day, a screen that waits for
   the server between keystrokes is unusable, and the team ends up writing the
   day on paper and entering it late.

   The index is built from the SAME functions the save uses, so the list can
   never offer something the server will refuse. */
$WORK = ze_merge_work(zp_work_index());
$HABIT = zp_worker_habit();

/* EACH WORKER CARRIES THE STAGES THEY ARE ALLOTTED, and the stage NAMES go
   into their search text too — so typing "packing" finds the packing hall even
   on the tab where no operation has been chosen yet. An empty list means every
   stage; that rule is stated once, in wFits() below.

   PREPARED HERE, NOT INSIDE THE <script> BLOCK. Everything in that block is
   JavaScript with <?= holes punched in it; a <?php ... ?> block of setup in
   the middle of it is PHP that the page's own test harness cannot lift out,
   and it broke that harness the first time I wrote it there. */
$wsMap = zp_worker_stage_map();
/* ONLY PEOPLE WITH A STAGE ARE PRODUCTION (owner, 19 Nov 2026). Head office,
   admin and the rest are in People Finance but never book a piece, so the
   pickers leave out anybody allotted no stage at all. Somebody on ANOTHER
   stage is still found by typing — standing in for a day keeps working.
   Safety net: until at least one person has a stage, everybody is offered,
   so a new setup is never left with an empty list. */
$PICK = $workers;
foreach ($workers as $w) {
    if (!empty($wsMap[(int)$w['id']])) {
        $PICK = array_values(array_filter($workers, fn($x) => !empty($wsMap[(int)$x['id']])));
        break;
    }
}
$stNm  = [];
foreach (zp_stage_all(false) as $s) $stNm[(int)$s['id']] = (string)$s['name'];

/* an unplannable line is reported, not hidden — collected above */
foreach ($lines as $l) {
    $bad = zp_size_problem($l);
    if ($bad !== '') $SIZE_PROBLEMS[] = $bad;
}

$today   = zp_entries(['date' => $date], 400);
/* MORE ABOUT EACH BOOKED LINE (owner, 21 Nov 2026): customer, size,
   description, who booked it and when — one small query for the day's ids. */
if ($today) {
    try {
        $tid = array_map(fn($t) => (int)$t['id'], $today);
        $st = db()->prepare('SELECT e.id, pf.customer_name, pi.size, pi.description, u.name uname
                             FROM zp_entries e
                             LEFT JOIN proforma_items pi ON pi.id = e.proforma_item_id
                             LEFT JOIN proforma_invoices pf ON pf.id = e.proforma_id
                             LEFT JOIN users u ON u.id = e.created_by
                             WHERE e.id IN (' . implode(',', array_fill(0, count($tid), '?')) . ')');
        $st->execute($tid);
        $more = [];
        foreach ($st->fetchAll() as $m) $more[(int)$m['id']] = $m;
        foreach ($today as &$t) {
            $m = $more[(int)$t['id']] ?? [];
            $t['customer'] = (string)($m['customer_name'] ?? ''); $t['size'] = (string)($m['size'] ?? '');
            $t['descr'] = trim((string)($m['description'] ?? '')); $t['uname'] = (string)($m['uname'] ?? '');
        }
        unset($t);
    } catch (Throwable $e) {}
}
/* HOW EACH WORKER IS PAID (owner, 19 Nov 2026): fixed salary, daily wages or
   contractor, from People Finance, shown beside the name in the lists and on
   the lines — one small query, read only. */
$PAYT = [];
try {
    foreach (db()->query("SELECT w.id, f.pay_type FROM zp_workers w JOIN att_fin_profiles f ON f.employee_id = w.att_employee_id WHERE f.pay_type IS NOT NULL")->fetchAll() as $r) {
        $PAYT[(int)$r['id']] = (string)$r['pay_type'];
    }
} catch (Throwable $e) { $PAYT = []; }
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
   first operation first and, inside it, oldest line first: a booking is
   shared down that list (answer: "sum like sizes"). The pay is the same
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
        $g['mem'][] = [(int)$r['item'], (int)$r['op'], round((float)$r['left'], 2), $opPos[$kind . ':' . (int)$r['op']], $itPos[(int)$r['item']]];
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
        $g['mem'] = array_map(fn($m) => [$m[0], $m[1], $m[2]], $g['mem']);
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
function ze_group_today(array $today): array {
    $out = []; $at = [];
    foreach ($today as $t) {
        $key = implode('|', [(int)($t['worker_id'] ?? 0), (string)($t['pi_no'] ?? ''), (string)($t['product_name'] ?? ''), (int)($t['part_id'] ?? 0),
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
$dayQty  = 0.0; $dayAmt = 0.0;
foreach ($today as $t) if ($t['status'] === 'active') { $dayQty += (float)$t['qty']; $dayAmt += (float)$t['amount']; }

/* ---- why the page might be empty, said plainly ---- */
$blockers = [];
if (!$lines) {
    $anyOrder = 0;
    try { $anyOrder = (int)db()->query("SELECT COUNT(*) FROM proforma_invoices WHERE production_enabled=1")->fetchColumn(); } catch (Throwable $e) {}
    if (!$anyOrder) $blockers[] = ['t' => 'No order is switched on for production yet.',
        'd' => 'Open a Proforma Invoice and turn production on for it. Nothing can be booked against an order the system has not been told to make.',
        'l' => 'proforma.php', 'lt' => 'Open Proforma Invoices'];
    else $blockers[] = ['t' => 'Orders are switched on, but none of their lines match a product.',
        'd' => 'A line is matched by its saved product link, or failing that by its name. Open Master Products and check the names line up.',
        'l' => 'product_master.php', 'lt' => 'Open Master Products'];
}
if ($lines && !$workers) $blockers[] = ['t' => 'No active workers.',
    'd' => 'A wage has to belong to somebody. Add the people on the machines first.',
    'l' => 'production_workers.php', 'lt' => 'Add workers'];
if ($lines && $workers) {
    if (!$WORK) $blockers[] = ['t' => 'These products have no parts with operations yet.',
        'd' => 'A product is made of parts, and a part carries its operations and rates. Define a part, then put it on the product.',
        'l' => 'part_library.php', 'lt' => 'Open Part Library'];
}

page_header('Daily Production Entry');
?>
<style>
.ze-wrap{max-width:1500px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}.zp-b.ok:hover{background:#12823b;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:6px 7px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tbody tr:hover{background:#fafcff}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.flash.bad ul{margin:7px 0 0;padding-left:19px;font-weight:500}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.cut{background:rgba(217,119,6,.13);color:#9a5710}
.pill.man{background:rgba(139,92,246,.14);color:#6d3fd4}
.pill.st{background:rgba(14,168,201,.14);color:#0b7f99}
.pill.canc{background:#fdeef1;color:#9c2740}
.left-big{font-variant-numeric:tabular-nums;font-weight:800}
.blocker{background:#fff6e8;border:1px solid #f3ddb8;border-radius:12px;padding:16px 18px;margin-bottom:14px}
.blocker b{display:block;font-size:14px;color:#8a5a10;margin-bottom:5px}
.blocker p{margin:0 0 11px;font-size:12.5px;color:#8a5a10;line-height:1.55}
#erows td{padding:5px 6px}
#erows .w-op{min-width:230px}.w-ord{min-width:230px}.w-wk{min-width:150px}
.tot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px;
     padding:11px 14px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px;font-size:13px}
.tot b{font-size:17px;color:#152033;font-variant-numeric:tabular-nums}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.rowleft{font-size:11px;color:#8a97ab;white-space:nowrap}

/* ---- the two-tab picker ---- */
.ze-tabs{display:flex;gap:4px;background:#f4f7fb;border:1px solid #e2e9f1;border-radius:13px;
         padding:4px;margin-bottom:14px;overflow-x:auto}
.ze-tab{flex:1;min-width:168px;border:0;background:transparent;font-family:inherit;cursor:pointer;
        padding:9px 13px;border-radius:9px;text-align:left;color:#6b7a90}
.ze-tab:hover{color:#152033}
.ze-tab[aria-selected="true"]{background:#1d6ff2;color:#fff;box-shadow:0 2px 9px -4px #1d6ff2}
.ze-tab b{display:block;font-size:13px;font-weight:800;letter-spacing:-.01em}
.ze-tab span{display:block;font-size:10.5px;opacity:.8;margin-top:1px}

.ze-pick{margin-bottom:14px}
.ze-srch{position:relative}
.ze-in{width:100%;padding:12px 74px 12px 40px;border:1.5px solid #d9e0ea;border-radius:11px;
       background:#fbfcfe;color:#152033;font-family:inherit;font-size:15px;font-weight:500}
.ze-in:focus{outline:none;border-color:#1d6ff2;background:#fff;box-shadow:0 0 0 4px rgba(29,111,242,.12)}
.ze-srch svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:17px;height:17px;
             color:#9aa8ba;pointer-events:none}
.ze-cnt{position:absolute;right:13px;top:50%;transform:translateY(-50%);font-size:11px;color:#9aa8ba;
        font-family:ui-monospace,monospace;pointer-events:none}
.ze-hint{margin:8px 2px 0;font-size:11px;color:#9aa8ba;display:flex;gap:13px;flex-wrap:wrap}
.ze-hint kbd{font-family:ui-monospace,monospace;font-size:10px;background:#f4f7fb;border:1px solid #e2e9f1;
             border-bottom-width:2px;border-radius:4px;padding:1px 4px;color:#38495f;margin-right:2px}

.ze-res{margin-top:10px;border:1px solid #e2e9f1;border-radius:11px;overflow:hidden;
        max-height:300px;overflow-y:auto;background:#fbfcfe}
.ze-res:empty{display:none}
.ze-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:11px;align-items:center;width:100%;
        padding:9px 13px;border:0;border-bottom:1px solid #eef2f7;background:transparent;cursor:pointer;
        text-align:left;font-family:inherit;color:#152033}
.ze-row:last-child{border-bottom:0}
.ze-row:hover{background:rgba(29,111,242,.07)}
.ze-row[data-cur="1"]{background:rgba(29,111,242,.11);box-shadow:inset 3px 0 0 #1d6ff2}
/* the Booked section: heading with links, a search + stage bar, the edit dialog */
.bk-hd{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.bk-links{display:flex;gap:6px}
.bk-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0 10px}
.bk-bar #bkQ{width:260px;max-width:100%;height:30px}
.bk-seg{display:inline-flex;flex-wrap:wrap;border:1px solid #d9dee4;border-radius:4px;overflow:hidden}
.bk-seg button{height:28px;padding:0 10px;border:0;border-right:1px solid #d9dee4;background:#fff;color:#556b82;font:600 12px inherit;cursor:pointer}
.bk-seg button:last-child{border-right:0}.bk-seg button.on{outline:2px solid #0a6ed1;outline-offset:-2px;color:#0a4f96}
.bk-seg button b{color:#1d2d3e;margin-left:3px}
.bk-cnt{margin-left:auto;color:#556b82;font-size:12px}
.bk-why{font-size:10.5px;color:#9a3412;max-width:150px;line-height:1.3;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.bk-desc{font-size:10.5px;color:#6a7b8c;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bk-dlg{border:1px solid #d9dee4;border-radius:8px;padding:16px 18px;width:min(440px,94vw);box-shadow:0 18px 40px rgba(16,32,56,.25)}
.bk-dlg::backdrop{background:rgba(16,32,56,.35)}
.bk-dlg h3{margin:0 0 6px;font-size:15px}
.bk-dlg .bk-what{font-size:12px;color:#38495f;margin-bottom:10px}
.bk-dlg label{display:block;font-size:12px;color:#556b82;font-weight:600;margin-bottom:8px}
.bk-dlg label .zin{display:block;width:100%;margin-top:3px;height:32px}
.bk-dlg .bk-note{font-size:11.5px;color:#6a7b8c;margin:4px 0 12px}
.bk-dlg .bk-btns{display:flex;justify-content:flex-end;gap:8px}
#bkT tbody tr[hidden]{display:none}
/* stage tint on the list rows (owner, 21 Nov 2026); the chosen row keeps its bar and gets a blue outline */
.zskin .ze-row.stg{background:var(--tb) !important;box-shadow:inset 4px 0 0 var(--tl) !important}
.zskin .ze-row.stg:hover{filter:brightness(.975)}
.zskin .ze-row.stg[data-cur="1"]{box-shadow:inset 4px 0 0 var(--tl),inset 0 0 0 2px #0a6ed1 !important}
.ze-t{font-size:13px;font-weight:800;letter-spacing:-.01em;margin-bottom:1px}
.ze-s{font-size:11px;color:#6b7a90;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.ze-s i{font-style:normal;color:#b6c0cf}
.ze-left{font-family:ui-monospace,monospace;font-size:12.5px;font-weight:800;color:#0d9488;text-align:right;white-space:nowrap}
.ze-left small{display:block;font-family:inherit;font-size:9.5px;color:#9aa8ba;font-weight:700;
               text-transform:uppercase;letter-spacing:.06em}
.ze-res mark{background:rgba(29,111,242,.16);color:#1558ad;border-radius:3px;padding:0 1px;font-weight:800}
.ze-none{padding:22px;text-align:center;color:#8a97ab;font-size:12.5px}
.ze-usual{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
          background:rgba(13,148,136,.13);color:#0b7f74;padding:1px 7px;border-radius:20px}

.ze-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;margin-top:11px;
         background:rgba(29,111,242,.08);border:1px solid #1d6ff2;border-radius:11px;padding:11px 13px}
.ze-head h3{margin:0 0 2px;font-size:14.5px;font-weight:800;letter-spacing:-.015em;color:#152033}
.ze-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.ze-pt{display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:5px;border:1px solid;white-space:nowrap;
       font-family:inherit;letter-spacing:0;vertical-align:1px}
.ze-pt.pt-salary{color:#1d4ed8;background:#eef4ff;border-color:#bcd0fb}
.ze-pt.pt-daily{color:#15803d;background:#effaf2;border-color:#b5e2c3}
.ze-pt.pt-contract{color:#b45309;background:#fff7ec;border-color:#f3d2a6}
.ze-chip{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;background:#fff;
         border:1px solid #e2e9f1;color:#38495f}

.ze-stash{border:1px solid #e2e9f1;border-radius:11px;margin-top:12px;overflow:hidden}
.ze-stash h4{margin:0;padding:9px 13px;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;
             color:#6b7a90;background:#f4f7fb;border-bottom:1px solid #e2e9f1;font-weight:800}
.ze-sl{display:flex;justify-content:space-between;align-items:center;gap:11px;padding:7px 13px;
       border-bottom:1px solid #f1f4f9;font-size:12.5px}
.ze-sl:last-child{border-bottom:0}
.ze-sl b{font-weight:700}
.ze-sl .x{border:1px solid #e2e9f1;background:#fff;color:#b6c0cf;width:26px;height:26px;border-radius:7px;
          cursor:pointer;font-size:14px;line-height:1;flex:none}
.ze-sl .x:hover{border-color:#cf3350;color:#cf3350;background:#fdeef1}
.over{border-color:#cf3350 !important;box-shadow:0 0 0 3px rgba(207,51,80,.14) !important}

/* THE IN-ROW LIST IS FIXED TO THE VIEWPORT, NOT PARKED IN THE CELL.
   The grid sits inside <div style="overflow-x:auto">, and any overflow other
   than visible turns that div into a scroll container that clips absolutely
   positioned children — and CSS computes overflow-y as auto whenever
   overflow-x is auto, so it clipped vertically too. The list showed one row
   and half of the next, with a scrollbar of its own. It now lives on <body>
   and is placed against the input by script. */
.zesug{position:fixed;z-index:120;background:#fff;overflow-y:auto;
       box-shadow:0 12px 34px rgba(21,32,51,.18)}
/* the running ceiling, beside the row hints */
.ze-cap{font-size:11px;color:#8a97ab;font-family:ui-monospace,monospace}
.ze-cap.bad{color:#cf3350;font-weight:800}
</style>

<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so this one attribute is the whole of the restyle. The page
         keeps its own class names — .zp-card, .zp-t, .zp-b and the .ze-*
         picker — and the skin maps onto them. */ ?>
<div class="zskin">
<div class="ze-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Daily Production Entry</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        Log what was cut and stitched today, and by whom. The wage follows automatically.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="zp-b" href="production_workers.php">Workers</a>
      <a class="zp-b" href="part_library.php">Part Library</a>
      <span class="code" style="font-size:10px;color:#a7b2c4">build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="flash bad">
      <b>Nothing was saved.</b> Every line has to be right before any of them go in &mdash; a half-saved
      sheet cannot be told apart from a whole one.
      <ul><?php foreach ($errors as $e2): ?><li><?= e($e2) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($SIZE_PROBLEMS): ?>
    <div class="flash bad">
      <b><?= count($SIZE_PROBLEMS) ?> order line<?= count($SIZE_PROBLEMS) === 1 ? '' : 's' ?>
         cannot be produced until the size is linked.</b>
      Pieces-per-set depends on the size &mdash; a Double taking two pillow cases needs two per set, not one.
      Until the size is linked, the pieces needed cannot be worked out, so these lines are left out rather
      than planned at a number that would be too low.
      <ul><?php foreach (array_unique($SIZE_PROBLEMS) as $sp): ?>
        <li><?= e($sp) ?></li><?php endforeach; ?></ul>
      <div style="margin-top:8px;font-weight:500">
        Open the proforma, pick the size on the line, save. This screen picks it up straight away.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($blockers as $b): ?>
    <div class="blocker">
      <b><?= e($b['t']) ?></b>
      <p><?= e($b['d']) ?></p>
      <a class="zp-b pri" href="<?= e($b['l']) ?>"><?= e($b['lt']) ?></a>
    </div>
  <?php endforeach; ?>

  <?php if (!$blockers): ?>
  <form method="post" id="bookForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="book">

    <div class="zp-card">
      <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <div style="max-width:180px">
          <span class="lab">Date of production</span>
          <input class="zin" type="date" name="entry_date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>"
                 onchange="location.href='production_entry.php?d='+encodeURIComponent(this.value)">
        </div>
        <span style="font-size:12px;color:#8a97ab;padding-bottom:8px">
          <?= $date === date('Y-m-d') ? 'Today.' : 'Booking for ' . e(date('D j M Y', strtotime($date))) . '.' ?>
          A future date is refused &mdash; wages cannot be earned before the work happens.</span>
      </div>

      <?php /* TWO WAYS IN, BECAUSE THE DAY ARRIVES TWO WAYS.
               Sometimes it is one operation and the six people who worked on
               it; sometimes it is one person and everything they did. Forcing
               either shape through the other doubles the typing. */ ?>
      <div class="ze-tabs" role="tablist">
        <button type="button" class="ze-tab" role="tab" aria-selected="true" id="tabA" onclick="zeTab('A')">
          <b>By operation</b><span>one job &rarr; many workers</span></button>
        <button type="button" class="ze-tab" role="tab" aria-selected="false" id="tabB" onclick="zeTab('B')">
          <b>By worker</b><span>one person &rarr; everything they did</span></button>
      </div>

      <div class="ze-pick">
        <div class="ze-srch">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
            <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
          <input class="ze-in" id="zeQ" autocomplete="off" spellcheck="false">
          <span class="ze-cnt" id="zeCnt"></span>
        </div>
        <div class="ze-hint">
          <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> move</span><span><kbd>Enter</kbd> choose</span>
          <span><kbd>Esc</kbd> clear</span><span><kbd>Ctrl</kbd>+<kbd>S</kbd> save the whole sheet</span>
        </div>
        <div class="ze-res" id="zeRes"></div>
        <div id="zeHead"></div>
      </div>

      <div id="zeGrid" hidden>
        <div style="overflow-x:auto">
        <table class="zp-t" id="etable" style="min-width:760px">
          <thead><tr id="zeHrow"></tr></thead>
          <tbody id="erows"></tbody>
        </table>
        </div>
        <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:11px">
          <button type="button" class="zp-b" onclick="zeAddRow()">+ Add line</button>
          <button type="button" class="zp-b" onclick="zeStash()">Done &mdash; next operation</button>
          <span style="font-size:11px;color:#8a97ab;font-family:ui-monospace,monospace">
            Enter on Qty starts the next line</span>
          <?php /* THE CEILING, WHILE YOU FILL IT — not only once you have gone
                   past it. The red block below still explains a refusal, but by
                   then the typing is already done; this counts up as you go so
                   the limit is visible before it is hit. */ ?>
          <span class="ze-cap" id="zeCap" style="margin-left:auto"></span>
        </div>
      </div>

      <?php /* THE SHEET. Everything picked so far, from either tab, waiting to
               be written in one go. Held in hidden inputs so the POST is an
               ordinary form post that the existing, unchanged save handler
               reads exactly as it always did. */ ?>
      <div id="zeSheet"></div>

      <div class="tot">
        <span>Today's sheet &mdash; <span id="rowCount">0</span> line(s)</span>
        <span><?= $showMoney ? 'PKR <b id="sheetTotal">0.00</b>' : '<b id="sheetTotal" hidden></b>' ?></span>
      </div>

      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:13px">
        <button class="zp-b ok" id="zeSave" style="padding:9px 20px;font-size:13.5px">Save the day's work</button>
      </div>

      <div class="note info" style="margin-top:13px">
        <b>One list, and a row in it is the whole job.</b> Order, customer, product, size, part, operation and
        rate &mdash; type any part of any of it and the list shortens. Pressing Enter fills all of it in, so the
        only thing left to type is the quantity. <b>Workers are listed by what they usually do</b>, learned from
        what was really booked, but nobody is locked to anything.
      </div>
    </div>
  </form>
  <?php endif; ?>

  <div class="zp-card">
    <?php $BK = $today ? ze_group_today($today) : []; ?>
    <div class="bk-hd">
      <h2>Booked on <?= e(date('D j M Y', strtotime($date))) ?></h2>
      <div class="bk-links">
        <a class="zp-b sm" href="production_reports.php?r=entries&amp;from=<?= e($date) ?>&amp;to=<?= e($date) ?>">Full report</a>
        <?php if (is_admin()): ?><a class="zp-b sm" href="production_amend.php">Corrections</a><?php endif; ?>
      </div>
    </div>
    <?php if (!$today): ?>
      <div class="empty">Nothing booked for this date yet.</div>
    <?php else:
      $stCnt = []; $nCanc = 0;
      foreach ($BK as $t) { if ($t['status'] !== 'active') { $nCanc++; continue; } $stCnt[(string)$t['stage']] = ($stCnt[(string)$t['stage']] ?? 0) + 1; } ?>
      <?php /* FIND AND EXPORT IN THE BROWSER: the rows are already on the page, so
               search, stage filter and CSV cost the server nothing. */ ?>
      <div class="bk-bar">
        <input class="zin" id="bkQ" placeholder="Search worker, order, product, operation…" autocomplete="off" oninput="bkFilter()">
        <span class="bk-seg" id="bkSeg">
          <button type="button" class="on" data-s="">All <b><?= count($BK) ?></b></button>
          <?php foreach ($stCnt as $sn => $c): ?>
            <button type="button" data-s="<?= e($sn) ?>" style="<?= e(ze_stage_tint($sn)) ?>"><?= e($sn) ?> <b><?= $c ?></b></button>
          <?php endforeach; ?>
          <?php if ($nCanc): ?><button type="button" data-s="__canc">Cancelled <b><?= $nCanc ?></b></button><?php endif; ?>
        </span>
        <span id="bkCnt" class="bk-cnt"></span>
        <button type="button" class="zp-b sm" onclick="bkCsv()">Download CSV</button>
      </div>
      <div style="overflow-x:auto">
      <table class="zp-t" id="bkT">
        <thead><tr>
          <th style="width:36px">#</th><th>Worker</th><th>Order &amp; product</th><th>Part &amp; operation</th>
          <th style="width:110px">Stage</th><th class="num" style="width:80px">Qty</th>
          <?php /* BOOKING A QUANTITY AND SEEING THE WAGE ARE TWO DIFFERENT PERMISSIONS.
                   This table printed both columns to anybody who could open the page,
                   so an operator not allowed to see rates saw every rate on the floor.
                   The entry grid above already respected can_see_rates(); this did not. */ ?>
          <?php if ($showMoney): ?>
            <th class="num" style="width:80px">Rate</th><th class="num" style="width:100px">Amount</th>
          <?php endif; ?>
          <th style="width:130px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($BK as $i => $t): $canc = $t['status'] !== 'active';
              $szs = array_values(array_filter($t['items'], fn($x) => $x !== '')); ?>
          <tr style="<?= e(ze_stage_tint((string)$t['stage'])) ?><?= $canc ? ';opacity:.55' : '' ?>"
              data-i="<?= $i ?>" data-s="<?= $canc ? '__canc' : e((string)$t['stage']) ?>">
            <td class="code"><?= $i + 1 ?><div style="font-size:9.5px;color:#8a97ab" title="Booked by <?= e($t['uname'] ?? '') ?>"><?= e(date('H:i', strtotime((string)$t['created_at']))) ?></div></td>
            <td><?= e($t['worker_name'] ?? '(removed worker)') ?>
                <div style="font-size:10px"><span class="code" style="font-size:10px"><?= e($t['worker_code'] ?? '') ?></span><?= ze_pay_pill($PAYT[(int)($t['worker_id'] ?? 0)] ?? null) ?></div></td>
            <td><?= e($t['product_name'] ?? '(removed product)') ?>
                <div class="code" style="font-size:10px"><?= e(trim(($t['pi_no'] ?? '') . ($t['customer'] !== '' ? ' · ' . $t['customer'] : ''), ' ·')) ?></div>
                <?php if (($t['descr'] ?? '') !== ''): ?><div class="bk-desc" title="<?= e($t['descr']) ?>"><?= e($t['descr']) ?></div><?php endif; ?></td>
            <td><?= e($t['part_name'] ?? '—') ?>
                <div class="code" style="font-size:10px"><?= e(implode(', ', $t['ops'])) ?></div></td>
            <td><?php
              /* the colour follows POSITION, not a word: the stage that makes the
                 pieces is marked apart from the ones limited by it, whatever you
                 have chosen to call them */
              echo ze_stage_pill((string)$t['stage']);
              if ($canc) echo ' <span class="pill canc">cancelled</span>';
            ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$t['qty'], 2), '0'), '.') ?><?php if ($szs): ?><div class="code" style="font-size:10px;white-space:nowrap;max-width:120px;overflow:hidden;text-overflow:ellipsis;margin-left:auto" title="<?= e(implode(', ', $szs)) ?>"><?= count($szs) > 1 ? count($szs) . ' sizes' : e($szs[0]) ?></div><?php endif; ?></td>
            <?php if ($showMoney): ?>
              <td class="num"><?= number_format((float)$t['rate_applied'], 2) ?></td>
              <td class="num"><?= number_format((float)$t['amount'], 2) ?></td>
            <?php endif; ?>
            <td>
              <?php if (!$canc): ?>
                <button type="button" class="zp-b sm" onclick="bkEdit(<?= $i ?>)">Edit</button>
                <button type="button" class="zp-b sm red" onclick="zeCancel('<?= e(implode(',', $t['ids'])) ?>')">Cancel</button>
              <?php elseif (trim((string)$t['cancel_reason']) !== ''): ?>
                <div class="bk-why" title="<?= e($t['cancel_reason']) ?>"><?= e($t['cancel_reason']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#f7f9fc">
          <td colspan="5" style="font-weight:800;font-size:12px">Day total (cancelled lines excluded)</td>
          <td class="num" style="font-weight:800"><?= rtrim(rtrim(number_format($dayQty, 2), '0'), '.') ?></td>
          <?php if ($showMoney): ?>
            <td></td>
            <td class="num" style="font-weight:800"><?= number_format($dayAmt, 2) ?></td>
          <?php endif; ?>
          <td></td>
        </tr></tfoot>
      </table>
      </div>

      <div class="note warn" style="margin-top:13px">
        <b>A cancelled line stays on the list.</b> It is marked, not removed, with the reason kept against it.
        Deleting it would make the day's total change with nothing on screen to explain why.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($today): ?>
  <dialog id="bkDlg" class="bk-dlg">
    <form method="post" id="editForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="edit_entry">
      <input type="hidden" name="entry_date" value="<?= e($date) ?>">
      <input type="hidden" name="entry_id" value="">
      <h3>Edit booked work</h3>
      <div id="bkWhat" class="bk-what"></div>
      <label>Quantity<input class="zin num" name="new_qty" inputmode="decimal" required></label>
      <label>Worker<select class="zin" name="new_worker" id="bkWorker"></select></label>
      <label>Why is it changed?<input class="zin" name="reason" value="Wrong quantity" required maxlength="150"></label>
      <div class="bk-note">The old line is not deleted: it stays on the list, marked, with this reason. The new quantity is checked against what is left, like any booking.</div>
      <div class="bk-btns"><button type="button" class="zp-b" onclick="document.getElementById('bkDlg').close()">Close</button>
        <button class="zp-b ok">Save the change</button></div>
    </form>
  </dialog>
  <script id="bkJs">
  /* the booked rows as data, for Edit and for the CSV — the same rows as the table */
  var BOOKED = <?= json_encode(array_map(fn($t) => [
      'ids' => implode(',', $t['ids']), 'wid' => (int)($t['worker_id'] ?? 0), 'w' => (string)($t['worker_name'] ?? ''), 'code' => (string)($t['worker_code'] ?? ''),
      'pt' => ['salary' => 'Fixed salary', 'daily' => 'Daily wages', 'contract' => 'Contractor'][$PAYT[(int)($t['worker_id'] ?? 0)] ?? ''] ?? '',
      'pi' => (string)($t['pi_no'] ?? ''), 'cust' => (string)($t['customer'] ?? ''), 'prod' => (string)($t['product_name'] ?? ''),
      'desc' => (string)($t['descr'] ?? ''), 'part' => (string)($t['part_name'] ?? ''), 'ops' => implode(', ', $t['ops']),
      'sizes' => implode(', ', array_values(array_filter($t['items'], fn($x) => $x !== ''))), 'st' => (string)$t['stage'],
      'status' => $t['status'] === 'active' ? 'Active' : 'Cancelled', 'qty' => round((float)$t['qty'], 2),
      'rate' => $showMoney ? (float)$t['rate_applied'] : null, 'amt' => $showMoney ? round((float)$t['amount'], 2) : null,
      'at' => date('H:i', strtotime((string)$t['created_at'])), 'by' => (string)($t['uname'] ?? ''), 'why' => (string)($t['cancel_reason'] ?? ''),
  ], $BK), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var BKDATE = <?= json_encode($date) ?>, BKMONEY = <?= $showMoney ? 'true' : 'false' ?>;
  var BKWORKERS = <?= json_encode(array_map(fn($w) => [(int)$w['id'], $w['worker_code'] . ' · ' . $w['worker_name']], $PICK), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  (function(){
    var seg = '';
    function low(r){ return [r.w, r.code, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.by].join(' ').toLowerCase(); }
    window.bkFilter = function(){
      var t = document.getElementById('bkQ').value.trim().toLowerCase().split(/\s+/).filter(Boolean), n = 0;
      [].forEach.call(document.querySelectorAll('#bkT tbody tr'), function(tr){
        var r = BOOKED[+tr.dataset.i], h = low(r);
        var ok = (!seg || tr.dataset.s === seg) && t.every(function(w){ return h.indexOf(w) >= 0; });
        tr.hidden = !ok; if (ok) n++;
      });
      document.getElementById('bkCnt').textContent = n + ' of ' + BOOKED.length;
    };
    document.getElementById('bkSeg').addEventListener('click', function(e){
      var b = e.target.closest('button'); if (!b) return;
      seg = b.dataset.s;
      [].forEach.call(this.querySelectorAll('button'), function(x){ x.classList.toggle('on', x === b); });
      bkFilter();
    });
    /* CSV OF WHAT IS ON SCREEN — built here, nothing asked of the server */
    window.bkCsv = function(){
      var head = ['Date','Booked at','By','Worker','Code','Pay type','Order','Customer','Product','Description','Part','Operations','Sizes','Stage','Status','Qty'];
      if (BKMONEY) head.push('Rate','Amount');
      head.push('Cancel reason');
      var lines = [head];
      [].forEach.call(document.querySelectorAll('#bkT tbody tr'), function(tr){
        if (tr.hidden) return;
        var r = BOOKED[+tr.dataset.i];
        var row = [BKDATE, r.at, r.by, r.w, r.code, r.pt, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.status, r.qty];
        if (BKMONEY) row.push(r.rate, r.amt);
        row.push(r.why);
        lines.push(row);
      });
      /* a cell that starts with = + - @ is written as text, so a spreadsheet never runs it */
      var csv = lines.map(function(l){ return l.map(function(v){
        v = v == null ? '' : String(v);
        if (/^[=+\-@]/.test(v) && isNaN(+v)) v = "'" + v;
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], {type:'text/csv;charset=utf-8'}));
      a.download = 'production_' + BKDATE + '.csv'; document.body.appendChild(a); a.click(); a.remove();
    };
    window.bkEdit = function(i){
      var r = BOOKED[i], f = document.getElementById('editForm'), sel = document.getElementById('bkWorker');
      f.entry_id.value = r.ids; f.new_qty.value = r.qty; f.reason.value = 'Wrong quantity';
      document.getElementById('bkWhat').textContent = r.w + ' · ' + r.pi + ' · ' + r.prod + ' · ' + r.part + ' → ' + r.ops + ' · ' + r.st;
      var opts = BKWORKERS.slice(); if (!opts.some(function(o){ return o[0] === r.wid; })) opts.unshift([r.wid, r.code + ' · ' + r.w]);
      sel.innerHTML = opts.map(function(o){ return '<option value="' + o[0] + '"' + (o[0] === r.wid ? ' selected' : '') + '></option>'; }).join('');
      [].forEach.call(sel.options, function(op, k){ op.textContent = opts[k][1]; });
      document.getElementById('bkDlg').showModal(); f.new_qty.select();
    };
    bkFilter();
  })();
  </script>
  <?php endif; ?>

  <form method="post" id="cancelForm" style="display:none">
    <?= csrf_field() ?><input type="hidden" name="action" value="cancel_entry">
    <input type="hidden" name="entry_date" value="<?= e($date) ?>">
    <input type="hidden" name="entry_id" value="">
    <input type="hidden" name="reason" value="">
  </form>
</div>

<script>
/* ====================================================================
   THE PICKER
   ====================================================================

   EVERYTHING RUNS IN THE BROWSER, ON DATA BUILT ONCE BY THE SERVER. At
   200-300 lines a day a screen that waits for the server between keystrokes is
   unusable, and the team ends up writing the day on paper and typing it in
   late — which is how the numbers stop being today's numbers.

   WHAT THE BROWSER DECIDES AND WHAT IT DOES NOT. It decides what to SHOW: the
   order of the list, what is left, the running total. It decides nothing about
   what is SAVED. Every line is re-checked by zp_book() on the server, which
   looks the rate up again and freezes it onto the row. A tampered page cannot
   book a wage this screen would not offer, and it cannot set its own rate.
   ==================================================================== */
(function(){
  var WORK    = <?= json_encode($WORK) ?>;
  var WORKERS = <?= json_encode(array_map(function ($w) use ($wsMap, $stNm, $PAYT) {
      $sg = $wsMap[(int)$w['id']] ?? [];
      $sn = [];
      foreach ($sg as $sid) if (isset($stNm[$sid])) $sn[] = $stNm[$sid];
      return [
        'id' => (int)$w['id'], 'code' => (string)$w['worker_code'], 'name' => (string)$w['worker_name'],
        'pt' => $PAYT[(int)$w['id']] ?? '',
        'dept' => (string)($w['department'] ?? ''),
        'sg'  => array_values(array_map('intval', $sg)),
        'hay' => mb_strtolower(trim($w['worker_code'] . ' ' . $w['worker_name'] . ' '
                 . ($w['department'] ?? '') . ' ' . implode(' ', $sn) . ' '
                 . (['salary' => 'fixed salary', 'daily' => 'daily wages', 'contract' => 'contractor'][$PAYT[(int)$w['id']] ?? ''] ?? ''))),
      ];
  }, $PICK)) ?>;
  var PAYW = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractor' };
  function payPill(t){ return PAYW[t] ? '<span class="ze-pt pt-' + t + '">' + PAYW[t] + '</span>' : ''; }
  var HABIT   = <?= json_encode($HABIT) ?>;
  var MONEY   = <?= $showMoney ? 'true' : 'false' ?>;

  var tab    = 'A';      // A = pick the work first, B = pick the worker first
  var picked = null;     // the chosen WORK row, or the chosen worker
  var rows   = [];       // the lines being typed right now
  var sheet  = [];       // everything staged, from either tab, waiting for Save
  var cur    = 0;

  var $ = function(id){ return document.getElementById(id); };
  function esc(t){ return String(t==null?'':t).replace(/[&<>"]/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (Math.round(v*100)/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function qn(v){ var r = Math.round(v*100)/100; return r.toLocaleString('en-US'); }

  /* EVERY TYPED WORD MUST APPEAR SOMEWHERE. "1042 over" finds the Overlock
     lines on that order — which is how people actually remember a job, half
     the PO number and half the operation. Order of the words does not matter. */
  function terms(){ return $('zeQ').value.trim().toLowerCase().split(/\s+/).filter(Boolean); }
  function hit(hay, t){ for (var i=0;i<t.length;i++) if (hay.indexOf(t[i]) < 0) return false; return true; }

  /* The text is ESCAPED FIRST and marked with two characters that cannot occur
     in escaped HTML, so a part somebody named <script> is shown as text and
     never becomes one. */
  var M1 = '\u0001', M2 = '\u0002';
  function hl(text, t){
    var out = esc(text);
    t.forEach(function(w){
      if(!w) return;
      out = out.replace(new RegExp('('+w.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','ig'), M1+'$1'+M2);
    });
    return out.split(M1).join('<mark>').split(M2).join('</mark>');
  }

  /* ---- LEARNED DEFAULTS: how likely is this pairing, from real history ---- */
  function score(workerId, opId){
    var m = (HABIT.byWorker || {})[workerId];
    return m ? (+m[opId] || 0) : 0;
  }
  function usualOps(workerId){
    var m = (HABIT.byWorker || {})[workerId] || {}, best = 0;
    for (var k in m) if (m[k] > best) best = m[k];
    return best;
  }

  /* ============================================================
     ONE ROW, DRAWN IN TWO PLACES
     ------------------------------------------------------------
     This page used to carry TWO search lists written separately: the big one
     at the top, and a cut-down one inside each grid row. They were roughly
     the same code typed twice, and they drifted — the in-row one lost the
     match highlighting, lost the chips, and on the "By worker" tab lost the
     LEFT count, which is the number that stops somebody over-booking. You
     could pick a job in a row without ever seeing how much of it was still
     open.

     Now there is one option shape, one renderer and one painter. The top list
     and the in-row list differ only in WHERE they are mounted, so they cannot
     drift apart again.
     ============================================================ */

  /* a unit of work, described once — everything a person needs to decide */
  /* STAGE COLOURS — the same rule as ze_stage_style() on the server, so a
     stage reads the same in the list, on the sheet and in the booked table. */
  function stStyle(st){
    var STPAL = [['#f1ecfb','#5a3fa8','#d6cbf2'],['#e3f5f7','#0e6170','#b3e0e6'],['#fbe9f1','#9d174d','#f2c4d8'],['#eef2f6','#33465f','#cfd8e3'],['#fdeee8','#9a3412','#f6cdb9']];
    var l = String(st||'').toLowerCase();
    if (l.indexOf('cut') >= 0) return 'background:#fff4e2;color:#9a5710;border-color:#f1cf97';
    if (/stitch|sew|singer|overlock/.test(l)) return 'background:#e8f1fc;color:#0a4f96;border-color:#b9d3f1';
    if (l.indexOf('pack') >= 0) return 'background:#e8f6ec;color:#1f6b35;border-color:#bfe3ca';
    var h = 0; for (var i=0;i<l.length;i++) h = (h*31 + l.charCodeAt(i)) >>> 0;
    var c = STPAL[h % STPAL.length];
    return 'background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2];
  }
  /* the page skin sets chip colours with !important, so the stage colour says it too */
  /* the row tint: a very light shade of the stage colour, a bar on the left */
  function stTint(st){
    var m = /background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})/.exec(stStyle(st));
    return m ? '--tb:' + m[1] + ';--tl:' + m[2] : '';
  }
  function stChip(st){ return st ? '<span class="ze-chip" style="' + stStyle(st).split(';').join(' !important;') + ' !important;font-weight:600">' + esc(st) + '</span>' : ''; }
  /* one size, or "3 sizes" with the list on hover */
  function szText(w){ return w.nsz > 1 ? w.nsz + ' sizes' : (w.size || ''); }

  function zeWorkOpt(w, click, ev){
    /* THE ORDER NUMBER AND THE PRODUCT, and nothing else on that line.
       The customer name used to sit here too. On a floor list it earns
       nothing — the person booking work knows the style, not the buyer —
       and it pushed the product, which is what they DO recognise, off the
       end of a narrow row. Asked for directly. */
    return { v:w.k, click:click, ev:ev,
             title: w.pn + ' → ' + w.on, strong: w.pi,
             bits: [w.prod, w.desc].filter(Boolean),
             chips:[szText(w), w.nop > 1 ? w.nop + ' operations' : ''].filter(Boolean), szt: w.nsz > 1 ? w.sizes : '', st: w.st,
             rate: w.rate, left: w.left };
  }
  /* DOES THIS PERSON WORK THIS STAGE?
     Stated once on the browser side, exactly as zp_worker_does_stage()
     states it on the server — and the two must never drift, or the list
     offers somebody the save then refuses.

     NOTHING ALLOTTED NO LONGER MEANS EVERY STAGE. It used to, and that was
     right while the table was empty. Then two hundred people arrived from
     the HR app — head office, the kitchen, fashion, the garment units — and
     every one of them passed every stage, so allotting forty people to
     Cutting narrowed the Cutting list from 200 to 200.

     Somebody allotted nothing is not "available for everything". They are
     not production, which is the truth about the man in the kitchen.

     THE SAFETY NET IS IN THE CALLER, not here: a stage NOBODY is on yet
     still offers the whole floor, so one stage can be set up this morning
     without the others going dark. */
  function wFits(w, sid){
    if (!sid) return true;
    return !!(w.sg && w.sg.length && w.sg.indexOf(sid) >= 0);
  }

  /* a person, described once */
  function zeWorkerOpt(w, opId, click, ev){
    return { v:w.id, click:click, ev:ev,
             title: w.name, strong: w.code,
             bits: [w.dept].filter(Boolean), chips: [], pay: w.pt,
             usual: opId ? score(w.id, opId) > 0 : usualOps(w.id) > 0 };
  }

  function zeRowHtml(o, t, isCur){
    return '<button type="button" class="ze-row' + (o.st ? ' stg" style="' + stTint(o.st) : '') + '" data-cur="' + (isCur ? 1 : 0) + '"'
      /* the value is on the element as well as in the handler: the body-level
         panel reads it back on mousedown and on Enter, without having to parse
         an attribute or fake an event */
      + ' data-pick="' + esc(o.v) + '"'
      /* The top list keeps an inline onclick (zePick is index-based). The
         in-row list passes none: its panel sits on the body and handles the
         whole list in one listener, so an inline handler here would fire the
         pick TWICE. */
      + (o.click ? ' ' + (o.ev || 'onclick') + '="' + o.click + '"' : '')
      + '>'
      + '<div><div class="ze-t">' + hl(o.title, t) + '</div><div class="ze-s">'
      +   (o.strong ? '<b style="color:#38495f">' + hl(o.strong, t) + '</b><i>&middot;</i>' : '')
      +   o.bits.map(function(b){ return hl(b, t); }).join('<i>&middot;</i>')
      +   o.chips.map(function(c){ return '<span class="ze-chip"' + (o.szt ? ' title="' + esc(o.szt) + '"' : '') + '>' + hl(c, t) + '</span>'; }).join('')
      +   (o.st ? stChip(o.st) : '')
      +   (o.pay ? payPill(o.pay) : '')
      +   (o.rate && MONEY ? '<span style="font-family:ui-monospace,monospace">' + n2(o.rate) + '/pc</span>' : '')
      +   (o.usual ? '<span class="ze-usual">usually</span>' : '')
      + '</div></div>'
      + (o.left != null ? '<div class="ze-left">' + qn(o.left) + '<small>left</small></div>'
                        : '<div class="ze-left" style="color:#b6c0cf">&rarr;</div>')
      + '</button>';
  }

  /* A LONG LIST IS DRAWN SHORT. Painting 1,200 rows on every keystroke is what
     makes a search feel heavy. The cut is SAID OUT LOUD, because a list that
     silently stops at eight looks like the other matches do not exist. */
  function zePaint(box, list, t, curIx, cap){
    cap = cap || 60;
    if (!list.length) { box.innerHTML = '<div class="ze-none">Nothing matches.</div>'; return; }
    var show = list.slice(0, cap);
    box.innerHTML = show.map(function(o, i){ return zeRowHtml(o, t, i === curIx); }).join('')
      + (list.length > show.length
         ? '<div class="ze-none">' + (list.length - show.length) + ' more &mdash; keep typing to narrow it.</div>'
         : '')
      /* A note the LIST carries about itself — today, "these are only the
         people on this stage". It is a plain div, not a .ze-row, so the arrow
         keys and Enter step straight past it and cannot pick it. */
      + (list.foot ? '<div class="ze-none">' + list.foot + '</div>' : '');
    var c = box.querySelector('[data-cur="1"]'); if (c) c.scrollIntoView({block:'nearest'});
  }

  function tabList(){
    var t = terms();
    /* only OPEN work is offered; finished rows are kept for the message below */
    if (tab === 'A') return WORK.filter(function(w){ return !w.done && hit(w.hay, t); });
    var list = WORKERS.filter(function(w){ return hit(w.hay, t); });
    /* the people who actually book work come first — an empty search on a
       floor of 200 should not open with whoever happened to be added first */
    return list.slice().sort(function(a,b){ return usualOps(b.id) - usualOps(a.id); });
  }

  function draw(){
    var box = $('zeRes'), t = terms();
    if (picked) { box.innerHTML=''; $('zeCnt').textContent=''; return; }
    var list = tabList();
    $('zeCnt').textContent = list.length + (list.length === 1 ? ' match' : ' matches');
    if (!list.length) {
      /* "NOTHING MATCHES" AND "THAT IS ALL DONE" ARE DIFFERENT ANSWERS.
         One is a typo to correct; the other is good news. Saying the first when
         the second is true sends somebody hunting for a job that is finished. */
      var fin = (tab === 'A') ? WORK.filter(function(w){ return w.done && hit(w.hay, t); }).length : 0;
      box.innerHTML = fin
        ? '<div class="ze-none"><b>' + fin + ' matching job' + (fin===1?' is':'s are')
          + ' already finished.</b><br>Nothing is left to book on '
          + (fin===1?'it':'them') + '.</div>'
        : '<div class="ze-none">Nothing matches &ldquo;' + esc($('zeQ').value) + '&rdquo;.</div>';
      return;
    }
    if (cur >= list.length) cur = list.length - 1;
    if (cur < 0) cur = 0;
    /* the SAME renderer the in-row list uses — see "ONE ROW, DRAWN IN TWO
       PLACES" above. zePick stays index-based, so the click carries the index. */
    zePaint(box, list.map(function(w, i){
      return tab === 'A' ? zeWorkOpt(w, 'zePick(' + i + ')', 'onclick')
                         : zeWorkerOpt(w, 0, 'zePick(' + i + ')', 'onclick');
    }), t, cur, 60);
  }

  window.zePick = function(i){
    var w = tabList()[i]; if (!w) return;
    picked = w;
    $('zeHead').innerHTML = (tab === 'A')
      ? '<div class="ze-head"><div><h3>' + esc(w.pn) + ' → ' + esc(w.on) + '</h3>'
        + '<div style="font-size:12px;color:#38495f"><b>' + esc(w.pi) + '</b> &middot; ' + esc(w.cust)
        + ' &middot; ' + esc(w.prod) + (w.desc ? ' &middot; <span style="color:#556b82">' + esc(w.desc) + '</span>' : '') + '</div><div class="ze-chips">'
        + (w.nsz > 1 ? '<span class="ze-chip" title="Shared across the sizes, first size first">' + w.nsz + ' sizes: ' + esc(w.sizes) + '</span>'
                     : (w.size ? '<span class="ze-chip">Size ' + esc(w.size) + '</span>' : ''))
        + (w.nop > 1 ? '<span class="ze-chip" title="Shared over the operations, first operation first">' + w.nop + ' operations</span>' : '')
        + stChip(w.st)
        + (MONEY ? '<span class="ze-chip">' + n2(w.rate) + ' / pc</span>' : '')
        + '<span class="ze-chip" style="color:#0d9488;border-color:#0d9488">' + qn(w.left) + ' left</span>'
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>'
      : '<div class="ze-head"><div><h3>' + esc(w.name) + '</h3><div class="ze-chips">'
        + '<span class="ze-chip">' + esc(w.code) + '</span>'
        + (w.dept ? '<span class="ze-chip">' + esc(w.dept) + '</span>' : '')
        + payPill(w.pt)
        + '</div></div><button type="button" class="zp-b sm" onclick="zeReset()">Change</button></div>';
    $('zeRes').innerHTML = ''; $('zeCnt').textContent = '';
    $('zeGrid').hidden = false;
    rows = [];
    zeAddRow();
  };

  window.zeReset = function(){
    picked = null; rows = []; cur = 0;
    $('zeQ').value = ''; $('zeHead').innerHTML = ''; $('zeGrid').hidden = true;
    grid(); draw(); $('zeQ').focus();
  };

  window.zeTab = function(t){
    tab = t;
    $('tabA').setAttribute('aria-selected', t === 'A');
    $('tabB').setAttribute('aria-selected', t === 'B');
    $('zeQ').placeholder = (t === 'A')
      ? 'Type an order, product, part or operation…'
      : 'Type a worker name, code or department…';
    zeReset();
  };

  /* ---- the grid of lines under whichever thing was picked ---- */
  window.zeAddRow = function(){
    rows.push({ v:'', label:'', q:'' });
    grid();
    var a = document.querySelectorAll('#erows .pk');
    if (a.length) a[a.length-1].focus();
  };
  window.zeDrop = function(i){ if (rows.length <= 1) return; rows.splice(i,1); grid(); };

  function other(v){        // what a row picks: a worker on tab A, a work row on tab B
    if (tab === 'A') { for (var i=0;i<WORKERS.length;i++) if (WORKERS[i].id === +v) return WORKERS[i]; }
    else             { for (var j=0;j<WORK.length;j++)    if (WORK[j].k === v)      return WORK[j]; }
    return null;
  }
  function rowRate(r){
    if (!r.v) return 0;
    return tab === 'A' ? (picked ? picked.rate : 0) : ((other(r.v) || {}).rate || 0);
  }
  function rowLeft(r){
    return tab === 'A' ? (picked ? picked.left : 0) : ((other(r.v) || {}).left || 0);
  }

  function grid(){
    $('zeHrow').innerHTML = '<th style="width:28px">#</th>'
      + '<th>' + (tab === 'A' ? 'Worker' : 'Order &middot; part &rarr; operation') + '</th>'
      + '<th class="num" style="width:96px">Qty</th>'
      + (MONEY ? '<th class="num" style="width:84px">Rate</th><th class="num" style="width:102px">Amount</th>' : '')
      + '<th style="width:34px"></th>';
    var html = '';
    rows.forEach(function(r,i){
      var rate = rowRate(r), amt = (parseFloat(r.q)||0) * rate;
      html += '<tr>'
        + '<td style="color:#b6c0cf;font-family:ui-monospace,monospace">' + (i+1) + '</td>'
        + '<td style="position:relative">'
        +   '<input class="zin pk" autocomplete="off" value="' + esc(r.label) + '"'
        +   ' placeholder="' + (tab === 'A' ? 'Type a name or code…' : 'Type an order, part or operation…') + '"'
        +   ' oninput="zeSug(' + i + ',this)" onfocus="zeSug(' + i + ',this)"'
        +   ' onkeydown="zeSugKey(event,' + i + ')" onblur="zeSugClose(' + i + ')">'
        /* NO POPUP LIVES IN HERE ANY MORE — see zeSugBox() below. The table is
           wrapped in overflow-x:auto, which makes that div a scroll container
           and clips anything absolutely positioned inside it. */
        + '</td>'
        + '<td><input class="zin num q' + (r.over ? ' over' : '') + '" inputmode="numeric" value="' + esc(r.q) + '"'
        +   ' placeholder="0" oninput="zeQty(' + i + ',this.value)" onkeydown="zeQtyKey(event,' + i + ')"></td>'
        + (MONEY ? '<td class="num" style="color:#8a97ab">' + (rate ? n2(rate) : '—') + '</td>'
                 + '<td class="num"><b>' + (amt ? n2(amt) : '—') + '</b></td>' : '')
        + '<td><button type="button" class="zp-b sm red" tabindex="-1" onclick="zeDrop(' + i + ')">&times;</button></td>'
        + '</tr>';
    });
    $('erows').innerHTML = html;
    totals();
  }

  /* ---- the in-row suggestion list, ordered by what this person usually does ----
     It builds the SAME options the top list does, so a work row in here now
     carries its customer, its stage, its rate and its LEFT count — all of
     which it used to drop, leaving you to pick a job on this tab without ever
     seeing how much of it was still open. */
  function sugList(i, text){
    var t = (text||'').trim().toLowerCase().split(/\s+/).filter(Boolean);
    /* No inline handler and so no hand-escaping of the value into a JS string
       either: the body-level panel reads data-pick, which esc() already made
       safe as an attribute. */
    var click = function(){ return ''; };
    if (tab === 'A') {
      var opId = picked ? picked.op : 0;
      /* THE STAGE NARROWS AN EMPTY BOX, AND ONLY AN EMPTY BOX.
         Three hundred names under the cursor is not a list. So with nothing
         typed, only the people allotted to this job's stage are offered — on
         a packing job that is twenty-six names instead of three hundred.
         The moment anything IS typed the search goes back over everybody,
         because typing a name means you have already decided who you want and
         no filter of mine should be able to say "that person is not here".
         Somebody standing in at another stage for a day is therefore always
         reachable, and the entry saves exactly as it always did. */
      /* THE TICKS DECIDE, TYPING INCLUDED (owner, 19 Nov 2026): "if we making
         cutting stage so list only who ticked as cutting". A Cutting job offers
         only the people ticked for Cutting on Production Workers — a typed name
         searches inside that list, not the whole floor. To let somebody stand
         in, tick the stage for them there. The safety net stays: a stage that
         NOBODY is ticked for yet still offers everybody. */
      var all = WORKERS.filter(function(w){ return hit(w.hay, t); });
      var sid = picked ? (picked.sid || 0) : 0;
      var use = all, narrowed = 0;
      if (sid) {
        /* NARROW ONLY WHEN SOMEBODY IS REALLY ON THIS STAGE.
           Testing "is the short list non-empty" was not enough and the test
           caught it: workers allotted NOTHING pass wFits for every stage, so a
           stage nobody has been put on yet still produced a list — of just
           those few — and three hundred people vanished behind a footnote.
           So the count is of people who NAME this stage. None of them means
           the stage means nothing yet, and the whole floor is offered. */
        var onStage = WORKERS.some(function(w){ return w.sg && w.sg.indexOf(sid) >= 0; });
        var fit = all.filter(function(w){ return wFits(w, sid); });
        if (onStage) { narrowed = all.length - fit.length; use = fit; }
      }
      var outw = use.slice().sort(function(a,b){ return score(b.id, opId) - score(a.id, opId); })
        .map(function(w){ return zeWorkerOpt(w, opId, click(), ''); });
      /* The cut is SAID, like every other cut on this screen. A list that
         silently stops short reads as "that person does not exist". */
      /* THE CUT IS SAID, and now it says which way round it is. With two
         hundred people synced from HR and a handful allotted, "191 more"
         is not a footnote about a filter — it is the screen telling him
         the allotment is doing its job. Nobody is unreachable: typing a
         name searches the whole floor. */
      outw.foot = narrowed
        ? 'Only people ticked for ' + esc(picked.st) + ' are listed — tick more on Production Workers.'
        : '';
      return outw;
    }
    var wid = picked ? picked.id : 0;
    /* the other way round: a person picked first is offered only the work of
       the stages they are ticked for (nobody ticked anywhere: everything) */
    var mine = picked && picked.sg && picked.sg.length ? picked.sg : null;
    return WORK.filter(function(w){ return !w.done && hit(w.hay, t) && (!mine || !w.sid || mine.indexOf(w.sid) >= 0); })
      .slice().sort(function(a,b){ return score(wid, b.op) - score(wid, a.op); })
      .map(function(w){
        var o = zeWorkOpt(w, click(), '');
        o.usual = score(wid, w.op) > 0;
        return o;
      });
  }
  /* ============================================================
     THE SECOND LIST LIVES ON THE BODY, NOT IN THE ROW
     ------------------------------------------------------------
     It used to be an absolutely positioned div inside the picker cell. The
     grid is wrapped in <div style="overflow-x:auto">, and ANY overflow other
     than visible makes that div a scroll container which clips absolutely
     positioned descendants. Worse, CSS computes overflow-y as auto whenever
     overflow-x is auto — so the list was clipped vertically as well, and got
     its own scrollbar. You saw the top row and half of the second.

     One panel, hung off the page's outermost wrapper and placed against the
     input with getBoundingClientRect(). Nothing above it can clip it. This is
     the same thing lov.js already does on the proforma grid, for the same
     reason.
     ============================================================ */
  var sugRow = -1;
  function zeSugBox(){
    var b = $('zeSugBox');
    if (!b) {
      b = document.createElement('div');
      b.id = 'zeSugBox';
      b.className = 'ze-res zesug';
      b.hidden = true;
      /* mousedown, not click: it must fire before the input's blur closes it */
      b.addEventListener('mousedown', function(e){
        var row = e.target.closest ? e.target.closest('.ze-row') : null;
        if (!row) return;
        e.preventDefault();
        var v = row.getAttribute('data-pick');
        if (v !== null && sugRow >= 0) zeSugSet(sugRow, v);
      });
      /* MOUNTED ON THE .zskin WRAPPER, NOT ON <body>. Either one escapes the
         overflow-x:auto container that was clipping it, but every skin rule
         for these rows is written as `.zskin .ze-row` — hang the panel off
         <body> and it lands OUTSIDE that ancestor and loses all of it. The
         wrapper is the outermost element on the page, so it clips nothing. */
      (document.querySelector('.zskin') || document.body).appendChild(b);
    }
    return b;
  }
  /* Put it where it FITS, not blindly underneath: drop below when there is
     room, flip above when there is not, and never run off either edge. */
  function zeSugPlace(el){
    var b = zeSugBox(), r = el.getBoundingClientRect();
    b.style.maxHeight = '';
    var w = Math.min(Math.max(r.width, 430), window.innerWidth - 16);
    b.style.width = w + 'px';
    var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
    b.style.left = left + 'px';
    var below = window.innerHeight - r.bottom - 10, above = r.top - 10;
    var h = b.offsetHeight;
    if (h <= below || below >= above) {
      b.style.top = (r.bottom + 3) + 'px';
      b.style.maxHeight = Math.max(120, below) + 'px';
    } else {
      b.style.maxHeight = Math.max(120, above) + 'px';
      b.style.top = Math.max(8, r.top - Math.min(b.offsetHeight, above) - 3) + 'px';
    }
  }
  /* the input scrolls with the page; the panel is fixed, so it has to follow */
  function zeSugTrack(){
    if (sugRow < 0) return;
    var el = document.querySelectorAll('#erows .pk')[sugRow];
    if (el) zeSugPlace(el); else zeSugHide();
  }
  window.addEventListener('scroll', zeSugTrack, true);
  window.addEventListener('resize', zeSugTrack);
  function zeSugHide(){ var b = $('zeSugBox'); if (b) b.hidden = true; sugRow = -1; }

  window.zeSug = function(i, el){
    rows[i].label = el.value;
    var pop = zeSugBox(), list = sugList(i, el.value);
    sugRow = i;
    if (!list.length) { pop.hidden = true; return; }
    /* 12, not 8, and the cut is stated. Eight was a silent truncation on a
       floor with 200 workers: the name you wanted was simply not there, with
       nothing on screen to say more existed. */
    zePaint(pop, list, (el.value||'').trim().toLowerCase().split(/\s+/).filter(Boolean), 0, 12);
    pop.hidden = false;
    zeSugPlace(el);
  };
  window.zeSugKey = function(e, i){
    var pop = $('zeSugBox');
    if (!pop || pop.hidden) { if (e.key === 'Enter') e.preventDefault(); return; }
    var o = [].slice.call(pop.querySelectorAll('.ze-row'));
    var k = -1;
    for (var x=0; x<o.length; x++) if (o[x].dataset.cur === '1') { k = x; break; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (o[k]) o[k].dataset.cur = '0';
      k = e.key === 'ArrowDown' ? Math.min(k+1, o.length-1) : Math.max(k-1, 0);
      o[k].dataset.cur = '1'; o[k].scrollIntoView({block:'nearest'});
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var p = o[k < 0 ? 0 : k];
      /* the row's pick is read straight off the element now, rather than
         synthesising a mousedown that a real listener has to interpret */
      if (p) zeSugSet(i, p.getAttribute('data-pick'));
    } else if (e.key === 'Escape') { zeSugHide(); }
  };
  window.zeSugClose = function(i){ setTimeout(function(){ if (sugRow === i) zeSugHide(); }, 130); };
  window.zeSugSet = function(i, v){
    var o = other(v);
    rows[i].v = (tab === 'A') ? +v : v;
    rows[i].label = o ? (tab === 'A' ? o.name : o.pn + ' → ' + o.on) : '';
    zeSugHide();
    grid();
    var q = document.querySelectorAll('#erows input.q');
    if (q[i]) { q[i].focus(); q[i].select(); }
  };

  window.zeQty = function(i, v){ rows[i].q = v.replace(/[^0-9.]/g,''); totals(); };
  window.zeQtyKey = function(e, i){
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (i === rows.length - 1) zeAddRow();
    else { var a = document.querySelectorAll('#erows .pk'); if (a[i+1]) a[i+1].focus(); }
  };

  /* ---- THE CEILING IS SHARED ON TAB A ----
     Every line there is the SAME operation, so three workers booking 200 each
     against 420 remaining is 600 and must be refused. Checking each line on its
     own would pass all three. On tab B each line is a different operation with
     its own ceiling, but the same one may be typed twice, so they are added up
     per operation before being checked.

     The staged sheet counts too: booking 300 now and 300 again after staging
     must not slip past a 420 ceiling. */
  function stagedOn(key){
    var n = 0;
    sheet.forEach(function(x){ if (x.g === key) n += x.qty; });
    return n;
  }
  /* what is already staged on ONE size line of one operation */
  function stagedItem(item, op, kind){
    var n = 0;
    sheet.forEach(function(x){ if (x.item === item && x.op === op && x.kind === kind) n += x.qty; });
    return n;
  }
  var grpSeq = 0;
  function totals(){
    var q = 0, amt = 0, n = 0, over = false;
    rows.forEach(function(r){ r.over = false; });
    if (tab === 'A') {
      rows.forEach(function(r){ var v = parseFloat(r.q)||0; if (r.v && v>0) { n++; q += v; amt += v * rowRate(r); } });
      if (picked && q + stagedOn(picked.k) > picked.left + 0.0001) {
        over = true;
        rows.forEach(function(r){ if ((parseFloat(r.q)||0) > 0) r.over = true; });
      }
    } else {
      var per = {};
      rows.forEach(function(r){
        var v = parseFloat(r.q)||0;
        if (r.v && v>0) { n++; q += v; amt += v * rowRate(r); per[r.v] = (per[r.v]||0) + v; }
      });
      rows.forEach(function(r){
        if (r.v && per[r.v] + stagedOn(r.v) > rowLeft(r) + 0.0001) { r.over = true; over = true; }
      });
    }
    var sa = 0;
    sheet.forEach(function(x){ sa += x.qty * x.rate; });
    var ng = {}; sheet.forEach(function(x){ ng[x.grp] = 1; });
    $('rowCount').textContent = Object.keys(ng).length + n;
    var tot = $('sheetTotal'); if (tot) tot.textContent = n2(sa + amt);

    var cells = document.querySelectorAll('#erows input.q');
    rows.forEach(function(r,i){ if (cells[i]) cells[i].classList.toggle('over', !!r.over); });
    $('zeSave').disabled = over || (sheet.length + n) === 0;

    /* the running ceiling. On tab A every line is the same operation, so one
       number covers the lot; on tab B each line has its own, so it only
       reports when one of them has been passed. */
    var cap = $('zeCap');
    if (cap) {
      if (tab === 'A' && picked) {
        var used = q + stagedOn(picked.k);
        cap.className = 'ze-cap' + (over ? ' bad' : '');
        cap.textContent = qn(used) + ' of ' + qn(picked.left) + ' left' + (over ? ' — OVER' : '');
      } else {
        cap.className = 'ze-cap' + (over ? ' bad' : '');
        cap.textContent = over ? 'a line is booked past what is left' : '';
      }
    }
    warn(over, q);
    return {n:n, q:q, amt:amt, over:over};
  }

  function warn(over, q){
    var w = $('zeWarn');
    if (!w) {
      w = document.createElement('div'); w.id = 'zeWarn';
      $('zeSheet').parentNode.insertBefore(w, $('zeSheet'));
    }
    if (!over) { w.innerHTML = ''; return; }
    w.innerHTML = '<div class="flash bad" style="margin-top:12px">'
      + (tab === 'A'
         ? '<b>These lines add up to ' + qn(q + stagedOn(picked.k)) + ' pieces, but only ' + qn(picked.left)
           + ' are left at this operation.</b> Nothing can be booked on pieces that were not made at the '
           + 'stage before it, so the sheet is held until the numbers fit.'
         : '<b>One of these operations is booked past what is left.</b> The red box shows which.')
      + '</div>';
  }

  /* ---- staging: finish this block and start another, without saving yet ---- */
  window.zeStash = function(){
    var t = totals();
    if (t.over) return;
    if (!t.n) { zeReset(); return; }
    rows.forEach(function(r){
      var v = parseFloat(r.q)||0;
      if (!r.v || v <= 0) return;
      var work   = (tab === 'A') ? picked : other(r.v);
      var worker = (tab === 'A') ? other(r.v) : picked;
      if (!work || !worker) return;
      /* kind rides on the staged row, not looked up again at save time — the
         work list can be rebuilt between staging and saving, and a row that
         re-derived its own kind then could book against the wrong table. */
      /* FIRST SIZE FIRST (owner, 21 Nov 2026: "use fifo or any size
         automatically but data entry daily must be easy"). One typed quantity
         is shared across the size lines of the merged row, oldest line first,
         each taking only what is still open there. It goes to the server as
         one line per size, exactly as before, so zp_book checks and prices
         every one; on screen it stays one line (grp). */
      var kind = work.kind || 'part', grp = ++grpSeq, rem = Math.round(v*100)/100;
      var mem = work.mem && work.mem.length ? work.mem : [[work.item, work.op, work.left]];
      var base = { kind:kind, g:work.k, grp:grp, worker:worker.id, rate:work.rate,
                   what:work.pn + ' → ' + work.on, who:worker.name, pt:worker.pt, pi:work.pi, st:work.st,
                   prod:work.prod, sz:szText(work) };
      for (var m = 0; m < mem.length && rem > 0.0001; m++) {
        var free = Math.round((mem[m][2] - stagedItem(mem[m][0], mem[m][1], kind))*100)/100;
        if (free <= 0) continue;
        var take = Math.min(free, rem);
        sheet.push(Object.assign({}, base, { item:mem[m][0], op:mem[m][1], qty:take }));
        rem = Math.round((rem - take)*100)/100;
      }
      /* cannot happen — totals() refuses a line past what is left — but a
         remainder is never dropped silently: it goes on the last line and the
         server refuses it with its own message */
      if (rem > 0.0001) sheet.push(Object.assign({}, base, { item:mem[mem.length-1][0], op:mem[mem.length-1][1], qty:rem }));
    });
    zeReset();
    paintSheet();
  };

  function paintSheet(){
    var el = $('zeSheet');
    if (!sheet.length) { el.innerHTML = ''; totals(); return; }
    /* one line per booking as typed, however many sizes it was shared over */
    var gs = [], at = {};
    sheet.forEach(function(x){
      if (at[x.grp] == null) { at[x.grp] = gs.length; gs.push({ x:x, qty:0, n:0 }); }
      var g = gs[at[x.grp]]; g.qty += x.qty; g.n++;
    });
    var h = '<div class="ze-stash"><h4>Waiting to be saved — ' + gs.length + ' line'
          + (gs.length === 1 ? '' : 's') + '</h4>';
    gs.forEach(function(g){
      var x = g.x;
      h += '<div class="ze-sl"><div><b>' + esc(x.who) + '</b> ' + payPill(x.pt) + ' &middot; ' + esc(x.what)
         + ' ' + stChip(x.st)
         + ' <span style="color:#8a97ab">(' + esc(x.pi) + (x.prod ? ' &middot; ' + esc(x.prod) : '') + ')</span>'
         + (g.n > 1 ? ' <span style="color:#8a97ab;font-size:11px">shared over ' + g.n + ' lines</span>' : '') + '</div>'
         + '<div style="display:flex;gap:11px;align-items:center">'
         + '<span style="font-family:ui-monospace,monospace"><b>' + qn(g.qty) + '</b> pcs'
         + (MONEY ? ' &middot; ' + n2(g.qty * x.rate) : '') + '</span>'
         + '<button type="button" class="x" onclick="zeUnstash(' + x.grp + ')">&times;</button></div></div>';
    });
    el.innerHTML = h + '</div>';
    totals();
  }
  window.zeUnstash = function(grp){ sheet = sheet.filter(function(x){ return x.grp !== grp; }); paintSheet(); };

  /* ---- SAVE: the staged sheet PLUS whatever is typed but not yet staged ----
     Forgetting the rows still on screen is the obvious way to lose somebody's
     wages, so Save stages them first and then posts the lot. */
  document.getElementById('bookForm').addEventListener('submit', function(ev){
    var t = totals();
    if (t.over) { ev.preventDefault(); return; }
    if (t.n) zeStash();
    if (!sheet.length) {
      ev.preventDefault();
      alert('Nothing to save yet — pick some work and type a quantity.');
      return;
    }
    var f = ev.target;
    [].slice.call(f.querySelectorAll('.zePost')).forEach(function(e){ e.remove(); });
    var nb = {}; sheet.forEach(function(x){ nb[x.grp] = 1; });
    var hb = document.createElement('input'); hb.type = 'hidden'; hb.className = 'zePost'; hb.name = 'n_book';
    hb.value = Object.keys(nb).length; f.appendChild(hb);
    sheet.forEach(function(x){
      [['r_item',x.item],['r_op',x.op],['r_kind',x.kind||'part'],['r_worker',x.worker],
       ['r_qty',x.qty],['r_note','']]
        .forEach(function(kv){
          var h = document.createElement('input');
          h.type = 'hidden'; h.className = 'zePost'; h.name = kv[0] + '[]'; h.value = kv[1];
          f.appendChild(h);
        });
    });
  });

  /* ---- keyboard on the main search ---- */
  $('zeQ').addEventListener('input', function(){ cur = 0; draw(); });
  $('zeQ').addEventListener('keydown', function(e){
    var list = tabList();
    if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur+1, Math.min(list.length,60)-1); draw(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur-1, 0); draw(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (list[cur]) zePick(cur); }
    else if (e.key === 'Escape') { $('zeQ').value = ''; cur = 0; draw(); }
  });

  document.addEventListener('keydown', function(ev){
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      var f = document.getElementById('bookForm');
      if (f && !$('zeSave').disabled) { f.requestSubmit ? f.requestSubmit() : f.submit(); }
    }
  });

  window.zeCancel = function(id){
    var why = prompt('Why is this entry being cancelled?\n\nThe line stays on the list, marked, with this reason against it.');
    if (why === null) return;
    if (!why.trim()) { alert('A reason is needed — a correction with no reason cannot be checked later.'); return; }
    var f = document.getElementById('cancelForm');
    f.entry_id.value = id; f.reason.value = why.trim(); f.submit();
  };

  zeTab('A');
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
