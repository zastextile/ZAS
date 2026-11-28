<?php
/*
  PRODUCTION WORKERS — the people on the machines.
  ================================================

  FROM 18 NOV 2026, PEOPLE COME FROM PEOPLE FINANCE › EMPLOYEES — the owner
  wants one list of people. Code, name, department and on-the-roll are copied
  from there by zp_people_sync() (includes/zprod.php); nobody adds, pastes or
  renames a person here, and the external HR app is no longer read. What
  stays here is production's own decision: the stages each person works.
  The notes below describe the screen as it was before that, and are kept
  for the history of why the stage rules are what they are.

  Deliberately the smallest screen in the module: a code, a name, a department,
  the stages they work, active or not. Everything else about a worker — what
  they made, what they were paid — lives in the entries and is read from there.

  THREE RULES.

  1. A CODE IS GIVEN, NOT DEMANDED. Ask a data-entry clerk to invent a unique
     code for 200 workers and you get W1, w1 and W01 for the same person. Leave
     it blank and the next free W001 is used.

  2. A WORKER WITH WAGES IS NEVER DELETED. Their name is on every entry they
     were paid for. Removing the row would leave those wages belonging to
     nobody, and no report could ever explain the gap. They go inactive
     instead — off every picker, still on every payslip that already exists.

  3. NO STAGE TICKED MEANS NOT PRODUCTION. This was the other way round until
     two hundred people arrived from the HR app in one sync — head office,
     the kitchen, fashion, the garment units. Under the old rule every one of
     them passed every stage, so allotting forty people to Cutting narrowed
     the Cutting list from 200 to 200; the allotment did nothing at all.

     Somebody with no stage is not "available for everything". They are not
     production, which is the truth about the man in the kitchen.

     THE SAFETY NET MOVED, IT DID NOT GO: a stage NOBODY is on yet still
     offers the whole floor, so one stage can be set up this morning and the
     others keep working exactly as they do now. That count is made by the
     entry screen; the rule itself lives in zp_worker_does_stage() so that no
     screen can decide it differently. Nobody is ever unreachable — typing a
     name searches everybody, and the list says how many it is holding back.

  4. A WHOLE DEPARTMENT AT A TIME. Two hundred people is two hundred forms,
     which is nobody's afternoon. Every worker carries a department, and a
     department is what decides the work, so the table at the top of the
     screen sets a floor in one press and the exceptions are corrected by
     hand afterwards.
*/
/* SAVE ONCE (owner, 28 Nov 2026: "talking to server live … allow multiple
   selection but did not talk to server live and save … with Ctrl+S or a Save
   button, like SAP Fiori / Oracle Fusion"). Chips and the Give / Take off
   buttons now change the screen only; changed people are marked, and one
   Save (or Ctrl+S) sends them all in one request, all or nothing. A pay-type
   filter (fixed salary / daily wages / contractor, from People Finance) sits
   beside the stage filter. The rest of the note below still holds.
   REMOVE FROM THE LIST (same day: "remove some emp names from list which are
   not production workers"): tick people, press Remove — or the ✕ on a row.
   They leave every view of this list and Daily Entry once saved, keep every
   wage already booked, stay in People Finance, and come back with Put back
   under the "Removed" chip. Nothing is deleted. */
/* THE SCREEN, REBUILT 18 NOV 2026 ("only allow all worker at a time … allow
   multiple select emp to apply one stage … modern and fast"). One table of
   everybody from People Finance: filter by department or stage, tick any
   people, press a stage to give it or take it off. Each person's stages are
   chips — click one to switch it. Every change saves at once, no reload.
   Old production workers nobody in People Finance matches are not shown
   here at all (owner: "no need such things here"); they are not offered on
   Daily Entry, and their past entries stay in history untouched. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }
require_once __DIR__ . '/includes/zprod.php';
require_once __DIR__ . '/includes/zp_correct.php';      // ze_pay_types(): fixed salary / daily / contractor from People Finance
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // read-only from here: other tabs need not wait
    $a = $_POST['action'] ?? '';
    if ($a === 'stage_bulk') {
        header('Content-Type: application/json');
        $r = zp_stage_bulk((array)($_POST['worker_ids'] ?? []), (array)($_POST['add'] ?? []), (array)($_POST['remove'] ?? []));
        echo json_encode($r);
        exit;
    }
    if ($a === 'stage_save') {
        header('Content-Type: application/json');
        $want = json_decode((string)($_POST['want'] ?? ''), true);
        $was  = json_decode((string)($_POST['was'] ?? ''), true);
        $hide = json_decode((string)($_POST['hide'] ?? '{}'), true);
        $hide = is_array($hide) ? array_map(fn($v) => (bool)$v, $hide) : [];
        echo json_encode(is_array($want) && is_array($was) ? zp_stage_save_many($want, $was, $hide) : ['ok' => false, 'error' => 'Nothing to save.']);
        exit;
    }
}

$msg = '';
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

/* THE SESSION IS LET GO BEFORE THE WORK BELOW (18 Nov 2026 slow log: this
   page and others waited in session_start for minutes). PHP locks a session
   until the page ends, so while this page worked every other tab of the same
   user waited. The menu at the top is the last thing that writes to the
   session, so it is made first, held back, and printed in its place. */
