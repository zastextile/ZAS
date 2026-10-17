<?php
/*
  IMPORT — SUPPLIER COMMERCIAL INVOICE TAB (view A of the two).

  The invoice lines: product category with its own (optional) specification,
  PCT/HS, the unit it is BILLED in (kg, m, yd, pcs …), quantity, rate, and
  ALWAYS the net kg, whatever it is billed in — stock and EFS count in kg.
  Totals are one per unit: kg and metres are never added together.

  Each line keeps its id when saved (updated in place), because packing,
  customs lines, receipts and EFS point at it. A line with packing rows, a GD
  line or a receipt cannot be removed until those are.

  PHASE 2: each line links to an Item Master material or a Product Master
  product (the same two lists Gate Inward offers), and shows what goes into
  stock in that item's own unit, kg in brackets: "12,000 MTR (3,600 kg)".
  "New item in Item Master" saves this invoice first, opens the existing New
  Item page filled from the line, and comes back here with the item linked.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];
$invoices = imp_invoices($id);

/* Back from Item Master → New Item. The line it was made for was stored in
   this session when the button was pressed (a link alone can change
   nothing), and only an item created since then is accepted. */
if (isset($_GET['new_item'])) {
    $pend = $_SESSION['imp_new_item'] ?? null;
    unset($_SESSION['imp_new_item']);
    $mid = (int)$_GET['new_item'];
    if ($pend && (int)$pend['import_id'] === $id && time() - (int)$pend['t'] < 7200 && $mid > (int)$pend['after_id'] && imp_can('imp', 'u')) {
        $m = db()->prepare("SELECT id, code, name FROM inv_materials WHERE id=?"); $m->execute([$mid]); $m = $m->fetch();
        if ($m) {
            db()->prepare("UPDATE imp_lines SET material_id=?, product_id=NULL, size_label=NULL WHERE id=? AND import_id=?")->execute([$mid, (int)$pend['line_id'], $id]);
            imp_log($id, 'Item linked', $m['code'] . ' · ' . $m['name'] . ' created in Item Master and linked to a line');
            $_SESSION['flash'] = 'Item ' . $m['code'] . ' created and linked to the line.';
        }
    }
    redirect('imp_invoice.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        imp_require('imp', 'u');
        $ivIds = array_map(fn($r) => (int)$r['id'], $invoices);
        $old = [];
        foreach (imp_lines($id) as $l) $old[(int)$l['id']] = $l;
        $items = imp_item_options(false);

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
            $key = (string)$g('item_key'); if (!isset($items[$key])) $key = '';
            $isProd = $key !== '' && $items[$key]['kind'] === 'prod';
            $sq = trim((string)$g('stock_qty')) === '' ? null : imp_num($g('stock_qty'));
            if ($sq !== null && $sq < 0) throw new Exception('Line ' . (count($rows) + 1) . ': the quantity into stock cannot be negative.');
            $rows[] = ['id' => (int)$lid, 'invoice_id' => $iv ?: null, 'category' => $cat, 'description' => $desc,
                       'material_id' => $key !== '' && !$isProd ? $items[$key]['id'] : null, 'product_id' => $isProd ? $items[$key]['id'] : null,
                       'size_label' => $isProd ? (mb_substr(trim((string)$g('size_label')), 0, 80) ?: null) : null, 'stock_qty' => $sq ?: null, 'form_index' => $i,
                       'hs_code' => mb_substr(trim((string)$g('hs_code')), 0, 20) ?: null, 'supplier_ref' => mb_substr(trim((string)$g('supplier_ref')), 0, 80) ?: null,
                       'bill_unit' => $unit, 'bill_qty' => $qty, 'rate' => $rate, 'net_kg' => $net, 'gross_kg' => $gross, 'packages' => $pk,
                       'specs' => $spec ? json_encode($spec, JSON_UNESCAPED_UNICODE) : null];
        }

        db()->beginTransaction();
        $keep = []; $idAt = [];
        foreach ($rows as $n => $r) {
            $cols = ['invoice_id', 'category', 'material_id', 'product_id', 'size_label', 'stock_qty', 'description', 'hs_code', 'supplier_ref', 'bill_unit', 'bill_qty', 'rate', 'net_kg', 'gross_kg', 'packages', 'specs'];
            $vals = array_map(fn($c) => $r[$c], $cols);
            if ($r['id'] > 0 && isset($old[$r['id']])) {
                db()->prepare("UPDATE imp_lines SET sort_order=?, " . implode(', ', array_map(fn($c) => "$c=?", $cols)) . " WHERE id=? AND import_id=?")
                    ->execute(array_merge([$n + 1], $vals, [$r['id'], $id]));
                $keep[] = $r['id'];
                $idAt[$r['form_index']] = $r['id'];
            } else {
                db()->prepare("INSERT INTO imp_lines (import_id, sort_order, " . implode(', ', $cols) . ") VALUES (?,?," . implode(',', array_fill(0, count($cols), '?')) . ")")
                    ->execute(array_merge([$id, $n + 1], $vals));
                $keep[] = (int)db()->lastInsertId();
                $idAt[$r['form_index']] = end($keep);
            }
        }
        $removed = [];
        foreach ($old as $lid => $l) {
            if (in_array($lid, $keep, true)) continue;
            $c = db()->prepare("SELECT COUNT(*) FROM imp_packing WHERE import_id=? AND line_id=?"); $c->execute([$id, $lid]);
            if ((int)$c->fetchColumn() > 0) throw new Exception('"' . $l['description'] . '" has packing rows. Remove them on PACKING first, then the line.');
            if (!empty($l['gd_line_id'])) throw new Exception('"' . $l['description'] . '" is on a GD line. Take it off on CUSTOMS first, then the line.');
            $c = db()->prepare("SELECT COUNT(*) FROM inv_gate_items gi JOIN inv_gate g ON g.id=gi.gate_id WHERE gi.imp_line_id=? AND g.status<>'reversed'"); $c->execute([$lid]);
            if ((int)$c->fetchColumn() > 0) throw new Exception('"' . $l['description'] . '" is on a Gate Inward pass. A received line stays on the invoice.');
            db()->prepare("DELETE FROM imp_lines WHERE id=? AND import_id=?")->execute([$lid, $id]);
            $removed[] = $l['description'];
        }
        $t = imp_totals(imp_lines($id));
        imp_log($id, 'Invoice lines saved', count($rows) . ' line' . (count($rows) === 1 ? '' : 's') . ' · ' . money_fmt($t['value'], $imp['currency'])
                . ' · ' . number_format($t['kg'], 3) . ' kg net' . ($removed ? ' · removed: ' . implode(', ', $removed) : ''));
        db()->commit();
        $_SESSION['flash'] = 'Invoice saved — ' . count($rows) . ' line' . (count($rows) === 1 ? '' : 's') . '.';

        /* "New item in Item Master" was pressed on a line: the invoice is
           saved (above), so nothing typed is lost; now open the existing New
           Item page filled from that line, and come back here after Save. */
        if (isset($_POST['new_item_for']) && function_exists('inv_perm') && inv_perm('master')) {
            $fi = (int)$_POST['new_item_for'];
            $lineId = $idAt[$fi] ?? 0;
            $src = null; foreach ($rows as $r) if ($r['form_index'] === $fi) $src = $r;
            if ($lineId && $src) {
                $maxId = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM inv_materials")->fetchColumn();
                $_SESSION['imp_new_item'] = ['import_id' => $id, 'line_id' => $lineId, 'after_id' => $maxId, 't' => time()];
                $uom = ['kg' => 'KG', 'm' => 'MTR', 'yd' => 'YDS', 'pcs' => 'PCS', 'set' => 'SET', 'roll' => 'ROLL', 'bale' => 'BALE', 'carton' => 'CTN'][$src['bill_unit']] ?? 'PCS';
                $grp = $src['category'] === 'Fabric' ? 'Fabric' : ($src['category'] === 'Accessories' ? 'Accessories' : 'Other');
                redirect('inv_items.php?' . http_build_query(['new' => 1, 'name' => $src['description'], 'uom' => $uom, 'group' => $grp,
                    'type' => in_array($src['category'], ['Fabric', 'Accessories', 'Other'], true) ? '' : $src['category'],
                    'return' => 'imp_invoice.php?id=' . $id]));
            }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_invoice.php?id=' . $id);
}

$lines = imp_lines($id);
$t = imp_totals($lines);
$canEdit = imp_can('imp', 'u');
$items = imp_item_options(false);
$canNewItem = $canEdit && function_exists('inv_perm') && inv_perm('master');
$blank = ['id' => 0, 'invoice_id' => $invoices[0]['id'] ?? 0, 'category' => 'Fabric', 'material_id' => null, 'product_id' => null, 'size_label' => null, 'stock_qty' => null,
          'description' => '', 'hs_code' => '', 'supplier_ref' => '', 'bill_unit' => 'kg', 'bill_qty' => '', 'rate' => '', 'net_kg' => '', 'gross_kg' => '', 'packages' => '', 'specs' => null];
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
    <table class="xtable" id="lnTable" style="min-width:1760px">
      <thead><tr><th style="width:112px">Category</th><th style="min-width:250px">Description</th><th style="width:118px">PCT / HS</th><th style="width:80px">Billed in</th><th class="num" style="width:100px">Billed qty</th><th class="num" style="width:92px">Rate / unit</th><th class="num" style="width:110px">Amount</th><th class="num" style="width:96px">Net kg</th><th class="num" style="width:96px">Gross kg</th><th class="num" style="width:70px">Pkgs</th><th class="num" style="width:80px">Per kg</th><th style="min-width:230px">Item (Item / Product Master)</th><th style="min-width:160px">Into stock <span class="imp-small">unit (kg)</span></th><?= count($invoices) > 1 ? '<th style="width:110px">Invoice</th>' : '' ?><th style="width:40px"></th></tr></thead>
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
          <td><input type="hidden" name="item_key[]" class="ikey" value="<?= e(imp_line_key($l)) ?>">
            <button type="button" class="ipick"<?= $canEdit ? '' : ' disabled' ?>></button>
            <input class="xin isize" name="size_label[]" value="<?= e($l['size_label'] ?? '') ?>" placeholder="size (finished goods)" style="display:none;margin-top:4px"<?= $canEdit ? '' : ' readonly' ?>></td>
          <td class="istock"><input class="xin num sq" name="stock_qty[]" value="<?= e($num($l['stock_qty'])) ?>" style="display:none"<?= $canEdit ? '' : ' readonly' ?>><div class="sqtxt"></div></td>
          <?php if (count($invoices) > 1): ?><td><select class="xin" name="invoice_id[]"<?= $canEdit ? '' : ' disabled' ?>><?php foreach ($invoices as $n => $iv): ?><option value="<?= (int)$iv['id'] ?>"<?= (int)$l['invoice_id'] === (int)$iv['id'] ? ' selected' : '' ?>><?= e($iv['invoice_no'] ?: 'Invoice ' . ($n + 1)) ?></option><?php endforeach; ?></select></td>
          <?php endif; ?>
          <td><?php if (count($invoices) <= 1): ?><input type="hidden" name="invoice_id[]" value="<?= (int)($invoices[0]['id'] ?? 0) ?>"><?php endif; ?><?php if ($canEdit): ?><button type="button" class="xbtn red sm del">✕</button><?php endif; ?></td>
        </tr>
        <tr class="sp" style="display:none"><td colspan="<?= count($invoices) > 1 ? 15 : 14 ?>" style="padding:0"><div class="spec">
          <label>Supplier item ref<input name="supplier_ref[]" value="<?= e($l['supplier_ref']) ?>"<?= $canEdit ? '' : ' readonly' ?>></label>
          <span class="spec-fields" style="display:contents"></span>
        </div></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total</td><td class="num" id="tUnit"></td><td></td><td class="num" id="tVal"></td><td class="num" id="tKg"></td><td class="num" id="tGross"></td><td class="num" id="tPk"></td><td class="num" id="tPerKg"></td><td colspan="<?= count($invoices) > 1 ? 4 : 3 ?>"></td></tr></tfoot>
    </table>
  </div>
  <?php if ($canEdit): ?><div style="margin-top:10px;display:flex;gap:9px"><button type="button" class="xbtn sec sm" id="addLine">+ Add line</button><button class="xbtn">Save invoice</button></div><?php endif; ?>
  <div class="xnote" style="margin-top:12px">Different units are <b>never added together</b> — billed quantity shows one total per unit (e.g. "25,000 kg · 12,000 m"). <b>Net kg is always kept</b>, whatever a line is billed in, because EFS counts in kg. A line billed in kg takes its net kg from the quantity unless you type another. All amounts are in <?= e($imp['currency']) ?>.<br>
    <b>Into stock</b> is in the linked item's own unit: billed in the same unit → the billed quantity; item kept in KG → the net kg; metres ↔ yards → converted (1 yd = 0.9144 m); anything else → type it once. The kg stays on the import for EFS and never enters the stock ledger.</div>
</div>
</form>

<div class="xcard"><h2>Two invoice views</h2><div class="xgrid">
  <div class="xnote"><b>A. Supplier commercial invoice</b> (this tab) — what the supplier billed. Payments are checked against this.</div>
  <div class="xnote"><b>B. Customs / GD working invoice</b> (<a href="imp_customs.php?id=<?= $id ?>">CUSTOMS</a> tab) — copied from these lines, then edited or grouped for the GD. Each customs line remembers which invoice lines it came from; supplier value, declared value, assessed value and landed cost stay four separate figures.</div>
</div></div>

<div id="ipBox" class="ipk-box" style="display:none">
  <div class="ipk-head"><input class="xin" id="ipQ" placeholder="Search code, name, type…" autocomplete="off" style="margin:0;flex:1;min-width:150px">
    <label class="tog" style="padding:0;font-size:12px"><input type="checkbox" id="ipCost"> only items used in costing</label></div>
  <div id="ipList" class="ipk-list"></div>
  <div class="ipk-foot"><button type="button" class="xbtn sec sm" id="ipNone">Clear link</button>
    <?php if ($canNewItem): ?><button type="submit" form="lnForm" class="xbtn sm" id="ipNew" name="new_item_for" value="0">+ New item in Item Master</button><span class="imp-small imp-muted">saves this invoice, opens your New Item page filled from the line, and comes back here</span><?php endif; ?></div>
</div>
<style>
.ipick{width:100%;text-align:left;border:1px dashed #cbd5e3;border-radius:9px;background:#fff;padding:7px 9px;font-size:12px;cursor:pointer;color:#152033;font-family:inherit;min-height:34px}
.ipick.set{border-style:solid;background:#f8fbfe}.ipick:disabled{cursor:default}
.ipk-box{position:fixed;z-index:60;width:min(600px,94vw);background:#fff;border:1px solid #cbd5e3;border-radius:12px;box-shadow:0 14px 34px rgba(11,42,74,.18);padding:8px}
.ipk-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:2px 2px 8px;border-bottom:1px solid #e3e9f2}
.ipk-list{max-height:300px;overflow-y:auto;padding:4px 0}
.ipk-sec{font-size:10.5px;font-weight:800;color:#8a97ab;letter-spacing:.05em;padding:8px 8px 3px;text-transform:uppercase}
.ipk-it{display:grid;grid-template-columns:78px minmax(0,1fr) auto auto;gap:8px;align-items:center;padding:6px 8px;border-radius:8px;font-size:12.5px;cursor:pointer}
.ipk-it:hover,.ipk-it.on{background:#eef6fb}
.ipk-code{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:#5a6b82}
.ipk-chip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap;background:#f6f8fc;border:1px solid #e3e9f2;color:#5a6b82}
.ipk-chip.u{background:rgba(14,168,201,.1);border-color:rgba(14,168,201,.3);color:#0b7f99}
.ipk-chip.c{background:rgba(109,91,208,.1);border-color:rgba(109,91,208,.28);color:#5a49c2}
.ipk-foot{display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #e3e9f2;padding-top:8px}
.kgb{color:#5a49c2;font-weight:600;white-space:nowrap}
</style>
<script>
(function () {
  var ITEMS = <?= json_encode(array_values($items), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var IMAP = {}; ITEMS.forEach(function (it) { IMAP[it.key] = it; });
  var UOM = <?= json_encode(IMP_UOM_MAP) ?>;
  var CANNEW = <?= $canNewItem ? 'true' : 'false' ?>;
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
  function unitOf(uom) { var u = String(uom || '').toUpperCase().trim(); return UOM[u] || u.toLowerCase(); }
  function q3(v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 3 }); }
  /* the same rule as imp_stock_qty() on the server */
  function stockOf(tr, it) {
    var bill = tr.querySelector('.unit').value, q = n(tr.querySelector('.q').value), nk = n(tr.querySelector('.nk').value);
    if (bill === 'kg' && !tr.querySelector('.nk').value.trim()) nk = q;
    var u = unitOf(it.uom);
    if (u === bill) return { qty: q, how: 'same as billed' };
    if (u === 'kg') return { qty: nk, how: 'from net kg' };
    if (u === 'm' && bill === 'yd') return { qty: Math.round(q * 0.9144 * 1000) / 1000, how: 'yd × 0.9144' };
    if (u === 'yd' && bill === 'm') return { qty: Math.round(q / 0.9144 * 1000) / 1000, how: 'm ÷ 0.9144' };
    var t = n(tr.querySelector('.sq').value); return t > 0 ? { qty: t, how: 'typed', typed: true } : { need: true };
  }
  function drawItem(tr) {
    var key = tr.querySelector('.ikey').value, it = IMAP[key], b = tr.querySelector('.ipick'), sz = tr.querySelector('.isize');
    b.classList.toggle('set', !!it);
    b.innerHTML = it ? '<span class="ipk-code">' + esc(it.code) + '</span> ' + esc(it.name) + ' <span class="ipk-chip u">' + esc(it.uom) + '</span>'
                     : '<span class="imp-muted">' + (EDIT ? '— pick an item —' : 'not linked') + '</span>';
    sz.style.display = it && it.kind === 'prod' ? '' : 'none';
    var cell = tr.querySelector('.istock'), sq = cell.querySelector('.sq'), txt = cell.querySelector('.sqtxt');
    if (!it) { sq.style.display = 'none'; txt.innerHTML = '<span class="imp-small imp-muted">link an item first</span>'; return; }
    var st = stockOf(tr, it), nk = n(tr.querySelector('.nk').value) || (tr.querySelector('.unit').value === 'kg' ? n(tr.querySelector('.q').value) : 0);
    var u = unitOf(it.uom), needBox = !(u === tr.querySelector('.unit').value || u === 'kg' || (u === 'm' && tr.querySelector('.unit').value === 'yd') || (u === 'yd' && tr.querySelector('.unit').value === 'm'));
    sq.style.display = needBox ? '' : 'none'; sq.placeholder = 'qty in ' + it.uom;
    if (st.need) { txt.innerHTML = '<span class="imp-small" style="color:#b8283f">billed in ' + esc(tr.querySelector('.unit').value) + ', item kept in ' + esc(it.uom) + ' — type the quantity in ' + esc(it.uom) + '</span>'; return; }
    txt.innerHTML = '<b>' + q3(st.qty) + ' ' + esc(it.uom) + '</b>' + (u === 'kg' ? '' : ' <span class="kgb">(' + q3(nk) + ' kg)</span>') + '<div class="imp-small imp-muted">' + st.how + '</div>';
  }
  function wire(tr) { drawSpec(tr); drawItem(tr); }
  /* the picker: one box, opened under whichever line's button was pressed */
  var box = document.getElementById('ipBox'), list = document.getElementById('ipList'), ipQ = document.getElementById('ipQ'), ipCost = document.getElementById('ipCost'), cur = null;
  function drawList() {
    var s = ipQ.value.trim().toLowerCase(), onlyC = ipCost.checked, sel = cur ? cur.querySelector('.ikey').value : '';
    var rows = ITEMS.filter(function (it) { return (!onlyC || it.costing > 0) && (!s || (it.code + ' ' + it.name + ' ' + it.type + ' ' + it.grp).toLowerCase().indexOf(s) >= 0); });
    var one = function (it) { return '<div class="ipk-it' + (it.key === sel ? ' on' : '') + '" data-k="' + esc(it.key) + '"><span class="ipk-code">' + esc(it.code) + '</span><span>' + esc(it.name) + ' <span class="imp-small imp-muted">· ' + esc(it.grp) + (it.type && it.kind === 'mat' ? ' / ' + esc(it.type) : '') + '</span></span><span class="ipk-chip u">' + esc(it.uom) + '</span>'
      + (it.kind === 'prod' ? '<span class="ipk-chip">by size</span>' : it.costing ? '<span class="ipk-chip c">costing ' + it.costing + '</span>' : '<span class="ipk-chip">not in costing</span>') + '</div>'; };
    var m = rows.filter(function (it) { return it.kind === 'mat'; }).slice(0, 150), p = rows.filter(function (it) { return it.kind === 'prod'; }).slice(0, 80);
    list.innerHTML = (m.length ? '<div class="ipk-sec">Item Master — materials</div>' + m.map(one).join('') : '') + (p.length ? '<div class="ipk-sec">Product Master — finished goods</div>' + p.map(one).join('') : '')
      + (rows.length ? '' : '<div class="imp-small imp-muted" style="padding:10px">Nothing matches.' + (CANNEW ? ' Create it with "New item in Item Master" below.' : ' Ask someone with Item Master access to add it.') + '</div>');
  }
  /* keep the box under its line's button (it is fixed, so the table's own scroll box never cuts it) */
  function place() {
    if (!cur) return;
    var r = cur.querySelector('.ipick').getBoundingClientRect(), h = box.offsetHeight, w = box.offsetWidth;
    box.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
    box.style.top = (r.bottom + h + 8 < window.innerHeight ? r.bottom + 4 : Math.max(8, r.top - h - 4)) + 'px';
  }
  function openPick(tr) {
    cur = tr; box.style.display = '';
    var nb = document.getElementById('ipNew'); if (nb) nb.value = String(Array.prototype.indexOf.call(tb.querySelectorAll('tr.ln'), tr));
    ipQ.value = ''; drawList(); place(); ipQ.focus({ preventScroll: true });
  }
  function closePick() { box.style.display = 'none'; cur = null; }
  ipQ.addEventListener('input', drawList); ipCost.addEventListener('change', drawList);
  list.addEventListener('click', function (e) { var r = e.target.closest('.ipk-it'); if (!r || !cur) return; cur.querySelector('.ikey').value = r.dataset.k; cur.querySelector('.sq').value = ''; drawItem(cur); closePick(); });
  document.getElementById('ipNone').addEventListener('click', function () { if (cur) { cur.querySelector('.ikey').value = ''; drawItem(cur); } closePick(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePick(); });
  document.addEventListener('mousedown', function (e) { if (box.style.display !== 'none' && !box.contains(e.target) && !e.target.closest('.ipick')) closePick(); });
  /* scrolling the page or the table moves the box with its line; scrolling the list inside the box is just that */
  window.addEventListener('scroll', function (e) { if (box.style.display !== 'none' && !box.contains(e.target)) place(); }, true);
  window.addEventListener('resize', place);
  tb.querySelectorAll('tr.ln').forEach(wire);
  tb.addEventListener('input', function (e) { var tr = e.target.closest('tr'); if (tr && tr.classList.contains('sp')) keepSpec(tr.previousElementSibling); calc(); if (tr && tr.classList.contains('ln') && !e.target.classList.contains('sq')) drawItem(tr); });
  tb.addEventListener('change', function (e) {
    var tr = e.target.closest('tr');
    if (e.target.classList.contains('cat')) { drawSpec(tr); tr.nextElementSibling.style.display = ''; tr.querySelector('.spec-tog').textContent = '▾ specification'; }
    if (tr && tr.classList.contains('sp')) keepSpec(tr.previousElementSibling);
    if (tr && tr.classList.contains('ln')) drawItem(tr);
    calc();
  });
  tb.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('.ipick') && EDIT) { var pr = t.closest('tr'); if (cur === pr) closePick(); else openPick(pr); return; }
    if (t.classList.contains('spec-tog')) { e.preventDefault(); var sp = t.closest('tr').nextElementSibling, open = sp.style.display === 'none'; sp.style.display = open ? '' : 'none'; t.textContent = (open ? '▾' : '▸') + ' specification'; }
    if (t.classList.contains('del')) { var tr = t.closest('tr'); if (tb.querySelectorAll('tr.ln').length > 1) { tr.nextElementSibling.remove(); tr.remove(); } else { tr.querySelectorAll('input:not([type=hidden])').forEach(function (i) { i.value = ''; }); } calc(); }
  });
  /* the template for a new line, copied once while the page loads */
  var protoRow = tb.querySelector('tr.ln').cloneNode(true), protoSp = tb.querySelector('tr.sp').cloneNode(true);
  function newRow(vals) {
    var a = protoRow.cloneNode(true), b = protoSp.cloneNode(true);
    a.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'invoice_id[]' ? i.value : ''; });
    a.querySelector('input[name="line_id[]"]').value = '0'; a.querySelector('.specs').value = '';
    b.querySelectorAll('input').forEach(function (i) { i.value = ''; }); a.querySelector('.ikey').value = '';
    vals = vals || {};
    if (vals.cat) a.querySelector('.cat').value = vals.cat;
    a.querySelector('.unit').value = vals.unit || 'kg';
    [['description', vals.desc], ['hs_code', vals.hs], ['bill_qty', vals.qty], ['rate', vals.rate], ['net_kg', vals.net], ['gross_kg', vals.gross], ['packages', vals.pkgs]].forEach(function (p) { if (p[1] != null) a.querySelector('[name="' + p[0] + '[]"]').value = p[1]; });
    b.style.display = 'none'; tb.appendChild(a); tb.appendChild(b); drawSpec(a); drawItem(a); return a;
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
