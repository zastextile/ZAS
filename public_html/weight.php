<?php
/*
  WEIGHT — profiles, and the weight of each shipment.

  One page for the desktop and the phone, built from afnan's Weight
  Profiles demo:
    Profile entry — materials per finished unit: Fabric, PVC / Polybag,
                    Polyester, Accessories, Miscellaneous, in whole grams;
                    an optional unit target they must add up to; or a
                    weighed sample carton that sets the target.
    Saved         — every profile with its WP code; update, duplicate,
                    take out of use; a live calculator for any quantity.
    Shipment      — one profile per invoice line; the packed quantity comes
                    from packing; net, gross, and Apply to the packing list.

  The rules live in includes/weight.php; this page asks them and draws.
  It never reloads: every change is one small request and the answer
  carries the whole state back, the same way Smart Packing works.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/packing.php';
require_once __DIR__ . '/includes/weight.php';
require_once __DIR__ . '/includes/mobile.php';

/* Uploaded by hand, so say plainly when a file was missed. */
if (function_exists('mob_needs')) {
    mob_show_fatal();
    mob_needs(['mob_header', 'mob_footer', 'mob_card_error'], 'includes/mobile.php');
    mob_needs(['pack_groups', 'pack_may_use', 'pack_product_key', 'pack_ensure_schema'], 'includes/packing.php');
    mob_needs(['wt_ensure_schema', 'wt_profiles', 'wt_profile_save', 'wt_calc', 'wt_apply', 'wt_spread'], 'includes/weight.php');
} else {
    http_response_code(500);
    exit('Upload includes/mobile.php — the copy on the server is older than this screen.');
}
require_login();
if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot open weight.'); }
if (!wt_may_use()) { http_response_code(403); exit('Weight is for the people who work on packing lists.'); }
exp_ensure_schema();
pack_ensure_schema();
wt_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$self = 'weight.php' . ($id > 0 ? '?id=' . $id : '');
$shipment = null; $items = []; $canEdit = false;
if ($id > 0) {
    $s = db()->prepare("SELECT * FROM shipments WHERE id=?");
    $s->execute([$id]);
    $shipment = $s->fetch() ?: null;
    if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }
    $ids = assigned_shipment_ids();
    if ($ids !== ['ALL'] && !in_array($id, $ids, true)) { http_response_code(403); exit('That shipment is not assigned to you.'); }
    $canEdit = wt_may_edit($shipment);
    $it = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
    $it->execute([$id]);
    $items = $it->fetchAll();
    /* links cut off by an old invoice save are mended before anything is counted */
    if (function_exists('pack_relink_orphans')) pack_relink_orphans($id, $items);
    /* the packing rows match the phone, and the weight follows, before it is shown */
    if (function_exists('pack_sync_if_stale')) pack_sync_if_stale($id);
    wt_refresh_if_stale($id);
}

/* Everything the screen draws from. */
function wt_page_state(int $id, ?array $shipment, array $items, bool $canEdit): array
{
    $st = ['profiles' => wt_profiles(), 'types' => WT_TYPES, 'units' => WT_UNITS, 'pkgTypes' => WT_PKG_TYPES,
           'tolerance' => WT_TOLERANCE_PCT, 'shipment' => null, 'calc' => null, 'canEditShip' => $canEdit, 'shipments' => []];
    if ($shipment) {
        $c = wt_calc($id, $items);
        unset($c['profiles']);
        $st['shipment'] = ['id' => $id, 'invoice' => (string)$shipment['invoice_no'], 'buyer' => (string)($shipment['buyer_name'] ?? '')];
        $st['calc'] = $c;
    } else {
        $ids = assigned_shipment_ids();
        try {
            if ($ids === ['ALL']) $rows = db()->query("SELECT id, invoice_no, buyer_name FROM shipments ORDER BY id DESC LIMIT 60")->fetchAll();
            elseif ($ids) {
                $q = db()->prepare("SELECT id, invoice_no, buyer_name FROM shipments WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY id DESC");
                $q->execute($ids); $rows = $q->fetchAll();
            } else $rows = [];
        } catch (Throwable $e) { $rows = []; }
        $st['shipments'] = array_map(static fn($r) => ['id' => (int)$r['id'], 'invoice' => (string)$r['invoice_no'], 'buyer' => (string)$r['buyer_name']], $rows);
    }
    return $st;
}

/* ============================================================== the API */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $_POST['_csrf'] = (string)($in['_csrf'] ?? '');
    verify_csrf();
    $answer = function (bool $ok, string $msg, array $extra = []) use ($id, $items): void {
        $sh = null;
        if ($id > 0) { $s = db()->prepare("SELECT * FROM shipments WHERE id=?"); $s->execute([$id]); $sh = $s->fetch() ?: null; }
        echo json_encode(['ok' => $ok, 'msg' => $msg, 'state' => wt_page_state($id, $sh, $items, $sh ? wt_may_edit($sh) : false)] + $extra);
        exit;
    };
    $op = (string)($in['op'] ?? '');
    try {
        switch ($op) {
            case 'profile_save':
                [$ok, $msg, $p] = wt_profile_save((array)($in['profile'] ?? []));
                $answer($ok, $msg, ['profile' => $p]);
            case 'profile_delete':
                [$ok, $msg] = wt_profile_delete((int)($in['profileId'] ?? 0));
                $answer($ok, $msg);
            case 'profile_active':
                [$ok, $msg] = wt_profile_set_active((int)($in['profileId'] ?? 0), !empty($in['active']));
                $answer($ok, $msg);
        }
        /* the rest change a shipment */
        if (!$shipment) $answer(false, 'Open a shipment first.');
        if (!$canEdit) { http_response_code(403); $answer(false, 'This shipment is locked.'); }
        switch ($op) {
            case 'link':   [$ok, $msg] = wt_link($id, $items, (int)($in['itemId'] ?? 0), (int)($in['profileId'] ?? 0)); $answer($ok, $msg);
            case 'unlink': [$ok, $msg] = wt_unlink($id, (int)($in['itemId'] ?? 0)); $answer($ok, $msg);
            case 'tare':   [$ok, $msg] = wt_tare_save($id, (string)($in['type'] ?? ''), (string)($in['kg'] ?? '')); $answer($ok, $msg);
            case 'apply':  [$ok, $msg] = wt_apply($id, $items, $in['finalNet'] ?? null, $in['finalGross'] ?? null); $answer($ok, $msg);
        }
    } catch (Throwable $e) {
        error_log('weight api ' . $op . ': ' . $e->getMessage());
        $answer(false, 'That did not save — the server hit a problem. Nothing was changed.');
    }
    $answer(false, 'Unknown request.');
}

/* =========================================================== the screen */
try {
    $state = wt_page_state($id, $shipment, $items, $canEdit);
    $csrfTok = csrf_token();
} catch (Throwable $e) {
    mob_header('Weight', 'm.php', '', 'manifest_pack.json');
    mob_card_error('The weight screen', $e);
    mob_footer();
    exit;
}
mob_header($shipment ? 'Weight — ' . (string)$shipment['invoice_no'] : 'Weight profiles',
           $shipment ? 'm_pack.php?id=' . $id : 'm.php',
           $shipment ? (string)($shipment['buyer_name'] ?? '') : 'Weight of one finished unit', 'manifest_pack.json');
?>
<div id="wp" class="wp">
  <nav class="wtabs" aria-label="Weight">
    <button type="button" data-a="tab" data-tab="entry">Profile entry</button>
    <button type="button" data-a="tab" data-tab="saved">Saved <span id="wCount"></span></button>
    <button type="button" data-a="tab" data-tab="link">Shipment weight</button>
  </nav>
  <main id="wMain"></main>
  <div id="wDock"></div>
</div>
<div id="wToast" class="wtoast" role="status" aria-live="polite" hidden></div>
<div id="wModal"></div>

<style>
/* Scoped under #wp, so the phone shell's own .card/.pill/.row stay as they are.
   Colours and shapes are afnan's Weight Profiles demo's. */
.wp{--ink:#183537;--muted:#64797b;--teal:#087f72;--dark:#123b3a;--mint:#e4f4ef;--line2:#dce7e4;--red:#b73b3b;
  color:var(--ink);padding-bottom:190px;max-width:100%;overflow-x:clip}
