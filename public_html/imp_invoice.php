<?php
/*
  IMPORT — SUPPLIER COMMERCIAL INVOICE TAB (view A of the two).

  The invoice lines: product category with its own (optional) specification,
  PCT/HS, the unit it is BILLED in (kg, m, yd, pcs …), quantity, rate, and
  ALWAYS the net kg, whatever it is billed in — stock and EFS count in kg.
  Totals are one per unit: kg and metres are never added together.

  Each line keeps its id when saved (updated in place), because packing now,
  and customs lines, receipts and EFS later, point at it. A line with packing
  rows cannot be removed until those rows are.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];
$invoices = imp_invoices($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        imp_require('imp', 'u');
        $ivIds = array_map(fn($r) => (int)$r['id'], $invoices);
        $old = [];
        foreach (imp_lines($id) as $l) $old[(int)$l['id']] = $l;
        $mats = [];
        if (function_exists('inv_materials')) foreach (inv_materials(false) as $mt) $mats[(int)$mt['id']] = true;

        $rows = [];
        foreach ((array)($_POST['line_id'] ?? []) as $i => $lid) {
            $g = static fn(string $k) => $_POST[$k][$i] ?? '';
            $desc = mb_substr(trim((string)$g('description')), 0, 300);
            $qty = imp_num($g('bill_qty')); $rate = imp_num($g('rate'));
            $net = imp_num($g('net_kg')); $gross = imp_num($g('gross_kg')); $pk = (int)imp_num($g('packages'));
            if ($desc === '' && $qty == 0 && $rate == 0 && $net == 0) continue;     /* an empty row */
            if ($desc === '') throw new Exception('Line ' . (count($rows) + 1) . ': describe the product.');
            $cat = (string)$g('category'); if (!in_array($cat, IMP_CATEGORIES, true)) $cat = 'Other';
            $unit = (string)$g('bill_unit'); if (!in_array($unit, IMP_UNITS, true)) throw new Exception('Line ' . (count($rows) + 1) . ': pick the billing unit.');
            foreach (['quantity' => $qty, 'rate' => $rate, 'net kg' => $net, 'gross kg' => $gross, 'packages' => $pk] as $lbl => $v)
                if ($v < 0) throw new Exception('Line ' . (count($rows) + 1) . ': ' . $lbl . ' cannot be negative.');
            if ($unit === 'kg' && $net == 0) $net = $qty;                            /* billed in kg: the net kg is the same, unless typed */
            if ($gross > 0 && $net > $gross) throw new Exception('Line ' . (count($rows) + 1) . ': net kg cannot be more than gross kg.');
            $spec = json_decode((string)$g('specs_json'), true);
            $spec = is_array($spec) ? array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 120), $spec), fn($v) => $v !== '') : [];
            $iv = (int)$g('invoice_id'); if (!in_array($iv, $ivIds, true)) $iv = $ivIds[0] ?? 0;
            $mat = (int)$g('material_id'); if (!isset($mats[$mat])) $mat = 0;
            $rows[] = ['id' => (int)$lid, 'invoice_id' => $iv ?: null, 'category' => $cat, 'material_id' => $mat ?: null, 'description' => $desc,
                       'hs_code' => mb_substr(trim((string)$g('hs_code')), 0, 20) ?: null, 'supplier_ref' => mb_substr(trim((string)$g('supplier_ref')), 0, 80) ?: null,
                       'bill_unit' => $unit, 'bill_qty' => $qty, 'rate' => $rate, 'net_kg' => $net, 'gross_kg' => $gross, 'packages' => $pk,
                       'specs' => $spec ? json_encode($spec, JSON_UNESCAPED_UNICODE) : null];
        }

        db()->beginTransaction();
        $keep = [];
        foreach ($rows as $n => $r) {
            $cols = ['invoice_id', 'category', 'material_id', 'description', 'hs_code', 'supplier_ref', 'bill_unit', 'bill_qty', 'rate', 'net_kg', 'gross_kg', 'packages', 'specs'];
            $vals = array_map(fn($c) => $r[$c], $cols);
            if ($r['id'] > 0 && isset($old[$r['id']])) {
                db()->prepare("UPDATE imp_lines SET sort_order=?, " . implode(', ', array_map(fn($c) => "$c=?", $cols)) . " WHERE id=? AND import_id=?")
                    ->execute(array_merge([$n + 1], $vals, [$r['id'], $id]));
                $keep[] = $r['id'];
            } else {
                db()->prepare("INSERT INTO imp_lines (import_id, sort_order, " . implode(', ', $cols) . ") VALUES (?,?," . implode(',', array_fill(0, count($cols), '?')) . ")")
                    ->execute(array_merge([$id, $n + 1], $vals));
                $keep[] = (int)db()->lastInsertId();
            }
        }
        $removed = [];
        foreach ($old as $lid => $l) {
            if (in_array($lid, $keep, true)) continue;
            $c = db()->prepare("SELECT COUNT(*) FROM imp_packing WHERE import_id=? AND line_id=?"); $c->execute([$id, $lid]);
            if ((int)$c->fetchColumn() > 0) throw new Exception('"' . $l['description'] . '" has packing rows. Remove them on PACKING first, then the line.');
            db()->prepare("DELETE FROM imp_lines WHERE id=? AND import_id=?")->execute([$lid, $id]);
            $removed[] = $l['description'];
        }
        $t = imp_totals(imp_lines($id));
        imp_log($id, 'Invoice lines saved', count($rows) . ' line' . (count($rows) === 1 ? '' : 's') . ' · ' . money_fmt($t['value'], $imp['currency'])
                . ' · ' . number_format($t['kg'], 3) . ' kg net' . ($removed ? ' · removed: ' . implode(', ', $removed) : ''));
        db()->commit();
        $_SESSION['flash'] = 'Invoice saved — ' . count($rows) . ' line' . (count($rows) === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_invoice.php?id=' . $id);
}

