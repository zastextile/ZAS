<?php
/*
  CORRECT ENTRIES — find booked work, edit it or cancel it, with a reason.
  ========================================================================
  (rebuilt 22 Nov 2026, the owner's choices: rows grouped like the Booked
  table with a switch to every saved line; Edit changes quantity, worker
  and date; tick many rows and cancel them under one reason; admin and
  colleague may use it.)

  A CORRECTION IS VISIBLE, NEVER INVISIBLE. Nothing here deletes or
  overwrites a booked line. Cancel marks it — who, when, why — and it stays
  on every list, faded. Edit is a cancel plus a new booking: the old line
  keeps "Edited: was 500, now 450 — <why>", the new one is checked against
  what is left exactly like any booking (includes/zp_correct.php, the same
  code Daily Production Entry uses).

  THE GRID (owner, 28 Nov 2026: "Excel style … move between cells … edit
  qty and rate everywhere … save one time"). Date, worker, quantity and —
  for an admin who sees rates — the rate are typed straight into the table,
  moved through with the arrow keys, Tab and Enter, pasted from Excel. Nothing
  reaches the server until Save: then every changed row goes in ONE request,
  each through the same ze_edit_row() as before, under one reason.

  ALL OR NOTHING. A ticked group whose cutting already has stitching on it is
  refused, and then nothing in that click is cancelled. Later stages are
  cancelled before earlier ones, so ticking a stitching line and its cutting
  together works.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Admin or colleague access required.'); }
require_once __DIR__ . '/includes/zprod.php';
require_once __DIR__ . '/includes/zp_correct.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
$showMoney = can_see_rates();
[$from, $to] = zp_range($_GET['from'] ?? $_POST['from'] ?? date('Y-m-d', strtotime('-6 days')), $_GET['to'] ?? $_POST['to'] ?? null);
/* a bounded window keeps the page light: at most about three months at once */
if (strtotime($to) - strtotime($from) > 92 * 86400) $from = date('Y-m-d', strtotime($to . ' -92 days'));
$back = 'production_amend.php?from=' . urlencode($from) . '&to=' . urlencode($to);

$canRate = $showMoney && is_admin();      // the wage rate itself is corrected by an admin only

