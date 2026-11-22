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
    <div class="selbar" id="caSel" hidden>
      <span><b id="caSelN">0</b> selected</span>
      <input class="zf-in" id="caWhy" maxlength="200" placeholder="Why are these being cancelled? (required — kept with every line)">
      <button type="button" class="zf-btn" id="caSelClear">Clear</button>
      <button type="button" class="zf-btn danger" id="caSelGo">Cancel selected</button>
    </div>
    <div class="zf-tw"><table class="zf-t">
      <thead><tr>
        <th style="width:28px"><input type="checkbox" id="caAll" title="Tick every active row shown"></th>
        <th>Date</th><th>Worker</th><th>Order &amp; product</th><th>Part &amp; operation</th><th>Stage</th>
        <th class="n">Qty</th><?php if ($showMoney): ?><th class="n">Rate</th><th class="n">Amount</th><?php endif; ?><th></th>
      </tr></thead>
      <tbody id="caBody"></tbody>
      <tfoot id="caFoot"></tfoot>
    </table></div>
    <div class="note">Cutting that stitching already stands on cannot be cancelled or reduced below it — reduce the stitching first, or tick both and cancel them together.</div>
  </div>
</div>

<dialog class="ca-dlg zf" id="caDlg">
  <form method="post" id="caEdit">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit">
    <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <input type="hidden" name="entry_id" value=""><input type="hidden" name="date" value=""><input type="hidden" name="exact" value="">
    <h3>Edit booked work</h3>
    <div class="what" id="caWhat"></div>
    <div class="two">
      <label>Quantity<input class="zin" name="new_qty" inputmode="decimal" required style="text-align:right"></label>
      <label>Date<input class="zin" type="date" name="new_date" max="<?= e(date('Y-m-d')) ?>" required></label>
    </div>
    <label>Worker<select class="zin" name="new_worker" id="caWk"></select></label>
    <label>Why is it changed?<input class="zin" name="reason" required maxlength="150" placeholder="e.g. counted again, wrong worker, booked on the wrong day"></label>
    <div class="hint" id="caHint"></div>
    <div class="btns"><button type="button" class="zf-btn" onclick="document.getElementById('caDlg').close()">Close</button>
      <button class="zf-btn ok">Save the change</button></div>
  </form>
</dialog>
<form method="post" id="caCancel" hidden>
  <?= csrf_field() ?><input type="hidden" name="action" value="cancel_many">
  <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
  <input type="hidden" name="entry_id" value=""><input type="hidden" name="reason" value="">
</form>

