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
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'stage_bulk') {
        header('Content-Type: application/json');
        $r = zp_stage_bulk((array)($_POST['worker_ids'] ?? []), (array)($_POST['add'] ?? []), (array)($_POST['remove'] ?? []));
        echo json_encode($r);
        exit;
    }
}

/* PEOPLE COME FROM PEOPLE FINANCE — copied on every open (see zp_people_sync). */
zp_people_sync(true);
$msg = '';
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

$all    = zp_workers();
$inUse  = zp_people_in_use();
$people = $inUse ? array_values(array_filter($all, fn($w) => $w['att_employee_id'] !== null)) : $all;
$stages = zp_stage_all(true);
$wsMap  = zp_worker_stage_map();
$earned = [];
try {
    foreach (db()->query("SELECT worker_id, COUNT(*) n, SUM(amount) amt FROM zp_entries WHERE status='active' GROUP BY worker_id")->fetchAll() as $r)
        $earned[(int)$r['worker_id']] = ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
} catch (Throwable $e) {}

$rows = array_map(fn($w) => [
    'id' => (int)$w['id'], 'code' => (string)$w['worker_code'], 'name' => (string)$w['worker_name'],
    'dept' => (string)($w['department'] ?? ''), 'on' => (int)$w['is_active'] === 1,
    'st' => array_values(array_map('intval', $wsMap[(int)$w['id']] ?? [])),
    'n' => $earned[(int)$w['id']]['n'] ?? 0, 'amt' => round($earned[(int)$w['id']]['amt'] ?? 0, 2),
], $people);
$depts = array_values(array_unique(array_filter(array_column($rows, 'dept'))));
sort($depts);