$lines = imp_lines($id);
$t = imp_totals($lines);
$canEdit = imp_can('imp', 'u');
$mats = function_exists('inv_materials') ? inv_materials(true) : [];
$blank = ['id' => 0, 'invoice_id' => $invoices[0]['id'] ?? 0, 'category' => 'Fabric', 'material_id' => null, 'description' => '', 'hs_code' => '', 'supplier_ref' => '',
          'bill_unit' => 'kg', 'bill_qty' => '', 'rate' => '', 'net_kg' => '', 'gross_kg' => '', 'packages' => '', 'specs' => null];
if (!$lines) $lines = [$blank];
$num = static fn($v) => ($v === '' || $v === null) ? '' : rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');

page_header('Invoice — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'invoice');
?>
<form method="post" id="lnForm">
<?= csrf_field() ?>
<input type="hidden" name="import_id" value="<?= $id ?>">
<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Supplier commercial invoice<?= !empty($invoices[0]['invoice_no']) ? ' · ' . e($invoices[0]['invoice_no']) : '' ?></h2>
    <?php if ($canEdit): ?><button type="button" class="xbtn sec sm" id="pasteBtn">Paste from Excel…</button><?php endif; ?>
  </div>
  <div id="pasteBox" style="display:none;margin-top:10px">
    <textarea class="xin" id="pasteTxt" rows="4" style="font-family:'IBM Plex Mono',monospace" placeholder="Columns: Description · PCT/HS · Billed in (kg/m/yd…) · Qty · Rate · Net kg · Gross kg · Packages · Category"></textarea>
    <button type="button" class="xbtn sm" style="margin-top:6px" id="pasteGo">Add pasted rows</button>
  </div>
  <div class="xwrap" style="margin-top:10px">
    <table class="xtable" id="lnTable" style="min-width:1360px">
      <thead><tr><th style="width:112px">Category</th><th style="min-width:250px">Description</th><th style="width:118px">PCT / HS</th><th style="width:80px">Billed in</th><th class="num" style="width:100px">Billed qty</th><th class="num" style="width:92px">Rate / unit</th><th class="num" style="width:110px">Amount</th><th class="num" style="width:96px">Net kg</th><th class="num" style="width:96px">Gross kg</th><th class="num" style="width:70px">Pkgs</th><th class="num" style="width:80px">Per kg</th><?= count($invoices) > 1 ? '<th style="width:110px">Invoice</th>' : '' ?><th style="width:40px"></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr class="ln">
          <td><input type="hidden" name="line_id[]" value="<?= (int)$l['id'] ?>"><input type="hidden" name="specs_json[]" class="specs" value="<?= e($l['specs'] ?? '') ?>">
            <select class="xin cat" name="category[]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach (IMP_CATEGORIES as $c): ?><option<?= $c === $l['category'] ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></td>
          <td><input class="xin" name="description[]" value="<?= e($l['description']) ?>"<?= $canEdit ? '' : ' readonly' ?>><a href="#" class="imp-small spec-tog">▸ specification</a></td>
          <td><input class="xin" name="hs_code[]" value="<?= e($l['hs_code']) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td><select class="xin unit" name="bill_unit[]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach (IMP_UNITS as $u): ?><option<?= $u === $l['bill_unit'] ? ' selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></td>
          <td><input class="xin num q" name="bill_qty[]" value="<?= e($num($l['bill_qty'])) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td><input class="xin num r" name="rate[]" value="<?= e($num($l['rate'])) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td class="num calc amt"></td>
          <td><input class="xin num nk" name="net_kg[]" value="<?= e($num($l['net_kg'])) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td><input class="xin num gk" name="gross_kg[]" value="<?= e($num($l['gross_kg'])) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td><input class="xin num pk" name="packages[]" value="<?= e($num($l['packages'])) ?>"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td class="num perkg"></td>
          <?php if (count($invoices) > 1): ?><td><select class="xin" name="invoice_id[]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach ($invoices as $n => $iv): ?><option value="<?= (int)$iv['id'] ?>"<?= (int)$l['invoice_id'] === (int)$iv['id'] ? ' selected' : '' ?>><?= e($iv['invoice_no'] ?: 'Invoice ' . ($n + 1)) ?></option><?php endforeach; ?></select></td>
          <?php endif; ?>
          <td><?php if (count($invoices) <= 1): ?><input type="hidden" name="invoice_id[]" value="<?= (int)($invoices[0]['id'] ?? 0) ?>"><?php endif; ?><?php if ($canEdit): ?><button type="button" class="xbtn red sm del">✕</button><?php endif; ?></td>
        </tr>
        <tr class="sp" style="display:none"><td colspan="<?= count($invoices) > 1 ? 13 : 12 ?>" style="padding:0"><div class="spec">
          <label style="flex:2">Stock item (Item Master, for Gate In later)
            <select name="material_id[]"<?= $canEdit ? '' : ' disabled' ?>><option value="0">— not linked yet —</option><?php foreach ($mats as $mt): ?><option value="<?= (int)$mt['id'] ?>"<?= (int)$l['material_id'] === (int)$mt['id'] ? ' selected' : '' ?>><?= e($mt['code'] . ' · ' . $mt['name']) ?></option><?php endforeach; ?></select></label>
          <label>Supplier item ref<input name="supplier_ref[]" value="<?= e($l['supplier_ref']) ?>"<?= $canEdit ? '' : ' readonly' ?>></label>
          <span class="spec-fields" style="display:contents"></span>
        </div></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total</td><td class="num" id="tUnit"></td><td></td><td class="num" id="tVal"></td><td class="num" id="tKg"></td><td class="num" id="tGross"></td><td class="num" id="tPk"></td><td class="num" id="tPerKg"></td><td colspan="<?= count($invoices) > 1 ? 2 : 1 ?>"></td></tr></tfoot>
    </table>
  </div>
  <?php if ($canEdit): ?><div style="margin-top:10px;display:flex;gap:9px"><button type="button" class="xbtn sec sm" id="addLine">+ Add line</button><button class="xbtn">Save invoice</button></div><?php endif; ?>
  <div class="xnote" style="margin-top:12px">Different units are <b>never added together</b> — billed quantity shows one total per unit (e.g. "25,000 kg · 12,000 m"). <b>Net kg is always kept</b>, whatever a line is billed in: stock and EFS count in kg. A line billed in kg takes its net kg from the quantity unless you type another. All amounts are in <?= e($imp['currency']) ?>.</div>
</div>
</form>

<div class="xcard"><h2>Two invoice views</h2><div class="xgrid">
  <div class="xnote"><b>A. Supplier commercial invoice</b> (this tab) — what the supplier billed. Payments are checked against this.</div>
  <div class="xnote"><b>B. Customs / GD working invoice</b> (CUSTOMS tab, Phase 2) — copied from these lines, then edited or grouped for the GD. Each customs line remembers which invoice lines it came from; supplier value, declared value, assessed value and landed cost stay four separate figures.</div>
</div></div>

<script>
(function () {
  var SPECS = <?= json_encode(IMP_SPECS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>, CUR = <?= json_encode($imp['currency']) ?>;
  var EDIT = <?= $canEdit ? 'true' : 'false' ?>, tb = document.querySelector('#lnTable tbody');
  var n = function (v) { var x = parseFloat(String(v || '').replace(/,/g, '')); return isFinite(x) ? x : 0; };
  var f = function (v, d) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d || 0, maximumFractionDigits: d == null ? 3 : d }); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  /* each row's specification, kept per category so switching category and
     back does not lose what was typed */
  function specOf(tr) { try { return JSON.parse(tr.querySelector('.specs').value || '{}') || {}; } catch (e) { return {}; } }
  function drawSpec(tr) {
    var sp = tr.nextElementSibling, box = sp.querySelector('.spec-fields'), cat = tr.querySelector('.cat').value, v = specOf(tr);
    box.innerHTML = (SPECS[cat] || []).map(function (s) {
      var val = v[s[0]] || '';
      return '<label>' + esc(s[1]) + (s[2] ? '<select data-sk="' + s[0] + '"' + (EDIT ? '' : ' disabled') + '>' + s[2].map(function (o) { return '<option' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>'
        : '<input data-sk="' + s[0] + '" value="' + esc(val) + '"' + (EDIT ? '' : ' readonly') + '>') + '</label>';
    }).join('') + '<label style="flex:2">Test report / supplier spec<span class="imp-small imp-muted">upload on DOCS — it can be linked to this line</span></label>';
  }
  function keepSpec(tr) {
    var v = specOf(tr); tr.nextElementSibling.querySelectorAll('[data-sk]').forEach(function (el) { v[el.dataset.sk] = el.value; });
    var cat = tr.querySelector('.cat').value, keys = (SPECS[cat] || []).map(function (s) { return s[0]; }), out = {};
    keys.forEach(function (k) { if (v[k]) out[k] = v[k]; });
    tr.querySelector('.specs').value = Object.keys(out).length ? JSON.stringify(out) : '';
  }
  function calc() {
    var byU = {}, val = 0, kg = 0, gr = 0, pk = 0;
    tb.querySelectorAll('tr.ln').forEach(function (tr) {
      var q = n(tr.querySelector('.q').value), r = n(tr.querySelector('.r').value), a = Math.round(q * r * 100) / 100, u = tr.querySelector('.unit').value;
      var nk = n(tr.querySelector('.nk').value); if (u === 'kg' && !tr.querySelector('.nk').value.trim()) nk = q;
      tr.querySelector('.amt').textContent = f(a, 2);
      tr.querySelector('.perkg').textContent = nk ? f(a / nk, 3) : '—';
      if (q) byU[u] = (byU[u] || 0) + q;
      val += a; kg += nk; gr += n(tr.querySelector('.gk').value); pk += n(tr.querySelector('.pk').value);
    });
    document.getElementById('tUnit').textContent = Object.keys(byU).map(function (u) { return f(byU[u]) + ' ' + u; }).join(' · ');
    document.getElementById('tVal').textContent = CUR + ' ' + f(val, 2);
    document.getElementById('tKg').textContent = f(kg); document.getElementById('tGross').textContent = f(gr); document.getElementById('tPk').textContent = f(pk);
    document.getElementById('tPerKg').textContent = kg ? f(val / kg, 3) : '—';
  }
  function wire(tr) { drawSpec(tr); }
  tb.querySelectorAll('tr.ln').forEach(wire);
  tb.addEventListener('input', function (e) { var tr = e.target.closest('tr'); if (tr && tr.classList.contains('sp')) keepSpec(tr.previousElementSibling); calc(); });
  tb.addEventListener('change', function (e) {
    var tr = e.target.closest('tr');
    if (e.target.classList.contains('cat')) { drawSpec(tr); tr.nextElementSibling.style.display = ''; tr.querySelector('.spec-tog').textContent = '▾ specification'; }
    if (tr && tr.classList.contains('sp')) keepSpec(tr.previousElementSibling);
    calc();
  });
  tb.addEventListener('click', function (e) {
    var t = e.target;
    if (t.classList.contains('spec-tog')) { e.preventDefault(); var sp = t.closest('tr').nextElementSibling, open = sp.style.display === 'none'; sp.style.display = open ? '' : 'none'; t.textContent = (open ? '▾' : '▸') + ' specification'; }
    if (t.classList.contains('del')) { var tr = t.closest('tr'); if (tb.querySelectorAll('tr.ln').length > 1) { tr.nextElementSibling.remove(); tr.remove(); } else { tr.querySelectorAll('input:not([type=hidden])').forEach(function (i) { i.value = ''; }); } calc(); }
  });
  /* the template for a new line, copied once while the page loads */
  var protoRow = tb.querySelector('tr.ln').cloneNode(true), protoSp = tb.querySelector('tr.sp').cloneNode(true);
  function newRow(vals) {
    var a = protoRow.cloneNode(true), b = protoSp.cloneNode(true);
    a.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'invoice_id[]' ? i.value : ''; });
    a.querySelector('input[name="line_id[]"]').value = '0'; a.querySelector('.specs').value = '';
    b.querySelectorAll('input').forEach(function (i) { i.value = ''; }); b.querySelector('select[name="material_id[]"]').value = '0';
    vals = vals || {};
    if (vals.cat) a.querySelector('.cat').value = vals.cat;
    a.querySelector('.unit').value = vals.unit || 'kg';
    [['description', vals.desc], ['hs_code', vals.hs], ['bill_qty', vals.qty], ['rate', vals.rate], ['net_kg', vals.net], ['gross_kg', vals.gross], ['packages', vals.pkgs]].forEach(function (p) { if (p[1] != null) a.querySelector('[name="' + p[0] + '[]"]').value = p[1]; });
    b.style.display = 'none'; tb.appendChild(a); tb.appendChild(b); drawSpec(a); return a;
  }
  var add = document.getElementById('addLine');
  if (add) add.addEventListener('click', function () { newRow({ cat: 'Fabric' }).querySelector('[name="description[]"]').focus(); calc(); });
  var pb = document.getElementById('pasteBtn');
  if (pb) pb.addEventListener('click', function () { var b = document.getElementById('pasteBox'); b.style.display = b.style.display === 'none' ? '' : 'none'; document.getElementById('pasteTxt').focus(); });
  var pg = document.getElementById('pasteGo');
  if (pg) pg.addEventListener('click', function () {
    var added = 0, units = <?= json_encode(IMP_UNITS) ?>, cats = <?= json_encode(IMP_CATEGORIES) ?>;
    document.getElementById('pasteTxt').value.split(/\r?\n/).forEach(function (line) {
      var c = line.split('\t'); if (c.length < 4 || !c[0].trim() || /^desc/i.test(c[0].trim())) return;
      var u = (c[2] || 'kg').trim().toLowerCase(); if (units.indexOf(u) < 0) u = 'kg';
      var cat = (c[8] || '').trim(); cat = cats.filter(function (x) { return x.toLowerCase() === cat.toLowerCase(); })[0] || 'Other';
      /* the empty starting row is filled first */
      var first = tb.querySelector('tr.ln'); var blank = tb.querySelectorAll('tr.ln').length === 1 && !first.querySelector('[name="description[]"]').value;
      if (blank) { first.nextElementSibling.remove(); first.remove(); }
      newRow({ desc: c[0].trim(), hs: (c[1] || '').trim(), unit: u, qty: n(c[3]) || '', rate: n(c[4]) || '', net: n(c[5]) || '', gross: n(c[6]) || '', pkgs: n(c[7]) || '', cat: cat }); added++;
    });
    document.getElementById('pasteTxt').value = ''; document.getElementById('pasteBox').style.display = 'none'; calc();
    alert(added + ' line' + (added === 1 ? '' : 's') + ' added — check them, then Save invoice.');
  });
  calc();
})();
</script>
<?php page_footer(); ?>
