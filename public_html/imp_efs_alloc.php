<?php
/*
  IMPORTS — ONE EXPORT ALLOCATION. Phase 3.

  One export shipment's EFS claim, built from the shipment's CUSTOMS invoice
  lines (its sale invoice lines while it has no customs invoice):
    - tick the lines that used imported EFS material;
    - give each its EFS value (all of the line, or only the part that used
      EFS material — the rest of the product may be local);
    - under each, add the import GD lines it used: kg typed by hand, value
      split by kg × valuation unless typed per line.
  The summary under it — and everything on EFS Reconciliation — is the total
  of that detail. The export GD's number, date, net weight and FOB can be
  added now or later; their differences are shown, never blocked.

  The browser only previews. imp_efs_compute() works everything out again on
  the server when it is saved, with the same rules. Filed with customs locks
  it; after that only a reversal (with its reason) changes the figures.
  Nothing here touches any invoice, stock or weight.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('efs', 'v');

$id = (int)($_GET['id'] ?? $_POST['alloc_id'] ?? 0);
$alloc = $id ? imp_efs_alloc($id) : null;
if ($id && !$alloc) { http_response_code(404); exit('That allocation is not there.'); }
$shipId = $alloc ? (int)$alloc['shipment_id'] : (int)($_GET['shipment'] ?? $_POST['shipment_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'imp_efs_alloc.php?' . ($id ? 'id=' . $id : 'shipment=' . $shipId);
    if ($act === 'save') {
        imp_require('efs', $id ? 'u' : 'c');
        $form = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($form)) { $_SESSION['error'] = 'The form did not arrive — try again.'; redirect($back); }
        $form['shipment_id'] = $shipId;
        $r = imp_efs_save($form, $id ?: null);
        if ($r['ok']) {
            unset($_SESSION['imp_efs_draft']);
            $_SESSION['flash'] = 'Allocation saved — ' . imp_efs_q($r['res']['kg']) . ' kg of import material, ' . ($r['res']['source']['kind'] === 'customs' ? 'customs' : 'sale') . ' invoice value counted ' . number_format($r['res']['counted'], 2) . '.';
            redirect('imp_efs_alloc.php?id=' . $r['id']);
        }
        /* keep what was typed so nothing has to be entered again */
        $_SESSION['imp_efs_draft'] = ['key' => $id ?: 's' . $shipId, 'form' => $form];
        $_SESSION['error'] = $r['error'];
        redirect($back);
    }
    if ($act === 'file') {
        imp_require('efs', 'u');
        $r = imp_efs_file($id, imp_date($_POST['filed_on'] ?? ''), (string)($_POST['filed_ref'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok'] ? 'Filed with customs. The figures are locked; a change now needs a reversal.' : $r['error'];
        redirect($back);
    }
    if ($act === 'reverse') {
        imp_require('efs', 'r');
        $r = imp_efs_reverse($id, (string)($_POST['reason'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok'] ? 'Reversed. Its kg and value went back to the import lines; a new allocation can be made for this shipment.' : $r['error'];
        redirect($back);
    }
    redirect($back);
}

/* no shipment yet: pick one */
if (!$shipId) {
    $ships = imp_efs_shipments();
    page_header('Export allocation');
    flash();
    echo imp_page_css(); ?>
    <div class="xcard"><h2>Export allocation — pick the shipment</h2>
      <div class="xwrap"><table class="xtable" style="min-width:680px"><thead><tr><th>Shipment</th><th>Date</th><th>Buyer</th><th class="num">Invoice value</th><th></th></tr></thead><tbody>
      <?php foreach ($ships as $s): ?>
        <tr><td><b><?= e($s['invoice_no']) ?></b></td><td><?= e(imp_dmy($s['invoice_date'])) ?></td><td><?= e($s['buyer_name']) ?></td><td class="num"><?= e(($s['currency'] ?: 'USD') . ' ' . number_format((float)$s['total_amount'], 2)) ?></td>
          <td><?= $s['alloc_id'] ? '<a class="xbtn sec sm" href="imp_efs_alloc.php?id=' . (int)$s['alloc_id'] . '">Open its allocation</a>' : (imp_can('efs', 'c') ? '<a class="xbtn sm" href="imp_efs_alloc.php?shipment=' . (int)$s['id'] . '">Allocate</a>' : '') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$ships): ?><tr><td colspan="5" class="imp-muted">No shipments yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php page_footer(); exit;
}

$ship = imp_efs_shipment($shipId);
if (!$ship) { http_response_code(404); exit('That shipment is not there.'); }
if (!$alloc) {
    $c = db()->prepare("SELECT id FROM imp_efs_alloc WHERE shipment_id=? AND status<>'reversed' ORDER BY id DESC LIMIT 1"); $c->execute([$shipId]);
    if ($ex = (int)$c->fetchColumn()) redirect('imp_efs_alloc.php?id=' . $ex);
    imp_require('efs', 'c');
}
$cur = (string)($ship['currency'] ?: 'USD');
$src = imp_efs_source($shipId);
$obl = imp_efs_obligations(null, $alloc ? (int)$alloc['id'] : null);
$editable = !$alloc || ($alloc['status'] === 'open' && imp_can('efs', 'u'));
$recheck = $alloc ? imp_efs_recheck($alloc) : '';

/* what the form starts from: a kept draft, the saved allocation, or nothing */
$draft = $_SESSION['imp_efs_draft'] ?? null;
if ($draft && $draft['key'] === ($id ?: 's' . $shipId)) { $start = $draft['form']; unset($_SESSION['imp_efs_draft']); }
elseif ($alloc) {
    $start = ['rates' => $alloc['rates_arr'], 'export_gd_no' => $alloc['export_gd_no'], 'export_gd_date' => $alloc['export_gd_date'], 'gd_net_kg' => $alloc['gd_net_kg'], 'gd_fob' => $alloc['gd_fob'],
              'below_reason' => $alloc['below_reason'], 'warn_reason' => $alloc['warn_reason'], 'lines' => []];
    foreach ($alloc['lines'] as $l) {
        $start['lines'][] = ['src_id' => (int)$l['src_id'], 'efs_value' => (float)$l['efs_value'], 'manual' => (bool)$l['manual'],
            'uses' => array_map(fn($u) => ['key' => ($u['target_kind'] === 'line' ? 'L' : 'O') . (int)$u['target_id'], 'kg' => (float)$u['kg'], 'value' => (float)$u['value_export']], $l['uses'])];
    }
} else $start = ['rates' => [], 'lines' => []];

/* the obligations the picker offers: those with kg left, plus any this allocation already uses — oldest deadline first */
$usedKeys = [];
foreach ((array)($start['lines'] ?? []) as $l) foreach ((array)($l['uses'] ?? []) as $u) $usedKeys[(string)($u['key'] ?? '')] = true;
$OBL = [];
foreach ($obl as $k => $o) {
    if ($o['max_kg'] <= 0.0005 && !isset($usedKeys[$k])) continue;
    $OBL[] = ['key' => $k, 'gd' => $o['gd_no'], 'ref' => $o['ref'], 'hs' => $o['hs'], 'product' => $o['product'], 'cur' => $o['currency'], 'val' => $o['valuation'],
              'floor' => $o['floor_per_kg'], 'va' => $o['va'], 'max' => $o['max_kg'], 'rcv' => $o['received_kg'], 'done' => $o['done_kg'], 'kind' => $o['kind'],
              'deadline' => $o['deadline'] ? imp_dmy($o['deadline']) : '—', 'pill' => $o['pill'], 'left' => $o['left'], 'dsort' => $o['deadline'] ?? '9999'];
}

page_header('Export allocation — ' . $ship['invoice_no']);
flash();
echo imp_page_css();
?>
<style>
.dl{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap}.dl.r{background:rgba(224,67,93,.13);color:#b8283f}.dl.o{background:rgba(217,119,6,.14);color:#b86a05}.dl.g{background:rgba(22,163,74,.12);color:#16803c}.dl.n{background:#f0f3f8;color:#5a6b82}
.efs-el{border:1px solid #e3e9f2;border-radius:12px;padding:10px 12px;background:#fff}.efs-el.on{border-color:rgba(14,168,201,.5);background:#f7fcfe}
.efs-src{font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#8a97ab}
.efs-up{color:#16803c;font-size:11.5px}.efs-dn{color:#b8283f;font-size:11.5px;font-weight:800}
.xwarn{padding:11px 14px;border-radius:12px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.28);font-size:12.5px;color:#9a5a06;line-height:1.6}
</style>
<div class="xhead">
  <div><div class="t">Export allocation · <?= e($ship['invoice_no']) ?></div>
    <div class="s"><?= !empty($ship['invoice_date']) ? e(imp_dmy($ship['invoice_date'])) . ' · ' : '' ?><?= e($ship['buyer_name']) ?> · invoice <?= e($cur . ' ' . number_format((float)$ship['total_amount'], 2)) ?> · built from the <b><?= $src['kind'] === 'customs' ? 'customs invoice' : 'sale invoice (no customs invoice yet)' ?></b></div></div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <?php if ($alloc): ?><span class="xpill <?= $alloc['status'] === 'filed' ? 'g' : ($alloc['status'] === 'reversed' ? 'n' : 'b') ?>"><?= e($alloc['status'] === 'filed' ? 'Filed ' . imp_dmy($alloc['filed_on']) . ' · ' . $alloc['filed_ref'] : ($alloc['status'] === 'open' ? 'Not filed' : ucfirst($alloc['status']))) ?></span><?php endif; ?>
    <?php if ($recheck): ?><span class="xpill o">recheck</span><?php endif; ?>
    <a class="xbtn sec sm" href="imp_efs_recon.php">EFS Reconciliation</a>
  </div>
</div>
<?php if ($recheck): ?><div class="xwarn" style="margin-bottom:12px"><b>Recheck:</b> <?= e($recheck) ?>. The figures below are as saved — check them against the invoice<?= $editable ? ' and save again' : '' ?>.</div><?php endif; ?>
<?php if ($alloc && $alloc['status'] === 'reversed'): ?><div class="xnote" style="margin-bottom:12px">Reversed <?= e(imp_dmy(substr((string)$alloc['reversed_at'], 0, 10))) ?>: <?= e($alloc['reverse_reason']) ?>. Its kg and value no longer count. <?= imp_can('efs', 'c') ? '<a href="imp_efs_alloc.php?shipment=' . $shipId . '">Make a new allocation for this shipment →</a>' : '' ?></div><?php endif; ?>
<?php if (!$src['lines']): ?><div class="xbad">This shipment has no invoice lines yet — add them on the shipment (or its Customs tab) first.</div><?php page_footer(); exit; endif; ?>

<?php if ($editable): ?>
<form method="post" id="aForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="alloc_id" value="<?= $id ?>"><input type="hidden" name="shipment_id" value="<?= $shipId ?>"><input type="hidden" name="payload" id="payload">
<div class="xcard">
  <h2>Export GD <span class="imp-small imp-muted" style="font-weight:600">— now or later</span></h2>
  <div class="xgrid">
    <label class="xlabel">Export GD no.<input class="xin" id="gdNo" value="<?= e($start['export_gd_no'] ?? '') ?>" placeholder="e.g. KEXP-HC-90145"></label>
    <label class="xlabel">Export GD date<input class="xin" type="date" id="gdDate" value="<?= e($start['export_gd_date'] ?? '') ?>"></label>
    <label class="xlabel">GD net weight (kg)<input class="xin num" id="gdKg" value="<?= e(($start['gd_net_kg'] ?? '') !== null ? (string)($start['gd_net_kg'] ?? '') : '') ?>"></label>
    <label class="xlabel">GD FOB value (<?= e($cur) ?>)<input class="xin num" id="gdFob" value="<?= e(($start['gd_fob'] ?? '') !== null ? (string)($start['gd_fob'] ?? '') : '') ?>"></label>
  </div>
  <div id="rates"></div>
</div>
<div class="xcard">
  <h2>Export lines that used EFS material</h2>
  <div class="imp-small imp-muted" style="margin:-6px 0 10px">Tick only the lines that used imported EFS material. Lower a line's EFS value when only part of the product uses it (the rest may be local).</div>
  <div id="lines" style="display:flex;flex-direction:column;gap:10px"></div>
</div>
<div class="xcard">
  <h2>Summary — the total of the detail above</h2>
  <div id="summary"></div>
  <div id="reasons"></div>
  <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px"><a class="xbtn sec" href="imp_efs_recon.php">Cancel</a><button class="xbtn" type="submit" id="saveBtn"><?= $alloc ? 'Save changes' : 'Save allocation' ?></button></div>
</div>
</form>
<?php else: /* filed, reversed, or no right to change it: the saved figures */ ?>
<div class="xcard">
  <h2>Export GD</h2>
  <p class="imp-small" style="margin:0">GD <b><?= e($alloc['export_gd_no'] ?: '—') ?></b> · <?= e(imp_dmy($alloc['export_gd_date'])) ?> · net weight <?= $alloc['gd_net_kg'] !== null ? e(imp_efs_q((float)$alloc['gd_net_kg'])) . ' kg' : '—' ?> · FOB <?= $alloc['gd_fob'] !== null ? e($cur . ' ' . number_format((float)$alloc['gd_fob'], 2)) : '—' ?><?= $alloc['rates_arr'] ? ' · rates: ' . e(implode(', ', array_map(fn($c, $r) => '1 ' . $cur . ' = ' . $r . ' ' . $c, array_keys($alloc['rates_arr']), $alloc['rates_arr']))) : '' ?></p>
</div>
<div class="xcard"><h2>Export lines and the import lines they used</h2>
  <div class="xwrap"><table class="xtable" style="min-width:900px"><thead><tr><th>Export line</th><th class="num">Line value</th><th class="num">EFS value</th><th>Import GD · line</th><th class="num">kg</th><th class="num">Assessed value of this kg</th><th class="num">Value counted</th></tr></thead><tbody>
  <?php $tk = 0; $tv = 0; foreach ($alloc['lines'] as $l): $first = true; foreach ($l['uses'] as $u): $k = ($u['target_kind'] === 'line' ? 'L' : 'O') . (int)$u['target_id']; $o = $obl[$k] ?? null; $tk += (float)$u['kg']; ?>
    <tr><?php if ($first): ?><td rowspan="<?= count($l['uses']) ?>"><b><?= e($l['description']) ?></b></td><td class="num" rowspan="<?= count($l['uses']) ?>"><?= e(number_format((float)$l['line_value'], 2)) ?></td><td class="num" rowspan="<?= count($l['uses']) ?>"><b><?= e(number_format((float)$l['efs_value'], 2)) ?></b></td><?php $tv += (float)$l['efs_value']; endif; $first = false; ?>
      <td><b><?= e($o ? ($o['gd_no'] ?: '—') : '?') ?></b><div class="efs-src"><?= e($o ? $o['ref'] : $k) ?></div></td><td class="num"><?= e(imp_efs_q((float)$u['kg'])) ?></td>
      <td class="num"><?= e(($o['currency'] ?? '') . ' ' . number_format((float)$u['floor_target'], 2)) ?></td>
      <td class="num"><?= e(($o['currency'] ?? '') . ' ' . number_format((float)$u['value_target'], 2)) ?> <?= (float)$u['value_target'] < (float)$u['floor_target'] - 0.005 ? '<span class="efs-dn">' . e(number_format((float)$u['floor_target'] - (float)$u['value_target'], 2)) . ' below</span>' : '<span class="efs-up">ok</span>' ?></td></tr>
  <?php endforeach; endforeach; ?>
  </tbody><tfoot><tr><td colspan="2">Total</td><td class="num"><?= e($cur . ' ' . number_format($tv, 2)) ?></td><td></td><td class="num"><?= e(imp_efs_q($tk)) ?> kg</td><td colspan="2"></td></tr></tfoot></table></div>
  <?php if ($alloc['below_reason']): ?><div class="xwarn" style="margin-top:10px">Below the assessed value — reason: <?= e($alloc['below_reason']) ?></div><?php endif; ?>
  <?php if ($alloc['warn_reason']): ?><div class="xwarn" style="margin-top:10px">More kg than received at Gate In — reason: <?= e($alloc['warn_reason']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($alloc && $alloc['status'] !== 'reversed'): ?>
<div class="xcard">
  <h2>Customs</h2>
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
    <?php if ($alloc['status'] === 'open' && imp_can('efs', 'u')): ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="file"><input type="hidden" name="alloc_id" value="<?= $id ?>">
      <label class="xlabel" style="margin:0">Filed on<input class="xin" type="date" name="filed_on" value="<?= e(imp_today()) ?>"></label>
      <label class="xlabel" style="margin:0">Customs reference<input class="xin" name="filed_ref" placeholder="statement / submission no."></label>
      <button class="xbtn">Filed with customs</button></form>
    <?php endif; ?>
    <?php if (imp_can('efs', 'r')): ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end" onsubmit="return confirm('Reverse this allocation? Its kg and value go back to the import lines; it stays on the record with your reason.');"><?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="alloc_id" value="<?= $id ?>">
      <label class="xlabel" style="margin:0">Reason to reverse<input class="xin" name="reason" placeholder="e.g. typed against the wrong GD" style="min-width:260px"></label>
      <button class="xbtn red">Reverse…</button></form>
    <?php endif; ?>
  </div>
  <div class="imp-small imp-muted" style="margin-top:8px"><?= $alloc['status'] === 'filed' ? 'Filed: the figures are locked. A change needs a reversal (with its reason), then a new allocation.' : 'Once filed with customs the figures lock; until then they can be changed and saved again.' ?></div>
</div>
<?php endif; ?>

<?php if ($editable): ?>
<script>
(function () {
  var SRC = <?= json_encode($src['lines'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var OBL = <?= json_encode($OBL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CUR = <?= json_encode($cur) ?>;
  var F = <?= json_encode($start, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  F.rates = F.rates && !Array.isArray(F.rates) ? F.rates : {}; F.lines = F.lines || [];
  var OB = {}; OBL.forEach(function (o) { OB[o.key] = o; });
  OBL.sort(function (a, b) { return a.dsort < b.dsort ? -1 : a.dsort > b.dsort ? 1 : 0; });
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function f(n, d) { d = d == null ? 2 : d; return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function q(n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 3 }); }
  function num(v) { var x = parseFloat(String(v == null ? '' : v).replace(/,/g, '')); return isFinite(x) ? x : 0; }
  function srcOf(id) { for (var i = 0; i < SRC.length; i++) if (SRC[i].id === id) return SRC[i]; return null; }
  function lineOf(id) { for (var i = 0; i < F.lines.length; i++) if (+F.lines[i].src_id === id) return F.lines[i]; return null; }
  function rateOf(c) { return c === CUR ? 1 : num(F.rates[c]); }
  /* the same maths as imp_efs_compute() on the server */
  function work(el) {
    var uses = (el.uses || []).filter(function (u) { return OB[u.key] && num(u.kg) > 0; }), efs = num(el.efs_value);
    var out = uses.map(function (u) { var o = OB[u.key], r = rateOf(o.cur); return { u: u, o: o, kg: num(u.kg), rate: r }; });
    if (el.manual) out.forEach(function (x) { x.ve = Math.round(num(x.u.value) * 100) / 100; });
    else {
      var w = out.map(function (x) { return x.rate > 0 ? x.kg * x.o.val / x.rate : x.kg * x.o.val; }), sw = w.reduce(function (a, b) { return a + b; }, 0), left = efs;
      out.forEach(function (x, i) { var v = i === out.length - 1 ? left : (sw > 0 ? Math.round(efs * w[i] / sw * 100) / 100 : 0); left = Math.round((left - v) * 100) / 100; x.ve = v; });
    }
    out.forEach(function (x) { x.vt = Math.round(x.ve * x.rate * 100) / 100; x.fl = Math.round(x.kg * x.o.floor * 100) / 100; x.diff = Math.round((x.vt - x.fl) * 100) / 100; });
    return out;
  }
  function diffTxt(d) { return Math.abs(d) < 0.005 ? '<span class="efs-up">= assessed</span>' : d > 0 ? '<span class="efs-up">+' + f(d) + ' above</span>' : '<span class="efs-dn">' + f(-d) + ' below</span>'; }
  function oblOption(o, sel) {
    var flag = o.left !== null && o.left < 0 ? ' · OVERDUE' : (o.left !== null && o.left <= 60 ? ' · due in ' + o.left + ' d' : '');
    return '<option value="' + esc(o.key) + '"' + (sel ? ' selected' : '') + '>' + esc((o.gd || 'GD —') + ' · ' + o.product + ' · ' + q(o.max) + ' kg left · ' + o.deadline + flag) + '</option>';
  }
  function draw() {
    var err = [], warn = [], notes = [], need = {};
    /* the lines */
    var h = '';
    SRC.forEach(function (s) {
      var el = lineOf(s.id), on = !!el;
      h += '<div class="efs-el' + (on ? ' on' : '') + '"><label class="tog" style="padding:0"><input type="checkbox" data-pick="' + s.id + '"' + (on ? ' checked' : '') + '> <span><b>' + esc(s.description) + '</b> <span class="imp-small imp-muted">' + (s.hs ? '· HS ' + esc(s.hs) + ' ' : '') + '· ' + q(s.qty) + ' ' + esc(s.unit) + ' · ' + CUR + ' ' + f(s.amount) + '</span></span></label>';
      if (on) {
        var W = work(el), sumV = W.reduce(function (a, x) { return a + x.ve; }, 0), fl = W.reduce(function (a, x) { return a + x.fl; }, 0), vt = W.reduce(function (a, x) { return a + x.vt; }, 0);
        if (num(el.efs_value) > s.amount + 0.005) err.push(s.description + ': EFS value is more than the line value');
        if (!W.length) err.push(s.description + ': add the import GD line it used, with kg');
        if (el.manual && W.length && Math.abs(sumV - num(el.efs_value)) > 0.005) err.push(s.description + ': the values typed add up to ' + f(sumV) + ', not the line\'s ' + f(el.efs_value));
        h += '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:8px 0 6px 28px"><label class="xlabel" style="margin:0;max-width:220px">EFS value of this line (' + CUR + ')<input class="xin num" data-efs="' + s.id + '" value="' + esc(el.efs_value) + '"></label>'
          + '<span class="imp-small imp-muted" style="max-width:340px">' + (num(el.efs_value) < s.amount ? 'Part of the line: ' + CUR + ' ' + f(s.amount - num(el.efs_value)) + ' is not counted.' : 'The whole line is counted.') + '</span>'
          + '<label class="tog" style="padding:0;font-size:12px"><input type="checkbox" data-manual="' + s.id + '"' + (el.manual ? ' checked' : '') + '> type the value per import line</label></div>'
          + '<div class="xwrap" style="margin-left:28px"><table class="xtable" style="min-width:820px"><thead><tr><th>Import GD · line used <span class="imp-small">(oldest first)</span></th><th class="num" style="width:110px">kg used</th><th class="num" style="width:140px">Assessed value of this kg</th><th class="num" style="width:140px">Value counted</th><th class="num" style="width:110px">Check</th><th></th></tr></thead><tbody>';
        (el.uses || []).forEach(function (u, ui) {
          var x = W.filter(function (w) { return w.u === u; })[0], o = OB[u.key];
          h += '<tr><td><select class="xin" data-useline="' + s.id + ':' + ui + '" style="margin:0"><option value="">— pick the import line —</option>' + OBL.map(function (ob) { return oblOption(ob, ob.key === u.key); }).join('') + '</select></td>'
            + '<td><input class="xin num" data-usekg="' + s.id + ':' + ui + '" value="' + (num(u.kg) ? esc(u.kg) : '') + '" placeholder="0" style="margin:0"></td>'
            + '<td class="num">' + (x ? o.cur + ' ' + f(x.fl) + (o.va ? '<div class="imp-small imp-muted">incl. +' + o.va + '% value add</div>' : '') : '—') + '</td>'
            + '<td class="num">' + (el.manual ? '<input class="xin num" data-useval="' + s.id + ':' + ui + '" value="' + (num(u.value) ? esc(u.value) : '') + '" placeholder="0.00" style="margin:0">' : (x ? CUR + ' ' + f(x.ve) : '—')) + (x && o.cur !== CUR ? '<div class="imp-small imp-muted">= ' + o.cur + ' ' + f(x.vt) + '</div>' : '') + '</td>'
            + '<td class="num">' + (x ? diffTxt(x.diff) : '') + '</td><td><button type="button" class="xbtn red sm" data-usedel="' + s.id + ':' + ui + '">✕</button></td></tr>';
          if (o && o.cur !== CUR) need[o.cur] = true;
        });
        h += '</tbody><tfoot><tr><td><button type="button" class="xbtn sec sm" data-useadd="' + s.id + '">+ import line</button></td><td class="num">' + q(W.reduce(function (a, x) { return a + x.kg; }, 0)) + ' kg</td><td class="num">' + f(fl) + '</td><td class="num">' + CUR + ' ' + f(sumV) + '</td><td class="num">' + (fl ? diffTxt(vt - fl) : '') + '</td><td></td></tr></tfoot></table></div>'
          + (fl && vt < fl - 0.005 ? '<div class="imp-small" style="margin:6px 0 0 28px;color:#b8283f">The value counted for this line is ' + f(fl - vt) + ' below the assessed value of the import material in it. Raise its EFS value, check the kg — or give the reason in the summary.</div>' : '');
      }
      h += '</div>';
    });
    document.getElementById('lines').innerHTML = h;
    /* exchange rates, only when an import's currency differs from the shipment's */
    var rh = Object.keys(need).map(function (c) { if (!(num(F.rates[c]) > 0)) err.push('type the exchange rate from the export GD: 1 ' + CUR + ' = how many ' + c);
      return '<label class="xlabel" style="max-width:260px">1 ' + CUR + ' = ' + c + ' (export GD rate)<input class="xin num" data-rate="' + c + '" value="' + esc(F.rates[c] || '') + '"></label>'; }).join('');
    document.getElementById('rates').innerHTML = rh ? '<div class="xgrid" style="margin-top:10px">' + rh + '</div>' : '';
    /* the summary per import line */
    var per = {}, kgT = 0, countedT = 0, below = 0;
    F.lines.forEach(function (el) { countedT += num(el.efs_value); work(el).forEach(function (x) { var p = per[x.o.key] = per[x.o.key] || { o: x.o, kg: 0, vt: 0, fl: 0 }; p.kg += x.kg; p.vt += x.vt; p.fl += x.fl; kgT += x.kg; if (x.diff < -0.005) below += -x.diff; }); });
    var sh = '<div class="xwrap"><table class="xtable" style="min-width:880px"><thead><tr><th>Import GD · line</th><th class="num">Can still take</th><th class="num">kg this shipment</th><th class="num">Assessed value of this kg</th><th class="num">Value counted</th><th class="num">Check</th><th>Deadline</th></tr></thead><tbody>';
    Object.keys(per).forEach(function (k) {
      var p = per[k], o = p.o, over = p.kg > o.max + 0.0005;
      if (over) err.push((o.gd || '—') + ' (' + o.ref + '): ' + q(p.kg) + ' kg is more than the ' + q(o.max) + ' kg it has left');
      if (o.kind === 'line' && o.rcv !== null && o.done + p.kg > o.rcv + 0.0005) warn.push((o.gd || '—') + ' (' + o.ref + '): ' + q(o.done + p.kg) + ' kg exported against ' + q(o.rcv) + ' kg received at Gate In');
      OBL.forEach(function (o2) { if (o2.key !== o.key && o2.hs && o2.hs === o.hs && o2.max > 0.0005 && !per[o2.key] && o2.dsort < o.dsort && notes.indexOf(o2.key) < 0) notes.push(o2.key); });
      sh += '<tr' + (over || p.vt < p.fl - 0.005 ? ' style="background:rgba(224,67,93,.06)"' : '') + '><td><b>' + esc(o.gd || '—') + '</b><div class="efs-src">' + esc(o.ref) + '</div></td><td class="num">' + q(o.max) + ' kg</td><td class="num"><b>' + q(p.kg) + '</b></td><td class="num">' + o.cur + ' ' + f(p.fl) + '</td><td class="num">' + o.cur + ' ' + f(p.vt) + '</td><td class="num">' + diffTxt(p.vt - p.fl) + '</td><td><span class="dl ' + o.pill[0] + '">' + esc(o.pill[1]) + '</span></td></tr>';
    });
    sh += (Object.keys(per).length ? '' : '<tr><td colspan="7" class="imp-muted">Tick an export line and add the import lines it used.</td></tr>')
      + '</tbody><tfoot><tr><td colspan="2">This shipment</td><td class="num">' + q(kgT) + ' kg</td><td colspan="2" class="num">EFS value counted ' + CUR + ' ' + f(countedT) + '</td><td colspan="2"></td></tr></tfoot></table></div>';
    var gdKg = num(F.gd_net_kg), gdFob = num(F.gd_fob);
    if (gdKg > 0 && Math.abs(kgT - gdKg) > 0.0005) sh += '<div class="xnote" style="margin-top:10px">Allocated ' + q(kgT) + ' kg of import material; the export GD net weight is ' + q(gdKg) + ' kg (' + (kgT > gdKg ? '+' : '') + q(kgT - gdKg) + ' kg). Shown, not blocked.</div>';
    if (gdFob > 0 && countedT > gdFob + 0.005) sh += '<div class="xnote" style="margin-top:10px">EFS value counted ' + f(countedT) + ' is more than the export GD FOB ' + f(gdFob) + '.</div>';
    notes.forEach(function (k) { var o2 = OB[k]; sh += '<div class="xwarn" style="margin-top:10px">Older pending for HS ' + esc(o2.hs) + ': <b>' + esc(o2.gd || '—') + '</b> (' + esc(o2.ref) + ') still has ' + q(o2.max) + ' kg, due ' + esc(o2.deadline) + ' — use it first?</div>'; });
    document.getElementById('summary').innerHTML = sh;
    /* the reasons, asked only when needed and always highlighted */
    var rs = '';
    if (below > 0.005) { rs += '<div class="xwarn" style="margin-top:10px"><b>' + f(below) + ' below the assessed value</b> of the import material in this shipment. Customs expects the value counted to be the same or a little more. Correct the figures, or say why:<input class="xin" id="belowWhy" value="' + esc(F.below_reason || '') + '" placeholder="e.g. assessed value under appeal, ref …" style="margin-top:6px"></div>';
      if (!String(F.below_reason || '').trim()) err.push('the value is below the assessed value — correct it or give the reason'); }
    if (warn.length) { rs += '<div class="xwarn" style="margin-top:10px"><b>More kg than received at Gate In:</b> ' + warn.map(esc).join('; ') + '. Say why:<input class="xin" id="warnWhy" value="' + esc(F.warn_reason || '') + '" placeholder="e.g. received before go-live, weighed at supplier scale" style="margin-top:6px"></div>';
      if (!String(F.warn_reason || '').trim()) err.push('more kg than received — give the reason'); }
    if (!F.lines.length) err.push('tick at least one export line that used EFS material');
    rs += err.length ? '<div class="xbad" style="margin-top:10px">Cannot save yet: ' + err.map(esc).join('; ') + '.</div>' : '';
    document.getElementById('reasons').innerHTML = rs;
    document.getElementById('saveBtn').disabled = err.length > 0;
  }
  function el2(key) { var p = key.split(':'); return [lineOf(+p[0]), +p[1]]; }
  var root = document.getElementById('aForm');
  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-useadd],[data-usedel]'); if (!t) return;
    if (t.dataset.useadd) lineOf(+t.dataset.useadd).uses.push({ key: '', kg: 0, value: 0 });
    else { var x = el2(t.dataset.usedel); x[0].uses.splice(x[1], 1); if (!x[0].uses.length) x[0].uses.push({ key: '', kg: 0, value: 0 }); }
    draw();
  });
  root.addEventListener('input', function (e) {
    var t = e.target, x;
    if (t.id === 'gdNo') F.export_gd_no = t.value; if (t.id === 'gdDate') F.export_gd_date = t.value;
    if (t.id === 'gdKg') F.gd_net_kg = t.value; if (t.id === 'gdFob') F.gd_fob = t.value;
    if (t.id === 'belowWhy') { F.below_reason = t.value; return; } if (t.id === 'warnWhy') { F.warn_reason = t.value; return; }
    if (t.dataset.rate) F.rates[t.dataset.rate] = t.value;
    if (t.dataset.efs) lineOf(+t.dataset.efs).efs_value = t.value;
    if (t.dataset.usekg) { x = el2(t.dataset.usekg); x[0].uses[x[1]].kg = t.value; }
    if (t.dataset.useval) { x = el2(t.dataset.useval); x[0].uses[x[1]].value = t.value; }
  });
  root.addEventListener('change', function (e) {
    var t = e.target, x;
    if (t.dataset.pick) { var id = +t.dataset.pick;
      if (t.checked) F.lines.push({ src_id: id, efs_value: srcOf(id).amount, manual: false, uses: [{ key: '', kg: 0, value: 0 }] });
      else F.lines = F.lines.filter(function (el) { return +el.src_id !== id; }); }
    if (t.dataset.manual) { var el = lineOf(+t.dataset.manual), W = work(el); el.manual = t.checked;
      if (el.manual) W.forEach(function (w) { w.u.value = w.ve; }); }   /* typed values start from the split */
    if (t.dataset.useline) { x = el2(t.dataset.useline); x[0].uses[x[1]].key = t.value; }
    /* redraw once the browser has moved the cursor, then put it back */
    setTimeout(function () {
      var a = document.activeElement, key = null;
      if (a && a.dataset) ['efs', 'usekg', 'useval', 'useline', 'rate'].forEach(function (k) { if (a.dataset[k] !== undefined) key = '[data-' + k + '="' + a.dataset[k] + '"]'; });
      if (!key && a && a.id) key = '#' + a.id;
      draw(); var n = key ? document.querySelector(key) : null; if (n) n.focus();
    }, 0);
  });
  root.addEventListener('submit', function () {
    var clean = { rates: F.rates, export_gd_no: F.export_gd_no || '', export_gd_date: F.export_gd_date || '', gd_net_kg: F.gd_net_kg || '', gd_fob: F.gd_fob || '',
      below_reason: F.below_reason || '', warn_reason: F.warn_reason || '',
      lines: F.lines.map(function (el) { return { src_id: +el.src_id, efs_value: num(el.efs_value), manual: !!el.manual, uses: el.uses.filter(function (u) { return u.key && num(u.kg) > 0; }).map(function (u) { return { key: u.key, kg: num(u.kg), value: num(u.value) }; }) }; }) };
    document.getElementById('payload').value = JSON.stringify(clean);
  });
  draw();
})();
</script>
<?php endif; ?>
<?php page_footer(); ?>
