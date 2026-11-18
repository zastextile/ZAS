<?php
/*
  DEMAND SHEET — one order, item by item. The rules are in includes/demand.php.

  Top: every order line (product · size · pieces) with the material lines its
  costing gives it, all editable in place like a spreadsheet — Tab and Enter
  move between cells, one Save keeps the lot. A carton or a polybag that holds
  many pieces is a "1 per N pcs" line: the need is whole units, rounded up.

  Bottom: the same purchase added up across every size — what is needed, what
  the order's purchase orders hold, what Gate Inward has received on them, and
  what is still to order. Tick lines → Create purchase order: an ordinary
  Purchase Contract for the order, for all or part of the balance.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/demand.php';
inv_ensure_schema();
dm_ensure_schema();
if (!inv_can_open('contract') && !is_admin()) { http_response_code(403); exit('You do not have permission to view demand sheets.'); }
$canEdit = is_admin() || inv_perm('master');
$canPo   = is_admin() || inv_perm('master') || inv_perm('gate');
$uid = (int) (current_user()['id'] ?? 0);
$pfId = (int) ($_GET['proforma'] ?? $_POST['proforma'] ?? 0);
$pf = $pfId ? dm_proforma($pfId) : null;
if (!$pf) { http_response_code(404); exit('No such proforma.'); }

/* every change is one JSON call; the answer carries the sheet as it now is */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    verify_csrf();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $in = json_decode((string) ($_POST['data'] ?? ''), true) ?: [];
    $a = (string) ($_POST['action'] ?? '');
    if (in_array($a, ['build', 'save', 'status'], true) && !$canEdit) { echo json_encode(['ok' => false, 'error' => 'Only the people who keep the costings can change a demand sheet.']); exit; }
    if ($a === 'po' && !$canPo) { echo json_encode(['ok' => false, 'error' => 'You may not make purchase orders.']); exit; }
    if ($a === 'status' && ($in['to'] ?? '') === 'draft' && !is_admin()) { echo json_encode(['ok' => false, 'error' => 'Only an administrator can reopen the final plan.']); exit; }
    $r = match ($a) {
        'build'  => dm_build($pfId, $uid),
        'save'   => dm_save($pfId, (array) ($in['rows'] ?? []), $uid),
        'status' => dm_set_status($pfId, (string) ($in['to'] ?? ''), $uid),
        'po'     => dm_make_po($pfId, (int) ($in['party_id'] ?? 0), (array) ($in['lines'] ?? []), (string) ($in['expected'] ?? ''), $uid),
        default  => ['ok' => false, 'error' => 'Unknown action.'],
    };
    if ($r['ok']) $r['sheet'] = dm_state($pfId);
    echo json_encode($r);
    exit;
}

function dm_state(int $pfId): array
{
    $d = dm_get($pfId);
    $lines = $d ? dm_lines((int) $d['id']) : [];
    return ['status' => $d['status'] ?? null, 'updated_at' => $d['updated_at'] ?? ($d['created_at'] ?? null), 'final_at' => $d['final_at'] ?? null,
            'lines' => $lines, 'summary' => $d ? dm_summary($pfId, $lines) : []];
}

/* first open: the sheet is made from the costings by itself */
$first = null;
if (!dm_get($pfId) && $canEdit) $first = dm_build($pfId, $uid);
$state = dm_state($pfId);
$mats = [];
try { $mats = db()->query("SELECT id, code, name, uom, colour, item_group, std_rate FROM inv_materials WHERE is_active = 1 ORDER BY name LIMIT 3000")->fetchAll(); } catch (Throwable $e) {}
$parties = [];
try { $parties = db()->query("SELECT id, code, name FROM inv_parties WHERE is_active = 1 AND party_type IN ('supplier','both') ORDER BY name")->fetchAll(); } catch (Throwable $e) {}
$pcs = 0.0;
try { $s = db()->prepare("SELECT COALESCE(SUM(qty),0) FROM proforma_items WHERE proforma_id = ?"); $s->execute([$pfId]); $pcs = (float) $s->fetchColumn(); } catch (Throwable $e) {}

page_header('Demand sheet');
?>
<style>
.dm{--ink:#1d2d3e;--muted:#5b6b7f;--line:#d9e0e8;--line2:#edf1f5;--head:#f5f7fa;--navy:#10213a;--pri:#0a6ed1;--good:#107e3e;--warn:#c35500;--bad:#bb0000;--ff:inherit;
  font-size:12.5px;color:var(--ink);max-width:1480px}