/* ONE SAVE FOR THE WHOLE GRID. Each changed row is still its own correction
   (old line kept, marked, new line checked), so a refusal is reported for
   that row and the others stand. Order matters for the stage rules: lower
   quantities go first, later stages first; then the rest, earlier stages
   first — so cutting can come down after the stitching on it, and go up
   before the stitching that needs it. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grid') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $ch  = json_decode((string)($_POST['changes'] ?? ''), true);
    $why = trim((string)($_POST['reason'] ?? ''));
    if (!is_array($ch) || !$ch) { echo json_encode(['ok' => false, 'error' => 'Nothing to save.']); exit; }
    if ($why === '') { echo json_encode(['ok' => false, 'error' => 'Give the reason for these corrections — it is kept against every old line.']); exit; }
    if (count($ch) > 300) { echo json_encode(['ok' => false, 'error' => 'At most 300 rows in one save.']); exit; }
    $todo = [];
    foreach ($ch as $c) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($c['ids'] ?? ''))), fn($x) => $x > 0));
        if (!$ids || count($ids) > 200) continue;
        $todo[] = ['k' => mb_substr((string)($c['k'] ?? ''), 0, 400), 'ids' => $ids, 'd' => (string)($c['d'] ?? ''),
                   'qty' => (float)str_replace(',', '', (string)($c['qty'] ?? 0)), 'wid' => (int)($c['wid'] ?? 0),
                   'nd' => (string)($c['nd'] ?? ''), 'exact' => !empty($c['exact']),
                   'rate' => array_key_exists('rate', $c) && trim((string)$c['rate']) !== '' ? (float)str_replace(',', '', (string)$c['rate']) : null];
    }
    $all = array_merge(...array_map(fn($t) => $t['ids'], $todo ?: [['ids' => []]]));
    $was = []; $pos = [];
    if ($all) {
        $st = db()->prepare('SELECT id, qty, stage FROM zp_entries WHERE id IN (' . implode(',', array_fill(0, count($all), '?')) . ')');
        $st->execute($all);
        foreach ($st->fetchAll() as $r) { $was[(int)$r['id']] = (float)$r['qty']; $pos[(int)$r['id']] = zp_stage_pos((string)$r['stage']) ?? 99; }
    }
    foreach ($todo as &$t) {
        $t['down'] = $t['qty'] < array_sum(array_map(fn($i) => $was[$i] ?? 0, $t['ids'])) - 0.0001;
        $t['pos']  = $pos[$t['ids'][0]] ?? 99;
    }
    unset($t);
    usort($todo, fn($a, $b) => ($b['down'] <=> $a['down']) ?: ($a['down'] ? $b['pos'] <=> $a['pos'] : $a['pos'] <=> $b['pos']));
    $res = []; $n = 0;
    foreach ($todo as $t) {
        if ($t['rate'] !== null && !$canRate) { $res[] = ['k' => $t['k'], 'ok' => false, 'error' => 'Only an admin may correct a rate.']; continue; }
        $r = ze_edit_row($t['ids'], $t['d'], $t['qty'], $t['wid'], $why, $userId, $t['nd'], $t['exact'], $t['rate']);
        if ($r['ok']) $n++;
        $res[] = ['k' => $t['k'], 'ok' => (bool)$r['ok'], 'msg' => (string)($r['msg'] ?? ''), 'error' => (string)($r['error'] ?? '')];
    }
    if ($n) $_SESSION['zp_msg'] = $n . ' row' . ($n === 1 ? '' : 's') . ' corrected. The old lines stay on the list, marked "Edited", with your reason.';
    echo json_encode(['ok' => true, 'saved' => $n, 'results' => $res]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'cancel_many') {
        /* later stages first, so a stitching line and the cutting under it can
           be cancelled in the same click */
        $ids = ze_post_ids();
        if ($ids) {
            $st = db()->prepare('SELECT id, stage FROM zp_entries WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $pos = [];
            foreach ($st->fetchAll() as $r) $pos[(int)$r['id']] = zp_stage_pos((string)$r['stage']) ?? 99;
            usort($ids, fn($x, $y) => ($pos[$y] ?? 0) <=> ($pos[$x] ?? 0) ?: $y <=> $x);
        }
        $r = ze_cancel_all($ids, (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = count($ids) . ' line' . (count($ids) === 1 ? '' : 's') . ' cancelled. They stay on the list, marked, with your reason.';
        else          $_SESSION['zp_err'] = $r['error'];
    } elseif ($a === 'edit') {
        $r = ze_edit_row(ze_post_ids(), (string)($_POST['date'] ?? ''), (float)str_replace(',', '', (string)($_POST['new_qty'] ?? 0)),
                         (int)($_POST['new_worker'] ?? 0), (string)($_POST['reason'] ?? ''), $userId,
                         (string)($_POST['new_date'] ?? ''), !empty($_POST['exact']));
        if ($r['ok']) $_SESSION['zp_msg'] = $r['msg'];
        else          $_SESSION['zp_err'] = $r['error'];
    }
    redirect($back);
}
$msg = $_SESSION['zp_msg'] ?? ''; $err = $_SESSION['zp_err'] ?? '';
unset($_SESSION['zp_msg'], $_SESSION['zp_err']);

$lines  = ze_entries_more(zp_entries(['from' => $from, 'to' => $to], 4000));
$groups = ze_group_today($lines, true);
$PAYT   = ze_pay_types();
$wsMap  = zp_worker_stage_map();
$PTW    = ['salary' => 'Fixed salary', 'daily' => 'Daily wages', 'contract' => 'Contractor'];

$pack = function (array $t, bool $grp) use ($showMoney, $PAYT, $PTW) {
    $sizes = $grp ? array_values(array_filter($t['items'], fn($x) => $x !== '')) : array_filter([(string)($t['size'] ?? '')]);
    return [
        'ids' => $grp ? implode(',', $t['ids']) : (string)(int)$t['id'], 'n' => $grp ? (int)$t['n'] : 1,
        'd' => (string)$t['entry_date'], 'at' => date('H:i', strtotime((string)$t['created_at'])), 'by' => (string)($t['uname'] ?? ''),
        'wid' => (int)($t['worker_id'] ?? 0), 'w' => (string)($t['worker_name'] ?? '(removed worker)'), 'code' => (string)($t['worker_code'] ?? ''),
        'pt' => $PTW[$PAYT[(int)($t['worker_id'] ?? 0)] ?? ''] ?? '',
        'pi' => (string)($t['pi_no'] ?? ''), 'cust' => (string)($t['customer'] ?? ''), 'prod' => (string)($t['product_name'] ?? '(removed product)'),
        'desc' => (string)($t['descr'] ?? ''), 'part' => (string)($t['part_name'] ?? '—'),
        'ops' => $grp ? implode(', ', $t['ops']) : (string)($t['operation_name'] ?? '(removed operation)'),
        'sizes' => implode(', ', $sizes), 'nsz' => count($sizes),
        'st' => (string)$t['stage'], 'sid' => (int)($t['stage_id'] ?? 0),
        'act' => $t['status'] === 'active', 'qty' => round((float)$t['qty'], 2),
        'rate' => $showMoney ? round((float)$t['rate_applied'], 2) : null, 'amt' => $showMoney ? round((float)$t['amount'], 2) : null,
        'why' => (string)($t['cancel_reason'] ?? ''), 'cby' => (string)($t['cname'] ?? ''),
        'cat' => $t['cancelled_at'] ? date('j M H:i', strtotime((string)$t['cancelled_at'])) : '',
        'ed' => str_starts_with((string)($t['cancel_reason'] ?? ''), 'Edited:'),
    ];
};
$G = array_map(fn($t) => $pack($t, true), $groups);
$L = array_map(fn($t) => $pack($t, false), $lines);
$W = array_values(array_map(fn($w) => ['id' => (int)$w['id'], 'l' => $w['worker_code'] . ' · ' . $w['worker_name'],
                                       'sg' => array_values(array_map('intval', $wsMap[(int)$w['id']] ?? []))], zp_workers()));
$STG = []; foreach ($lines as $t) $STG[(string)$t['stage']] = ze_stage_style((string)$t['stage']);

page_header('Correct Entries');
?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<style>
.zf.ca{min-width:0;max-width:100%}
.zf.ca .zf-card{min-width:0;max-width:100%}
.zf.ca .zf-tw{max-width:100%;overflow-x:auto;max-height:calc(100vh - 250px)}
.zf.ca .zf-t th{text-transform:none !important;letter-spacing:0 !important;font-size:12px !important}
.zf.ca .zf-t tbody tr{cursor:default}
.zf.ca .zf-t td{vertical-align:middle}
.zf.ca tr.off td{opacity:.55}
.zf.ca tr.sel td{background:#eaf2fc !important}
.zf.ca .st{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:3px;border:1px solid;white-space:nowrap}
.zf.ca .pt{font-size:10px;font-weight:600;padding:0 5px;border-radius:3px;background:#fff4e2;color:#9a5710;border:1px solid #f1cf97;margin-left:4px}
.zf.ca .why{font-size:11px;color:#9a3412;min-width:160px;max-width:260px;line-height:1.3;white-space:normal}
.zf.ca .why small{display:block;color:var(--mut)}
.zf.ca .desc{font-size:11px;color:#6a7b8c;max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.zf.ca .tg{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--mut);cursor:pointer;white-space:nowrap}
.zf.ca .rng{display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap}
.zf.ca .rng label{font-size:11.5px;color:var(--mut);display:flex;flex-direction:column;gap:2px}
.zf.ca .selbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 10px;background:#eaf2fc;border-bottom:1px solid #c3dbf5}
.zf.ca .selbar b{color:#0a4f96}
.zf.ca .selbar .zf-in{flex:1;min-width:220px}
.zf.ca .flash{padding:9px 12px;border-radius:5px;font-size:13px;font-weight:600;margin-bottom:10px}
.zf.ca .flash.ok{background:#eef8f0;border:1px solid #c6e6cf;color:#256f3a}
.zf.ca .flash.bad{background:#fdf0ef;border:1px solid #f3c6c2;color:#b42318}
.zf.ca .note{font-size:12px;color:var(--mut);padding:8px 10px;border-top:1px solid var(--soft)}
/* ---- the grid (28 Nov 2026) ---- */
.zf.ca .gh{display:flex;gap:6px 14px;flex-wrap:wrap;align-items:center;padding:6px 10px;font-size:11.5px;color:#556b82;background:#f7f9fb;border-bottom:1px solid var(--soft)}
.zf.ca .gh kbd{font-family:ui-monospace,monospace;font-size:10px;background:#fff;border:1px solid #d9dee4;border-bottom-width:2px;border-radius:3px;padding:0 4px;color:#38495f}
.zf.ca .gh .ghm{margin-left:auto;color:#0a4f96}
.zf.ca table.gr td.ed{cursor:cell;position:relative}
.zf.ca table.gr td.ed:not(.ro):hover{background:#f3f8fe !important}
.zf.ca table.gr td.ed.ro{cursor:default}
.zf.ca table.gr td.ac{outline:2px solid #0a6ed1;outline-offset:-2px;background:#eef5fd !important}
.zf.ca table.gr td.chg{background:#fff7d6 !important}
.zf.ca table.gr td.chg.ac{background:#fff1b8 !important}
.zf.ca table.gr td.bad{background:#fdecea !important;outline:2px solid #e5484d;outline-offset:-2px}
.zf.ca table.gr td.editing{padding:2px 4px !important;background:#fff !important}
.zf.ca table.gr .gin{width:100%;min-width:90px;height:30px;box-sizing:border-box;border:2px solid #0a6ed1;border-radius:3px;padding:0 6px;font:inherit;font-size:13px;text-align:right;background:#fff}
.zf.ca table.gr select.gin{text-align:left;min-width:190px}
.zf.ca table.gr input[type=date].gin{text-align:left;min-width:140px}
.zf.ca table.gr td:focus{outline:none}
.zf.ca table.gr td.ac:focus{outline:2px solid #0a6ed1}
.zf.ca table.gr .was{font-size:10.5px;color:#9a5710;text-decoration:line-through}
.zf.ca table.gr td.amch{color:#9a5710;font-weight:700}
.zf.ca table.gr tr.rch td:first-child{box-shadow:inset 3px 0 0 #e9a23b}
.zf.ca table.gr tr.rerr td:first-child{box-shadow:inset 3px 0 0 #e5484d}
.zf.ca table.gr tr.rok td{opacity:.6}
.zf.ca table.gr td.nw{white-space:nowrap}
.zf.ca .okb{font-size:11.5px;font-weight:700;color:#256f3a}
.zf.ca .gsave{position:sticky;bottom:0;z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 10px;background:#fffbea;border-top:2px solid #e9a23b;box-shadow:0 -6px 16px -10px rgba(16,32,56,.35)}
.zf.ca .gsave .zf-in{flex:1;min-width:240px}
.ca-toast{position:fixed;right:18px;bottom:18px;z-index:9999;background:#1d2d3e;color:#fff;padding:10px 14px;border-radius:6px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:420px}
.ca-toast.bad{background:#b42318}
.ca-dlg{border:1px solid #d9dee4;border-radius:8px;padding:16px 18px;width:min(460px,94vw);box-shadow:0 18px 40px rgba(16,32,56,.25);font-size:13px}
.ca-dlg::backdrop{background:rgba(16,32,56,.35)}
.ca-dlg h3{margin:0 0 6px;font-size:15px}
.ca-dlg .what{font-size:12px;color:#38495f;margin-bottom:10px;line-height:1.45}
.ca-dlg .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ca-dlg label{display:block;font-size:12px;color:#556b82;font-weight:600;margin-bottom:8px}
.ca-dlg .zin{display:block;width:100%;margin-top:3px;height:32px;box-sizing:border-box;border:1px solid #c9d3df;border-radius:4px;padding:0 8px;font:inherit}
.ca-dlg .hint{font-size:11.5px;color:#6a7b8c;margin:2px 0 12px;line-height:1.4}
.ca-dlg .btns{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:760px){.zf.ca .zf-kpis{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px}.zf.ca .zf-kpi b{font-size:16px}}
</style>
<div class="zskin">
<div class="zf ca">
  <div class="zf-hd">
    <div><h1>Correct entries</h1>
      <div class="zf-sub">Edit or cancel booked work, always with a reason. Nothing is deleted: the old line stays on the list, marked.</div></div>
    <form method="get" class="rng">
      <label>From<input class="zf-in" type="date" name="from" value="<?= e($from) ?>"></label>
      <label>To<input class="zf-in" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>"></label>
      <button class="zf-btn pri">Show</button>
      <a class="zf-btn" href="production_entry.php">Daily entry</a>
      <a class="zf-btn" href="production_orders.php">Order status</a>
    </form>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="zf-kpis" id="caKpi"></div>
  <div class="zf-card">
    <div class="zf-bar">
      <span class="zf-seg" id="caSeg">
        <button type="button" data-f="act" class="on">Active</button>
        <button type="button" data-f="off">Cancelled</button>
        <button type="button" data-f="all">All</button>
      </span>
      <input class="zf-in q" id="caQ" placeholder="Search worker, order, product, operation…" autocomplete="off" style="width:230px">
      <select class="zf-in" id="caW"><option value="">Worker: all</option></select>
      <select class="zf-in" id="caS"><option value="">Stage: all</option></select>
      <label class="tg" title="Grouped rows join the sizes and operations of one booking. Every line shows each saved size line on its own."><input type="checkbox" id="caLines"> Every line</label>
      <span class="zf-cnt" id="caCnt"></span>
      <button type="button" class="zf-btn sm" id="caCsv">Export Excel</button>
    </div>
    <div class="gh">
      <span><b>Edit like Excel:</b> click a cell (or use the arrows) and type.</span>
      <span><kbd>Enter</kbd> down</span><span><kbd>Tab</kbd> right</span><span><kbd>F2</kbd> edit</span><span><kbd>Esc</kbd> undo cell</span>
      <span><kbd>Ctrl</kbd>+<kbd>V</kbd> paste from Excel</span><span><kbd>Ctrl</kbd>+<kbd>D</kbd> copy from above</span>
      <span class="ghm">Nothing is sent until <b>Save</b>.</span>
    </div>
    <div class="selbar" id="caSel" hidden>
      <span><b id="caSelN">0</b> selected</span>
      <input class="zf-in" id="caWhy" maxlength="200" placeholder="Why are these being cancelled? (required — kept with every line)">
      <button type="button" class="zf-btn" id="caSelClear">Clear</button>
      <button type="button" class="zf-btn danger" id="caSelGo">Cancel selected</button>
    </div>
    <div class="zf-tw"><table class="zf-t gr" id="caT">
      <thead><tr>
        <th style="width:28px"><input type="checkbox" id="caAll" title="Tick every active row shown"></th>
        <th class="ec">Date</th><th class="ec">Worker</th><th>Order &amp; product</th><th>Part &amp; operation</th><th>Stage</th>
        <th class="n ec">Qty</th><?php if ($showMoney): ?><th class="n<?= $canRate ? ' ec' : '' ?>">Rate</th><th class="n">Amount</th><?php endif; ?><th></th>
      </tr></thead>
      <tbody id="caBody"></tbody>
      <tfoot id="caFoot"></tfoot>
    </table></div>
    <div class="gsave" id="caSave" hidden>
      <span id="caChg"></span>
      <input class="zf-in" id="caGWhy" maxlength="150" placeholder="Why are these corrected? (required — kept with every old line)">
      <button type="button" class="zf-btn" id="caDiscard">Discard</button>
      <button type="button" class="zf-btn ok" id="caGo">Save</button>
    </div>
    <div class="note">Every correction keeps the old line on the list, marked "Edited", and books the new one through the same checks as Daily Production. Cutting that stitching already stands on cannot go below it — lower the stitching in the same save and it is done first.<?= $canRate ? ' A rate you type is what that line pays; an untouched rate stays what was paid.' : '' ?></div>
  </div>
</div>

<form method="post" id="caCancel" hidden>
  <?= csrf_field() ?><input type="hidden" name="action" value="cancel_many">
  <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
  <input type="hidden" name="entry_id" value=""><input type="hidden" name="reason" value="">
</form>

<script>
(function(){
  var G = <?= json_encode($G, JSON_HEX_TAG | JSON_HEX_AMP) ?>, L = <?= json_encode($L, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var W = <?= json_encode($W, JSON_HEX_TAG | JSON_HEX_AMP) ?>, STG = <?= json_encode($STG, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var MONEY = <?= $showMoney ? 'true' : 'false' ?>, CANRATE = <?= $canRate ? 'true' : 'false' ?>, FROM = <?= json_encode($from) ?>, TO = <?= json_encode($to) ?>, TODAY = <?= json_encode(date('Y-m-d')) ?>;
  var $ = function(i){ return document.getElementById(i); };
  function esc(t){ return String(t == null ? '' : t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (+v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function qn(v){ return (Math.round(v*100)/100).toLocaleString('en-US'); }
  function nice(d){ var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'short'}); }
  function stPill(s){ var c = (STG[s] || '').split(';').join(' !important;'); return '<span class="st" style="' + c + '">' + esc(s) + '</span>'; }
  function num(v){ v = String(v == null ? '' : v).replace(/,/g, '').trim(); if (v === '') return NaN; return +v; }

  var seg = 'act', every = false, picked = {};
  var wk = {}, sg = {}, WBY = {}, WCODE = {};
  W.forEach(function(w){ WBY[w.id] = w; var c = String(w.l).split(' · ')[0].toLowerCase(); WCODE[c] = w.id; });
  L.forEach(function(r){ wk[r.wid] = r.code + ' · ' + r.w; sg[r.st] = 1; });
  $('caW').innerHTML += Object.keys(wk).sort(function(a, b){ return wk[a].localeCompare(wk[b]); }).map(function(k){ return '<option value="' + k + '">' + esc(wk[k]) + '</option>'; }).join('');
  $('caS').innerHTML += Object.keys(sg).map(function(k){ return '<option>' + esc(k) + '</option>'; }).join('');

  /* ============ THE GRID: changes live here until Save ============ */
  var COLS = ['nd', 'wid', 'qty'].concat(CANRATE ? ['rate'] : []);
  var CH = {}, ERR = {}, DONE = {}, RS = [], AC = null, ED = null;
  function key(r){ return (every ? 'L' : 'G') + r.ids; }
  function orig(r, c){ return c === 'nd' ? r.d : c === 'wid' ? r.wid : c === 'qty' ? r.qty : r.rate; }
  function val(r, c){ var x = CH[key(r)]; return x && x[c] !== undefined ? x[c] : orig(r, c); }
  function isCh(r, c){ var x = CH[key(r)]; return !!(x && x[c] !== undefined); }
  function bad(r, c){
    if (!isCh(r, c)) return '';
    var v = val(r, c);
    if (c === 'qty') { var q = num(v); return isNaN(q) || q <= 0 ? 'A quantity above 0 (to remove a line use Cancel)' : ''; }
    if (c === 'rate') { var t = num(v); return isNaN(t) || t < 0 ? 'Not a rate' : ''; }
    if (c === 'nd') return /^\d{4}-\d{2}-\d{2}$/.test(v) && v <= TODAY ? '' : 'A real date, not in the future';
    if (c === 'wid') return WBY[v] ? '' : 'Choose a worker from the list';
    return '';
  }
  function setVal(r, c, v){
    if (!r.act || DONE[key(r)]) return;
    var k = key(r), x = CH[k] || {}, o = orig(r, c), same;
    if (c === 'qty' || c === 'rate') { var nv = num(v); same = !isNaN(nv) && Math.abs(nv - (+o || 0)) < 0.0001; }
    else if (c === 'wid') { v = +v; same = v === o; }
    else same = v === o;
    if (same) delete x[c]; else x[c] = v;
    if (Object.keys(x).length) CH[k] = x; else delete CH[k];
    delete ERR[k];
  }

  function rows(){
    var t = $('caQ').value.trim().toLowerCase().split(/\s+/).filter(Boolean), w = $('caW').value, s = $('caS').value;
    return (every ? L : G).filter(function(r){
      if (seg === 'act' && !r.act) return false;
      if (seg === 'off' && r.act) return false;
      if (w && String(r.wid) !== w) return false;
      if (s && r.st !== s) return false;
      var h = [r.w, r.code, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.by, r.why].join(' ').toLowerCase();
      return t.every(function(x){ return h.indexOf(x) >= 0; });
    });
  }
  function cell(r, i, c, inner, extra){
    var ed = r.act && !DONE[key(r)], cls = 'ed' + (ed ? '' : ' ro') + (isCh(r, c) ? ' chg' : '') + (bad(r, c) ? ' bad' : '') + (extra ? ' ' + extra : '');
    return '<td class="' + cls + '" data-ri="' + i + '" data-c="' + c + '" tabindex="-1"' + (bad(r, c) ? ' title="' + esc(bad(r, c)) + '"' : '') + '>' + inner + '</td>';
  }
  function rowHtml(r, i){
    var k = key(r), on = !!picked[r.ids], q = num(val(r, 'qty')), rt = num(val(r, 'rate')), wd = WBY[val(r, 'wid')], nd = val(r, 'nd');
    var amt = MONEY ? (isNaN(q) || isNaN(rt) ? null : q * rt) : null;
    return '<tr class="' + (r.act ? '' : 'off') + (on ? ' sel' : '') + (CH[k] ? ' rch' : '') + (ERR[k] ? ' rerr' : '') + (DONE[k] ? ' rok' : '') + '" data-ri="' + i + '">'
      + '<td>' + (r.act && !DONE[k] ? '<input type="checkbox" data-pick="' + esc(r.ids) + '"' + (on ? ' checked' : '') + '>' : '') + '</td>'
      + cell(r, i, 'nd', esc(nice(nd)) + '<div class="s2" title="Booked by ' + esc(r.by) + '">' + esc(r.at) + (r.by ? ' · ' + esc(r.by) : '') + '</div>', 'nw')
      + cell(r, i, 'wid', (wd && isCh(r, 'wid') ? esc(String(wd.l).split(' · ').slice(1).join(' · ')) + '<div class="s2">' + esc(String(wd.l).split(' · ')[0]) + '</div>'
              : esc(r.w) + '<div class="s2">' + esc(r.code) + (r.pt ? '<span class="pt">' + esc(r.pt) + '</span>' : '') + '</div>'))
      + '<td>' + esc(r.prod) + '<div class="s2">' + esc(r.pi) + (r.cust ? ' · ' + esc(r.cust) : '') + '</div>' + (r.desc ? '<div class="desc" title="' + esc(r.desc) + '">' + esc(r.desc) + '</div>' : '') + '</td>'
      + '<td>' + esc(r.part) + '<div class="s2">' + esc(r.ops) + '</div></td>'
      + '<td>' + stPill(r.st) + '</td>'
      + cell(r, i, 'qty', (isNaN(q) ? esc(val(r, 'qty')) : qn(q)) + (r.sizes ? '<div class="s2" title="' + esc(r.sizes) + '">' + (r.nsz > 1 ? r.nsz + ' sizes' : esc(r.sizes)) + '</div>' : '') + (isCh(r, 'qty') && !isNaN(q) ? '<div class="was">was ' + qn(r.qty) + '</div>' : ''), 'n')
      + (MONEY ? (CANRATE ? cell(r, i, 'rate', (isNaN(rt) ? esc(val(r, 'rate')) : n2(rt)) + (isCh(r, 'rate') && !isNaN(rt) ? '<div class="was">was ' + n2(r.rate) + '</div>' : ''), 'n') : '<td class="n">' + n2(r.rate) + '</td>')
               + '<td class="n' + (CH[k] && amt !== null && Math.abs(amt - r.amt) > 0.004 ? ' amch' : '') + '">' + (amt === null ? '—' : n2(amt)) + '</td>' : '')
      + '<td' + (r.act ? ' style="white-space:nowrap"' : '') + '>' + (DONE[k] ? '<span class="okb" title="Reload the page to see the new line">Saved ✓ · reload to see</span>'
          : ERR[k] ? '<div class="why" style="color:#b42318">' + esc(ERR[k]) + '</div>'
          : r.act ? '<button type="button" class="zf-btn sm danger" data-one="' + esc(r.ids) + '">Cancel</button>'
          : '<div class="why">' + esc(r.why || 'no reason recorded') + '<small>' + esc((r.cby ? r.cby + ' · ' : '') + r.cat) + '</small></div>') + '</td>'
      + '</tr>';
  }
  function paint(){
    RS = rows(); var h = '', q = 0, a = 0;
    RS.forEach(function(r, i){ if (r.act) { var x = num(val(r, 'qty')); q += isNaN(x) ? 0 : x; a += MONEY ? (x * num(val(r, 'rate')) || 0) : 0; } h += rowHtml(r, i); });
    $('caBody').innerHTML = h || '<tr><td colspan="11"><div class="zf-none">Nothing matches in ' + esc(nice(FROM)) + ' – ' + esc(nice(TO)) + '. Widen the dates above.</div></td></tr>';
    $('caFoot').innerHTML = RS.length ? '<tr><td></td><td colspan="5">Total · ' + RS.length + ' row' + (RS.length === 1 ? '' : 's') + ' (cancelled not counted' + (Object.keys(CH).length ? ', with your changes' : '') + ')</td><td class="n">' + qn(q) + '</td>'
      + (MONEY ? '<td></td><td class="n">' + n2(a) + '</td>' : '') + '<td></td></tr>' : '';
    $('caCnt').textContent = RS.length + ' of ' + (every ? L : G).length;
    window._caRows = RS;
    kpis(); selbar(); savebar(); focusAc();
  }
  function repaintRow(i){
    var tr = $('caBody').querySelector('tr[data-ri="' + i + '"]'); if (!tr || !RS[i]) return paint();
    var t = document.createElement('tbody'); t.innerHTML = rowHtml(RS[i], i); tr.parentNode.replaceChild(t.firstChild, tr);
    savebar();
  }
  function kpis(){
    var act = L.filter(function(r){ return r.act; }), off = L.filter(function(r){ return !r.act; });
    var ed = off.filter(function(r){ return r.ed; }).length, ppl = {}; act.forEach(function(r){ ppl[r.wid] = 1; });
    var amt = act.reduce(function(t, r){ return t + (r.amt || 0); }, 0);
    var c = [['k-blue', 'Active lines', act.length, MONEY ? 'PKR ' + n2(amt) + ' in wages' : qn(act.reduce(function(t, r){ return t + r.qty; }, 0)) + ' pieces'],
             ['k-grey', 'People', Object.keys(ppl).length, 'with work in these dates'],
             ['k-amber', 'Edited', ed, 'old lines kept, marked "Edited"'],
             ['k-red', 'Cancelled', off.length - ed, 'with their reasons']];
    $('caKpi').innerHTML = c.map(function(k){ return '<div class="zf-kpi ' + k[0] + '" style="cursor:default"><span>' + k[1] + '</span><b>' + k[2] + '</b><small>' + esc(k[3]) + '</small></div>'; }).join('');
  }
  function selbar(){
    var n = Object.keys(picked).length;
    $('caSel').hidden = !n; $('caSelN').textContent = n;
    $('caSelGo').textContent = 'Cancel ' + n + ' selected';
  }
  function badCount(){ var n = 0; Object.keys(CH).forEach(function(k){ var r = byKey(k); if (r) COLS.forEach(function(c){ if (bad(r, c)) n++; }); }); return n; }
  function byKey(k){ var list = k[0] === 'L' ? L : G, ids = k.slice(1); for (var i = 0; i < list.length; i++) if (list[i].ids === ids) return list[i]; return null; }
  function savebar(){
    var n = Object.keys(CH).length, cells = 0; Object.keys(CH).forEach(function(k){ cells += Object.keys(CH[k]).length; });
    var b = badCount();
    $('caSave').hidden = !n && !Object.keys(ERR).length;
    $('caChg').innerHTML = n ? '<b>' + n + '</b> row' + (n === 1 ? '' : 's') + ' changed · ' + cells + ' cell' + (cells === 1 ? '' : 's') + (b ? ' · <span style="color:#b42318">' + b + ' to fix (red)</span>' : '') : 'Nothing waiting';
    $('caGo').textContent = n ? 'Save ' + n + ' row' + (n === 1 ? '' : 's') : 'Save';
    $('caGo').disabled = !n || !!b;
  }

  /* ---- the active cell and the keyboard ---- */
  function cellEl(ri, ci){ return $('caBody').querySelector('td[data-ri="' + ri + '"][data-c="' + COLS[ci] + '"]'); }
  function focusAc(){
    [].forEach.call($('caBody').querySelectorAll('td.ac'), function(x){ x.classList.remove('ac'); });
    if (!AC || !RS[AC.r]) return;
    var td = cellEl(AC.r, AC.c); if (!td) return;
    td.classList.add('ac');
    /* keep the keyboard on the grid — unless the person has gone to another box (search, reason) */
    var a = document.activeElement;
    if (!ED && a !== td && (!a || a === document.body || $('caT').contains(a))) td.focus({ preventScroll: true });
    td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  function move(dr, dc){
    if (!AC) return;
    var r = AC.r, c = AC.c + dc;
    if (c >= COLS.length) { c = 0; r++; } else if (c < 0) { c = COLS.length - 1; r--; }
    r += dr;
    /* step over cancelled and saved rows */
    var step = dr < 0 || (dr === 0 && dc < 0) ? -1 : 1;
    while (RS[r] && (!RS[r].act || DONE[key(RS[r])])) r += step;
    if (!RS[r]) return;
    AC = { r: r, c: c }; focusAc();
  }
  function startEdit(first){
    if (!AC) return; var r = RS[AC.r], c = COLS[AC.c]; if (!r || !r.act || DONE[key(r)]) return;
    var td = cellEl(AC.r, AC.c); if (!td) return;
    var inp;
    if (c === 'wid') {
      inp = document.createElement('select');
      var opts = W.filter(function(w){ return !r.sid || !w.sg.length || w.sg.indexOf(r.sid) >= 0; });
      if (!opts.some(function(w){ return w.id === r.wid; })) opts.unshift({ id: r.wid, l: r.code + ' · ' + r.w });
      opts.forEach(function(w){ var o = document.createElement('option'); o.value = w.id; o.textContent = w.l; inp.appendChild(o); });
      inp.value = String(val(r, 'wid'));
    } else {
      inp = document.createElement('input');
      if (c === 'nd') { inp.type = 'date'; inp.max = TODAY; inp.value = val(r, 'nd'); }
      else { inp.type = 'text'; inp.inputMode = 'decimal'; inp.value = first != null ? first : String(val(r, c)); }
    }
    inp.className = 'gin';
    td.textContent = ''; td.appendChild(inp); td.classList.add('editing');
    ED = { r: AC.r, c: AC.c, el: inp };
    inp.focus();
    if (inp.type === 'text') { if (first == null) inp.select(); else inp.setSelectionRange(inp.value.length, inp.value.length); }
  }
  function commit(){
    if (!ED) return; var e = ED; ED = null;
    setVal(RS[e.r], COLS[e.c], e.el.value);
    repaintRow(e.r); focusAc();
  }
  function cancelEdit(){ if (!ED) return; var e = ED; ED = null; repaintRow(e.r); focusAc(); }

  $('caBody').addEventListener('mousedown', function(e){
    var td = e.target.closest('td.ed'); if (!td || td.classList.contains('editing')) return;
    if (ED) commit();
    AC = { r: +td.dataset.ri, c: COLS.indexOf(td.dataset.c) };
    if (!RS[AC.r].act) return;
    setTimeout(focusAc, 0);
  });
  $('caBody').addEventListener('dblclick', function(e){ var td = e.target.closest('td.ed'); if (td && !ED) { AC = { r: +td.dataset.ri, c: COLS.indexOf(td.dataset.c) }; startEdit(); } });
  $('caT').addEventListener('keydown', function(e){
    if (!AC) return;
    if (ED) {
      var sel = ED.el.tagName === 'SELECT', date = ED.el.type === 'date';
      if (e.key === 'Enter') { e.preventDefault(); commit(); move(1, 0); }
      else if (e.key === 'Tab') { e.preventDefault(); commit(); move(0, e.shiftKey ? -1 : 1); }
      else if (e.key === 'Escape') { e.preventDefault(); cancelEdit(); }
      else if (!sel && !date && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); commit(); move(e.key === 'ArrowUp' ? -1 : 1, 0); }
      /* the worker list: arrows step through it the same way on every browser */
      else if (sel && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); var s0 = ED.el; s0.selectedIndex = Math.max(0, Math.min(s0.options.length - 1, s0.selectedIndex + (e.key === 'ArrowUp' ? -1 : 1))); }
      return;
    }
    if (e.target.closest && e.target.closest('input[type=checkbox],button')) return;
    var k = e.key;
    if (k === 'ArrowDown' || k === 'ArrowUp') { e.preventDefault(); move(k === 'ArrowUp' ? -1 : 1, 0); }
    else if (k === 'ArrowLeft' || k === 'ArrowRight') { e.preventDefault(); move(0, k === 'ArrowLeft' ? -1 : 1); }
    else if (k === 'Tab') { e.preventDefault(); move(0, e.shiftKey ? -1 : 1); }
    else if (k === 'Enter' || k === 'F2') { e.preventDefault(); startEdit(); }
    else if (k === 'Escape') { e.preventDefault(); var r = RS[AC.r], x = CH[key(r)]; if (x) { delete x[COLS[AC.c]]; if (!Object.keys(x).length) delete CH[key(r)]; repaintRow(AC.r); focusAc(); } }
    else if ((e.ctrlKey || e.metaKey) && (k === 'd' || k === 'D')) { e.preventDefault(); var up = AC.r - 1; while (RS[up] && !RS[up].act) up--; if (RS[up]) { setVal(RS[AC.r], COLS[AC.c], String(val(RS[up], COLS[AC.c]))); repaintRow(AC.r); focusAc(); } }
    else if ((k === 'Delete' || k === 'Backspace') && (COLS[AC.c] === 'qty' || COLS[AC.c] === 'rate')) { e.preventDefault(); startEdit(''); }
    else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && /[0-9.,]/.test(k) && (COLS[AC.c] === 'qty' || COLS[AC.c] === 'rate')) { e.preventDefault(); startEdit(k); }
  });
  /* paste a block copied from Excel, starting at the active cell */
  document.addEventListener('paste', function(e){
    if (!AC || ED || !$('caT').contains(document.activeElement)) return;
    var txt = (e.clipboardData || window.clipboardData).getData('text'); if (!txt) return;
    e.preventDefault();
    var lines = txt.replace(/\r/g, '').replace(/\n$/, '').split('\n'), r = AC.r, n = 0;
    lines.forEach(function(line){
      while (RS[r] && (!RS[r].act || DONE[key(RS[r])])) r++;
      if (!RS[r]) return;
      line.split('\t').forEach(function(v, j){
        var c = COLS[AC.c + j]; if (!c) return; v = v.trim();
        if (c === 'wid') { var id = WCODE[v.split(' ')[0].toLowerCase()]; if (id) v = id; else return; }
        if (c === 'nd') { var m = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/.exec(v); if (m) v = m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2); }
        setVal(RS[r], c, v); n++;
      });
      r++;
    });
    paint(); toast(n + ' cell' + (n === 1 ? '' : 's') + ' pasted — check them, then Save.');
  });

  function toast(t, badT){ var x = document.createElement('div'); x.className = 'ca-toast' + (badT ? ' bad' : ''); x.textContent = t; document.body.appendChild(x); setTimeout(function(){ x.remove(); }, badT ? 6000 : 3500); }

  /* ---- Save: every changed row in ONE request ---- */
  $('caDiscard').addEventListener('click', function(){ if (Object.keys(CH).length && !confirm('Throw away the changes you typed?')) return; CH = {}; ERR = {}; paint(); });
  $('caGo').addEventListener('click', function(){
    var why = $('caGWhy').value.trim();
    if (!why) { $('caGWhy').focus(); $('caGWhy').style.borderColor = '#b42318'; return; }
    if (badCount()) return toast('Fix the red cells first.', true);
    var out = Object.keys(CH).map(function(k){
      var r = byKey(k), x = CH[k], o = { k: k, ids: r.ids, d: r.d, qty: num(val(r, 'qty')), wid: +val(r, 'wid'), nd: val(r, 'nd'), exact: k[0] === 'L' ? 1 : 0 };
      if (x.rate !== undefined) o.rate = num(x.rate);
      return o;
    });
    /* the page's own form carries the security token */
    var fd = new FormData($('caCancel')); fd.set('action', 'save_grid'); fd.set('reason', why); fd.delete('entry_id'); fd.append('changes', JSON.stringify(out));
    var b = this; b.disabled = true; b.textContent = 'Saving…';
    fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(j){
      if (!j.ok) { savebar(); return toast(j.error || 'Not saved.', true); }
      var fail = 0;
      (j.results || []).forEach(function(x){ if (x.ok) { DONE[x.k] = 1; delete CH[x.k]; delete ERR[x.k]; } else { ERR[x.k] = x.error; fail++; } });
      if (!fail) { window.removeEventListener('beforeunload', guard); location.reload(); return; }
      paint();
      toast(j.saved + ' saved, ' + fail + ' refused — the reason is on each red row. Fix them and Save again, or Discard. Reload to see the saved ones.', true);
    }).catch(function(){ savebar(); toast('The server could not be reached. Nothing was saved; try again.', true); });
  });
  function guard(e){ if (Object.keys(CH).length) { e.preventDefault(); e.returnValue = ''; } }
  window.addEventListener('beforeunload', guard);

  /* ---- filters, ticks, cancel (unchanged in what they do) ---- */
  function keepOrDrop(){ return !Object.keys(CH).length || confirm('Switching the view drops the changes you typed. Continue?'); }
  $('caSeg').addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; seg = b.dataset.f; [].forEach.call(this.children, function(x){ x.classList.toggle('on', x === b); }); picked = {}; AC = null; paint(); });
  ['caQ', 'caW', 'caS'].forEach(function(id){ $(id).addEventListener('input', function(){ AC = null; paint(); }); $(id).addEventListener('change', function(){ AC = null; paint(); }); });
  $('caLines').addEventListener('change', function(){ if (!keepOrDrop()) { this.checked = every; return; } CH = {}; ERR = {}; every = this.checked; picked = {}; AC = null; paint(); });
  $('caAll').addEventListener('change', function(){ var on = this.checked; picked = {}; if (on) RS.forEach(function(r){ if (r.act && !DONE[key(r)]) picked[r.ids] = 1; }); paint(); });
  $('caSelClear').addEventListener('click', function(){ picked = {}; $('caAll').checked = false; paint(); });
  $('caBody').addEventListener('change', function(e){ var c = e.target.closest('[data-pick]'); if (!c) return; if (c.checked) picked[c.dataset.pick] = 1; else delete picked[c.dataset.pick]; var tr = c.closest('tr'); tr.classList.toggle('sel', c.checked); selbar(); });

  function cancel(ids, why){
    window.removeEventListener('beforeunload', guard);
    var f = $('caCancel'); f.entry_id.value = ids; f.reason.value = why; f.submit();
  }
  $('caSelGo').addEventListener('click', function(){
    var why = $('caWhy').value.trim();
    if (!why) { $('caWhy').focus(); $('caWhy').style.borderColor = '#b42318'; return; }
    if (Object.keys(CH).length && !confirm('You also have typed changes that are not saved. Cancelling now drops them. Continue?')) return;
    if (!confirm('Cancel ' + Object.keys(picked).length + ' row(s)? They stay on the list, marked, with this reason.')) return;
    cancel(Object.keys(picked).join(','), why);
  });
  $('caBody').addEventListener('click', function(e){
    var one = e.target.closest('[data-one]'); if (!one) return;
    if (Object.keys(CH).length && !confirm('You have typed changes that are not saved. Cancelling this line now drops them. Continue?')) return;
    var why = prompt('Why is this being cancelled?\n\nThe line stays on the list, marked, with this reason.');
    if (why === null) return;
    if (!why.trim()) { alert('A reason is needed — a correction nobody can explain is indistinguishable from a mistake.'); return; }
    cancel(one.dataset.one, why.trim());
  });
  $('caCsv').addEventListener('click', function(){
    var head = ['Date', 'Booked at', 'By', 'Worker', 'Code', 'Pay type', 'Order', 'Customer', 'Product', 'Description', 'Part', 'Operations', 'Sizes', 'Stage', 'Status', 'Qty'];
    if (MONEY) head.push('Rate', 'Amount');
    head.push('Reason', 'Cancelled by', 'Cancelled at');
    var out = [head];
    RS.forEach(function(r){
      var row = [r.d, r.at, r.by, r.w, r.code, r.pt, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.act ? 'Active' : (r.ed ? 'Edited' : 'Cancelled'), r.qty];
      if (MONEY) row.push(r.rate, r.amt);
      row.push(r.why, r.cby, r.cat); out.push(row);
    });
    var csv = out.map(function(l){ return l.map(function(v){ v = v == null ? '' : String(v); if (/^[=+\-@]/.test(v) && isNaN(+v)) v = "'" + v; return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
    a.download = 'production_entries_' + FROM + '_to_' + TO + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
  /* start on the first editable cell, so the arrows work at once */
  paint();
  for (var i0 = 0; i0 < RS.length; i0++) if (RS[i0].act) { AC = { r: i0, c: COLS.indexOf('qty') }; break; }
  focusAc();
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