.wp button,.wp input,.wp select{font:inherit;min-height:46px;border:1px solid var(--line2);border-radius:11px;color:var(--ink);background:#fff}
.wp button{padding:9px 13px;font-weight:650;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.wp button:disabled{opacity:.45;cursor:default}
.wp input,.wp select{width:100%;padding:10px 12px;border-color:#cbdad6;font-size:16px}
.wp label{display:block;font-size:12px;font-weight:650;color:var(--muted);margin-bottom:5px}
.wp h2{font-size:20px;letter-spacing:-.5px;margin:0}.wp h3{font-size:15px;margin:0}
.wp .small,.wp .hint{font-size:11.5px;color:var(--muted)}.wp .hint{margin-top:7px}
.wp .row{display:flex;align-items:center;gap:8px;padding:0;margin:0;border:0}.wp .between{justify-content:space-between}
.wp .grow{flex:1;min-width:0}.wp .gap{margin-top:14px}
.wp .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.wp .card{border:1px solid var(--line2);border-radius:16px;background:#fff;padding:15px;margin-bottom:13px}
.wp .primary{background:var(--teal);color:#fff;border-color:var(--teal)}
.wp .soft{background:var(--mint);color:var(--teal);border-color:#beded3}
.wp .quiet{background:transparent;border-color:transparent}.wp .danger{color:var(--red)}
.wp .mini{font-size:12px;min-height:40px}.wp .full{width:100%}
.wp .pill{display:inline-flex;white-space:nowrap;align-items:center;font-size:11px;font-weight:750;color:var(--teal);background:var(--mint);padding:5px 8px;border-radius:18px}
.wp .pill.warn{color:#8b5b0a;background:#fff4df}.wp .pill.bad{color:var(--red);background:#fff1ed}.wp .pill.gray{color:var(--muted);background:#edf1f0}
.wp .eyebrow{font-size:10px;letter-spacing:1.2px;font-weight:800;color:var(--muted)}
.wp .notice{background:#eef5f2;padding:11px 12px;border-radius:10px;font-size:12.5px;color:#456563}
.wp .notice.warn{background:#fff4df;color:#8b5b0a}
.wp .error{color:var(--red);font-size:12.5px}.wp .error:empty{display:none}
.wp .num{font-variant-numeric:tabular-nums}
.wp .big{font-size:26px;font-weight:750;min-height:59px}
.wp details{border-top:1px solid var(--line2);padding-top:9px;margin-top:12px}
.wp summary{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:36px;cursor:pointer;list-style:none;font-size:12.5px;font-weight:650;color:var(--muted)}
.wp summary::-webkit-details-marker{display:none}.wp summary:after{content:'+';font-size:20px}.wp details[open]>summary:after{content:'−'}
.wp .typepick{border:0;background:transparent;font-size:24px;letter-spacing:-.6px;padding:4px 0;min-height:40px}
.wp .chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.wp .chips button{flex-grow:1;font-size:12px}
.wp .chips .on{background:var(--mint);border-color:var(--teal)}
.wp .balance{display:flex;justify-content:space-between;gap:8px;border:1px solid #b9ded0;border-radius:12px;background:var(--mint);padding:10px 12px;margin:12px 0;font-size:11.5px}
.wp .balance strong{font-size:23px;display:block;line-height:1.3;color:var(--teal)}.wp .balance .right{text-align:right}
.wp .balance.over{background:#fff1ed;border-color:#eec8bd}.wp .balance.over strong{color:var(--red)}
.wp .mrow{display:flex;gap:7px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line2);font-size:12.5px}
.wp .mrow button{padding:7px 10px}
.wp .cat{display:flex;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid var(--line2);font-size:12.5px}
.wp .pcard{border:1px solid var(--line2);border-radius:14px;background:#fff;margin:0 0 10px;padding:10px 13px}
.wp .pcard>summary{color:var(--ink);min-height:48px}
.wp .pname{font-size:14px;font-weight:750;overflow-wrap:anywhere}.wp .pcode{font-size:10.5px;color:var(--muted);margin-top:3px}
.wp .acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.wp .acts button{flex:1}
.wp .calc{background:var(--mint);border-radius:12px;padding:12px;margin-top:12px}
.wp .tw{font-size:25px;letter-spacing:-.5px;color:var(--teal);font-weight:800}
.wp .search{margin:12px 0}
.wp .empty{padding:25px;text-align:center;color:var(--muted)}
.wp .sug{display:flex;gap:8px;align-items:center;background:#fffbea;border:1px solid #f1d58a;border-radius:11px;padding:9px 11px;margin-top:10px;font-size:12.5px}
.wtabs{display:flex;gap:6px;margin:0 0 14px}
.wtabs button{flex:1;min-width:0;border:1px solid #dce7e4;background:#fff;color:#64797b;font-size:13px;min-height:46px;border-radius:11px;font-weight:650;padding:8px 4px}
.wtabs button.on{background:#123b3a;color:#fff;border-color:#123b3a}
.wdock{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:calc(100% - 24px);max-width:600px;z-index:25;background:#fff;
  border:1px solid #dce7e4;border-radius:16px 16px 0 0;box-shadow:0 -7px 24px #153e3410;padding:10px 13px max(10px,env(safe-area-inset-bottom));color:#183537}
.wdock .cnt{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:8px;font-size:12px;margin-bottom:8px}
.wdock .cnt label{font-size:10px;margin-bottom:2px;display:block;color:#64797b;font-weight:650}.wdock .cnt strong{font-size:13px;font-variant-numeric:tabular-nums}
.wdock .prog{height:4px;border-radius:5px;background:#e9f0ed;overflow:hidden;margin-bottom:9px}.wdock .prog i{display:block;height:100%;background:#087f72;transition:width .12s}
.wp .save{width:100%;position:relative;overflow:hidden;min-height:61px;padding:9px 12px;color:#fff;font-weight:800;
  background:linear-gradient(115deg,#08796b,#159681);border:1px solid #08796b;border-radius:12px;box-shadow:0 3px 12px #08847026}
.wp .save .t{display:block;font-size:14px}.wp .save .c{display:block;font-size:11.5px;font-weight:550;margin-top:3px;overflow-wrap:anywhere}
.wp .save.ok{background:linear-gradient(110deg,#087d68,#19a98d,#158a89)}
.wtoast{position:fixed;bottom:185px;left:50%;transform:translateX(-50%);z-index:80;width:calc(100% - 40px);max-width:580px;border-radius:12px;padding:11px 14px;background:#123b3a;color:#fff;font-size:13px;box-shadow:0 8px 28px #143c3622}
.wtoast.err{background:#a83737}
.wback{position:fixed;inset:0;z-index:70;background:#123b3a75;display:flex;align-items:center;justify-content:center;padding:18px}
.wmodal{width:100%;max-width:560px;max-height:85dvh;overflow:auto;border-radius:16px;background:#fff;padding:18px;color:#183537}
.wmodal h2{font-size:18px;margin:0}.wmodal .foot{display:flex;gap:7px;margin-top:15px}.wmodal .foot button{flex:1}
.wmodal button,.wmodal input{font:inherit;min-height:44px;border:1px solid #dce7e4;border-radius:11px;background:#fff;color:#183537;padding:9px 12px}
.wmodal input{width:100%;font-size:16px}.wmodal .primary{background:#087f72;color:#fff;border-color:#087f72}
.wmodal table{width:100%;border-collapse:collapse;font-size:12px}.wmodal td,.wmodal th{padding:7px 4px;border-top:1px solid #dce7e4;text-align:left;vertical-align:top}
.wmodal th{font-size:10px;color:#64797b;border-top:0}.wmodal .n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
@media(max-width:370px){.wtabs button{font-size:11.5px}.wp .balance strong{font-size:20px}}
</style>

<script>
(function () {
"use strict";
var S = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var CSRF = <?= json_encode($csrfTok) ?>;
var API = <?= json_encode($self . ($id > 0 ? '&' : '?') . 'api=1') ?>;
var DKEY = 'zas-weight-draft';
var tab = S.shipment ? 'link' : 'entry';
if (/^#(entry|saved|link)$/.test(location.hash)) tab = location.hash.slice(1);
var query = '', showOff = false, expanded = {}, calcQ = {}, receipt = null, receiptT, toastT, busy = false, openLine = null, pendingLine = null;

var $ = function (q, r) { return (r || document).querySelector(q); };
var esc = function (x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
var fmt = function (n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 3 }); };
var k3 = function (n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); };
var kg = function (g) { return (Number(g) / 1000).toFixed(3); };
var prof = function (id) { return S.profiles.find(function (p) { return p.id === +id; }); };
var unitWord = function (u) { return u === 'sets' ? 'set' : u === 'pcs' ? 'piece' : 'unit'; };

function toast(m, err) { var t = $('#wToast'); t.textContent = m; t.classList.toggle('err', !!err); t.hidden = false; clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, 3400); }
function api(body) {
  body._csrf = CSRF;
  return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) })
    .then(function (r) { return r.json().catch(function () { throw Error('The server did not answer properly (' + r.status + ').'); }); })
    .then(function (j) { if (j.state) S = j.state; if (!j.ok) throw Error(j.msg || 'That did not save.'); return j; });
}

/* ---------------- the draft profile, kept in this browser until saved */
function blankDraft() { return { id: null, name: '', unit: 'pcs', notes: '', target: '', sampleQty: '10', sampleGross: '', sampleNet: '', samplePkgType: 'Carton',
  materials: [{ category: S.types[0], description: '', grams: '' }], cursor: 0, showTypes: false, showDesc: false, readingOpen: false, reviewOpen: false, settingsOpen: false }; }
var draft;
try { draft = JSON.parse(localStorage.getItem(DKEY) || 'null'); } catch (e) { draft = null; }
if (!draft || !Array.isArray(draft.materials) || !draft.materials.length) draft = blankDraft();
draft.cursor = Math.max(0, Math.min(draft.cursor || 0, draft.materials.length - 1));
function keep() { try { localStorage.setItem(DKEY, JSON.stringify(draft)); } catch (e) {} }

/* ---------------- the same checks as the demo, and as the server */
function grams(v, label) { var t = String(v == null ? '' : v).trim(); if (!t) return null; if (!/^\d+$/.test(t)) throw Error(label + ': enter whole grams, without commas or decimals.'); var n = Number(t); if (n > 100000000) throw Error(label + ': weight is too large.'); return n; }
function kgIn(v, label) { var t = String(v == null ? '' : v).trim(); if (!t) return null; if (!/^\d*\.?\d{0,3}$/.test(t) || !/\d/.test(t) || Number(t) <= 0 || Number(t) > 100000) throw Error(label + ': enter a positive kg value with up to 3 decimals.'); return Number(t); }
function matTotal() { return draft.materials.reduce(function (s, l) { return s + Number(l.grams || 0); }, 0); }
function cats(lines) { return S.types.map(function (c) { return { category: c, grams: lines.filter(function (l) { return l.category === c; }).reduce(function (s, l) { return s + Number(l.grams || 0); }, 0) }; }).filter(function (x) { return x.grams > 0; }); }
function reading() {
  var g = kgIn(draft.sampleGross, 'Sample gross'), n = kgIn(draft.sampleNet, 'Sample net');
  if (g == null && n == null) return null;
  var q = String(draft.sampleQty || '').trim();
  if (!/^\d+$/.test(q) || +q < 1 || +q > 1000000) throw Error('Enter a whole quantity from 1 to 1,000,000.');
  if (g != null && n != null && n > g) throw Error('Sample net cannot exceed gross.');
  var unit = n == null ? null : Math.round(n * 1000) / +q;
  if (unit != null && !Number.isInteger(unit)) throw Error('This reading gives a fractional gram per unit. Check quantity, or use a direct whole-gram unit target.');
  return { grossKg: g, netKg: n, quantity: +q, unitGrams: unit, tareGrams: g != null && n != null ? Math.round((g - n) * 1000) : null };
}
function status() {
  var err = '', target = null, cal = null;
  try {
    draft.materials.forEach(function (l) { grams(l.grams, 'Material weight'); if ((l.description || '').length > 160) throw Error('Keep a material description within 160 characters.'); });
    cal = reading(); target = cal && cal.unitGrams != null ? cal.unitGrams : grams(draft.target, 'Unit target');
    if (target != null && target <= 0) throw Error('Unit target must be greater than zero.');
  } catch (e) { err = e.message; }
  var total = matTotal(), unit = target == null ? total : target, rem = target == null ? 0 : target - total, nm = draft.name.trim().toLowerCase();
  if (!err && !nm) err = 'Enter a profile name.';
  if (!err && draft.name.trim().length > 120) err = 'Keep the profile name within 120 characters.';
  if (!err && S.profiles.some(function (p) { return p.id !== draft.id && p.name.trim().toLowerCase() === nm; })) err = 'This profile name already exists. Use a different name.';
  if (!err && total <= 0) err = 'Enter at least one material weight.';
  if (!err && target != null && rem !== 0) err = rem < 0 ? fmt(-rem) + ' g above target. Correct the materials.' : fmt(rem) + ' g remaining. Complete the material total.';
  return { total: total, target: target, unit: unit, rem: rem, cal: cal, error: err, ready: !err };
}

/* ---------------- Profile entry */
function numIn(id, val, next, dec, extra) { return '<input id="' + id + '" type="text" inputmode="' + (dec ? 'decimal' : 'numeric') + '"' + (dec ? '' : ' pattern="[0-9]*"') + ' data-sel data-next="' + next + '" enterkeyhint="next" autocomplete="off" value="' + esc(val) + '" ' + (extra || '') + '>'; }
function renderEntry() {
  var d = draft, l = d.materials[d.cursor], editing = d.id ? prof(d.id) : null;
  $('#wMain').innerHTML = '<div class="row between" style="margin-bottom:12px"><div><div class="eyebrow">MATERIALS PER FINISHED UNIT</div><h2>' + (d.id ? 'Update profile' : 'New weight profile') + '</h2></div><span class="pill">' + (editing ? esc(editing.reference) : 'New') + '</span></div>'
    + (pendingLine ? '<div class="notice gap" style="margin:0 0 12px">For <b>' + esc(pendingLine.name) + '</b> — it links to that line when saved.</div>' : '')
    + '<section class="card"><label for="pName">Profile name</label><input id="pName" maxlength="120" placeholder="e.g. Bedding Queen · 1,100 g" value="' + esc(d.name) + '" enterkeyhint="next" data-next="pTarget">'
    + '<details id="pSettings"' + (d.settingsOpen ? ' open' : '') + '><summary><span>Unit: ' + esc(d.unit) + ' · optional notes</span></summary><div class="gap"><label for="pUnit">Quantity unit for this profile</label><select id="pUnit">'
    + S.units.map(function (u) { return '<option' + (u === d.unit ? ' selected' : '') + '>' + u + '</option>'; }).join('') + '</select></div><div class="gap"><label for="pNotes">Optional notes</label><input id="pNotes" value="' + esc(d.notes) + '" maxlength="250" placeholder="Use, quality or size note"></div></details>'
    + '<div class="row between gap"><label for="pTarget" style="margin:0">Optional unit target · grams</label><button type="button" class="mini quiet" data-a="auto-total">Use auto total</button></div>'
    + numIn('pTarget', d.target, 'pGrams', false, 'placeholder="Auto: sum of materials"') + '<div class="hint">Leave blank to calculate the unit weight from the materials. Filled in, the types must add up to it exactly.</div>'
    + '<details id="pReading"' + (d.readingOpen ? ' open' : '') + '><summary><span>Or calculate from sample gross / net · kg</span></summary>'
    + '<div class="grid2 gap"><div><label for="pSQty">Number of ' + esc(d.unit) + ' in the sample package</label>' + numIn('pSQty', d.sampleQty, 'pSGross') + '</div>'
    + '<div><label for="pSPkg">Sample package type</label><select id="pSPkg">' + S.pkgTypes.map(function (t) { return '<option' + (t === d.samplePkgType ? ' selected' : '') + '>' + t + '</option>'; }).join('') + '</select></div></div>'
    + '<div class="grid2 gap"><div><label for="pSGross">Sample gross · kg</label>' + numIn('pSGross', d.sampleGross, 'pSNet', true, 'placeholder="11"') + '</div><div><label for="pSNet">Sample net · kg</label>' + numIn('pSNet', d.sampleNet, 'pGrams', true, 'placeholder="10"') + '</div></div>'
    + '<div id="pFormula" class="notice gap"></div><div class="hint">The sample sets the unit target. Gross − net is the empty package: it is offered as that package\'s weight on a shipment, never counted as unit weight.</div></details></section>'
    + '<section class="card"><div class="row between"><div><div class="eyebrow">MATERIAL ' + (d.cursor + 1) + '</div><button type="button" class="typepick" data-a="types">' + esc(l.category) + ' ⌄</button></div><button type="button" class="mini quiet" data-a="repeat">+ Repeat type</button></div>'
    + (d.showTypes ? '<div class="chips">' + S.types.map(function (t) { return '<button type="button" class="' + (l.category === t ? 'on' : '') + '" data-a="type" data-type="' + esc(t) + '">' + esc(t) + '</button>'; }).join('') + '</div>' : '')
    + '<div id="pBal" class="balance" role="status" aria-live="polite"><div><b>Material total / unit</b><div id="pEntered"></div></div><div class="right"><strong id="pRem"></strong><span id="pState"></span></div></div>'
    + '<label for="pGrams">' + esc(l.category) + ' weight · grams per ' + unitWord(d.unit) + '</label>' + numIn('pGrams', l.grams, 'next-material', false, 'class="big" placeholder="0 = skip"')
    + '<div class="row between gap"><button type="button" class="mini quiet" data-a="desc">' + (d.showDesc ? 'Hide' : '+ Add') + ' description</button><button type="button" class="mini" data-a="skip">Skip →</button></div>'
    + (d.showDesc ? '<div class="gap"><label for="pDesc">Description · optional</label><input id="pDesc" maxlength="160" enterkeyhint="next" data-next="pGrams" value="' + esc(l.description) + '" placeholder="e.g. Second fabric quality"></div>' : '')
    + '<div class="row gap"><button type="button" class="soft full" data-a="next-material">Next material →</button></div>'
    + '<details id="pReview"' + (d.reviewOpen ? ' open' : '') + '><summary><span>Entered materials · ' + d.materials.filter(function (x) { return Number(x.grams) > 0; }).length + '</span><span>Edit</span></summary>'
    + d.materials.map(function (x, i) { return '<div class="mrow"><span class="grow">' + esc(x.category) + (x.description ? '<br><small>' + esc(x.description) + '</small>' : '') + '</span><button type="button" class="mini" data-a="edit-mat" data-i="' + i + '">' + fmt(x.grams) + ' g</button><button type="button" class="mini danger" data-a="rm-mat" data-i="' + i + '" aria-label="Remove material line">×</button></div>'; }).join('')
    + '<div class="gap">' + cats(d.materials).map(function (c) { return '<div class="cat"><span>' + esc(c.category) + '</span><b>' + fmt(c.grams) + ' g / unit</b></div>'; }).join('') + '</div></details>'
    + '<div id="pErr" class="error gap" role="alert"></div></section>'
    + (d.id || pendingLine ? '<button type="button" class="quiet full" data-a="new">Cancel · start a new profile</button>' : '')
    + '<div class="hint" style="margin:15px 0">Profiles are saved by name and WP code, then linked to invoice lines on Shipment weight.</div>';
  $('#wDock').innerHTML = '<div class="wdock"><div class="cnt" aria-live="polite"><div><label>Unit weight</label><strong id="dUnit"></strong></div><div><label>Materials</label><strong id="dMat"></strong></div><div><label>Balance</label><strong id="dRem"></strong></div></div>'
    + '<div class="prog"><i id="dProg"></i></div><button type="button" id="wSave" class="save" data-a="save"></button></div>';
  refresh();
}
/* counters only — the box being typed in is never redrawn */
function refresh() {
  if (tab !== 'entry') return;
  var s = status();
  $('#pEntered').textContent = fmt(s.total) + (s.target == null ? ' g · auto total' : ' / ' + fmt(s.target) + ' g');
  $('#pRem').textContent = fmt(Math.abs(s.rem)) + ' g';
  $('#pState').textContent = s.target == null ? 'Automatic total' : s.rem < 0 ? 'Above target' : s.rem === 0 ? '✓ Balanced' : 'Remaining balance';
  $('#pBal').classList.toggle('over', s.rem < 0);
  $('#dUnit').textContent = fmt(s.unit) + ' g'; $('#dMat').textContent = fmt(s.total) + ' g';
  $('#dRem').textContent = s.rem < 0 ? fmt(-s.rem) + ' g over' : fmt(s.rem) + ' g';
  $('#dProg').style.width = s.unit > 0 ? Math.min(100, s.total / s.unit * 100) + '%' : '0%';
  $('#pErr').textContent = s.error;
  var f = 'Optional: gross 11 kg, net 10 kg, quantity 10 → 1,000 g per unit.';
  try { var r = reading(); if (r) f = (r.netKg == null ? 'Enter net to calculate unit weight' : r.netKg + ' kg × 1,000 ÷ ' + r.quantity + ' = ' + fmt(r.unitGrams) + ' g each') + (r.tareGrams != null ? '<br>Empty ' + esc(draft.samplePkgType.toLowerCase()) + ': ' + fmt(r.tareGrams) + ' g' : ''); }
  catch (e) { f = esc(e.message); }
  if ($('#pFormula')) $('#pFormula').innerHTML = f;
  saveButton(s);
}
/* rebuilt only when its text changes, so a tap is never lost under a redraw */
function saveButton(s) {
  var b = $('#wSave'); if (!b) return;
  var h, dis = false;
  if (tab === 'entry') {
    s = s || status(); dis = !s.ready || busy;
    h = '<span class="t">' + (busy ? 'SAVING…' : receipt ? '✓ Saved ' + esc(receipt.name) : draft.id ? 'UPDATE PROFILE' : 'SAVE PROFILE') + '</span><span class="c">'
      + esc(receipt ? receipt.reference + ' · ' + fmt(receipt.grams) + ' g per unit' : s.ready ? fmt(s.total) + ' g materials · complete' : s.error) + '</span>';
  } else h = '<span class="t">NEW PROFILE →</span><span class="c">Create another profile or open a saved one</span>';
  b.disabled = dis; b.classList.toggle('ok', !!receipt);
  if (b._h !== h) { b._h = h; b.innerHTML = h; }
}
function capture(el) {
  var d = draft, map = { pName: 'name', pUnit: 'unit', pNotes: 'notes', pSQty: 'sampleQty', pSGross: 'sampleGross', pSNet: 'sampleNet', pSPkg: 'samplePkgType' };
  if (tab !== 'entry') return false;
  if (map[el.id]) d[map[el.id]] = el.value;
  else if (el.id === 'pTarget') { d.target = el.value; d.sampleGross = ''; d.sampleNet = ''; if ($('#pSGross')) $('#pSGross').value = ''; if ($('#pSNet')) $('#pSNet').value = ''; }
  else if (el.id === 'pGrams') d.materials[d.cursor].grams = el.value;
  else if (el.id === 'pDesc') d.materials[d.cursor].description = el.value;
  else return false;
  if (el.id === 'pSNet' || el.id === 'pSGross' || el.id === 'pSQty') { try { var r = reading(); if (r && r.unitGrams != null) { d.target = String(r.unitGrams); $('#pTarget').value = d.target; } } catch (e) {} }
  receipt = null; keep(); refresh(); return true;
}
function nextMaterial() {
  var d = draft; grams(d.materials[d.cursor].grams, 'Material weight');
  if (d.cursor + 1 < d.materials.length) d.cursor++;
  else {
    if (d.materials.length >= 100) throw Error('Use up to 100 material lines per profile.');
    var nx = S.types.indexOf(d.materials[d.cursor].category) + 1;
    if (nx >= S.types.length) { d.reviewOpen = true; keep(); render(); var b = $('#wSave'); if (b) b.focus(); return; }
    d.materials.push({ category: S.types[nx], description: '', grams: '' }); d.cursor++;
  }
  d.showTypes = false; d.showDesc = false; keep(); render(); sel($('#pGrams'));
}
function saveProfile() {
  var s = status(); if (!s.ready) throw Error(s.error);
  var d = draft, line = pendingLine; busy = true; saveButton();
  api({ op: 'profile_save', profile: { id: d.id || 0, name: d.name, unit: d.unit, notes: d.notes, target: s.cal && s.cal.unitGrams != null ? '' : d.target,
    sampleQty: d.sampleQty, sampleGross: d.sampleGross, sampleNet: d.sampleNet, samplePkgType: s.cal ? d.samplePkgType : '', materials: d.materials } })
    .then(function (j) {
      busy = false; receipt = { name: j.profile.name, reference: j.profile.reference, grams: j.profile.unitNetGrams };
      draft = blankDraft(); keep(); clearTimeout(receiptT); receiptT = setTimeout(function () { receipt = null; saveButton(); }, 2400);
      if (line && S.shipment) {
        pendingLine = null; openLine = line.id;
        return api({ op: 'link', itemId: line.id, profileId: j.profile.id }).then(function (k) { tab = 'link'; render(); toast(j.profile.reference + ' saved and linked to ' + line.name); })
          .catch(function (e) { tab = 'link'; render(); toast(e.message, true); });
      }
      expanded = {}; expanded[j.profile.id] = true; tab = 'saved'; render(); window.scrollTo(0, 0); toast(j.msg);
    })
    .catch(function (e) { busy = false; saveButton(); toast(e.message, true); });
}
function loadProfile(id, dup) {
  var p = prof(id); if (!p) return;
  if ((draft.name.trim() || matTotal() > 0) && !window.confirm('Replace the current unsaved profile draft? Saved profiles stay.')) return;
  var c = p.calibration;
  draft = blankDraft();
  draft.id = dup ? null : p.id; draft.name = dup ? p.name.slice(0, 110) + ' — copy' : p.name; draft.unit = p.unit; draft.notes = p.notes;
  draft.materials = p.materials.map(function (m) { return { category: m.category, description: m.description, grams: String(m.grams) }; });
  if (c) { draft.sampleQty = String(c.quantity); draft.sampleGross = c.grossKg == null ? '' : String(c.grossKg); draft.sampleNet = c.netKg == null ? '' : String(c.netKg); draft.samplePkgType = c.packageType || 'Carton'; draft.readingOpen = true; }
  receipt = null; keep(); tab = 'entry'; render(); window.scrollTo(0, 0); sel($(dup ? '#pName' : '#pGrams'));
}

/* ---------------- Saved */
function profileCard(p) {
  return '<details class="pcard" data-prof="' + p.id + '"' + (expanded[p.id] ? ' open' : '') + '><summary><div class="grow"><div class="pname">' + esc(p.name) + '</div><div class="pcode">' + esc(p.reference) + ' · v' + p.version + ' · ' + esc(p.unit) + (p.active ? '' : ' · not in use') + '</div></div><span class="pill' + (p.active ? '' : ' gray') + '">' + fmt(p.unitNetGrams) + ' g</span></summary>'
    + '<div><div class="hint">' + (p.notes ? esc(p.notes) + '<br>' : '') + kg(p.unitNetGrams) + ' kg per unit' + (p.inUse ? ' · used on shipments' : '') + '</div>'
    + cats(p.materials).map(function (c) { return '<div class="cat"><span>' + esc(c.category) + '</span><b>' + fmt(c.grams) + ' g / unit</b></div>'; }).join('')
    + '<details><summary>Material line details</summary>' + p.materials.map(function (m) { return '<div class="cat"><span>' + esc(m.category) + (m.description ? ' · ' + esc(m.description) : '') + '</span><b>' + fmt(m.grams) + ' g</b></div>'; }).join('') + '</details>'
    + (p.calibration ? '<details><summary>Original sample reading · reference</summary><div class="notice gap">' + p.calibration.quantity + ' ' + esc(p.unit) + ' in a ' + esc((p.calibration.packageType || 'package').toLowerCase()) + ' · gross ' + (p.calibration.grossKg == null ? '—' : p.calibration.grossKg) + ' kg · net ' + (p.calibration.netKg == null ? '—' : p.calibration.netKg) + ' kg<br>'
        + (p.calibration.packageTareGrams == null ? 'The empty package needs both gross and net.' : 'Empty ' + esc((p.calibration.packageType || 'package').toLowerCase()) + ': ' + fmt(p.calibration.packageTareGrams) + ' g — offered on shipments.') + '</div></details>' : '')
    + '<div class="acts"><button type="button" class="mini" data-a="edit-prof" data-id="' + p.id + '">Update</button><button type="button" class="mini soft" data-a="dup-prof" data-id="' + p.id + '">Duplicate</button><button type="button" class="mini" data-a="ref" data-id="' + p.id + '">Reference</button>'
    + (p.inUse ? '<button type="button" class="mini" data-a="active" data-id="' + p.id + '" data-on="' + (p.active ? 0 : 1) + '">' + (p.active ? 'Not in use' : 'Back in use') + '</button>'
               : '<button type="button" class="mini danger" data-a="del-prof" data-id="' + p.id + '">Delete</button>') + '</div>'
    + '<div class="calc"><label for="cq' + p.id + '">Quantity · ' + esc(p.unit) + ' · live weight calculation</label><input id="cq' + p.id + '" type="text" inputmode="numeric" pattern="[0-9]*" data-sel data-calc="' + p.id + '" value="' + esc(calcQ[p.id] || '1') + '"><div id="cr' + p.id + '" class="gap"></div></div></div></details>';
}
function calcBox(id) {
  var box = $('#cr' + id), p = prof(id); if (!box || !p) return;
  var t = String(calcQ[id] || '1').trim();
  if (!/^\d+$/.test(t) || +t > 1000000) { box.innerHTML = '<div class="error">Enter a whole quantity from 0 to 1,000,000.</div>'; return; }
  var q = +t;
  box.innerHTML = '<div class="tw">' + (p.unitNetGrams * q / 1000).toFixed(3) + ' kg</div><div class="hint">' + fmt(p.unitNetGrams) + ' g × ' + fmt(q) + ' ' + esc(p.unit) + ' ÷ 1,000</div>'
    + cats(p.materials.map(function (m) { return { category: m.category, grams: m.grams * q }; })).map(function (c) { return '<div class="cat"><span>' + esc(c.category) + '</span><b>' + fmt(c.grams) + ' g · ' + kg(c.grams) + ' kg</b></div>'; }).join('');
}
function renderList() {
  var off = S.profiles.filter(function (p) { return !p.active; }).length;
  var ps = S.profiles.filter(function (p) { return (showOff || p.active) && (p.name + ' ' + p.reference + ' ' + p.notes).toLowerCase().indexOf(query.toLowerCase()) >= 0; });
  $('#wList').innerHTML = ps.map(profileCard).join('') || '<div class="empty">No matching profiles.</div>';
  if (off) $('#wList').innerHTML += '<button type="button" class="mini quiet full" data-a="show-off">' + (showOff ? 'Hide' : 'Show') + ' ' + off + ' not in use</button>';
  ps.forEach(function (p) { calcBox(p.id); });
}
function renderSaved() {
  $('#wMain').innerHTML = '<div class="row between"><div><div class="eyebrow">READY TO REUSE</div><h2>Saved profiles</h2></div><button type="button" class="mini primary" data-a="new">+ New</button></div>'
    + '<div class="hint">Tap a profile to see its materials, update or duplicate it, or work out any quantity.</div>'
    + '<input id="wSearch" class="search" type="search" placeholder="Search profile name or WP code…" value="' + esc(query) + '" aria-label="Search profiles"><div id="wList"></div>'
    + '<div class="notice gap">Total net weight = profile grams × quantity ÷ 1,000. A profile used on a shipment cannot be deleted — mark it "Not in use" instead.</div>';
  renderList();
  $('#wDock').innerHTML = '<div class="wdock"><button type="button" id="wSave" class="save" data-a="new"></button></div>';
  saveButton();
}

/* ---------------- Shipment weight */
function lineCard(l) {
  var p = l.profileId ? prof(l.profileId) : null, open = openLine === l.id, edit = S.canEditShip, a = l.applied;
  var badge = !p ? (l.qty > 0 ? '<span class="pill warn">No profile</span>' : '<span class="pill gray">Not packed</span>')
    : a && a.state === 'profile_updated' ? '<span class="pill warn">Profile updated</span>'
    : a && a.state === 'changed' ? '<span class="pill warn">Changed</span>'
    : '<span class="pill">' + k3(l.net) + ' kg</span>';
  var byType = {}, order = [];
  l.rows.forEach(function (r) { if (!byType[r.type]) { byType[r.type] = { s: [], q: 0, p: 0 }; order.push(r.type); } byType[r.type].s.push(r.serial); byType[r.type].q += r.qty; byType[r.type].p += r.packages; });
  var rows = order.length ? order.map(function (t) { var g = byType[t]; return '<div class="cat"><span><b>' + esc(t) + '</b> ' + g.s.map(esc).join(', ') + '</span><b class="num">' + fmt(g.p) + ' · ' + fmt(g.q) + ' ' + esc(l.unit) + '</b></div>'; }).join('')
    : '<div class="hint">When this line is packed, its weight fills in by itself.</div>';
  var body;
  if (p) {
    body = '<div class="calc"><div class="row between"><div class="grow"><b>' + esc(p.reference) + ' · ' + esc(p.name) + '</b><div class="hint" style="margin-top:2px">' + fmt(p.unitNetGrams) + ' g per ' + esc(p.unit) + ' · v' + p.version + (p.active ? '' : ' · not in use') + '</div></div>'
      + (edit ? '<button type="button" class="mini" data-a="pick" data-line="' + l.id + '">Change</button>' : '') + '</div>'
      + (l.qty > 0 ? '<div class="tw gap">' + k3(l.net) + ' kg net</div><div class="hint">' + fmt(p.unitNetGrams) + ' g × ' + fmt(l.qty) + ' ' + esc(l.unit) + ' packed ÷ 1,000 · gross ' + k3(l.gross) + ' kg</div>'
          + (Math.abs(l.qty - l.invoiceQty) > 0.0005 ? '<div class="hint">Invoice says ' + fmt(l.invoiceQty) + ' ' + esc(l.unit) + '. The packed quantity is used.</div>' : '')
          + '<details><summary>By material type</summary>' + cats(p.materials.map(function (m) { return { category: m.category, grams: m.grams * l.qty }; })).map(function (c) { return '<div class="cat"><span>' + esc(c.category) + '</span><b>' + k3(c.grams / 1000) + ' kg</b></div>'; }).join('') + '</details>'
        : '<div class="hint gap">' + fmt(p.unitNetGrams) + ' g each · the weight appears when packed.</div>')
      + (a && a.state === 'profile_updated' ? '<div class="notice warn gap">' + esc(p.reference) + ' was updated after this shipment\'s weight was applied. Applied: ' + k3(a.net) + ' kg net. Press Apply to use the new figures.</div>' : '')
      + (a && a.state === 'changed' ? '<div class="notice warn gap">Packing or profile changed since Apply (applied ' + k3(a.net) + ' kg net). Apply again.</div>' : '')
      + '</div>';
  } else {
    body = (l.suggest && edit ? '<div class="sug"><span class="grow">Last used for this product' + (l.suggest.from ? ' on ' + esc(l.suggest.from) : '') + ': <b>' + esc(l.suggest.reference) + ' · ' + esc(l.suggest.name) + '</b></span><button type="button" class="mini primary" data-a="choose" data-line="' + l.id + '" data-pid="' + l.suggest.profileId + '">Use</button></div>' : '')
      + (edit ? '<div class="row gap"><button type="button" class="primary grow" data-a="pick" data-line="' + l.id + '">Link a weight profile</button><button type="button" class="mini soft" data-a="new-for" data-line="' + l.id + '">+ New</button></div>' : '<div class="hint">No profile linked.</div>');
  }
  return '<details class="pcard" data-line="' + l.id + '"' + (open ? ' open' : '') + '><summary><div class="grow"><div class="pname">' + esc(l.name) + ' <span class="small">' + esc(l.no) + '</span></div><div class="pcode">' + (l.hint ? esc(l.hint) + ' · ' : '') + 'invoice ' + fmt(l.invoiceQty) + ' ' + esc(l.unit) + '</div></div>' + badge + '</summary>'
    + '<div><div class="eyebrow gap">PACKED</div>' + rows + body + '</div></details>';
}
function totalsNow() {
  /* worked out again in the browser while an empty package weight is being typed */
  var c = S.calc, net = 0, gross = 0;
  c.lines.forEach(function (l) { if (!l.profileId) return; var t = 0; Object.keys(l.byType).forEach(function (k) { t += Number((tareTyping[k] != null ? tareTyping[k] : c.tares[k].kg) || 0) * l.byType[k]; }); net += l.net; gross += l.net + t; });
  return { net: net, gross: gross };
}
var tareTyping = {}, finalVals = { net: '', gross: '', open: false };
function renderLink() {
  if (!S.shipment) {
    $('#wMain').innerHTML = '<div class="eyebrow">SHIPMENT WEIGHT</div><h2 style="margin-bottom:10px">Pick a shipment</h2>'
      + (S.shipments.length ? S.shipments.map(function (s) { return '<a class="pcard" style="display:block;text-decoration:none;color:inherit" href="weight.php?id=' + s.id + '#link"><div class="pname">' + esc(s.invoice) + '</div><div class="pcode">' + esc(s.buyer) + '</div></a>'; }).join('') : '<div class="empty">No shipment is assigned to you.</div>');
    $('#wDock').innerHTML = ''; return;
  }
  var c = S.calc, t = c.totals, ap = c.applied, edit = S.canEditShip;
  tareTyping = {};
  $('#wMain').innerHTML = '<div class="row between" style="margin-bottom:10px"><div><div class="eyebrow">WEIGHT BY INVOICE LINE</div><h2>Shipment weight</h2></div><span class="pill">' + esc(S.shipment.invoice) + '</span></div>'
    + (ap ? '<div class="notice' + (ap.current ? '' : ' warn') + '" style="margin-bottom:12px">' + (ap.current ? '✓ Up to date · ' : 'A profile changed — press Apply to use it. Counted since ') + esc(String(ap.at).slice(0, 16)) + ': ' + k3(ap.net) + ' kg net · ' + k3(ap.gross) + ' kg gross.</div>'
         : '<div class="notice" style="margin-bottom:12px">Link one profile to each invoice line. The quantity is what packing has already packed, so nothing is typed twice. One weight per line, however many serial ranges it has.</div>')
    + (edit ? '' : '<div class="notice warn" style="margin-bottom:12px">This shipment is locked. You can look, not change.</div>')
    + c.lines.map(lineCard).join('')
    + (Object.keys(c.tares).length ? '<section class="card"><div class="eyebrow">EMPTY PACKAGE WEIGHT · FOR GROSS</div><div class="hint" style="margin:4px 0 8px">Once per kind of package for this shipment. Gross = net + this × packages.</div>'
      + Object.keys(c.tares).map(function (ty) { var x = c.tares[ty]; return '<div class="grid2" style="align-items:end;margin-top:8px"><div><label for="tare-' + esc(ty) + '">' + esc(ty) + ' · kg each</label><input id="tare-' + esc(ty) + '" type="text" inputmode="decimal" data-sel data-tare="' + esc(ty) + '" value="' + (x.kg ? esc(String(x.kg)) : '') + '" placeholder="0.000"' + (edit ? '' : ' disabled') + '></div>'
        + '<div class="hint num" id="tc-' + esc(ty) + '">× ' + fmt(x.packages) + ' = ' + k3(x.kg * x.packages) + ' kg' + (x.source && x.source !== 'typed' ? '<br>from ' + esc(x.source) : '') + '</div></div>'; }).join('') + '</section>' : '')
    + (edit ? '<details class="card" id="finalBox"' + (finalVals.open ? ' open' : '') + '><summary><span>Final weight from the scale · optional · within ±' + S.tolerance + '%</span></summary><div class="grid2 gap"><div><label for="fNet">Final net · kg</label><input id="fNet" type="text" inputmode="decimal" data-sel placeholder="' + k3(t.net) + '" value="' + esc(finalVals.net) + '"></div><div><label for="fGross">Final gross · kg</label><input id="fGross" type="text" inputmode="decimal" data-sel placeholder="' + k3(t.gross) + '" value="' + esc(finalVals.gross) + '"></div></div><div class="hint">Leave blank to use the worked-out figures. Each line is scaled the same way, so the lines still add up.</div></details>' : '')
    + '<div class="row gap"><button type="button" class="mini" data-a="preview">Packing list preview</button></div>'
    + '<div class="hint" style="margin:15px 0">The weight updates by itself: link a profile, pack more or less, type an empty-package weight — the packing list rows, the shipment totals and the prints follow at once. Press Apply only to enter a scale figure (within ±' + S.tolerance + '%) or to take a profile that was edited after it was linked.</div>';
  var now = totalsNow();
  $('#wDock').innerHTML = '<div class="wdock"><div class="cnt" aria-live="polite"><div><label>Net</label><strong id="dNet">' + k3(now.net) + ' kg</strong></div><div><label>Gross</label><strong id="dGross">' + k3(now.gross) + ' kg</strong></div><div><label>Lines linked</label><strong>' + t.linked + ' / ' + t.lines + '</strong></div></div>'
    + '<div class="prog"><i style="width:' + (t.lines ? t.linked / t.lines * 100 : 0) + '%"></i></div>'
    + (edit ? '<button type="button" id="wApply" class="save" data-a="apply"' + (t.linked ? '' : ' disabled') + '><span class="t">' + (ap && ap.current ? '✓ WEIGHT UP TO DATE' : 'APPLY WEIGHT TO PACKING LIST') + '</span><span class="c">' + fmt(t.packages) + ' packages · ' + (t.pending ? t.pending + ' packed line' + (t.pending === 1 ? '' : 's') + ' still need a profile' : 'every packed line has a profile') + '</span></button>' : '') + '</div>';
}
function previewHTML() {
  var c = S.calc, rows = [], pr = [], tq = 0, tp = 0, tn = 0, tg = 0;
  c.lines.forEach(function (l) {
    var g = {}, o = [];
    l.rows.forEach(function (r) { if (!g[r.type]) { g[r.type] = { s: [], q: 0, p: 0 }; o.push(r.type); } g[r.type].s.push(r.serial); g[r.type].q += r.qty; g[r.type].p += r.packages; });
    o.forEach(function (t) { pr.push('<tr><td><b>' + esc(l.name) + '</b> <small>' + esc(l.no) + '</small></td><td>' + esc(t) + '<br><small>' + g[t].s.map(esc).join(', ') + '</small></td><td class="n">' + fmt(g[t].p) + '</td><td class="n">' + fmt(g[t].q) + '</td></tr>'); });
    if (!l.rows.length) return;
    var p = l.profileId ? prof(l.profileId) : null; tq += l.qty; tp += l.packages; if (p) { tn += l.net; tg += l.gross; }
    rows.push('<tr><td><b>' + esc(l.name) + '</b> <small>' + esc(l.no) + '</small><br><small>' + (p ? esc(p.reference) + ' · ' + fmt(p.unitNetGrams) + ' g' : 'weight pending') + '</small></td><td class="n">' + fmt(l.qty) + '</td><td class="n">' + fmt(l.packages) + '</td><td class="n">' + (p ? k3(l.net) : '—') + '</td><td class="n">' + (p ? k3(l.gross) : '—') + '</td></tr>');
  });
  return '<h3 style="margin-top:12px">Packing rows</h3><div style="overflow-x:auto"><table><tr><th>Invoice line</th><th>Package · serials</th><th class="n">Pkgs</th><th class="n">Qty</th></tr>' + pr.join('') + '</table></div>'
    + '<div class="hint" style="font-size:11.5px;color:#64797b">Same package type → one row; a new row only where the type changes.</div>'
    + '<h3 style="margin-top:14px">Weight summary</h3><div style="overflow-x:auto"><table><tr><th>Invoice line</th><th class="n">Packed</th><th class="n">Pkgs</th><th class="n">Net kg</th><th class="n">Gross kg</th></tr>' + rows.join('')
    + '<tr style="font-weight:800"><td>Total</td><td class="n">' + fmt(tq) + '</td><td class="n">' + fmt(tp) + '</td><td class="n">' + k3(tn) + '</td><td class="n">' + k3(tg) + '</td></tr></table></div>';
}
function pickModal(lineId) {
  var l = S.calc.lines.find(function (x) { return x.id === +lineId; });
  openModal('Weight profile · ' + l.name, '<div style="font-size:12px;color:#64797b">' + esc(l.no) + (l.hint ? ' · ' + esc(l.hint) : '') + ' · counted in ' + esc(l.unit) + '</div><input id="wpSearch" style="margin:12px 0" type="search" placeholder="Search name or WP code…" aria-label="Search profiles" data-line="' + l.id + '"><div id="wpList"></div>',
    '<button type="button" data-a="new-for" data-line="' + l.id + '">+ New profile</button>' + (l.profileId ? '<button type="button" data-a="unlink" data-line="' + l.id + '">Remove link</button>' : '') + '<button type="button" data-a="close">Cancel</button>');
  pickList(l, '');
  setTimeout(function () { var i = $('#wpSearch'); if (i) i.focus(); }, 10);
}
function pickList(l, q) {
  var ps = S.profiles.filter(function (p) { return p.active && (p.name + ' ' + p.reference).toLowerCase().indexOf(q.toLowerCase()) >= 0; });
  ps.sort(function (a, b) { return (b.unit === l.unit) - (a.unit === l.unit) || a.reference.localeCompare(b.reference); });
  $('#wpList').innerHTML = ps.map(function (p) { var ok = p.unit === l.unit;
    return '<button type="button" style="width:100%;text-align:left;margin-bottom:6px;display:block' + (l.profileId === p.id ? ';border-color:#087f72;background:#e4f4ef' : '') + '" data-a="choose" data-line="' + l.id + '" data-pid="' + p.id + '"' + (ok ? '' : ' disabled') + '><b>' + esc(p.reference) + ' · ' + esc(p.name) + '</b><br><span style="font-size:12px;color:#64797b">' + fmt(p.unitNetGrams) + ' g per ' + esc(p.unit) + (ok ? '' : ' · this line is counted in ' + esc(l.unit)) + '</span></button>'; }).join('')
    || '<div class="empty" style="padding:20px;text-align:center;color:#64797b">No matching profile. Make a new one.</div>';
}

/* ---------------- dialogs, tabs */
function openModal(title, body, foot) {
  $('#wModal').innerHTML = '<div class="wback"><section class="wmodal" role="dialog" aria-modal="true" aria-labelledby="wMT"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><h2 id="wMT">' + esc(title) + '</h2><button type="button" data-a="close" aria-label="Close">×</button></div>' + body + (foot ? '<div class="foot">' + foot + '</div>' : '') + '</section></div>';
}
function closeModal() { $('#wModal').innerHTML = ''; }
function render() {
  document.querySelectorAll('#wp [data-a=tab]').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === tab); });
  $('#wCount').textContent = '(' + S.profiles.filter(function (p) { return p.active; }).length + ')';
  if (tab === 'entry') renderEntry(); else if (tab === 'saved') renderSaved(); else renderLink();
}
function sel(el) { if (!el) return; el.focus(); try { el.select(); } catch (e) {} }

function onAction(el) {
  var a = el.dataset.a, d = draft;
  if (busy && a !== 'close') return;
  switch (a) {
    case 'tab': tab = el.dataset.tab; receipt = null; render(); window.scrollTo(0, 0); break;
    case 'close': closeModal(); break;
    case 'new':
      if (d.name.trim() || matTotal() > 0) { if (!window.confirm('Replace the current unsaved profile draft? Saved profiles stay.')) break; }
      pendingLine = null; draft = blankDraft(); keep(); receipt = null; tab = 'entry'; render(); window.scrollTo(0, 0); sel($('#pName')); break;
    case 'save': if (tab === 'entry') saveProfile(); else onAction({ dataset: { a: 'new' } }); break;
    case 'auto-total': d.target = ''; d.sampleGross = ''; d.sampleNet = ''; keep(); render(); break;
    case 'next-material': nextMaterial(); break;
    case 'skip': d.materials[d.cursor].grams = ''; nextMaterial(); break;
    case 'types': d.showTypes = !d.showTypes; render(); break;
    case 'type': d.materials[d.cursor].category = el.dataset.type; d.showTypes = false; keep(); render(); sel($('#pGrams')); break;
    case 'repeat':
      if (d.materials.length >= 100) throw Error('Use up to 100 material lines.');
      d.materials.splice(d.cursor + 1, 0, { category: d.materials[d.cursor].category, description: '', grams: '' }); d.cursor++; d.showDesc = false; keep(); render(); sel($('#pGrams')); break;
    case 'desc': d.showDesc = !d.showDesc; render(); if (d.showDesc) $('#pDesc').focus(); break;
    case 'edit-mat': d.cursor = +el.dataset.i; d.showDesc = false; keep(); render(); sel($('#pGrams')); break;
    case 'rm-mat': d.materials.splice(+el.dataset.i, 1); if (!d.materials.length) d.materials = blankDraft().materials; d.cursor = Math.min(d.cursor, d.materials.length - 1); keep(); render(); break;
    case 'edit-prof': loadProfile(el.dataset.id, false); break;
    case 'dup-prof': loadProfile(el.dataset.id, true); break;
    case 'ref': { var p = prof(el.dataset.id);
      openModal('Profile reference', '<div class="notice" style="margin-top:12px;background:#eef5f2;padding:11px 12px;border-radius:10px;font-size:12.5px">' + esc(p.name) + ' · ' + fmt(p.unitNetGrams) + ' g / ' + esc(p.unit) + '</div><label style="display:block;margin-top:12px;font-size:12px;color:#64797b" for="wRef">WP code</label><input id="wRef" readonly value="' + esc(p.reference) + '">', '<button type="button" class="primary" data-a="copy-ref" data-ref="' + esc(p.reference) + '">Copy</button><button type="button" data-a="close">Close</button>');
      setTimeout(function () { sel($('#wRef')); }, 5); break; }
    case 'copy-ref': { var ref = el.dataset.ref; try { navigator.clipboard.writeText(ref).then(function () { toast(ref + ' copied'); }, function () { sel($('#wRef')); }); } catch (e) { sel($('#wRef')); } break; }
    case 'del-prof': { var pd = prof(el.dataset.id); if (!pd || !window.confirm('Delete ' + pd.reference + ' · ' + pd.name + '?')) break;
      api({ op: 'profile_delete', profileId: pd.id }).then(function (j) { render(); toast(j.msg); }).catch(function (e) { render(); toast(e.message, true); }); break; }
    case 'active': api({ op: 'profile_active', profileId: +el.dataset.id, active: el.dataset.on === '1' }).then(function (j) { render(); toast(j.msg); }).catch(function (e) { toast(e.message, true); }); break;
    case 'show-off': showOff = !showOff; renderList(); break;
    case 'pick': pickModal(el.dataset.line); break;
    case 'choose': openLine = +el.dataset.line;
      api({ op: 'link', itemId: +el.dataset.line, profileId: +el.dataset.pid }).then(function (j) { closeModal(); render(); toast(j.msg); }).catch(function (e) { toast(e.message, true); }); break;
    case 'unlink': if (!window.confirm('Remove the profile from this line?')) break;
      api({ op: 'unlink', itemId: +el.dataset.line }).then(function (j) { closeModal(); render(); toast(j.msg); }).catch(function (e) { toast(e.message, true); }); break;
    case 'new-for': { var l = S.calc.lines.find(function (x) { return x.id === +el.dataset.line; });
      if ((d.name.trim() || matTotal() > 0) && !window.confirm('Replace the current unsaved profile draft? Saved profiles stay.')) break;
      closeModal(); pendingLine = { id: l.id, name: l.name }; draft = blankDraft(); draft.name = l.name + ' · '; draft.unit = l.unit; keep(); receipt = null; tab = 'entry'; render(); window.scrollTo(0, 0); sel($('#pName')); break; }
    case 'preview': openModal('Packing list · ' + S.shipment.invoice, previewHTML()); break;
    case 'apply': {
      var fn = $('#fNet') ? $('#fNet').value.trim() : '', fg = $('#fGross') ? $('#fGross').value.trim() : '';
      busy = true; var b = $('#wApply'); if (b) b.disabled = true;
      api({ op: 'apply', finalNet: fn, finalGross: fg }).then(function (j) { busy = false; finalVals = { net: '', gross: '', open: false }; render(); toast(j.msg); }).catch(function (e) { busy = false; render(); toast(e.message, true); }); break; }
  }
}

/* ---------------- events */
document.addEventListener('click', function (e) {
  var el = e.target.closest('[data-a]');
  if (!el || el.disabled || !(document.getElementById('wp').contains(el) || $('#wModal').contains(el))) return;
  try { onAction(el); } catch (err) { toast(err.message, true); }
});
document.addEventListener('input', function (e) {
  var el = e.target;
  if (el.dataset && el.dataset.calc) { calcQ[el.dataset.calc] = el.value; calcBox(+el.dataset.calc); return; }
  if (el.id === 'wSearch') { query = el.value; renderList(); return; }
  if (el.id === 'fNet' || el.id === 'fGross') { finalVals[el.id === 'fNet' ? 'net' : 'gross'] = el.value; return; }
  if (el.id === 'wpSearch') { pickList(S.calc.lines.find(function (x) { return x.id === +el.dataset.line; }), el.value); return; }
  if (el.dataset && el.dataset.tare) {
    var v = el.value.trim(), ok = v === '' || (/^\d*\.?\d{0,3}$/.test(v) && /\d/.test(v) && Number(v) <= 1000), ty = el.dataset.tare;
    el.style.borderColor = ok ? '' : '#b73b3b'; el.setAttribute('aria-invalid', ok ? 'false' : 'true');
    if (!ok) { $('#tc-' + ty).textContent = 'Use kg, like 0.800'; return; }
    tareTyping[ty] = v === '' ? 0 : Number(v);
    var n = S.calc.tares[ty].packages, now = totalsNow();
    $('#tc-' + ty).textContent = '× ' + fmt(n) + ' = ' + k3(tareTyping[ty] * n) + ' kg';
    $('#dNet').textContent = k3(now.net) + ' kg'; $('#dGross').textContent = k3(now.gross) + ' kg';
    return;
  }
  capture(el);
});
document.addEventListener('change', function (e) {
  var el = e.target;
  if (el.dataset && el.dataset.tare) {
    if (el.getAttribute('aria-invalid') === 'true') return;
    api({ op: 'tare', type: el.dataset.tare, kg: el.value.trim() }).then(function () { if (!(document.activeElement && document.activeElement.dataset && document.activeElement.dataset.tare)) render(); })
      .catch(function (err) { toast(err.message, true); });
    return;
  }
  if (el.tagName === 'SELECT') capture(el);
});
document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('#wp [data-sel]')) sel(e.target); });
document.addEventListener('toggle', function (e) {
  var t = e.target;
  if (t.dataset && t.dataset.prof) { if (t.open) expanded[t.dataset.prof] = true; else delete expanded[t.dataset.prof]; }
  if (t.dataset && t.dataset.line) { if (t.open) openLine = +t.dataset.line; else if (openLine === +t.dataset.line) openLine = null; }
  if (t.id === 'finalBox') finalVals.open = t.open;
  var map = { pSettings: 'settingsOpen', pReading: 'readingOpen', pReview: 'reviewOpen' };
  if (map[t.id]) { draft[map[t.id]] = t.open; keep(); }
}, true);
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeModal();
  if (e.key === 'Enter' && e.target.dataset && e.target.dataset.next) {
    e.preventDefault();
    try { var nx = e.target.dataset.next; if (nx === 'next-material') nextMaterial(); else sel($('#' + nx)); } catch (err) { toast(err.message, true); }
  }
});

render();
window.ZASWeight = { get state() { return S; }, get draft() { return draft; }, get tab() { return tab; }, render: render, status: status };
})();
</script>
<?php
mob_footer();