.dm h1{margin:0;font-size:18px;font-weight:600}
.dm .lead{margin:3px 0 0;color:var(--muted);font-size:12.5px}
.dm .top{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px}
.dm .acts{display:flex;gap:6px;flex-wrap:wrap}
.dm .b{height:28px;padding:0 11px;border:1px solid var(--pri);border-radius:4px;background:#fff;color:var(--pri);font:600 12.5px/26px var(--ff);cursor:pointer;text-decoration:none;display:inline-block;white-space:nowrap}
.dm .b:hover{background:#eef5fc}.dm .b.pri{background:var(--pri);color:#fff}.dm .b.pri:hover{background:#0858a8}
.dm .b:disabled{opacity:.45;cursor:default}
.dm .pill{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11.5px;font-weight:600;vertical-align:2px}
.dm .pill.draft{background:#fff1d6;color:#8a4b00}.dm .pill.final{background:#e7f4ec;color:var(--good)}
/* the object header's key figures: one slim line, Fiori style */
.dm .dmkpis{display:flex;gap:6px 30px;flex-wrap:wrap;padding:8px 14px;background:#fff;border:1px solid var(--line);border-radius:4px;margin-bottom:8px}
.dm .dmk .k{display:block;font-size:11px;color:var(--muted)}
.dm .dmk b{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums}
.dm .dmk b small{font-size:11.5px;font-weight:600;color:var(--muted);margin-left:2px}
.dm .dmk.hot b{color:var(--bad)}.dm .dmk.ok b{color:var(--good)}
.dm .warn{background:#fff8e8;border:1px solid #f0c36d;color:#6b4300;border-radius:4px;padding:6px 12px;margin-bottom:8px;font-size:12.5px}
.dm .tabs{display:flex;align-items:center;gap:2px;border-bottom:1px solid var(--line);padding:0 8px 0 4px;flex-wrap:wrap}
.dm .tabs [role=tab]{border:0;background:none;padding:10px 12px 8px;font:600 13px var(--ff);color:var(--muted);border-bottom:2px solid transparent;cursor:pointer}
.dm .tabs [role=tab][aria-selected=true]{color:var(--pri);border-bottom-color:var(--pri)}
.dm .tabs [role=tab] .c{display:inline-block;margin-left:6px;padding:0 6px;border-radius:9px;background:var(--line2);color:var(--muted);font-size:11px;line-height:17px}
.dm .tabs .hint{margin-left:auto;color:var(--muted);font-size:11.5px;padding:6px 4px}
.dm .panel{background:#fff;border:1px solid var(--line);border-radius:4px;margin-bottom:12px}
.dm .ph{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid var(--line)}
.dm .ph h3{margin:0;font-size:14px;font-weight:600}
.dm .ph .n{color:var(--muted);font-size:12px}
.dm .ph .grow{flex:1 1 auto}
.dm .scroll{overflow:auto;max-height:calc(100vh - 300px);min-height:200px}
.dm table{width:100%;border-collapse:collapse}
.dm thead th{position:sticky;top:0;z-index:2;background:var(--head);color:var(--muted);font-size:11.5px;font-weight:600;text-align:left;padding:6px 8px;white-space:nowrap;border-bottom:1px solid var(--line)}
.dm thead th.n,.dm td.n{text-align:right}
.dm tbody td{border-bottom:1px solid var(--line2);padding:1px 4px;vertical-align:middle;height:30px}
.dm tbody tr:hover td{background:#f7fafd}
.dm tr.og td{background:#eef3f8;padding:4px 8px;border-bottom:1px solid var(--line);height:auto}
.dm tr.og b{font-size:12.5px}.dm tr.og .m{color:var(--muted);font-size:12px;margin-left:8px}
.dm tr.og .add{float:right;border:0;background:none;color:var(--pri);font:600 12px var(--ff);cursor:pointer}
.dm td input,.dm td select{width:100%;height:26px;border:1px solid transparent;border-radius:3px;background:transparent;font:inherit;color:inherit;padding:0 6px;box-sizing:border-box}
.dm td input:hover,.dm td select:hover{border-color:var(--line)}
.dm td input:focus,.dm td select:focus{outline:none;border-color:var(--pri);background:#fff;box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.dm td input.num{text-align:right;font-variant-numeric:tabular-nums}
.dm td.calc{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap;padding-right:10px}
.dm td.calc small{font-weight:400;color:var(--muted);font-size:11px;margin-left:3px}
/* the ÷ toggle, as on Product Costing and Final Costing: on = one unit serves N pieces */
.dm .qw{display:flex;align-items:center;gap:3px}
.dm .shbtn{width:22px;height:22px;flex-shrink:0;border-radius:4px;border:1px solid #cbd5e3;background:#fff;color:#8a97ab;font-size:12px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0}
.dm .shbtn:hover{border-color:var(--pri);color:var(--pri)}
.dm .shbtn.on{background:#e1edf9;border-color:var(--pri);color:var(--pri);font-weight:700}
.dm .shbtn:disabled{cursor:default}
.dm tr.isshared td{background:#fbfaf3}
.dm .del{border:0;background:none;color:#a0a8b4;font-size:16px;cursor:pointer;padding:0 4px}.dm .del:hover{color:var(--bad)}
.dm .mcode{font-size:10.5px;color:var(--muted);font-family:ui-monospace,Menlo,Consolas,monospace;margin-left:6px;font-weight:400}
.dm tr.dirty td:first-child{box-shadow:inset 3px 0 0 var(--warn)}
.dm .ro td input,.dm .ro td select{pointer-events:none}
.dm .sum td{padding:4px 8px}
.dm .sum td.n{font-variant-numeric:tabular-nums}
.dm .sum tr.grp td{background:#eef3f8;font-weight:600;font-size:11.5px;color:var(--muted);height:auto;padding:3px 8px}
.dm .sum .todo{font-weight:700;color:var(--bad)}.dm .sum .done{color:var(--good);font-weight:600}
.dm .sum a{color:var(--pri);text-decoration:none;font-weight:600}
.dm .bar{position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;padding:8px 12px;background:var(--navy);color:#fff;border-radius:0 0 4px 4px}
.dm .bar[hidden]{display:none}
.dm .bar .b{border-color:rgba(255,255,255,.5);color:#fff;background:transparent}.dm .bar .b.pri{background:#fff;color:var(--navy);border-color:#fff}
.dm dialog{border:1px solid var(--line);border-radius:6px;padding:0;width:min(860px,96vw);box-shadow:0 12px 40px rgba(16,33,58,.25)}
.dm dialog::backdrop{background:rgba(16,33,58,.35)}
.dm dialog .dh{padding:12px 16px;border-bottom:1px solid var(--line);font-weight:600;font-size:15px}
.dm dialog .db{padding:12px 16px;max-height:60vh;overflow:auto}
.dm dialog .df{padding:10px 16px;border-top:1px solid var(--line);display:flex;gap:8px;justify-content:flex-end;align-items:center}
.dm dialog label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:3px}
.dm dialog .row{display:flex;gap:12px;margin-bottom:10px}.dm dialog .row>div{flex:1}
.dm dialog select,.dm dialog input[type=date]{width:100%;height:32px;border:1px solid var(--line);border-radius:4px;padding:0 8px;font:inherit}
.dm dialog td input{border-color:var(--line);background:#fff}
.dm .err{color:var(--bad);font-size:12.5px}
.dm .toast{position:fixed;right:20px;bottom:20px;background:var(--navy);color:#fff;padding:10px 14px;border-radius:4px;font-size:13px;z-index:50;box-shadow:0 6px 20px rgba(0,0,0,.25)}
.dm .toast a{color:#9fd0ff;font-weight:600}
@media print{.dm .acts,.dm .bar,.dm .add,.dm .del,.dm .sumck,.dm .tabs{display:none!important}.dm .scroll{max-height:none;overflow:visible}.dm #pane-lines,.dm #pane-buy{display:block!important}}
</style>
<div class="dm" id="dm">
  <div class="top">
    <div>
      <h1>Demand sheet · <?= e($pf['pi_no']) ?> <span class="pill" id="dm-status"></span></h1>
      <p class="lead"><?= e($pf['customer_name'] ?? '') ?> · <?= number_format($pcs) ?> pieces · made from each size's Product Costing, every line yours to amend</p>
    </div>
    <div class="acts">
      <a class="b" href="proforma.php?id=<?= (int) $pfId ?>">← Proforma</a>
      <?php if ($canEdit): ?><button type="button" class="b" id="dm-rebuild">Rebuild from costing</button><?php endif; ?>
      <?php if ($canEdit): ?><button type="button" class="b" id="dm-final"></button><?php endif; ?>
      <button type="button" class="b" onclick="window.print()">Print</button>
    </div>
  </div>
  <div id="dm-warn"></div>
  <div class="dmkpis" id="dm-tiles"></div>
  <section class="panel">
    <div class="tabs" role="tablist">
      <button type="button" role="tab" data-tab="lines" aria-selected="true">Lines<span class="c" id="dm-n"></span></button>
      <button type="button" role="tab" data-tab="buy" aria-selected="false">To buy<span class="c" id="dm-nb"></span></button>
      <span class="hint" id="dm-hint"></span>
    </div>
    <div id="pane-lines">
    <div class="scroll"><table id="dm-grid"><thead><tr>
      <th style="width:128px">Group</th><th style="min-width:200px">Item</th><th style="min-width:160px">Colour / size / design</th><th style="width:70px">Unit</th>
      <th class="n" style="width:120px" title="÷ on: one unit (carton, polybag) for this many pieces">Qty / pc</th><th class="n" style="width:86px">kg / unit</th><th class="n" style="width:70px">Extra %</th>
      <th class="n" style="width:110px">Need</th><th class="n" style="width:96px">kg</th><th style="width:28px"></th></tr></thead><tbody></tbody></table></div>
    <div class="bar" id="dm-savebar" hidden><b id="dm-dirty"></b><span class="grow" style="flex:1"></span>
      <button type="button" class="b" id="dm-undo">Undo changes</button><button type="button" class="b pri" id="dm-save">Save</button></div>
    </div>
    <div id="pane-buy" hidden>
    <div class="scroll"><table class="sum" id="dm-sum"><thead><tr>
      <th class="sumck" style="width:30px"><input type="checkbox" id="dm-all" aria-label="Tick every line still to order"></th><th>Item</th><th>Colour / size / design</th><th>Used in</th><th>Unit</th>
      <th class="n">Need</th><th class="n">kg</th><th class="n">On PO</th><th class="n">Received</th><th class="n">Still to order</th></tr></thead><tbody></tbody></table></div>
    <div class="bar" id="dm-pobar" hidden><b id="dm-picked"></b><span style="flex:1"></span><button type="button" class="b pri" id="dm-po">Create purchase order…</button></div>
    </div>
  </section>
  <dialog id="dm-dlg"><div class="dh">Create purchase order</div>
    <div class="db">
      <div class="row"><div><label for="dm-party">Supplier</label><select id="dm-party"><option value="">Choose…</option>
        <?php foreach ($parties as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name'] . ' (' . $p['code'] . ')') ?></option><?php endforeach; ?></select></div>
        <div style="flex:0 0 180px"><label for="dm-exp">Expected by</label><input type="date" id="dm-exp"></div></div>
      <table><thead><tr><th>Item</th><th class="n">Still to order</th><th class="n" style="width:130px">Order now</th><th style="width:70px">Unit</th><th class="n" style="width:110px">Rate (PKR)</th><th class="n">Amount</th></tr></thead><tbody id="dm-dlg-lines"></tbody></table>
      <p class="lead">A normal Purchase Contract for this order. Order less than the balance to buy in parts; Gate Inward receives against it as usual.</p>
      <p class="err" id="dm-dlg-err"></p>
    </div>
    <div class="df"><span class="n" id="dm-dlg-total" style="margin-right:auto;font-weight:600"></span><button type="button" class="b" id="dm-dlg-cancel">Cancel</button><button type="button" class="b pri" id="dm-dlg-ok">Create purchase order</button></div>
  </dialog>
</div>
<script>
(function(){
  var S = <?= json_encode($state) ?>;
  var MATS = <?= json_encode(array_map(fn($m) => ['id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'], 'uom' => $m['uom'], 'colour' => $m['colour'], 'rate' => (float) $m['std_rate']], $mats)) ?>;
  var CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>, CAN_PO = <?= $canPo ? 'true' : 'false' ?>, IS_ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>, PF = <?= (int) $pfId ?>;
  var FIRST = <?= json_encode($first) ?>;
  var GROUPS = ['Fabric', 'Accessories', 'Packing'];
  var rows = [], dirty = false, tick = {};
  var byId = {}; MATS.forEach(function(m){ byId[m.id] = m; });

  function $(id){ return document.getElementById(id); }
  var HINT = { lines: 'Tab / Enter to move · "1 per N pcs" for a carton or polybag that holds many pieces', buy: 'The same item and colour / size / design, added up across every size · tick lines to order' };
  function tab(t){
    Array.prototype.forEach.call(document.querySelectorAll('.dm [role=tab]'), function(b){ b.setAttribute('aria-selected', b.dataset.tab === t ? 'true' : 'false'); });
    $('pane-lines').hidden = t !== 'lines'; $('pane-buy').hidden = t !== 'buy'; $('dm-hint').textContent = HINT[t];
    try { localStorage.setItem('dm-tab', t); } catch (e) {}
  }
  document.querySelector('.dm .tabs').addEventListener('click', function(e){ var b = e.target.closest('[role=tab]'); if (b) tab(b.dataset.tab); });
  function el(t, a, kids){ var e = document.createElement(t); a = a || {}; for (var k in a) { if (a[k] === null || a[k] === undefined) continue; if (k === 'text') e.textContent = a[k]; else if (typeof a[k] === 'function') e.addEventListener(k, a[k]); else e.setAttribute(k, a[k]); } (kids || []).forEach(function(c){ if (c !== null && c !== undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }); return e; }
  function n(v){ v = String(v === null || v === undefined ? '' : v).replace(/,/g, ''); var x = parseFloat(v); return isNaN(x) ? 0 : x; }
  function fmt(v, d){ return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: d === undefined ? 2 : d }); }
  function toast(html){ var t = el('div', { class: 'toast' }); t.innerHTML = html; document.getElementById('dm').appendChild(t); setTimeout(function(){ t.remove(); }, 6000); }
  function post(action, data){
    var f = new FormData(); f.append('_csrf', CSRF); f.append('action', action); f.append('proforma', PF); f.append('data', JSON.stringify(data || {}));
    return fetch(location.pathname + '?proforma=' + PF, { method: 'POST', body: f, credentials: 'same-origin' }).then(function(r){ return r.json(); }).catch(function(){ return { ok: false, error: 'No answer from the server. Nothing was changed.' }; });
  }
  /* THE FORMULA — the same as dm_need() and dm_buy() in includes/demand.php.
     [to buy in the line's unit, kg, units]: a kg line is bought by its kg */
  function need(r){
    var f = 1 + Math.max(0, n(r.extra_pct)) / 100, q;
    if (+r.shared) q = n(r.per_pc) > 0 ? Math.ceil(+(n(r.pcs) / n(r.per_pc) * f).toFixed(6)) : 0;
    else q = Math.round(n(r.pcs) * n(r.per_pc) * f * 1000) / 1000;
    var kg = Math.round(q * n(r.kg_per) * 1000) / 1000;
    if (String(r.unit || '').trim().toLowerCase() === 'kg') { var b = n(r.kg_per) > 0 ? kg : q; return [b, b, q]; }
    return [q, kg, q];
  }
  /* under the figure: its unit, and for a kg line made of units, how many units it is */
  function needSub(r, c){ var u = r.unit || ''; return (u.toLowerCase() === 'kg' && +r.shared && c[2] !== c[0]) ? fmt(c[2], 0) + ' units × ' + fmt(n(r.kg_per), 3) + ' kg' : (+r.shared ? fmt(n(r.pcs), 0) + ' pcs ÷ ' + fmt(n(r.per_pc), 0) + ' a unit, rounded up' : fmt(n(r.pcs), 0) + ' pcs × ' + fmt(n(r.per_pc), 4)); }
  var ro = function(){ return !CAN_EDIT || S.status === 'final'; };

  /* -------------------------------------------------------------- grid */
  var COLS = ['line_group', 'item_name', 'spec', 'unit', 'per_pc', 'shared', 'kg_per', 'extra_pct'];   // shared: the ÷ button
  function drawGrid(){
    var tb = $('dm-grid').tBodies[0]; tb.textContent = '';
    $('dm-grid').classList.toggle('ro', ro());
    var lastKey = null, nLines = 0;
    rows.forEach(function(r, i){
      var key = (r.proforma_item_id || '') + '|' + r.product + '|' + r.size;
      if (key !== lastKey) {
        lastKey = key;
        tb.appendChild(el('tr', { class: 'og' }, [el('td', { colspan: '10' }, [el('b', { text: (r.product || 'Order line') + (r.size ? ' · ' + r.size : '') }), el('span', { class: 'm', text: fmt(r.pcs, 0) + ' pcs' }),
          ro() ? null : el('button', { type: 'button', class: 'add', text: '+ line', click: function(){ addLine(i); } })])]));
      }
      nLines++;
      tb.appendChild(lineRow(r, i));
    });
    $('dm-n').textContent = String(nLines);
  }
  function inp(r, k, cls, extra){
    var a = { value: r[k] === null || r[k] === undefined ? '' : String(r[k]), 'data-k': k, class: cls || null };
    for (var x in (extra || {})) a[x] = extra[x];
    if (ro()) a.readonly = 'readonly';
    return el('input', a);
  }
  function lineRow(r, i){
    var calc = need(r);
    var g = el('select', { 'data-k': 'line_group' }, GROUPS.map(function(x){ var o = el('option', { value: x, text: x }); if (r.line_group === x) o.selected = true; return o; }));
    var sh = el('button', { type: 'button', class: 'shbtn' + (+r.shared ? ' on' : ''), tabindex: '-1', 'data-sh': '1', text: '÷',
      title: +r.shared ? 'Shared: one unit for this many pieces — need = pieces ÷ this, rounded up. Click for per piece.' : 'Per piece: need = pieces × this. Click ÷ when one carton or polybag serves many pieces.' });
    if (ro()) sh.disabled = true;
    var item = inp(r, 'item_name', null, { list: 'dm-mats', placeholder: 'item or material', title: r.material_code ? 'Material ' + r.material_code : 'Not linked to a material' });
    var tr = el('tr', { 'data-i': String(i), class: +r.shared ? 'isshared' : null }, [
      el('td', {}, [g]),
      el('td', {}, [item]),
      el('td', {}, [inp(r, 'spec', null, { placeholder: 'white, 200 TC, logo…' })]),
      el('td', {}, [inp(r, 'unit', null, { placeholder: 'kg' })]),
      el('td', {}, [el('div', { class: 'qw' }, [inp(r, 'per_pc', 'num', { inputmode: 'decimal', title: +r.shared ? 'Pieces one unit holds' : 'Quantity one piece needs' }), sh])]),
      el('td', {}, [inp(r, 'kg_per', 'num', { inputmode: 'decimal', title: 'kg of one unit' })]),
      el('td', {}, [inp(r, 'extra_pct', 'num', { inputmode: 'decimal' })]),
      el('td', { class: 'calc', 'data-need': '1', title: needSub(r, calc) }, [fmt(calc[0], 3), el('small', { text: r.unit || '' })]),
      el('td', { class: 'calc', 'data-kg': '1' }, [calc[1] ? fmt(calc[1], 2) : '—']),
      el('td', {}, [ro() ? null : el('button', { type: 'button', class: 'del', title: 'Remove this line', text: '×', click: function(){ rows.splice(i, 1); mark(); drawGrid(); } })])
    ]);
    if (r._dirty) tr.classList.add('dirty');
    return tr;
  }
  function addLine(i){
    var r = rows[i];
    rows.splice(i + 1, 0, { id: null, proforma_item_id: r.proforma_item_id, product: r.product, size: r.size, pcs: r.pcs, line_group: 'Accessories', material_id: null, item_name: '', spec: '', unit: 'pc', per_pc: 1, shared: 0, kg_per: 0, extra_pct: 0, _dirty: true });
    mark(); drawGrid();
    var tr = $('dm-grid').querySelector('tr[data-i="' + (i + 1) + '"] input[data-k=item_name]'); if (tr) tr.focus();
  }
  function mark(){ dirty = true; var k = rows.filter(function(r){ return r._dirty; }).length; $('dm-savebar').hidden = false; $('dm-dirty').textContent = 'Unsaved changes' + (k ? ' · ' + k + ' line' + (k === 1 ? '' : 's') : ''); }
  /* one listener for the whole grid: edit, recalculate the row, move with Enter */
  $('dm-grid').addEventListener('input', function(e){
    var t = e.target, tr = t.closest('tr[data-i]'); if (!tr || ro()) return;
    var r = rows[+tr.dataset.i], k = t.dataset.k;
    if (k === 'item_name') {
      r.item_name = t.value;
      var m = MATS.filter(function(x){ return (x.code + ' · ' + x.name) === t.value || x.name === t.value; })[0];
      r.material_id = m ? m.id : null;
      if (m) { r.item_name = m.name; if (!r.unit) r.unit = m.uom; if (!r.spec && m.colour) r.spec = m.colour; r.material_code = m.code; }
      else r.material_code = null;
    }
    else r[k] = t.value;
    r._dirty = true; mark();
    if (k === 'line_group' || (k === 'item_name' && r.material_id)) { var pos = k; drawGrid(); var f = $('dm-grid').querySelector('tr[data-i="' + tr.dataset.i + '"] [data-k="' + pos + '"]'); if (f) f.focus(); return; }
    var c = need(r);
    tr.classList.add('dirty');
    tr.querySelector('[data-need]').firstChild.nodeValue = fmt(c[0], 3);
    tr.querySelector('[data-need] small').textContent = r.unit || ''; tr.querySelector('[data-need]').title = needSub(r, c);
    tr.querySelector('[data-kg]').textContent = c[1] ? fmt(c[1], 2) : '—';
    drawTiles();
  });
  $('dm-grid').addEventListener('change', function(e){ if (e.target.tagName === 'SELECT') e.target.dispatchEvent(new Event('input', { bubbles: true })); });
  /* ÷ — the same need either way: 0.0833 a piece is 1 per 12 pcs, and back */
  function flip(x){ var v = 1 / x, r = Math.round(v); return Math.abs(v - r) < 0.02 ? r : Math.round(v * 10000) / 10000; }
  $('dm-grid').addEventListener('click', function(e){
    var b = e.target.closest('[data-sh]'); if (!b || ro()) return;
    var i = +b.closest('tr[data-i]').dataset.i, r = rows[i], was = n(r.per_pc);
    r.shared = +r.shared ? 0 : 1;
    r.per_pc = was > 0 ? flip(was) : (r.shared ? 12 : 0);
    r._dirty = true; mark(); drawGrid(); drawTiles();
    var f = $('dm-grid').querySelector('tr[data-i="' + i + '"] [data-k=per_pc]'); if (f) { f.focus(); f.select(); }
  });
  $('dm-grid').addEventListener('keydown', function(e){
    var t = e.target; if (!t.dataset || !t.dataset.k) return;
    var down = e.key === 'Enter' || e.key === 'ArrowDown', up = e.key === 'ArrowUp';
    if (!down && !up) return;
    if (t.tagName === 'SELECT' && e.key !== 'Enter') return;
    e.preventDefault();
    var tr = t.closest('tr[data-i]'), i = +tr.dataset.i + (up ? -1 : 1);
    var nx = $('dm-grid').querySelector('tr[data-i="' + i + '"] [data-k="' + t.dataset.k + '"]');
    if (nx) { nx.focus(); if (nx.select) nx.select(); }
  });

  /* ------------------------------------------------------------ summary */
  function drawSummary(){
    var tb = $('dm-sum').tBodies[0]; tb.textContent = '';
    var grp = null;
    S.summary.forEach(function(x){
      if (x.line_group !== grp) { grp = x.line_group; tb.appendChild(el('tr', { class: 'grp' }, [el('td', { colspan: '10', text: grp })])); }
      var cb = el('input', { type: 'checkbox', 'aria-label': 'Tick ' + x.item_name, click: function(){ tick[x.key] = cb.checked; paintPo(); } });
      cb.checked = !!tick[x.key]; if (!CAN_PO || S.status === null) cb.disabled = true;
      var pos = x.pos.length ? x.pos.map(function(p){ return el('a', { href: 'inv_contracts.php?edit=' + p.id, text: p.no, title: 'Open the purchase contract' }); }) : [];
      var poCell = el('td', { class: 'n' }, [fmt(x.ordered, 3)].concat(pos.length ? [el('div', { style: 'font-size:11px' }, pos.reduce(function(a, p, i){ if (i) a.push(', '); a.push(p); return a; }, []))] : []));
      tb.appendChild(el('tr', {}, [
        el('td', { class: 'sumck' }, [cb]),
        el('td', {}, [el('b', { text: x.item_name }), x.material_code ? el('span', { class: 'mcode', text: x.material_code }) : null]),
        el('td', { text: x.spec || '—' }),
        el('td', { title: x.sizes.join('\n'), text: x.sizes.length + (x.sizes.length === 1 ? ' size' : ' sizes') }),
        el('td', { text: x.unit || '' }),
        el('td', { class: 'n', text: fmt(x.need, 3) }),
        el('td', { class: 'n', text: x.kg ? fmt(x.kg, 2) : '—' }),
        poCell,
        el('td', { class: 'n', text: fmt(x.received, 3) }),
        el('td', { class: 'n' }, [x.to_order > 0 ? el('span', { class: 'todo', text: fmt(x.to_order, 3) }) : el('span', { class: 'done', text: 'ordered' })])
      ]));
    });
    $('dm-nb').textContent = String(S.summary.length);
    if (!S.summary.length) tb.appendChild(el('tr', {}, [el('td', { colspan: '10', class: 'lead', text: 'Nothing to buy yet — no line has an item.' })]));
    paintPo();
  }
  function paintPo(){
    var k = Object.keys(tick).filter(function(x){ return tick[x]; });
    $('dm-pobar').hidden = !k.length;
    $('dm-picked').textContent = k.length + (k.length === 1 ? ' item ticked' : ' items ticked');
  }
  $('dm-all').addEventListener('change', function(){ var on = this.checked; S.summary.forEach(function(x){ tick[x.key] = on && x.to_order > 0; }); drawSummary(); });

  /* ------------------------------------------------------- tiles, state */
  function drawTiles(){
    var t = { lines: rows.length, kgF: 0, kgA: 0, kgP: 0 };
    rows.forEach(function(r){ var c = need(r); if (r.line_group === 'Fabric') t.kgF += c[1]; else if (r.line_group === 'Packing') t.kgP += c[1]; else t.kgA += c[1]; });
    var todo = S.summary.filter(function(x){ return x.to_order > 0; }).length;
    var pos = {}; S.summary.forEach(function(x){ x.pos.forEach(function(p){ pos[p.id] = 1; }); });
    var tl = $('dm-tiles'); tl.textContent = '';
    [['Fabric', fmt(t.kgF, 1), 'kg'], ['Packing', fmt(t.kgP, 1), 'kg'], ['Accessories', fmt(t.kgA, 1), 'kg'],
     ['Items to buy', String(S.summary.length), ''], ['Still to order', String(todo), todo ? 'items' : '', todo ? 'hot' : 'ok'], ['Purchase orders', String(Object.keys(pos).length), '']]
      .forEach(function(x){ tl.appendChild(el('div', { class: 'dmk ' + (x[3] || '') }, [el('span', { class: 'k', text: x[0] }), el('b', {}, [x[1], x[2] ? el('small', { text: x[2] }) : null])])); });
  }
  function drawHead(){
    var st = $('dm-status'); st.className = 'pill ' + (S.status || 'draft'); st.textContent = S.status === 'final' ? 'Final plan' : 'Draft';
    var fb = $('dm-final');
    if (fb) { fb.textContent = S.status === 'final' ? 'Reopen' : 'Lock as final plan'; fb.disabled = S.status === 'final' && !IS_ADMIN; }
    var rb = $('dm-rebuild'); if (rb) rb.disabled = S.status === 'final';
    var w = $('dm-warn'); w.textContent = '';
    var miss = rows.filter(function(r){ return !r.item_name && !r.material_id; });
    if (FIRST && FIRST.missing && FIRST.missing.length) w.appendChild(el('div', { class: 'warn', text: 'No costing found for: ' + FIRST.missing.join(', ') + '. Their lines are empty — fill them in here, or make the costing in Product Costing and press Rebuild.' }));
    else if (miss.length) w.appendChild(el('div', { class: 'warn', text: miss.length + ' line' + (miss.length === 1 ? ' has' : 's have') + ' no item yet (an order line without a costing). Fill them in, or make the costing and press Rebuild.' }));
  }
  function load(sheet){ S = sheet; rows = S.lines.map(function(r){ return Object.assign({}, r); }); dirty = false; $('dm-savebar').hidden = true; drawHead(); drawGrid(); drawSummary(); drawTiles(); }
  window.addEventListener('beforeunload', function(e){ if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  $('dm-save').addEventListener('click', function(){
    var b = this; b.disabled = true; b.textContent = 'Saving…';
    post('save', { rows: rows.map(function(r){ var o = {}; ['id', 'proforma_item_id', 'product', 'size', 'pcs', 'material_id'].concat(COLS).forEach(function(k){ o[k] = r[k]; }); return o; }) }).then(function(x){
      b.disabled = false; b.textContent = 'Save';
      if (!x.ok) return alert(x.error);
      load(x.sheet); toast('Saved.');
    });
  });
  $('dm-undo').addEventListener('click', function(){ load(S); });
  var rb = $('dm-rebuild');
  if (rb) rb.addEventListener('click', function(){
    if (!confirm('Make every line again from the costings? Your changes to the lines are replaced. Purchase orders already made are not touched.')) return;
    post('build').then(function(x){ if (!x.ok) return alert(x.error); FIRST = x; load(x.sheet); toast('Made again from the costings.'); });
  });
  var fb = $('dm-final');
  if (fb) fb.addEventListener('click', function(){
    if (dirty) return alert('Save your changes first.');
    var to = S.status === 'final' ? 'draft' : 'final';
    if (to === 'final' && !confirm('Lock this demand as the final plan? The lines can then only be changed after an administrator reopens it.')) return;
    post('status', { to: to }).then(function(x){ if (!x.ok) return alert(x.error); load(x.sheet); toast(to === 'final' ? 'Locked as the final plan.' : 'Reopened.'); });
  });

  /* -------------------------------------------------- purchase order */
  var dlg = $('dm-dlg');
  $('dm-po').addEventListener('click', function(){
    if (dirty) return alert('Save your changes first, so the order is made from the sheet as it is.');
    var tb = $('dm-dlg-lines'); tb.textContent = ''; $('dm-dlg-err').textContent = '';
    S.summary.filter(function(x){ return tick[x.key]; }).forEach(function(x){
      var rate = x.material_id && byId[x.material_id] ? byId[x.material_id].rate : 0;
      var q = el('input', { class: 'num', inputmode: 'decimal', value: String(x.to_order > 0 ? x.to_order : 0), 'data-key': x.key });
      var rt = el('input', { class: 'num', inputmode: 'decimal', value: rate ? String(rate) : '', 'data-rate': x.key, placeholder: '0' });
      var amt = el('td', { class: 'n', 'data-amt': x.key });
      tb.appendChild(el('tr', {}, [el('td', {}, [el('b', { text: x.item_name }), x.spec ? el('div', { class: 'lead', text: x.spec }) : null]), el('td', { class: 'n', text: fmt(x.to_order, 3) }), el('td', {}, [q]), el('td', { text: x.unit || '' }), el('td', {}, [rt]), amt]));
    });
    sumDlg(); dlg.showModal();
  });
  function sumDlg(){
    var tot = 0;
    Array.prototype.forEach.call($('dm-dlg-lines').querySelectorAll('tr'), function(tr){
      var q = n(tr.querySelector('[data-key]').value), r = n(tr.querySelector('[data-rate]').value), a = q * r; tot += a;
      tr.querySelector('[data-amt]').textContent = a ? fmt(a, 2) : '—';
    });
    $('dm-dlg-total').textContent = tot ? 'Total Rs ' + fmt(tot, 2) : '';
  }
  $('dm-dlg-lines').addEventListener('input', sumDlg);
  $('dm-dlg-cancel').addEventListener('click', function(){ dlg.close(); });
  $('dm-dlg-ok').addEventListener('click', function(){
    var b = this, lines = Array.prototype.map.call($('dm-dlg-lines').querySelectorAll('tr'), function(tr){ var q = tr.querySelector('[data-key]'); return { key: q.dataset.key, qty: n(q.value), rate: n(tr.querySelector('[data-rate]').value) }; });
    b.disabled = true;
    post('po', { party_id: +$('dm-party').value, expected: $('dm-exp').value, lines: lines }).then(function(x){
      b.disabled = false;
      if (!x.ok) { $('dm-dlg-err').textContent = x.error; return; }
      dlg.close(); tick = {}; $('dm-all').checked = false; load(x.sheet); tab('buy');
      toast('Purchase order <a href="inv_contracts.php?edit=' + x.contract_id + '">' + x.contract_no + '</a> made. Gate Inward can now receive against it.');
    });
  });

  /* materials for the item box */
  var dl = el('datalist', { id: 'dm-mats' }); MATS.forEach(function(m){ dl.appendChild(el('option', { value: m.code + ' · ' + m.name })); }); document.body.appendChild(dl);
  load(S);
  var t0 = 'lines'; try { t0 = localStorage.getItem('dm-tab') || 'lines'; } catch (e) {}
  tab(t0 === 'buy' ? 'buy' : 'lines');
})();
</script>
<?php page_footer(); ?>