csrf_token();
ob_start();
page_header('Production Workers');
$pageHead = ob_get_clean();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

/* PEOPLE COME FROM PEOPLE FINANCE — copied on every open (see zp_people_sync). */
zp_people_sync(true);

$all    = zp_workers();
$inUse  = zp_people_in_use();
$people = $inUse ? array_values(array_filter($all, fn($w) => $w['att_employee_id'] !== null)) : $all;
$stages = zp_stage_all(true);
$wsMap  = zp_worker_stage_map();
$PAYT   = ze_pay_types();
$HIDDEN = zp_hidden_workers();
$earned = [];
try {
    foreach (db()->query("SELECT worker_id, COUNT(*) n, SUM(amount) amt FROM zp_entries WHERE status='active' GROUP BY worker_id")->fetchAll() as $r)
        $earned[(int)$r['worker_id']] = ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
} catch (Throwable $e) {}

$rows = array_map(fn($w) => [
    'id' => (int)$w['id'], 'code' => (string)$w['worker_code'], 'name' => (string)$w['worker_name'],
    'dept' => (string)($w['department'] ?? ''), 'on' => (int)$w['is_active'] === 1, 'pt' => (string)($PAYT[(int)$w['id']] ?? ''),
    'st' => array_values(array_map('intval', $wsMap[(int)$w['id']] ?? [])), 'hid' => isset($HIDDEN[(int)$w['id']]),
    'n' => $earned[(int)$w['id']]['n'] ?? 0, 'amt' => round($earned[(int)$w['id']]['amt'] ?? 0, 2),
], $people);
$depts = array_values(array_unique(array_filter(array_column($rows, 'dept'))));
sort($depts);

