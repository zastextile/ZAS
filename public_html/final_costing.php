<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';
require_login();
require_admin();
fc_ensure_schema();

$shipmentId = (int)($_GET['shipment_id'] ?? 0);
$st = db()->prepare("SELECT * FROM shipments WHERE id=?");
$st->execute([$shipmentId]);
$shipment = $st->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

// Locked lines are final and can't change without an explicit Reopen, so
// there's no need to keep re-checking their product match/profitability on
// every view — only Draft lines still get the real (possibly AI-calling) work.
$finals = fc_get_for_shipment($shipmentId);
$lockedItemIds = fc_locked_item_ids($finals);

// This recomputes matching + profitability + price-history checks for every
// Draft line — real work, including live AI calls for any unconfirmed match.
// Kept for 10 minutes per shipment so reopening the same shipment shortly
// after (very common while you're actively working on it) is instant instead
// of redoing all of it. Re-sync and Link Product refill this cache themselves
// right after acting, so a fresh view always follows a real change — never
// stale, and never a wasted extra recompute either.
$estimate = fc_estimate_cached($shipmentId, $lockedItemIds);
$cur = $shipment['currency'] ?: 'PKR';
$uid = current_user()['id'];

/* Active Product Master list, cached — used only for the "no costing found,
   link it manually" picker. Plain DB read, no OpenAI calls, no extra server load. */
$allProducts = cache_remember('products_active_list:v' . cache_version('products'), 30, function () {
    return db()->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name LIMIT 500")->fetchAll();
});

// auto-seed a draft final costing for every invoice line that doesn't have one yet
if ($estimate) {
    foreach ($estimate['line_costing'] as $lc) {
        $itemRow = null;
        foreach ($estimate['items'] as $it) { if ((int)$it['line_no'] === (int)$lc['line']) { $itemRow = $it; break; } }
        if (!$itemRow) continue;
        fc_seed_from_estimate((int)$itemRow['id'], $shipmentId, $cur, $lc['materials'], $uid, $lc['costing_version_id'] ?? null);
    }
}

// Re-fetch (not a duplicate of the one above) — seeding just created new
// final_costings rows for previously-unseeded items, and this is the copy
// actually used for rendering, so it must reflect those.
$finals = fc_get_for_shipment($shipmentId);

page_header('Final Costing — ' . $shipment['invoice_no']);
flash();
?>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so this one attribute is the whole of the restyle and removing
         it puts the page back exactly as it was. Nothing below is edited: the
         page keeps its own class names, and the skin maps onto them. */ ?>