page_header('Production Workers');
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
.pw .flash{margin:8px 0;padding:8px 12px;border-radius:6px;background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
</style>
<?php /* THE SKIN, OPTED IN — see assets/css/zskin.css. */ ?>
<div class="zskin">
<div class="pw">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div><h1>Production Workers</h1>
      <p class="sub"><?= count($rows) ?> people &middot; tick people, then press a stage to give it or take it off &middot; saves at once</p></div>
    <a class="chip" style="text-decoration:none;color:#33465f" href="production_entry.php">Daily Entry →</a>
  </div>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <div class="src">People come from <a href="att_finance.php#people">People Finance › Employees</a> — add, rename or mark as left there. Here you only choose <b>which stages</b> each person works. On Daily Entry a stage's list shows the people on it — anybody else is still found by typing their name or code.</div>

  <?php if (!$stages): ?>
    <div class="src">No stages yet. Add them on <a href="production_stages.php">Production Stages</a>.</div>
  <?php endif; ?>

  <div class="bar">
    <input type="search" id="q" placeholder="Search code or name" aria-label="Search code or name" autocomplete="off">
    <select id="fd" aria-label="Department"><option value="">All departments</option>
      <?php foreach ($depts as $d): ?><option><?= e($d) ?></option><?php endforeach; ?></select>
    <select id="fs" aria-label="Stage"><option value="">Any stage</option><option value="none">No stage yet</option>
      <?php foreach ($stages as $s): ?><option value="<?= (int)$s['id'] ?>">On <?= e($s['name']) ?></option><?php endforeach; ?></select>
    <label style="display:flex;gap:5px;align-items:center;color:#5a6b82"><input type="checkbox" id="fl"> Show who has left</label>
    <span class="cnt" id="cnt"></span>
  </div>
  <div class="tot" id="tot"></div>

  <div class="act" id="act" hidden>
    <b id="selN"></b>
    <span class="lbl">Give:</span>
    <?php foreach ($stages as $s): ?><button type="button" class="add" data-add="<?= (int)$s['id'] ?>">+ <?= e($s['name']) ?></button><?php endforeach; ?>
    <span class="lbl">Take off:</span>
    <?php foreach ($stages as $s): ?><button type="button" class="rem" data-rem="<?= (int)$s['id'] ?>">− <?= e($s['name']) ?></button><?php endforeach; ?>
    <button type="button" class="clr" id="clr">Untick all</button>
  </div>

  <div class="tw"><table>
    <thead><tr><th class="ck"><input type="checkbox" id="all" aria-label="Tick everyone shown"></th>
      <th>Code</th><th>Name</th><th>Department</th><th>Stages</th><th class="n">Entries</th><th class="n">Earned (PKR)</th></tr></thead>
    <tbody id="tb"></tbody>
  </table></div>

  <div class="toast" id="toast"></div>
</div>
</div><?php /* closes .zskin */ ?>

<script>
(function(){
  var ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var STAGES = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']], $stages), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var $ = function(id){ return document.getElementById(id); };
  var sel = {}, byId = {};
  ROWS.forEach(function(r){ byId[r.id] = r; });
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function money(v){ return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  var tt = null;
  function toast(msg, bad){ var t = $('toast'); t.textContent = msg; t.className = 'toast show' + (bad ? ' err' : ''); clearTimeout(tt); tt = setTimeout(function(){ t.className = 'toast'; }, 2600); }

  function shown(){
    var q = $('q').value.trim().toLowerCase(), d = $('fd').value, s = $('fs').value, left = $('fl').checked;
    return ROWS.filter(function(r){
      if (!left && !r.on) return false;
      if (d && r.dept !== d) return false;
      if (s === 'none' && r.st.length) return false;
      if (s && s !== 'none' && r.st.indexOf(+s) < 0) return false;
      if (q && (r.code + ' ' + r.name).toLowerCase().indexOf(q) < 0) return false;
      return true;
    });
  }
  function totals(){
    var active = ROWS.filter(function(r){ return r.on; });
    var h = STAGES.map(function(s){ var n = active.filter(function(r){ return r.st.indexOf(s.id) >= 0; }).length;
      return '<button type="button" data-fs="' + s.id + '"' + ($('fs').value === String(s.id) ? ' class="on"' : '') + '>' + esc(s.name) + ' <b>' + n + '</b></button>'; });
    var none = active.filter(function(r){ return !r.st.length; }).length;
    h.push('<button type="button" data-fs="none"' + ($('fs').value === 'none' ? ' class="on"' : '') + '>No stage <b>' + none + '</b></button>');
    $('tot').innerHTML = h.join('');
  }
  function chips(r){
    return '<div class="chips">' + STAGES.map(function(s){ var on = r.st.indexOf(s.id) >= 0;
      return '<button type="button" class="chip' + (on ? ' on' : '') + '" data-w="' + r.id + '" data-s="' + s.id + '" title="' + (on ? 'Take ' : 'Give ') + esc(s.name) + '">' + esc(s.name) + '</button>'; }).join('') + '</div>';
  }
  function draw(){
    var list = shown();
    $('tb').innerHTML = list.length ? list.map(function(r){
      return '<tr data-id="' + r.id + '"' + (sel[r.id] ? ' class="sel"' : '') + '><td class="ck"><input type="checkbox" data-ck="' + r.id + '"' + (sel[r.id] ? ' checked' : '') + ' aria-label="Tick ' + esc(r.name) + '"></td>'
        + '<td class="code">' + esc(r.code) + '</td><td>' + esc(r.name) + (r.on ? '' : '<span class="left">left</span>') + '</td><td class="dept">' + esc(r.dept || '—') + '</td>'
        + '<td>' + chips(r) + '</td><td class="n">' + r.n + '</td><td class="n">' + money(r.amt) + '</td></tr>';
    }).join('') : '<tr><td colspan="7" class="none">Nobody matches.</td></tr>';
    $('cnt').textContent = list.length + ' shown of ' + ROWS.filter(function(r){ return r.on; }).length + ' on the roll';
    var allOn = list.length && list.every(function(r){ return sel[r.id]; });
    $('all').checked = !!allOn; $('all').indeterminate = !allOn && list.some(function(r){ return sel[r.id]; });
    totals(); bar();
  }
  function selIds(){ return Object.keys(sel).filter(function(k){ return sel[k]; }).map(Number); }
  function bar(){ var n = selIds().length; $('act').hidden = !n; $('selN').textContent = n + (n === 1 ? ' person ticked' : ' people ticked'); }

  function send(ids, add, rem, done){
    var fd = new FormData();
    fd.append('_csrf', CSRF); fd.append('action', 'stage_bulk');
    ids.forEach(function(i){ fd.append('worker_ids[]', i); });
    add.forEach(function(i){ fd.append('add[]', i); });
    rem.forEach(function(i){ fd.append('remove[]', i); });
    fetch('production_workers.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) { toast(d.error || 'Not saved.', true); done && done(false); return; }
        Object.keys(d.stages).forEach(function(id){ if (byId[id]) byId[id].st = d.stages[id]; });
        done && done(true, d);
        draw();
      }).catch(function(){ toast('Not saved — check the connection.', true); done && done(false); });
  }

  ['q', 'fd', 'fs', 'fl'].forEach(function(id){ $(id).addEventListener(id === 'q' ? 'input' : 'change', draw); });
  $('tot').addEventListener('click', function(e){ var b = e.target.closest('button[data-fs]'); if (!b) return;
    $('fs').value = $('fs').value === b.getAttribute('data-fs') ? '' : b.getAttribute('data-fs'); draw(); });
  $('all').addEventListener('change', function(){ var on = this.checked; shown().forEach(function(r){ sel[r.id] = on; }); draw(); });
  $('clr').addEventListener('click', function(){ sel = {}; draw(); });
  $('tb').addEventListener('change', function(e){ var c = e.target.closest('input[data-ck]'); if (!c) return;
    sel[+c.getAttribute('data-ck')] = c.checked; c.closest('tr').classList.toggle('sel', c.checked);
    var list = shown(), allOn = list.every(function(r){ return sel[r.id]; }); $('all').checked = allOn; $('all').indeterminate = !allOn && list.some(function(r){ return sel[r.id]; }); bar(); });
  $('tb').addEventListener('click', function(e){
    var c = e.target.closest('button.chip'); if (!c) return;
    var w = +c.getAttribute('data-w'), s = +c.getAttribute('data-s'), on = c.classList.contains('on');
    c.disabled = true;
    send([w], on ? [] : [s], on ? [s] : [], function(ok){ if (ok) toast((on ? 'Taken off: ' : 'Given: ') + c.textContent + ' — ' + byId[w].name); else c.disabled = false; });
  });
  $('act').addEventListener('click', function(e){
    var b = e.target.closest('button[data-add],button[data-rem]'); if (!b) return;
    var ids = selIds(), add = b.hasAttribute('data-add'), s = +(b.getAttribute('data-add') || b.getAttribute('data-rem'));
    var name = (STAGES.filter(function(x){ return x.id === s; })[0] || {}).name || '';
    if (ids.length > 25 && !confirm((add ? 'Give ' : 'Take off ') + name + ' for ' + ids.length + ' people?')) return;
    send(ids, add ? [s] : [], add ? [] : [s], function(ok, d){ if (ok) toast((add ? 'Given ' : 'Taken off ') + name + ' — ' + d.n + (d.n === 1 ? ' person' : ' people')); });
  });
  draw();
})();
</script>
<?php page_footer(); ?>
