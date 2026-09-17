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
<link rel="stylesheet" href="assets/css/lov.css?v=2">
<script src="assets/js/lov.js?v=2"></script>
<script src="assets/js/grid.js?v=2"></script>

<div class="topbar">
  <div><h1>Final Costing</h1><p class="lead"><?= e($shipment['invoice_no']) ?> · <?= e($shipment['buyer_name']) ?> — enter actual costs once the shipment is complete; estimate stays untouched until this is locked.</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <form method="post" action="final_costing_resync.php" onsubmit="return confirm('Re-sync ALL unlocked lines below with current Product Costing? Locked lines are skipped.')">
      <?= csrf_field() ?>
      <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
      <button class="zbtn sec">⟳ Re-sync All from Product Costing</button>
    </form>
    <a class="zbtn sec" href="final_costing_export.php?shipment_id=<?= (int)$shipmentId ?>">Export CSV</a>
    <a class="zbtn sec" href="shipment_view.php?id=<?= (int)$shipmentId ?>">Back to Shipment</a>
  </div>
</div>
<p style="font-size:11.5px;color:#8a97ab;margin:-10px 0 18px">Final Costing is a one-time snapshot, not live-linked to Product Costing — if you correct a costing after this was created, use Re-sync to pull the updated numbers in (locked lines are protected; reopen first).</p>

<div class="zcard">
  <h2 style="margin-bottom:12px">Upload Completed CSV</h2>
  <form method="post" action="final_costing_import.php" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
    <input type="file" name="csv_file" accept=".csv" required>
    <button class="zbtn sec">Upload &amp; Preview</button>
  </form>
  <p style="font-size:11.5px;color:#8a97ab;margin:10px 0 0">Export the CSV above, fill in actual quantities/rates in Excel, then upload it here. You'll see a preview with any warnings before anything is saved — nothing changes until you confirm.</p>
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
    echo fc_render_line_card($lc, $itemRow, $fc, $cur, $shipmentId);
endforeach; ?>

<?php if (empty($estimate['line_costing'])): ?>
<div class="zcard"><p style="color:#8a97ab;text-align:center;margin:0">No invoice lines on this shipment yet.</p></div>
<?php endif; ?>

<script src="assets/js/final_costing.js?v=26"></script>
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

  var COLS = ['grp','item','desc','qty','unit','wt','rate'];

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
        var LBL = { qty:'Qty', rate:'Rate', wt:'Alloc. Wt' };
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
  fcToastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3200);
}
</script>
<div class="fc-toast" id="fcToast"></div>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