<script>
(function(){
  var G = <?= json_encode($G, JSON_HEX_TAG | JSON_HEX_AMP) ?>, L = <?= json_encode($L, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var W = <?= json_encode($W, JSON_HEX_TAG | JSON_HEX_AMP) ?>, STG = <?= json_encode($STG, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var MONEY = <?= $showMoney ? 'true' : 'false' ?>, FROM = <?= json_encode($from) ?>, TO = <?= json_encode($to) ?>;
  var $ = function(i){ return document.getElementById(i); };
  function esc(t){ return String(t == null ? '' : t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (+v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function qn(v){ return (Math.round(v*100)/100).toLocaleString('en-US'); }
  function nice(d){ var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString('en-GB', {weekday:'short', day:'numeric', month:'short'}); }
  function stPill(s){ var c = (STG[s] || '').split(';').join(' !important;'); return '<span class="st" style="' + c + '">' + esc(s) + '</span>'; }

  var seg = 'act', every = false, picked = {};
  /* fill the two drop-downs from what is in the window */
  var wk = {}, sg = {};
  L.forEach(function(r){ wk[r.wid] = r.code + ' · ' + r.w; sg[r.st] = 1; });
  $('caW').innerHTML += Object.keys(wk).sort(function(a, b){ return wk[a].localeCompare(wk[b]); }).map(function(k){ return '<option value="' + k + '">' + esc(wk[k]) + '</option>'; }).join('');
  $('caS').innerHTML += Object.keys(sg).map(function(k){ return '<option>' + esc(k) + '</option>'; }).join('');

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
  function paint(){
    var rs = rows(), h = '', q = 0, a = 0;
    rs.forEach(function(r, i){
      if (r.act) { q += r.qty; a += r.amt || 0; }
      var on = !!picked[r.ids];
      h += '<tr class="' + (r.act ? '' : 'off') + (on ? ' sel' : '') + '">'
        + '<td>' + (r.act ? '<input type="checkbox" data-pick="' + esc(r.ids) + '"' + (on ? ' checked' : '') + '>' : '') + '</td>'
        + '<td style="white-space:nowrap">' + esc(nice(r.d)) + '<div class="s2" title="Booked by ' + esc(r.by) + '">' + esc(r.at) + (r.by ? ' · ' + esc(r.by) : '') + '</div></td>'
        + '<td>' + esc(r.w) + '<div class="s2">' + esc(r.code) + (r.pt ? '<span class="pt">' + esc(r.pt) + '</span>' : '') + '</div></td>'
        + '<td>' + esc(r.prod) + '<div class="s2">' + esc(r.pi) + (r.cust ? ' · ' + esc(r.cust) : '') + '</div>' + (r.desc ? '<div class="desc" title="' + esc(r.desc) + '">' + esc(r.desc) + '</div>' : '') + '</td>'
        + '<td>' + esc(r.part) + '<div class="s2">' + esc(r.ops) + '</div></td>'
        + '<td>' + stPill(r.st) + '</td>'
        + '<td class="n">' + qn(r.qty) + (r.sizes ? '<div class="s2" title="' + esc(r.sizes) + '">' + (r.nsz > 1 ? r.nsz + ' sizes' : esc(r.sizes)) + '</div>' : '') + '</td>'
        + (MONEY ? '<td class="n">' + n2(r.rate) + '</td><td class="n">' + n2(r.amt) + '</td>' : '')
        + '<td' + (r.act ? ' style="white-space:nowrap"' : '') + '>' + (r.act
            ? '<button type="button" class="zf-btn sm" data-edit="' + i + '">Edit</button> <button type="button" class="zf-btn sm danger" data-one="' + esc(r.ids) + '">Cancel</button>'
            : '<div class="why">' + esc(r.why || 'no reason recorded') + '<small>' + esc((r.cby ? r.cby + ' · ' : '') + r.cat) + '</small></div>') + '</td>'
        + '</tr>';
    });
    $('caBody').innerHTML = h || '<tr><td colspan="11"><div class="zf-none">Nothing matches in ' + esc(nice(FROM)) + ' – ' + esc(nice(TO)) + '. Widen the dates above.</div></td></tr>';
    $('caFoot').innerHTML = rs.length ? '<tr><td></td><td colspan="5">Total · ' + rs.length + ' row' + (rs.length === 1 ? '' : 's') + ' (cancelled not counted)</td><td class="n">' + qn(q) + '</td>'
      + (MONEY ? '<td></td><td class="n">' + n2(a) + '</td>' : '') + '<td></td></tr>' : '';
    $('caCnt').textContent = rs.length + ' of ' + (every ? L : G).length;
    window._caRows = rs;
    kpis(); selbar();
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

  $('caSeg').addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; seg = b.dataset.f; [].forEach.call(this.children, function(x){ x.classList.toggle('on', x === b); }); picked = {}; paint(); });
  ['caQ', 'caW', 'caS'].forEach(function(id){ $(id).addEventListener('input', paint); $(id).addEventListener('change', paint); });
  $('caLines').addEventListener('change', function(){ every = this.checked; picked = {}; paint(); });
  $('caAll').addEventListener('change', function(){ var on = this.checked; picked = {}; if (on) (window._caRows || []).forEach(function(r){ if (r.act) picked[r.ids] = 1; }); paint(); });
  $('caSelClear').addEventListener('click', function(){ picked = {}; $('caAll').checked = false; paint(); });
  $('caBody').addEventListener('change', function(e){ var c = e.target.closest('[data-pick]'); if (!c) return; if (c.checked) picked[c.dataset.pick] = 1; else delete picked[c.dataset.pick]; paint(); });

  function cancel(ids, why){
    var f = $('caCancel'); f.entry_id.value = ids; f.reason.value = why; f.submit();
  }
  $('caSelGo').addEventListener('click', function(){
    var why = $('caWhy').value.trim();
    if (!why) { $('caWhy').focus(); $('caWhy').style.borderColor = '#b42318'; return; }
    var ids = Object.keys(picked).join(',');
    if (!confirm('Cancel ' + Object.keys(picked).length + ' row(s)? They stay on the list, marked, with this reason.')) return;
    cancel(ids, why);
  });
  $('caBody').addEventListener('click', function(e){
    var one = e.target.closest('[data-one]');
    if (one) {
      var why = prompt('Why is this being cancelled?\n\nThe line stays on the list, marked, with this reason.');
      if (why === null) return;
      if (!why.trim()) { alert('A reason is needed — a correction nobody can explain is indistinguishable from a mistake.'); return; }
      cancel(one.dataset.one, why.trim()); return;
    }
    var ed = e.target.closest('[data-edit]');
    if (!ed) return;
    var r = window._caRows[+ed.dataset.edit], f = $('caEdit');
    f.entry_id.value = r.ids; f.date.value = r.d; f.new_date.value = r.d; f.new_qty.value = r.qty; f.reason.value = ''; f.exact.value = every ? '1' : '';
    $('caWhat').innerHTML = '<b>' + esc(r.w) + '</b> · ' + esc(r.pi) + ' · ' + esc(r.prod) + '<br>' + esc(r.part) + ' → ' + esc(r.ops) + ' · ' + stPill(r.st)
      + ' · ' + qn(r.qty) + ' pcs' + (r.sizes ? ' (' + esc(r.sizes) + ')' : '');
    /* only people ticked for this stage, and always the person it is booked to now */
    var opts = W.filter(function(w){ return !r.sid || !w.sg.length || w.sg.indexOf(r.sid) >= 0; });
    if (!opts.some(function(w){ return w.id === r.wid; })) opts.unshift({id: r.wid, l: r.code + ' · ' + r.w});
    $('caWk').innerHTML = opts.map(function(w){ return '<option value="' + w.id + '"' + (w.id === r.wid ? ' selected' : '') + '>' + esc(w.l) + '</option>'; }).join('');
    $('caHint').textContent = every
      ? 'This one saved line is re-booked on the same size and operation.'
      : (r.n > 1 ? 'This row is ' + r.n + ' saved lines. They are kept as they are with the new worker or date; a lower quantity comes off the last lines, a higher one is shared over what is still free.' : 'The line is kept as it is with the new worker or date.')
        + ' The old line stays on the list, marked "Edited", and the new one is checked against what is left.';
    $('caDlg').showModal(); f.new_qty.select();
  });
  $('caCsv').addEventListener('click', function(){
    var head = ['Date', 'Booked at', 'By', 'Worker', 'Code', 'Pay type', 'Order', 'Customer', 'Product', 'Description', 'Part', 'Operations', 'Sizes', 'Stage', 'Status', 'Qty'];
    if (MONEY) head.push('Rate', 'Amount');
    head.push('Reason', 'Cancelled by', 'Cancelled at');
    var out = [head];
    (window._caRows || []).forEach(function(r){
      var row = [r.d, r.at, r.by, r.w, r.code, r.pt, r.pi, r.cust, r.prod, r.desc, r.part, r.ops, r.sizes, r.st, r.act ? 'Active' : (r.ed ? 'Edited' : 'Cancelled'), r.qty];
      if (MONEY) row.push(r.rate, r.amt);
      row.push(r.why, r.cby, r.cat); out.push(row);
    });
    var csv = out.map(function(l){ return l.map(function(v){ v = v == null ? '' : String(v); if (/^[=+\-@]/.test(v) && isNaN(+v)) v = "'" + v; return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }).join(','); }).join('\r\n');
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], {type: 'text/csv;charset=utf-8'}));
    a.download = 'production_entries_' + FROM + '_to_' + TO + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  });
  paint();
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