echo $pageHead;
?>
<style>
.pw{max-width:1280px;font-size:13px;color:#1d2d3e}
.pw h1{margin:0;font-size:19px}
.pw .sub{margin:2px 0 0;color:#6a7a8c;font-size:12.5px}
.pw .src{margin:10px 0;padding:8px 12px;border-radius:6px;background:#eef5fc;border:1px solid #d0e3f7;color:#24496e;font-size:12.5px}
.pw .src a{color:#0a5fb4;font-weight:700}
.pw .bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 0}
.pw .bar input[type=search],.pw .bar select{height:32px;border:1px solid #c9d3df;border-radius:6px;padding:0 9px;font:inherit;background:#fff;color:inherit}
.pw .bar input[type=search]{flex:1 1 240px;min-width:180px}
.pw .bar .cnt{margin-left:auto;color:#6a7a8c;font-size:12px;white-space:nowrap}
.pw .tot{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 6px}
.pw .tot button{border:1px solid #d6dee8;background:#fff;border-radius:14px;padding:3px 10px;font:inherit;font-size:12px;color:#33465f;cursor:pointer}
.pw .tot button b{font-variant-numeric:tabular-nums}
.pw .tot button.on{background:#0a5fb4;border-color:#0a5fb4;color:#fff}
.pw .act{position:sticky;top:0;z-index:5;display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:7px 10px;margin:0 0 6px;
         background:#1d2d3e;color:#fff;border-radius:6px;font-size:12.5px}
.pw .act[hidden]{display:none}
.pw .act b{margin-right:6px}
.pw .act .lbl{opacity:.75;margin-left:8px}
.pw .act button{border:1px solid rgba(255,255,255,.35);background:transparent;color:#fff;border-radius:5px;padding:4px 10px;font:inherit;font-size:12px;cursor:pointer}
.pw .act button.add:hover{background:#107e3e;border-color:#107e3e}
.pw .act button.rem:hover{background:#b00;border-color:#b00}
.pw .act button.clr{margin-left:auto}
.pw .tw{border:1px solid #dfe5ec;border-radius:6px;background:#fff;overflow:auto;max-height:calc(100vh - 290px)}
.pw table{width:100%;border-collapse:collapse}
.pw thead th{position:sticky;top:0;background:#f5f7fa;z-index:2;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#6a7a8c;padding:7px 9px;border-bottom:1px solid #dfe5ec;white-space:nowrap}
.pw tbody td{padding:5px 9px;border-bottom:1px solid #eef1f5;vertical-align:middle}
.pw tbody tr:hover td{background:#f7fafd}
.pw tbody tr.sel td{background:#eaf3fc}
.pw td.ck,.pw th.ck{width:30px;text-align:center}
.pw td.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#33465f;white-space:nowrap}
.pw td.n,.pw th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.pw td.dept{color:#5a6b82}
.pw .chips{display:flex;gap:4px;flex-wrap:wrap}
.pw .chip{border:1px solid #d6dee8;background:#fff;color:#8a97ab;border-radius:12px;padding:2px 9px;font:inherit;font-size:11.5px;cursor:pointer;line-height:1.5}
.pw .chip.on{background:#0b2a4a;border-color:#0b2a4a;color:#fff}
.pw .chip:disabled{opacity:.5;cursor:wait}
.pw .left{color:#b45309;font-size:11px;font-weight:700;margin-left:6px}
.pw .none{padding:18px;text-align:center;color:#8a97ab}
.pw .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1d2d3e;color:#fff;padding:9px 16px;border-radius:6px;font-size:12.5px;opacity:0;transition:.2s;pointer-events:none;z-index:50}
.pw .toast.show{opacity:1}
.pw .toast.err{background:#b00}
/* the shared skin paints every button white; these chips and the bar keep their own colours */
.zskin .pw .chip{background:#fff !important;color:#8a97ab !important;border:1px solid #d6dee8 !important;box-shadow:none !important}
.zskin .pw .chip.on{background:#0b2a4a !important;color:#fff !important;border-color:#0b2a4a !important}
.zskin .pw .act button{background:transparent !important;color:#fff !important;border:1px solid rgba(255,255,255,.35) !important;box-shadow:none !important}
.zskin .pw .act button.add:hover{background:#107e3e !important;border-color:#107e3e !important}
.zskin .pw .act button.rem:hover{background:#b00 !important;border-color:#b00 !important}
.zskin .pw .tot button{background:#fff !important;color:#33465f !important;border:1px solid #d6dee8 !important;box-shadow:none !important}
.zskin .pw .tot button.on{background:#0a5fb4 !important;color:#fff !important;border-color:#0a5fb4 !important}
.pw .sub kbd{font-family:ui-monospace,monospace;font-size:10.5px;background:#f4f7fb;border:1px solid #d6dee8;border-bottom-width:2px;border-radius:3px;padding:0 4px}
/* pay type (28 Nov 2026) */
.pw .pt{display:inline-block;font-size:11px;font-weight:700;border-radius:10px;padding:1px 8px;white-space:nowrap}
.pw .pt-salary{background:#e8f1fc;color:#0a4f96}.pw .pt-daily{background:#fff4e2;color:#9a5710}.pw .pt-contract{background:#f1ecfb;color:#5a3fa8}.pw .pt-none{color:#a3adba;font-weight:400}
.pw .tot.pay button[data-pt="salary"].on{background:#0a4f96 !important;border-color:#0a4f96 !important}
.pw .tot.pay button[data-pt="daily"].on{background:#9a5710 !important;border-color:#9a5710 !important}
.pw .tot.pay button[data-pt="contract"].on{background:#5a3fa8 !important;border-color:#5a3fa8 !important}
/* not saved yet: an amber edge on the row, a dashed amber ring on the chip */
.pw tbody tr.chg td:first-child{box-shadow:inset 4px 0 0 #e9a23b}
.zskin .pw .chip.mod{border:2px dashed #e9a23b !important}
.pw .savebar{position:sticky;bottom:0;z-index:6;display:flex;gap:8px;align-items:center;padding:9px 12px;margin-top:8px;background:#fffbea;border:1px solid #f1cf97;border-top:2px solid #e9a23b;border-radius:6px;box-shadow:0 -6px 16px -10px rgba(16,32,56,.35)}
.pw .savebar[hidden]{display:none}
.pw .savebar #svN{flex:1;font-size:13px}
.zskin .pw .savebar button{border-radius:5px !important;padding:6px 14px !important;font:inherit !important;font-weight:700 !important;cursor:pointer;box-shadow:none !important}
.zskin .pw .savebar .sb-dis{background:#fff !important;color:#33465f !important;border:1px solid #c9d3df !important}
.zskin .pw .savebar .sb-go{background:#107e3e !important;color:#fff !important;border:1px solid #107e3e !important}
.zskin .pw .savebar .sb-go:disabled{opacity:.6;cursor:wait}
.pw td.x{width:28px;text-align:center;padding:0 4px}
.zskin .pw .rx{background:transparent !important;border:0 !important;box-shadow:none !important;color:#a3adba !important;font-size:15px;line-height:1;padding:2px 6px !important;cursor:pointer;border-radius:4px !important}
.zskin .pw .rx:hover{background:#fdecec !important;color:#b00 !important}
.zskin .pw .rx.back{color:#107e3e !important;font-size:12px;font-weight:700;white-space:nowrap}
.zskin .pw .rx.back:hover{background:#e8f6ee !important}
.pw tbody tr.gone td{background:#fbf3f3;color:#9aa5b4}
.pw tbody tr.gone td.code,.pw tbody tr.gone td:nth-child(3){text-decoration:line-through}
.pw .gonetag{color:#b00;font-size:11px;font-weight:700;margin-left:6px;text-decoration:none;display:inline-block}
.zskin .pw .act button.out:hover{background:#7a1f1f !important;border-color:#7a1f1f !important}
.pw .flash{margin:8px 0;padding:8px 12px;border-radius:6px;background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
</style>
<?php /* THE SKIN, OPTED IN — see assets/css/zskin.css. */ ?>
<div class="zskin">
<div class="pw">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div><h1>Production Workers</h1>
      <p class="sub"><?= count(array_filter($rows, fn($r) => !$r['hid'])) ?> people &middot; click a stage, or tick people and press Give / Take off &middot; nothing is sent until <b>Save</b> (or <kbd>Ctrl</kbd>+<kbd>S</kbd>)</p></div>
    <a class="chip" style="text-decoration:none;color:#33465f" href="production_entry.php">Daily Entry →</a>
  </div>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <div class="src">People come from <a href="att_finance.php#people">People Finance › Employees</a> — add, rename or mark as left there. Here you only choose <b>which stages</b> each person works. <b>Only people with a stage are production</b>: this list and Daily Entry show them; somebody with no stage (head office, admin…) is left out — find them under <b>No stage</b> and give them a stage to add them. On Daily Entry a job lists only the people ticked for its stage — a Cutting job shows the Cutting people, Stitching the stitchers, Packing the packers, even when you type a name. To let somebody stand in, tick that stage for them here.</div>

  <?php if (!$stages): ?>
    <div class="src">No stages yet. Add them on <a href="production_stages.php">Production Stages</a>.</div>
  <?php endif; ?>

  <div class="bar">
    <input type="search" id="q" placeholder="Search code or name" aria-label="Search code or name" autocomplete="off">
    <select id="fd" aria-label="Department"><option value="">All departments</option>
      <?php foreach ($depts as $d): ?><option><?= e($d) ?></option><?php endforeach; ?></select>
    <select id="fs" aria-label="Stage"><option value="prod">In production (has a stage)</option><option value="">Everyone</option><option value="none">No stage yet</option><option value="hid">Removed (not production)</option>
      <?php foreach ($stages as $s): ?><option value="<?= (int)$s['id'] ?>">On <?= e($s['name']) ?></option><?php endforeach; ?></select>
    <label style="display:flex;gap:5px;align-items:center;color:#5a6b82"><input type="checkbox" id="fl"> Show who has left</label>
    <span class="cnt" id="cnt"></span>
  </div>
  <div class="tot" id="tot"></div>
  <div class="tot pay" id="pay"></div>

  <div class="act" id="act" hidden>
    <b id="selN"></b>
    <span class="lbl">Give:</span>
    <?php foreach ($stages as $s): ?><button type="button" class="add" data-add="<?= (int)$s['id'] ?>">+ <?= e($s['name']) ?></button><?php endforeach; ?>
    <span class="lbl">Take off:</span>
    <?php foreach ($stages as $s): ?><button type="button" class="rem" data-rem="<?= (int)$s['id'] ?>">− <?= e($s['name']) ?></button><?php endforeach; ?>
    <span class="lbl">Not production:</span>
    <button type="button" class="out" id="rmv" title="Take the ticked people off this list and Daily Entry (People Finance and their wages are not touched)">✕ Remove from list</button>
    <button type="button" class="add" id="back" hidden>↩ Put back</button>
    <button type="button" class="clr" id="clr">Untick all</button>
  </div>

  <div class="tw"><table>
    <thead><tr><th class="ck"><input type="checkbox" id="all" aria-label="Tick everyone shown"></th>
      <th>Code</th><th>Name</th><th>Department</th><th>Pay type</th><th>Stages</th><th class="n">Entries</th><th class="n">Earned (PKR)</th><th class="x"></th></tr></thead>
    <tbody id="tb"></tbody>
  </table></div>

  <div class="savebar" id="sv" hidden>
    <span id="svN"></span>
    <button type="button" class="sb-dis" id="svDis">Discard</button>
    <button type="button" class="sb-go" id="svGo">Save</button>
  </div>
  <div class="toast" id="toast"></div>
</div>
</div><?php /* closes .zskin */ ?>

<script>
(function(){
  var ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var STAGES = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']], $stages), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var PAYW = { salary: 'Fixed salary', daily: 'Daily wages', contract: 'Contractor' };
  var $ = function(id){ return document.getElementById(id); };
  var sel = {}, byId = {}, ORIG = {}, ORIGH = {}, payF = '', lastCk = null;
  /* nobody has a stage yet (a new setup): show everyone, or the page would open empty */
  var PRODQ = true;
  if (!ROWS.some(function(r){ return r.st.length; })) document.getElementById('fs').value = '';
  ROWS.forEach(function(r){ byId[r.id] = r; ORIG[r.id] = r.st.slice().sort(function(a, b){ return a - b; }); ORIGH[r.id] = !!r.hid; r.hid = !!r.hid; });
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v){ return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  var tt = null;
  function toast(msg, bad){ var t = $('toast'); t.textContent = msg; t.className = 'toast show' + (bad ? ' err' : ''); clearTimeout(tt); tt = setTimeout(function(){ t.className = 'toast'; }, bad ? 6000 : 2600); }

  /* ---- what is changed and not saved yet: everything stays in this page ---- */
  function sorted(a){ return a.slice().sort(function(x, y){ return x - y; }); }
  function stChanged(r){ return sorted(r.st).join(',') !== ORIG[r.id].join(','); }
  function hidChanged(r){ return r.hid !== ORIGH[r.id]; }
  function changed(r){ return stChanged(r) || hidChanged(r); }
  /* remove = not production: off this list and Daily Entry, stages cleared; undo before Save brings the old stages back */
  function setHid(r, on){
    if (r.hid === on) return;
    r.hid = on;
    if (on) r.st = []; else if (!ORIGH[r.id]) r.st = ORIG[r.id].slice();
  }
  function changedRows(){ return ROWS.filter(changed); }
  function setStage(r, s, on){ var i = r.st.indexOf(s); if (on && i < 0) r.st.push(s); if (!on && i >= 0) r.st.splice(i, 1); }

  function shown(){
    var q = $('q').value.trim().toLowerCase(), d = $('fd').value, s = $('fs').value, left = $('fl').checked;
    return ROWS.filter(function(r){
      if (s === 'hid') { if (!r.hid && !ORIGH[r.id]) return false; }           // the Removed list: who is (or was) taken off
      else if (r.hid && ORIGH[r.id]) return false;                              // removed and saved: gone from every other view
      if (!left && !r.on) return false;
      if (d && r.dept !== d) return false;
      if (payF && (payF === 'none' ? r.pt : r.pt !== payF)) return false;
      if (s === 'prod' && !r.st.length && !(q && PRODQ) && !changed(r)) return false;   // typing a name still finds a no-stage person; a person just changed stays in view
      if (s === 'none' && r.st.length && !changed(r)) return false;
      if (s && s !== 'none' && s !== 'prod' && s !== 'hid' && r.st.indexOf(+s) < 0 && !changed(r)) return false;
      if (q && (r.code + ' ' + r.name).toLowerCase().indexOf(q) < 0) return false;
      return true;
    });
  }
  function totals(){
    var active = ROWS.filter(function(r){ return r.on && !r.hid; });
    var h = STAGES.map(function(s){ var n = active.filter(function(r){ return r.st.indexOf(s.id) >= 0; }).length;
      return '<button type="button" data-fs="' + s.id + '"' + ($('fs').value === String(s.id) ? ' class="on"' : '') + '>' + esc(s.name) + ' <b>' + n + '</b></button>'; });
    var none = active.filter(function(r){ return !r.st.length; }).length;
    h.unshift('<button type="button" data-fs="prod"' + ($('fs').value === 'prod' ? ' class="on"' : '') + '>In production <b>' + (active.length - none) + '</b></button>');
    h.push('<button type="button" data-fs="none"' + ($('fs').value === 'none' ? ' class="on"' : '') + '>No stage <b>' + none + '</b></button>');
    var gone = ROWS.filter(function(r){ return r.hid; }).length;
    if (gone || $('fs').value === 'hid') h.push('<button type="button" data-fs="hid"' + ($('fs').value === 'hid' ? ' class="on"' : '') + ' title="Taken off this list as not production — open to put somebody back">✕ Removed <b>' + gone + '</b></button>');
    $('tot').innerHTML = h.join('');
    /* pay type: counted on the people the other filters leave */
    var base = ROWS.filter(function(r){ return (r.on || $('fl').checked) && ($('fs').value === 'hid' ? r.hid : !r.hid); }), cnt = { salary: 0, daily: 0, contract: 0, none: 0 };
    base.forEach(function(r){ cnt[r.pt && cnt[r.pt] !== undefined ? r.pt : 'none']++; });
    $('pay').innerHTML = '<span style="font-size:12px;color:#6a7a8c;align-self:center;margin-right:2px">Pay type:</span>'
      + [['', 'All', base.length], ['salary', 'Fixed salary', cnt.salary], ['daily', 'Daily wages', cnt.daily], ['contract', 'Contractor', cnt.contract], ['none', 'Not set', cnt.none]]
        .filter(function(x){ return x[0] === '' || x[2] > 0; })
        .map(function(x){ return '<button type="button" data-pt="' + x[0] + '"' + (payF === x[0] ? ' class="on"' : '') + '>' + x[1] + ' <b>' + x[2] + '</b></button>'; }).join('');
  }
  function chips(r){
    if (r.hid) return '<span style="font-size:11.5px;color:#9aa5b4">not production</span>';
    return '<div class="chips">' + STAGES.map(function(s){ var on = r.st.indexOf(s.id) >= 0, mod = (ORIG[r.id].indexOf(s.id) >= 0) !== on;
      return '<button type="button" class="chip' + (on ? ' on' : '') + (mod ? ' mod' : '') + '" data-w="' + r.id + '" data-s="' + s.id + '" title="' + (on ? 'Take ' : 'Give ') + esc(s.name) + (mod ? ' — not saved yet' : '') + '">' + esc(s.name) + '</button>'; }).join('') + '</div>';
  }
  function draw(){
    var list = shown();
    $('tb').innerHTML = list.length ? list.map(function(r){
      return '<tr data-id="' + r.id + '" class="' + (sel[r.id] ? 'sel' : '') + (changed(r) ? ' chg' : '') + (r.hid ? ' gone' : '') + '"><td class="ck"><input type="checkbox" data-ck="' + r.id + '"' + (sel[r.id] ? ' checked' : '') + ' aria-label="Tick ' + esc(r.name) + '"></td>'
        + '<td class="code">' + esc(r.code) + '</td><td>' + esc(r.name) + (r.hid ? '<span class="gonetag">' + (ORIGH[r.id] ? 'removed' : 'removing · not saved') + '</span>' : (ORIGH[r.id] ? '<span class="gonetag" style="color:#107e3e">putting back · not saved</span>' : '')) + (r.on ? '' : '<span class="left">left</span>') + (!r.st.length && !r.hid && $('fs').value === 'prod' ? '<span class="left" style="background:#fff4e5;color:#a35a00">no stage · not production</span>' : '') + '</td><td class="dept">' + esc(r.dept || '—') + '</td>'
        + '<td>' + (PAYW[r.pt] ? '<span class="pt pt-' + r.pt + '">' + PAYW[r.pt] + '</span>' : '<span class="pt pt-none">—</span>') + '</td>'
        + '<td>' + chips(r) + '</td><td class="n">' + r.n + '</td><td class="n">' + money(r.amt) + '</td>'
        + '<td class="x">' + (r.hid ? '<button type="button" class="rx back" data-back="' + r.id + '" title="Put ' + esc(r.name) + ' back on the list">↩ Put back</button>'
                                    : '<button type="button" class="rx" data-rm="' + r.id + '" title="Remove ' + esc(r.name) + ' from this list — not production">✕</button>') + '</td></tr>';
    }).join('') : '<tr><td colspan="9" class="none">' + ($('fs').value === 'hid' ? 'Nobody has been removed.' : 'Nobody matches.') + '</td></tr>';
    $('cnt').textContent = list.length + ' shown of ' + ROWS.filter(function(r){ return r.on && !r.hid; }).length + ' on the roll';
    var allOn = list.length && list.every(function(r){ return sel[r.id]; });
    $('all').checked = !!allOn; $('all').indeterminate = !allOn && list.some(function(r){ return sel[r.id]; });
    totals(); bar(); savebar();
  }
  function selIds(){ return Object.keys(sel).filter(function(k){ return sel[k]; }).map(Number); }
  function bar(){
    var ids = selIds(), n = ids.length; $('act').hidden = !n; $('selN').textContent = n + (n === 1 ? ' person ticked' : ' people ticked');
    var anyHid = ids.some(function(id){ return byId[id] && byId[id].hid; }), anyOn = ids.some(function(id){ return byId[id] && !byId[id].hid; });
    $('rmv').hidden = !anyOn; $('back').hidden = !anyHid;
  }
  function savebar(){
    var ch = changedRows(), give = 0, take = 0, rm = 0, bk = 0;
    ch.forEach(function(r){ if (hidChanged(r)) { if (r.hid) rm++; else bk++; } if (r.hid) return; r.st.forEach(function(s){ if (ORIG[r.id].indexOf(s) < 0) give++; }); ORIG[r.id].forEach(function(s){ if (r.st.indexOf(s) < 0) take++; }); });
    $('sv').hidden = !ch.length;
    $('svN').innerHTML = '<b>' + ch.length + '</b> ' + (ch.length === 1 ? 'person' : 'people') + ' changed · ' + give + ' stage' + (give === 1 ? '' : 's') + ' given, ' + take + ' taken off'
      + (rm ? ' · <b style="color:#b00">' + rm + ' removed from the list</b>' : '') + (bk ? ' · <b style="color:#107e3e">' + bk + ' put back</b>' : '') + ' · not saved yet';
    $('svGo').textContent = 'Save ' + ch.length + ' (Ctrl+S)';
  }

  /* ---- Save: everyone changed, in ONE request, all or nothing ---- */
  var saving = false;
  function save(){
    var ch = changedRows(); if (!ch.length || saving) return;
    var want = {}, was = {}, hide = {};
    ch.forEach(function(r){ if (stChanged(r)) { want[r.id] = sorted(r.st); was[r.id] = ORIG[r.id]; } if (hidChanged(r)) hide[r.id] = r.hid; });
    var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('action', 'stage_save'); fd.append('want', JSON.stringify(want)); fd.append('was', JSON.stringify(was)); fd.append('hide', JSON.stringify(hide));
    saving = true; $('svGo').disabled = true; $('svGo').textContent = 'Saving…';
    fetch('production_workers.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        saving = false; $('svGo').disabled = false;
        if (!d.ok) { savebar(); toast(d.error || 'Not saved.', true); return; }
        Object.keys(d.stages).forEach(function(id){ if (byId[id]) { byId[id].st = d.stages[id].slice(); ORIG[id] = sorted(d.stages[id]); } });
        Object.keys(d.hidden || {}).forEach(function(id){ if (byId[id]) { byId[id].hid = !!d.hidden[id]; ORIGH[id] = !!d.hidden[id]; if (byId[id].hid) sel[id] = false; } });
        draw(); toast('Saved — ' + d.n + (d.n === 1 ? ' person' : ' people') + ' updated.');
      }).catch(function(){ saving = false; $('svGo').disabled = false; savebar(); toast('Not saved — check the connection and press Save again. Your changes are still here.', true); });
  }
  $('svGo').addEventListener('click', save);
  $('svDis').addEventListener('click', function(){
    if (!confirm('Throw away the changes that are not saved?')) return;
    ROWS.forEach(function(r){ r.st = ORIG[r.id].slice(); r.hid = ORIGH[r.id]; }); draw();
  });
  document.addEventListener('keydown', function(e){ if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); save(); } });
  window.addEventListener('beforeunload', function(e){ if (changedRows().length) { e.preventDefault(); e.returnValue = ''; } });

  /* ---- filters, ticks (Shift+click ticks a range), chips, Give / Take off — all on the page ---- */
  ['q', 'fd', 'fs', 'fl'].forEach(function(id){ $(id).addEventListener(id === 'q' ? 'input' : 'change', draw); });
  $('tot').addEventListener('click', function(e){ var b = e.target.closest('button[data-fs]'); if (!b) return;
    $('fs').value = $('fs').value === b.getAttribute('data-fs') ? '' : b.getAttribute('data-fs'); draw(); });
  $('pay').addEventListener('click', function(e){ var b = e.target.closest('button[data-pt]'); if (!b) return; var v = b.getAttribute('data-pt'); payF = payF === v ? '' : v; draw(); });
  $('all').addEventListener('change', function(){ var on = this.checked; shown().forEach(function(r){ sel[r.id] = on; }); draw(); });
  $('clr').addEventListener('click', function(){ sel = {}; draw(); });
  $('tb').addEventListener('click', function(e){
    var ck = e.target.closest('input[data-ck]');
    if (ck) {
      var id = +ck.getAttribute('data-ck'), on = ck.checked;
      if (e.shiftKey && lastCk !== null) {
        var ids = shown().map(function(r){ return r.id; }), a = ids.indexOf(lastCk), b = ids.indexOf(id);
        if (a >= 0 && b >= 0) ids.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function(x){ sel[x] = on; });
      }
      sel[id] = on; lastCk = id; draw(); return;
    }
    var rx = e.target.closest('button[data-rm],button[data-back]');
    if (rx) { var p = byId[+(rx.getAttribute('data-rm') || rx.getAttribute('data-back'))], on = rx.hasAttribute('data-rm');
      setHid(p, on); draw(); toast((on ? 'Removed ' : 'Put back ') + p.name + '. Not saved yet: press Save or Ctrl+S.'); return; }
    var c = e.target.closest('button.chip'); if (!c) return;
    var r = byId[+c.getAttribute('data-w')], s = +c.getAttribute('data-s'); if (r.hid) return;
    setStage(r, s, r.st.indexOf(s) < 0); draw();
  });
  $('act').addEventListener('click', function(e){
    var b = e.target.closest('button[data-add],button[data-rem]'); if (!b) return;
    var ids = selIds(), add = b.hasAttribute('data-add'), s = +(b.getAttribute('data-add') || b.getAttribute('data-rem'));
    var name = (STAGES.filter(function(x){ return x.id === s; })[0] || {}).name || '';
    ids.forEach(function(id){ if (byId[id] && !byId[id].hid) setStage(byId[id], s, add); });
    draw(); toast((add ? 'Given ' : 'Taken off ') + name + ' — ' + ids.length + (ids.length === 1 ? ' person' : ' people') + '. Not saved yet: press Save or Ctrl+S.');
  });
  $('rmv').addEventListener('click', function(){
    var ids = selIds().filter(function(id){ return byId[id] && !byId[id].hid; }); if (!ids.length) return;
    ids.forEach(function(id){ setHid(byId[id], true); sel[id] = false; });
    draw(); toast('Removed ' + ids.length + (ids.length === 1 ? ' person' : ' people') + ' from the list. Not saved yet: press Save or Ctrl+S.');
  });
  $('back').addEventListener('click', function(){
    var ids = selIds().filter(function(id){ return byId[id] && byId[id].hid; }); if (!ids.length) return;
    ids.forEach(function(id){ setHid(byId[id], false); sel[id] = false; });
    draw(); toast('Put back ' + ids.length + (ids.length === 1 ? ' person' : ' people') + '. Not saved yet: press Save or Ctrl+S.');
  });
  draw();
})();
</script>
<?php page_footer(); ?>