<div class="zskin">
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0}
.zbtn{padding:10px 16px;border:none;border-radius:10px;cursor:pointer;font-weight:700;font-size:12.5px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3)}
.zbtn.green{background:linear-gradient(100deg,#16a34a,#0e7a3d)}
.fc-in{padding:7px 8px;border-radius:8px;border:1px solid #cbd5e3;font-size:12.5px;width:100%;font-family:inherit}
.fc-table{width:100%;border-collapse:collapse;font-size:12.5px}
.fc-table th{background:#f6f8fc;color:#5a6b82;font-size:10px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:5px 8px;border-bottom:1px solid #e3e9f2}
.fc-table td{padding:3px 8px;border-bottom:1px solid #f6f8fc}
.badge{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge.draft{background:rgba(217,119,6,.14);color:#a25c04}
.badge.locked{background:rgba(22,163,74,.14);color:#16a34a}
.fc-in.mini{max-width:92px}
.num{text-align:right;color:#0ea8c9;font-weight:600}
.qtywrap{display:flex;gap:6px;align-items:center}
.shbtn{width:22px;height:22px;flex-shrink:0;border-radius:6px;border:1px solid #cbd5e3;background:#ffffff;color:#8a97ab;font-size:12px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.15s;padding:0}
.shbtn:hover{border-color:rgba(14,168,201,.5);color:#0ea8c9}
.shbtn.on{background:linear-gradient(135deg,#0ea8c9,#6d5bd0);border-color:transparent;color:#fff;box-shadow:0 0 10px rgba(14,168,201,.4)}
.zbtn.sm{padding:6px 9px;font-size:12px}
.fc-toast{position:fixed;left:50%;bottom:26px;transform:translate(-50%,20px);background:#152033;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;opacity:0;transition:.25s;box-shadow:0 12px 30px rgba(0,0,0,.2);z-index:50;pointer-events:none;max-width:90vw;text-align:center}
.fc-toast.show{opacity:1;transform:translate(-50%,0)}
.fc-toast.err{background:#b8283f}
/* Save as product costing — one shared dialog */
.fc-sa{border:none;border-radius:14px;padding:0;width:min(470px,94vw);box-shadow:0 24px 60px rgba(15,23,42,.28);color:#152033}
.fc-sa::backdrop{background:rgba(15,23,42,.38)}
.fc-sa form{padding:16px 18px 14px;margin:0}
.fc-sa h3{margin:0 0 3px;font-size:15px}
.fc-sa .sub{margin:0 0 12px;font-size:11.5px;color:#5a6b82;line-height:1.45}
.fc-sa .opt{display:flex;gap:8px;align-items:flex-start;padding:9px 10px;border:1px solid #e3e9f2;border-radius:10px;margin-bottom:7px;cursor:pointer}
.fc-sa .opt.on{border-color:#0ea8c9;background:rgba(14,168,201,.05)}
.fc-sa .opt input[type=radio]{margin-top:3px;accent-color:#0ea8c9}
.fc-sa .opt b{font-size:12.5px;display:block;margin-bottom:5px}
.fc-sa .opt .fc-combo{max-width:none;display:block}
.fc-sa .row2{display:grid;grid-template-columns:1.4fr 1fr;gap:8px;margin-top:4px}
.fc-sa label.f{font-size:10.5px;font-weight:700;color:#5a6b82;text-transform:uppercase;letter-spacing:.03em;display:block}
.fc-sa label.f .fc-in{margin-top:3px;text-transform:none;letter-spacing:0;font-weight:400}
.fc-sa .hint{font-size:10.5px;color:#8a97ab;margin:4px 0 0}
.fc-sa .err{display:none;margin:10px 0 0;padding:7px 10px;border-radius:8px;background:rgba(224,67,93,.1);color:#b8283f;font-size:12px}
#fcSaDlg .fc-in{border:1px solid #cbd5e3 !important;background:#fff !important;border-radius:8px;padding:7px 9px;box-shadow:none}
#fcSaDlg .fc-in:focus{outline:none;border-color:#0ea8c9 !important;box-shadow:0 0 0 3px rgba(14,168,201,.15)}
.fc-sa .acts{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
.fc-chip{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:20px;border:1px solid #e3e9f2;background:#ffffff;cursor:pointer;font-size:12px;font-weight:700}
.fc-chip:hover{border-color:#0ea8c9;background:rgba(14,168,201,.06)}
.fc-chip-pct{font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;background:rgba(22,163,74,.1);color:#16a34a}
.fc-chip-pct.mid{background:rgba(217,119,6,.1);color:#d97706}
.fc-combo{position:relative;max-width:280px}
.fc-combo-list{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1px solid #e3e9f2;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.12);z-index:5;max-height:220px;overflow-y:auto;display:none}
.fc-combo-list.show{display:block}
.fc-combo-item{padding:8px 11px;font-size:12.5px;cursor:pointer}
.fc-combo-item:hover{background:rgba(14,168,201,.08)}
.fc-combo-item mark{background:rgba(217,119,6,.28);color:inherit;border-radius:3px;padding:0 1px}
.fc-combo-empty{padding:10px;font-size:12px;color:#8a97ab;text-align:center}
</style>
<link rel="stylesheet" href="assets/css/lov.css?v=3">
<link rel="stylesheet" href="assets/css/zfiori.css?v=2">
<script src="assets/js/lov.js?v=3"></script>
<script src="assets/js/grid.js?v=2"></script>

<?php
/* THE OVERVIEW (SAP Fiori / Oracle Fusion look, as the Attendance screens):
   a header with the shipment's totals, then one slim row per invoice line;
   a row opens its full card below it. Rows that look wrong say so — a
   costing in another currency, or a cost far from the sale price — so a
   bad entry is seen before it reaches the dashboard. */
$fx = fx_get_rates();
$T = ['sales' => 0.0, 'costed_sales' => 0.0, 'cost' => 0.0, 'n' => 0, 'costed' => 0, 'locked' => 0, 'flagged' => 0];
foreach ($estimate['line_costing'] ?? [] as $lcx) {
    $itx = null; foreach ($estimate['items'] as $it) { if ((int)$it['line_no'] === (int)$lcx['line']) { $itx = $it; break; } }
    if (!$itx) continue;
    $fcx = $finals[(int)$itx['id']] ?? null; $fx1 = fc_line_facts($lcx, $fcx, $cur, $fx);
    $T['n']++; $T['sales'] += (float)$lcx['sales_amount'];
    if ($fx1['cost'] !== null) { $T['costed']++; $T['costed_sales'] += (float)$lcx['sales_amount']; $T['cost'] += $fx1['cost'] * (float)$lcx['qty']; }
    if ($fx1['status'] === 'locked') $T['locked']++;
    foreach ($fx1['flags'] as $fl) if ($fl[0] !== 'info') { $T['flagged']++; break; }
}
$T['profit'] = $T['costed_sales'] - $T['cost'];
$T['markup'] = $T['cost'] > 0 ? $T['profit'] / $T['cost'] * 100 : null;
?>
<div class="zfc">
<div class="zfc-head">
  <div class="zfc-title">
    <div><h1>Final Costing <span><?= e($shipment['invoice_no']) ?></span></h1>
      <p><?= e($shipment['buyer_name']) ?> · invoice in <b><?= e($cur) ?></b> · <?= (int)$T['n'] ?> line<?= $T['n'] === 1 ? '' : 's' ?> · <?= (int)$T['locked'] ?> locked</p></div>
    <div class="zfc-acts">
      <form method="post" action="final_costing_resync.php" onsubmit="return confirm('Re-sync ALL unlocked lines below with current Product Costing? Locked lines are skipped.')">
        <?= csrf_field() ?><input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
        <button class="act">⟳ Re-sync all</button>
      </form>
      <a class="act" href="final_costing_export.php?shipment_id=<?= (int)$shipmentId ?>">Export CSV</a>
      <button type="button" class="act" onclick="var d=document.getElementById('zfcCsv');d.hidden=!d.hidden">Upload CSV</button>
      <a class="act" href="shipment_view.php?id=<?= (int)$shipmentId ?>">Back to shipment</a>
    </div>
  </div>
  <div class="zfc-tiles">
    <div class="t"><span>Sales</span><b><?= e($cur) ?> <?= number_format($T['sales'], 2) ?></b></div>
    <div class="t"><span>Final cost (<?= (int)$T['costed'] ?> of <?= (int)$T['n'] ?> costed)</span><b><?= e($cur) ?> <?= number_format($T['cost'], 2) ?></b></div>
    <div class="t <?= $T['profit'] < 0 ? 'bad' : 'good' ?>"><span>Profit on costed lines</span><b><?= e($cur) ?> <?= number_format($T['profit'], 2) ?></b></div>
    <div class="t <?= $T['markup'] !== null && $T['markup'] < 0 ? 'bad' : 'info' ?>"><span>Markup on cost</span><b><?= $T['markup'] === null ? '—' : number_format($T['markup'], 1) . '%' ?></b></div>
  </div>
  <?php if ($T['flagged']): ?>
  <div class="zfc-strip"><b><?= (int)$T['flagged'] ?> line<?= $T['flagged'] === 1 ? '' : 's' ?> to check</b> — marked in red below. The usual cause: rates typed in rupees on a costing in another currency. Open the line and set <b>Rates in</b> to the currency you typed.</div>
  <?php endif; ?>
  <div class="zfc-csv" id="zfcCsv" hidden>
    <form method="post" action="final_costing_import.php" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <input type="file" name="csv_file" accept=".csv" required>
      <button class="act">Upload &amp; preview</button>
      <span>Export the CSV, fill actual quantities and rates in Excel, upload it here. You see a preview with warnings first; nothing changes until you confirm.</span>
    </form>
  </div>
  <div class="zfc-bar">
    <span>Final costing is a snapshot of Product Costing — after correcting a costing, use Re-sync (locked lines are protected).</span>
    <span class="zfc-tools">
      <button type="button" class="act sm" id="zfcProblems" aria-pressed="false">Only lines to check</button>
      <button type="button" class="act sm" id="zfcOpenAll">Open all</button>
      <button type="button" class="act sm" id="zfcCloseAll">Close all</button>
    </span>
  </div>
  <div class="zfc-cols"><span>#</span><span>Product</span><span class="n">Qty</span><span class="n">Sale / pc</span><span class="n">Cost / pc</span><span class="n">Markup</span><span>Status</span><span>Check</span><span></span></div>
</div>
<script>
/* Replaces the old <input list=datalist> picker, which only enabled its
   button on a byte-for-byte exact match to a product name — silently
   staying disabled on different case/spacing and feeling broken. This is a
   real filtered dropdown: type any part of the name (case-insensitive),
   click a result, done. Shared by both the "Not this" box and the "no
   match found" box via a field-id prefix ('fix' or 'link'). */
var PM_PRODUCTS = <?= json_encode(array_column($allProducts, 'id', 'name'), JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function fcComboFilter(prefix, itemId){
  var input = document.getElementById(prefix+'Input'+itemId);
  var list = document.getElementById(prefix+'List'+itemId);
  if (!input || !list) return;
  var q = input.value.trim().toLowerCase();
  var names = Object.keys(PM_PRODUCTS).filter(function(n){ return q === '' || n.toLowerCase().indexOf(q) !== -1; }).slice(0, 40);
  list.innerHTML = '';
  if (!names.length) {
    var empty = document.createElement('div'); empty.className = 'fc-combo-empty'; empty.textContent = 'No matching product';
    list.appendChild(empty);
  } else {
    names.forEach(function(n){
      var idx = q === '' ? -1 : n.toLowerCase().indexOf(q);
      var item = document.createElement('div'); item.className = 'fc-combo-item';
      if (idx >= 0) {
        item.appendChild(document.createTextNode(n.slice(0, idx)));
        var mk = document.createElement('mark'); mk.textContent = n.slice(idx, idx + q.length); item.appendChild(mk);
        item.appendChild(document.createTextNode(n.slice(idx + q.length)));
      } else {
        item.textContent = n;
      }
      item.onmousedown = function(){ fcComboPick(prefix, itemId, PM_PRODUCTS[n], n); };
      list.appendChild(item);
    });
  }
  list.classList.add('show');
}
function fcComboPick(prefix, itemId, pid, name){
  var input = document.getElementById(prefix+'Input'+itemId);
  if (input) input.value = name;
  document.getElementById(prefix+'Pid'+itemId).value = pid;
  document.getElementById(prefix+'Btn'+itemId).disabled = !pid;
  fcComboHide(prefix, itemId);
}
/* Suggestion chips carry their product id/name as data-attributes (not
   inline JS string literals) so a product name containing a quote or
   apostrophe can never break the click handler. */
function fcComboPickEl(prefix, itemId, el){
  fcComboPick(prefix, itemId, el.getAttribute('data-pid'), el.getAttribute('data-name'));
}
function fcComboHide(prefix, itemId){
  var list = document.getElementById(prefix+'List'+itemId);
  if (list) list.classList.remove('show');
}
</script>

<?php foreach ($estimate['line_costing'] ?? [] as $lc):
    $itemRow = null;
    foreach ($estimate['items'] as $it) { if ((int)$it['line_no'] === (int)$lc['line']) { $itemRow = $it; break; } }
    if (!$itemRow) continue;
    $itemId = (int)$itemRow['id'];
    $fc = $finals[$itemId] ?? null;
    echo '<section class="zfc-line" id="fcLine' . $itemId . '">' . fc_row_summary_html($lc, $fc, $cur, $fx, $itemId)
       . '<div class="zfc-body" hidden>' . fc_render_line_card($lc, $itemRow, $fc, $cur, $shipmentId) . '</div></section>';
endforeach; ?>
</div><?php /* closes .zfc */ ?>

<?php if (empty($estimate['line_costing'])): ?>
<div class="zcard"><p style="color:#8a97ab;text-align:center;margin:0">No invoice lines on this shipment yet.</p></div>
<?php endif; ?>

<script src="assets/js/final_costing.js?v=27"></script>
<script>
/* ---- the item picker + spreadsheet keys ---------------------------------
   Same two shared files as Gate Inward/Outward and the invoice, so there is
   one picker and one set of keys across the app rather than three dialects.
   Product Costing is deliberately NOT changed in this release — it keeps its
   datalist until you say otherwise. */
(function(){
  if(!window.LOV || !window.GRID) return;

  var ITEMS = <?= json_encode(fc_inv_items(), JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  LOV.register('fcitem', {
    cols: [
      { label:'Code',  w:'84px',               cls:'gg', get:function(r,q){ return LOV.hl(r.i.code, q); } },
      { label:'Item',  w:'minmax(150px,1fr)',  cls:'nm', get:function(r,q){ return LOV.hl(r.i.name, q); } },
      { label:'Group', w:'96px',               cls:'gg', get:function(r){ return LOV.esc(r.i.group||''); } },
      { label:'Unit',  w:'52px',               cls:'gg', get:function(r){ return LOV.esc(r.i.uom||''); } },
      { label:'Std rate', w:'78px',            cls:'gg num', get:function(r){ return Number(r.i.rate||0).toFixed(2); } }
    ],
    title: function(){ return 'Item Master — code, group, unit and standard rate'; },
    empty: function(f,q){
      return q ? 'No item matches that. Type it anyway — Final Costing records what you actually bought.'
               : 'Item Master is empty.';
    },
    rows: function(f, q, showAll, cb){
      var out = [];
      ITEMS.forEach(function(i){
        var sc = LOV.score(q, i.code, i.name, i.group);
        if(sc > 0) out.push({ i:i, sc:sc });
      });
      /* Code order on a tie, never quantity or rate — the list is for
         finding a thing, not ranking it. Same rule as the stock screens. */
      out.sort(function(a,b){ return a.sc!==b.sc ? b.sc-a.sc : a.i.code.localeCompare(b.i.code); });
      cb(out, 0);
    },
    pick: function(f, r){
      var tr = f.closest('tr');
      f.value = r.i.name;
      /* Fill the two fields you retype most — but never overwrite something
         already entered. What you ACTUALLY paid is the point of this screen,
         so the standard rate is a starting value, not an answer. */
      var u = tr.querySelector('[data-c="unit"]');
      if(u && !u.value.trim()) u.value = r.i.uom || '';
      var rate = tr.querySelector('[data-c="rate"]');
      if(rate && (!rate.value.trim() || parseFloat(rate.value) === 0)) {
        rate.value = Number(r.i.rate||0).toFixed(2);
      }
      if(typeof fcCalc === 'function' && rate) fcCalc(rate);
      fcRecalcTotal(f);
      var q = tr.querySelector('[data-c="qty"]'); if(q){ q.focus(); q.select(); }
    }
  });

  /* One attach on a stable ancestor. LOV delegates off data-lov, so cards
     swapped in later by fcAjaxSubmit are picked up with no re-attach. */
  LOV.attach(document.body);

  var COLS = ['grp','item','desc','qty','unit','rate'];

  /* GRID is different — it walks root.children, so it belongs to one tbody
     and dies with it when a card is replaced. Re-wire per card after every
     swap. A tbody already wired is skipped so a second call cannot
     double-bind. */
  window.fcWire = function(card){
    if(!card) return;
    var tb = card.querySelector('tbody[data-fcgrid]');
    if(!tb || tb.dataset.wired === '1') return;     // locked cards have no grid
    tb.dataset.wired = '1';
    var itemId = tb.dataset.fcgrid;
    var warn = document.getElementById('fcPaste'+itemId);

    GRID.attach(tb, {
      cols: COLS,
      addRow: function(){ return fcAddRow(itemId, ''); },
      onEdit: function(tr, col){
        var r = tr.querySelector('[data-c="rate"]');
        if(typeof fcCalc === 'function' && r) fcCalc(r);
      },
      afterChange: function(){ fcRecalcTotal(tb); },
      onPasteProblem: function(bad){
        if(!warn) return;
        var LBL = { qty:'Qty', rate:'Rate' };
        warn.innerHTML = '<b>Check these before saving:</b> ' + bad.slice(0,6).map(function(b){
            return 'line ' + b.row + ' ' + (LBL[b.col]||b.col) + ' ("' + LOV.esc(b.text) + '")';
          }).join(', ') + (bad.length>6 ? ' and ' + (bad.length-6) + ' more' : '')
          + '. Left exactly as pasted because the number could not be read — type it in.';
        warn.style.display = 'block';
      }
    });

    /* Ctrl+S saves the card the cursor is in, not every card on the page. */
    tb.addEventListener('keydown', function(e){
      if((e.ctrlKey||e.metaKey) && (e.key==='s'||e.key==='S')){
        e.preventDefault();
        var form = tb.closest('form'); if(form) form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      if(e.altKey && (e.key==='n'||e.key==='N')){
        e.preventDefault();
        var tr = fcAddRow(itemId, '');
        var first = tr.querySelector('[data-c="grp"]'); if(first) first.focus();
      }
    });
  };
  window.fcWireAll = function(){
    [].slice.call(document.querySelectorAll('[id^=fcCard]')).forEach(window.fcWire);
  };
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.fcWireAll);
  else window.fcWireAll();
})();

var fcToastTimer = null;
function fcToast(msg, ok){
  var t = document.getElementById('fcToast');
  t.textContent = msg;
  t.className = 'fc-toast show' + (ok ? '' : ' err');
  clearTimeout(fcToastTimer);
  fcToastTimer = setTimeout(function(){ t.classList.remove('show'); }, Math.max(3200, String(msg).length * 55));
}
</script>
<script>
/* THE OVERVIEW: open and close lines, keep each row up to date after a
   save, and the "Rates in" switch with its live check. Display only —
   saving still goes through the card's own forms. */
(function(){
  var root = document.querySelector('.zfc'); if(!root) return;
  function setOpen(sec, on){
    var row = sec.querySelector('.zfc-row'), body = sec.querySelector('.zfc-body');
    body.hidden = !on; row.setAttribute('aria-expanded', on ? 'true' : 'false');
    if(on){ var card = body.querySelector('[id^=fcCard]'); if(card && window.fcWire) window.fcWire(card); live(card); }
  }
  root.addEventListener('click', function(e){
    var row = e.target.closest('.zfc-row'); if(!row) return;
    var sec = row.closest('.zfc-line'); setOpen(sec, row.getAttribute('aria-expanded') !== 'true');
  });
  var secs = function(){ return [].slice.call(root.querySelectorAll('.zfc-line')); };
  document.getElementById('zfcOpenAll').onclick = function(){ secs().forEach(function(s){ if(!s.hidden) setOpen(s, true); }); };
  document.getElementById('zfcCloseAll').onclick = function(){ secs().forEach(function(s){ setOpen(s, false); }); };
  var pb = document.getElementById('zfcProblems');
  pb.onclick = function(){
    var on = pb.getAttribute('aria-pressed') !== 'true'; pb.setAttribute('aria-pressed', on ? 'true' : 'false');
    secs().forEach(function(s){ s.hidden = on && !s.querySelector('.zfc-row.bad, .zfc-row .fl.warn'); });
  };
  /* one or two lines: open them; more: a list to open from */
  if(secs().length <= 2) secs().forEach(function(s){ setOpen(s, true); });

  /* after a save / re-sync / link the card is replaced: refresh its row from the card */
  new MutationObserver(function(list){
    list.forEach(function(m){ [].forEach.call(m.addedNodes, function(n){
      if(n.nodeType !== 1 || !n.id || n.id.indexOf('fcCard') !== 0) return;
      var sec = n.closest('.zfc-line'), tpl = n.querySelector('template.fc-rowsum');
      if(sec && tpl){ var fresh = tpl.content.firstElementChild.cloneNode(true); fresh.setAttribute('aria-expanded', 'true'); sec.replaceChild(fresh, sec.querySelector('.zfc-row')); }
      live(n);
    }); });
  }).observe(root, { childList: true, subtree: true });

  /* RATES IN: every rate on the card, and new rows, count in this currency */
  var add0 = window.fcAddRow;
  window.fcAddRow = function(itemId, cur){
    var sel = document.querySelector('#fcCard' + itemId + ' .fc-ratecur');
    return add0(itemId, (sel && sel.value) || cur);
  };
  root.addEventListener('change', function(e){
    var sel = e.target.closest('.fc-ratecur'); if(!sel) return;
    var card = sel.closest('[id^=fcCard]');
    [].forEach.call(card.querySelectorAll('input[name="native_currency[]"]'), function(h){ h.value = sel.value; });
    [].forEach.call(card.querySelectorAll('.fc-curlbl'), function(l){ l.textContent = sel.value; });
    var tot = card.querySelector('[id^=fcTotal]'); if(tot) tot.textContent = tot.textContent.replace(/^[A-Z]{3}/, sel.value);
    live(card);
  });
  /* the live check: this card's cost per piece against its sale price */
  function live(card){
    if(!card) return;
    var out = card.querySelector('.fc-live'), sel = card.querySelector('.fc-ratecur'), tot = card.querySelector('[id^=fcTotal]');
    if(!out || !sel || !tot) return;
    var fx = {}; try { fx = JSON.parse(out.getAttribute('data-fx') || '{}'); } catch(x){}
    var amt = parseFloat((tot.textContent || '').replace(/[^0-9.\-]/g, '')) || 0;
    var inv = card.getAttribute('data-inv'), sale = parseFloat(card.getAttribute('data-sale')) || 0;
    if(!amt){ out.textContent = ''; out.className = 'fc-live'; return; }
    var perInv = amt * (fx[sel.value] || 1) / (fx[inv] || 1);
    var ratio = sale > 0 ? perInv / sale : 0, mk = perInv > 0 ? (sale - perInv) / perInv * 100 : 0;
    var txt = 'Cost per piece ≈ ' + inv + ' ' + perInv.toFixed(2) + ' against a sale price of ' + inv + ' ' + sale.toFixed(2) + ' — markup ' + mk.toFixed(1) + '%';
    var bad = ratio >= 3 || (ratio > 0 && ratio <= .1);
    if(bad) txt += '. That is ' + (ratio >= 3 ? ratio.toFixed(ratio < 10 ? 1 : 0) + '× the sale price' : 'only ' + (ratio * 100).toFixed(1) + '% of it') + ' — are the rates really in ' + sel.value + '?';
    out.textContent = txt; out.className = 'fc-live' + (bad ? ' bad' : '');
  }
  /* only a change of a card's total re-checks it (the check's own text must not, or it would loop) */
  new MutationObserver(function(list){ list.forEach(function(m){
    var t = m.target.nodeType === 3 ? m.target.parentElement : m.target;
    var tot = t && t.closest ? t.closest('[id^=fcTotal]') : null;
    if(tot) live(tot.closest('[id^=fcCard]'));
  }); }).observe(root, { characterData: true, subtree: true, childList: true });
})();
</script>
<dialog class="fc-sa" id="fcSaDlg" aria-labelledby="fcSaTitle">
  <form method="dialog" onsubmit="return fcSaveAsGo(event)">
    <h3 id="fcSaTitle">Save as product costing</h3>
    <p class="sub">Copies this card's lines, per piece, into Product Costing as a <b>new</b> costing. Nothing existing is changed. Next time this buyer ships <b id="fcSaLineName"></b>, this costing is found by itself.</p>
    <label class="opt on" id="fcSaOptVar"><input type="radio" name="sa_mode" value="variation" checked onchange="fcSaMode()">
      <span style="flex:1;min-width:0"><b>Add as a variation under a product</b>
        <span class="fc-combo"><input type="text" id="saInput0" class="fc-in" placeholder="Type a product name…" autocomplete="off"
          oninput="document.getElementById('saPid0').value='';fcSaCheck();fcComboFilter('sa',0)" onfocus="fcSaPick('variation');fcComboFilter('sa',0)" onblur="setTimeout(function(){fcComboHide('sa',0);fcSaCheck()},150)">
          <span class="fc-combo-list" id="saList0"></span></span>
        <input type="hidden" id="saPid0" value=""></span></label>
    <label class="opt" id="fcSaOptNew"><input type="radio" name="sa_mode" value="new" onchange="fcSaMode()">
      <span style="flex:1;min-width:0"><b>Or make a new product</b>
        <input type="text" id="fcSaNew" class="fc-in" maxlength="200" placeholder="New product name" oninput="fcSaCheck()" onfocus="fcSaPick('new')"></span></label>
    <div class="row2">
      <label class="f">Costing name<input type="text" id="fcSaVer" class="fc-in" maxlength="160"></label>
      <label class="f">Size<input type="text" id="fcSaSize" class="fc-in" maxlength="80"></label>
    </div>
    <p class="hint">The size is looked for in an invoice line's name and description — it is what picks this costing next time.</p>
    <p class="err" id="fcSaErr"></p>
    <div class="acts">
      <button type="button" class="zbtn sec" onclick="document.getElementById('fcSaDlg').close()">Cancel</button>
      <button type="submit" class="zbtn" id="saBtn0" disabled>Save costing</button>
    </div>
  </form>
</dialog>
<script>
/* SAVE AS PRODUCT COSTING. One dialog for every card: the card's button
   carries what to suggest. The card's own lines are sent with it, so what
   is on screen is what is copied; the card comes back redrawn, no reload. */
var FC_INVOICE = <?= json_encode((string)$shipment['invoice_no'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
var fcSaFrom = null, fcSaForce = false;
function fcSaveAsOpen(btn){
  fcSaFrom = btn;
  var d = btn.dataset, $ = function(id){ return document.getElementById(id); };
  $('fcSaLineName').textContent = '"' + d.line + '"';
  $('saInput0').value = d.pname || ''; $('saPid0').value = d.pid && d.pid !== '0' ? d.pid : '';
  $('fcSaNew').value = d.line || '';
  $('fcSaVer').value = (d.line || 'Costing') + ' (' + FC_INVOICE + ')';
  $('fcSaSize').value = d.size || '';
  $('fcSaErr').style.display = 'none'; fcSaForce = false; $('saBtn0').textContent = 'Save costing';
  fcSaPick('variation');
  $('fcSaDlg').showModal();
  (d.pid && d.pid !== '0' ? $('fcSaVer') : $('saInput0')).focus();
}
function fcSaPick(mode){
  document.querySelector('#fcSaDlg input[name=sa_mode][value=' + mode + ']').checked = true; fcSaMode();
}
function fcSaMode(){
  var nw = document.querySelector('#fcSaDlg input[name=sa_mode][value=new]').checked;
  document.getElementById('fcSaOptVar').classList.toggle('on', !nw);
  document.getElementById('fcSaOptNew').classList.toggle('on', nw);
  fcSaCheck();
}
function fcSaCheck(){
  var nw = document.querySelector('#fcSaDlg input[name=sa_mode][value=new]').checked;
  document.getElementById('saBtn0').disabled = nw ? !document.getElementById('fcSaNew').value.trim() : !document.getElementById('saPid0').value;
}
function fcSaveAsGo(ev){
  ev.preventDefault();
  var $ = function(id){ return document.getElementById(id); }, d = fcSaFrom.dataset, id = d.item;
  var card = $('fcCard' + id), form = card ? card.querySelector('form[action="final_costing_save.php"]') : null;
  var fd = form ? new FormData(form) : new FormData();
  if (!form) {   // a locked card: copied as it stands
    var tok = document.querySelector('input[name=_csrf]');
    fd.append('_csrf', tok ? tok.value : ''); fd.append('final_costing_id', d.fc); fd.append('shipment_id', <?= (int)$shipmentId ?>);
  }
  var nw = document.querySelector('#fcSaDlg input[name=sa_mode][value=new]').checked;
  fd.append('sa_mode', nw ? 'new' : 'variation');
  fd.append('sa_product_id', nw ? '' : $('saPid0').value);
  fd.append('sa_new_name', nw ? $('fcSaNew').value : '');
  fd.append('sa_version', $('fcSaVer').value);
  fd.append('sa_size', $('fcSaSize').value);
  if (fcSaForce) fd.append('sa_force', '1');
  fd.append('ajax', '1');
  var b = $('saBtn0'); b.disabled = true; b.textContent = 'Saving…';
  fetch('final_costing_to_product.php', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(r){
    b.textContent = 'Save costing';
    if (!r.success) {
      $('fcSaErr').textContent = r.message || 'Could not save.'; $('fcSaErr').style.display = 'block';
      if (r.confirm) { fcSaForce = true; b.textContent = 'Save anyway'; }
      fcSaCheck(); return;
    }
    $('fcSaDlg').close();
    if (r.html && card) { card.outerHTML = r.html; if (window.fcWire) window.fcWire($('fcCard' + id)); }
    fcToast(r.message, true);
  }).catch(function(){
    b.textContent = 'Save costing'; fcSaCheck();
    $('fcSaErr').textContent = 'Network error — please try again.'; $('fcSaErr').style.display = 'block';
  });
  return false;
}
</script>
<div class="fc-toast" id="fcToast"></div>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
