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
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$editId = (int)($_GET['w'] ?? 0);
$msg = ''; $err = '';

/* PEOPLE COME FROM PEOPLE FINANCE (owner's decision, 18 Nov 2026).
   Code, name, department and on-the-roll are copied from People Finance ›
   Employees by zp_people_sync() — nobody types or pastes a person here, and
   the external HR app is no longer read. What this screen still decides is
   production's own business: which stages each person works. */
zp_people_sync(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'stages') {
        $wid = (int)($_POST['worker_id'] ?? 0);
        $ok = false;
        try { $st = db()->prepare("SELECT id FROM zp_workers WHERE id = ?"); $st->execute([$wid]); $ok = (bool)$st->fetchColumn(); } catch (Throwable $e) {}
        if ($ok) {
            zp_save_worker_stages($wid, (array)($_POST['stage'] ?? []));
            $_SESSION['zp_msg'] = 'Stages saved.';
            redirect('production_workers.php');
        }
        $err = 'That worker was not found.';
    } elseif ($a === 'deptstage') {
        /* THE WHOLE POINT OF THIS ACTION IS THAT IT IS NOT ONE WORKER.
           The form posts stage[<department>][] — a department that appears
           with no boxes ticked is a real answer ("these people do no
           production"), so the list of departments ON THE FORM is what is
           acted on, not the list of departments that happen to have ticks. */
        $want = [];
        foreach ((array)($_POST['dept'] ?? []) as $d) {
            $d = (string)$d;
            $want[$d] = array_map('intval', (array)($_POST['stage'][$d] ?? []));
        }
        $r = zp_apply_dept_stages($want, ($_POST['mode'] ?? 'set') !== 'add');
        if ($r['ok']) {
            $_SESSION['zp_msg'] = 'Stages applied to ' . number_format($r['people']) . ' worker'
                . ($r['people'] === 1 ? '' : 's') . ' across ' . $r['depts'] . ' department'
                . ($r['depts'] === 1 ? '' : 's') . '.';
            redirect('production_workers.php#bulk');
        }
        $err = $r['error'];
    } elseif ($a === 'retire') {
        /* ONLY FOR A WORKER NOBODY IN PEOPLE FINANCE MATCHES. Set inactive, never
           deleted: every wage already booked keeps their name. */
        $wid = (int)($_POST['worker_id'] ?? 0);
        try {
            $st = db()->prepare("UPDATE zp_workers SET is_active = 0 WHERE id = ? AND att_employee_id IS NULL");
            $st->execute([$wid]);
            $_SESSION['zp_msg'] = $st->rowCount() ? 'Made inactive. Their production entries and wages are kept.' : 'Nothing changed — that worker is in People Finance; change them there.';
        } catch (Throwable $e) { $_SESSION['zp_msg'] = 'Could not change that worker.'; }
        redirect('production_workers.php#notinpeople');
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }


$all = zp_workers();
$inUse = zp_people_in_use();
/* the main list is the people from People Finance; the rest are listed apart, below */
$workers = $inUse ? array_values(array_filter($all, fn($w) => $w['att_employee_id'] !== null)) : $all;
$notIn = zp_workers_not_in_people();
$edit = null;
foreach ($workers as $w) if ((int)$w['id'] === $editId) { $edit = $w; break; }

/* how much each has earned, and how many entries — one query, not one per row */
$earned = [];
try {
    foreach (db()->query("SELECT worker_id, COUNT(*) n, SUM(amount) amt
                          FROM zp_entries WHERE status='active' GROUP BY worker_id")->fetchAll() as $r)
        $earned[(int)$r['worker_id']] = ['n' => (int)$r['n'], 'amt' => (float)$r['amt']];
} catch (Throwable $e) {}

$depts = [];
foreach ($workers as $w) if (trim((string)$w['department']) !== '' && !in_array($w['department'], $depts, true)) $depts[] = $w['department'];
sort($depts);

/* Where the stage allotment has got to, department by department — the
   number that tells him what is left to do. */
$deptPic = zp_dept_stage_picture();

/* The stage list and every worker's allotment — two queries for the whole
   screen, not two per row. */
$stages   = zp_stage_all(true);
$wsMap    = zp_worker_stage_map();
$editWs   = $editId > 0 ? ($wsMap[$editId] ?? []) : [];
$stageNm  = [];
foreach (zp_stage_all(false) as $s) $stageNm[(int)$s['id']] = (string)$s['name'];
/* How many people are on each stage — the number that tells you whether the
   allotment is worth anything yet. A stage nobody is on narrows nothing. */
$stageCount = [];
foreach ($wsMap as $wid => $sids) foreach ($sids as $sid) $stageCount[$sid] = ($stageCount[$sid] ?? 0) + 1;
$anyStage = count(array_filter($workers, fn($w) => empty($wsMap[(int)$w['id']])));


page_header('Production Workers');
?>
<style>
.zw-wrap{max-width:1180px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}.zp-b.ok:hover{background:#12823b;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9}
table.zp-t tbody tr:hover{background:#fafcff}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
/* A long employee number wraps inside its own cell instead of pushing
   every other column off the screen. */
.wrapcode{max-width:170px;word-break:break-all;line-height:1.3}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.on{background:rgba(22,163,74,.13);color:#15803d}
.pill.off{background:#eef1f6;color:#8a97ab}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
/* Three kinds of news on the HR card: what will change, what went well,
   and who has left HR but is still here. Only the last one is a warning. */
.note.info{background:#eef6ff;border-color:#cfe3fb;color:#28527d}
.note.ok{background:#f4fbf6;border-color:#cfe9d8;color:#1d6b46}
.note.warn{background:#fff7f8;border-color:#f3ccd4;color:#9a2740}
.zp-card p.sub{margin:0 0 11px;font-size:11.5px;color:#8a97ab}
table.zp-t td.r,table.zp-t th.r{text-align:right;font-variant-numeric:tabular-nums}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
/* A chip IS a checkbox. The box itself is hidden and the label carries the
   look, so the form posts with no JavaScript and the keyboard still works —
   tab to it, space to tick. :has() paints the ticked state live; browsers
   without it fall back to the .on class PHP already put there, which is
   correct on load and only stops updating until the page is saved. */
.zw-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px}
.zw-chip{display:inline-flex;align-items:center;height:28px;padding:0 11px;border-radius:14px;
         border:1px solid #d9e0ea;background:#fff;font-size:12px;font-weight:700;color:#5a6b82;
         cursor:pointer;white-space:nowrap;user-select:none;text-decoration:none;line-height:1}
.zw-chip input{position:absolute;opacity:0;width:0;height:0}
.zw-chip.on,.zw-chip:has(input:checked){background:#0b2a4a;border-color:#0b2a4a;color:#fff}
.zw-chip:has(input:focus-visible){box-shadow:0 0 0 3px rgba(14,168,201,.3)}
.zw-chip.add{border-style:dashed;color:#8a97ab;font-weight:600}
.zw-hint{margin-top:6px;font-size:11.5px;color:#8a97ab}
.zw-tag{display:inline-block;padding:1px 8px;margin:1px 3px 1px 0;border-radius:10px;font-size:11px;
        font-weight:700;background:#eef4ff;border:1px solid #cfe0fb;color:#1d4ea8}
.zw-tag.any{background:#f4f6fa;border-color:#e6ebf2;color:#8a97ab;font-style:italic;font-weight:600}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zw-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Production Workers</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        <?= count($workers) ?> on the list &middot;
        <?= count(array_filter($workers, fn($w) => (int)$w['is_active'] === 1)) ?> active</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <a class="zp-b" href="production_entry.php">Daily Entry</a>
      <span class="code" style="font-size:10px;color:#a7b2c4"
            title="Modified date of this file on the server.">build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>
    </div>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="note" style="margin-bottom:14px">
    <b>People come from <a href="att_finance.php#people">People Finance › Employees</a>.</b>
    Code, name, department and who has left are taken from there by themselves &mdash; add or change a person there,
    and it shows here and on Daily Entry. This screen decides only <b>which stages</b> each person works.
  </div>

  <?php /* ==================================================================
           STAGES BY DEPARTMENT — two hundred edits become ten
           ==================================================================
           "each worker edit then choose stage dificult for me for the first
            time so guide me to apply stage as ease as see we have more than
            200 worker just sync"

           The answer was already in what HR sent: every worker carries a
           department, and a department is what decides the work. Tick the
           stages a department works, press Apply, and everybody in it is
           done at once. Leave a department with NOTHING ticked and its
           people do no production — which is the only honest thing to say
           about Head Office and the kitchen.

           A worker can still be edited one at a time below; this is the
           first pass, not a replacement for it. */ ?>
  <div class="zp-card" id="bulk">
    <h2>Set stages a whole department at a time</h2>
    <p class="sub">The fastest way through a fresh sync. Tick what a department works and apply it to
      everyone in it — then correct the few exceptions by hand below.</p>

    <?php if (!$stages): ?>
      <div class="note warn">There are no stages set up yet. Add them in
        <a href="production_stages.php">Stages</a> first — Cutting, Stitching, Packing and whatever
        else your floor does.</div>
    <?php elseif (!$deptPic): ?>
      <div class="note info">No workers yet.</div>
    <?php else: ?>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="deptstage">
        <table class="zp-t">
          <thead><tr>
            <th>Department</th>
            <th class="r" style="width:74px">People</th>
            <?php foreach ($stages as $st): ?>
              <th style="width:104px"><?= e($st['name']) ?></th>
            <?php endforeach; ?>
            <th style="width:150px">Where it stands</th>
          </tr></thead>
          <tbody>
          <?php foreach ($deptPic as $dept => $p): ?>
            <tr>
              <td><b><?= e($dept) ?></b>
                  <input type="hidden" name="dept[]" value="<?= e($dept) ?>"></td>
              <td class="r"><?= number_format($p['n']) ?></td>
              <?php foreach ($stages as $st):
                  $sid = (int)$st['id'];
                  $have = (int)($p['stages'][$sid] ?? 0);
                  /* TICKED WHEN EVERYBODY IN THE DEPARTMENT ALREADY HAS IT.
                     Ticking it when only some do would make Apply quietly
                     spread one person's allotment across their whole floor. */
                  $all = $have > 0 && $have === $p['n']; ?>
                <td style="text-align:center">
                  <label style="display:block;cursor:pointer">
                    <input type="checkbox" name="stage[<?= e($dept) ?>][]" value="<?= $sid ?>" <?= $all ? 'checked' : '' ?>>
                    <?php if ($have > 0 && !$all): ?>
                      <span style="display:block;font-size:9.5px;color:#9a5710"><?= $have ?> of <?= $p['n'] ?></span>
                    <?php endif; ?>
                  </label>
                </td>
              <?php endforeach; ?>
              <td>
                <?php if ($p['none'] === $p['n']): ?>
                  <span style="font-size:11px;color:#9a2740">none set — not on any production list</span>
                <?php elseif ($p['none'] > 0): ?>
                  <span style="font-size:11px;color:#9a5710"><?= $p['none'] ?> still with none</span>
                <?php else: ?>
                  <span style="font-size:11px;color:#1d6b46">all set</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:11px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
          <button class="zp-b pri" type="submit">Apply to every department above</button>
          <?php /* REPLACE IS THE DEFAULT AND SAYS SO. "Add" exists for the
                   second pass — giving the whole garment unit Packing as well
                   without losing the Stitching they already have. */ ?>
          <label style="font-size:12px;color:#5a6b82;display:inline-flex;gap:6px;align-items:center;cursor:pointer">
            <input type="radio" name="mode" value="set" checked> Replace what each person has</label>
          <label style="font-size:12px;color:#5a6b82;display:inline-flex;gap:6px;align-items:center;cursor:pointer">
            <input type="radio" name="mode" value="add"> Add to it</label>
        </div>
        <p style="font-size:11px;color:#8a97ab;margin:9px 0 0;line-height:1.55">
          A department with nothing ticked means <b>these people do no production</b> — they stay on
          this list, keep every wage already booked, and simply stop being offered on the entry
          screen. That is how Head Office and the kitchen are meant to be left.
          <br>Only active workers are touched. Anyone switched off keeps what they had.</p>
      </form>

      <?php /* THE SAFETY NET, SAID OUT LOUD. A stage nobody is on yet still
               offers the whole floor, so he can do one stage today and the
               rest keep working exactly as they do now. Without this said,
               the first Apply looks like it did nothing. */ ?>
      <?php
        $wide = [];
        foreach ($stages as $st) if (empty($stageCount[(int)$st['id']])) $wide[] = (string)$st['name'];
      ?>
      <div class="note <?= $wide ? 'info' : 'ok' ?>" style="margin-top:12px">
        <?php if ($wide): ?>
          <b>Still offering everybody:</b> <?= e(implode(', ', $wide)) ?>.
          A stage nobody has been put on yet offers the whole floor, on purpose — so you can set up
          one stage at a time and nothing goes dark while you work. As soon as one person is put on
          a stage, that stage only offers the people on it.
        <?php else: ?>
          <b>Every stage now has people on it</b>, so every production list is narrowed to the right
          floor. Typing a name still reaches anybody.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($edit): ?>
  <div class="zp-card" id="stages">
    <h2>Stages &mdash; <?= e($edit['worker_name']) ?> <span class="code" style="font-weight:400">(<?= e($edit['worker_code']) ?><?= $edit['department'] ? ' · ' . e($edit['department']) : '' ?>)</span></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="stages">
      <input type="hidden" name="worker_id" value="<?= (int)$editId ?>">
      <div style="margin-top:10px">
        <span class="lab">Stages this person works &mdash; tick any number</span>
        <?php if (!$stages): ?>
          <div class="note">No stages are set up yet. Add them on
            <a href="production_stages.php">Production Stages</a> and they will appear here.</div>
        <?php else: ?>
        <div class="zw-chips">
          <?php foreach ($stages as $s): $sid = (int)$s['id']; $on = in_array($sid, $editWs, true); ?>
            <label class="zw-chip<?= $on ? ' on' : '' ?>">
              <input type="checkbox" name="stage[]" value="<?= $sid ?>" <?= $on ? 'checked' : '' ?>>
              <span><?= e($s['name']) ?></span>
            </label>
          <?php endforeach; ?>
          <a class="zw-chip add" href="production_stages.php">+ add a stage</a>
        </div>
        <div class="zw-hint">Nothing ticked = not offered on any production list (office and kitchen staff). Wages already booked are always kept.</div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
        <button class="zp-b pri">Save stages</button>
        <a class="zp-b" href="production_workers.php">Close</a>
        <span style="font-size:11.5px;color:#8a97ab;align-self:center">Name, code or department wrong? Change them in
          <a href="att_finance.php#people">People Finance › Employees</a>.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="zp-card">
    <h2>Everyone</h2>
    <?php if (!$workers): ?>
      <div class="empty">Nobody yet. Add people in <a href="att_finance.php#people">People Finance › Employees</a> &mdash; they appear here by themselves.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="zp-t">
      <thead><tr>
        <th style="width:40px">#</th><th style="width:76px">Code</th><th>Name</th>
        <th style="width:150px">Department</th><th style="min-width:200px">Stages</th><th style="width:80px">Status</th>
        <th class="num" style="width:70px">Entries</th><th class="num" style="width:110px">Earned (PKR)</th>
        <th style="width:150px">Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($workers as $i => $w): $wid = (int)$w['id']; $e2 = $earned[$wid] ?? ['n' => 0, 'amt' => 0]; ?>
        <tr>
          <td class="code"><?= $i + 1 ?></td>
          <?php /* SHOWN WHOLE, ALWAYS. A reference like PI-260908-786 can be
                   shortened because its tail identifies it; an employee number
                   cannot, because two people's numbers may differ only in the
                   part that would be dropped. So a long code wraps rather than
                   being cut, and the column is allowed to grow a little. */ ?>
          <td class="code wrapcode"><b><?= e($w['worker_code']) ?></b></td>
          <td><?= e($w['worker_name']) ?></td>
          <td style="color:#5a6b82"><?= e($w['department'] ?: '—') ?></td>
          <?php /* A stage that has since been renamed still reads correctly here
                   because the allotment is held by id; a stage that was deleted
                   outright is skipped rather than printed as a bare number. */ ?>
          <td><?php $mine = $wsMap[$wid] ?? []; if (!$mine): ?>
              <span class="zw-tag any">any stage</span>
            <?php else: foreach ($mine as $sid): if (!isset($stageNm[$sid])) continue; ?>
              <span class="zw-tag"><?= e($stageNm[$sid]) ?></span>
            <?php endforeach; endif; ?></td>
          <td><?= $w['is_active'] ? '<span class="pill on">Active</span>' : '<span class="pill off">Inactive</span>' ?></td>
          <td class="num"><?= number_format($e2['n']) ?></td>
          <td class="num"><?= number_format($e2['amt'], 2) ?></td>
          <td style="white-space:nowrap">
            <a class="zp-b sm" href="production_workers.php?w=<?= $wid ?>#stages">Stages</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($stages): ?>
    <div class="note" style="margin-top:14px">
      <b>The stage narrows the list on Daily Entry — it never blocks anybody.</b>
      Book a job and the people allotted to that stage are offered first; everyone else is one click
      away behind <b>show all</b>, and the entry saves normally. A filter that refused work which really
      happened would be worse than no filter.
      <?php if ($anyStage): ?>
        <br><br><?= $anyStage === count($workers)
          ? 'Nothing is allotted yet, so every list still shows all ' . count($workers) . ' — exactly as before. Tick stages on the people you are sure about and the lists shorten as you go.'
          : '<b>' . $anyStage . '</b> of ' . count($workers) . ' have no stage ticked and are still offered everywhere.' ?>
      <?php endif; ?>
      <?php if ($stageCount): ?>
        <br><br><?php $bits = [];
          foreach ($stages as $s) { $sid = (int)$s['id'];
            $bits[] = e($s['name']) . ' <b>' . (int)($stageCount[$sid] ?? 0) . '</b>'; }
          echo 'On each stage: ' . implode(' &middot; ', $bits); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>

  <?php if ($notIn): ?>
  <div class="zp-card" id="notinpeople">
    <h2>Not in People Finance (<?= count($notIn) ?>)</h2>
    <p class="sub">Old production workers nobody in People Finance matches. They are <b>not offered</b> on Daily Entry any more;
      every entry and wage already booked to them is kept. To keep one working: add them in
      <a href="att_finance.php#people">People Finance › Employees</a> with this code (or put it as their
      <i>Production worker code</i>) &mdash; they join up by themselves. Otherwise make them inactive.</p>
    <table class="zp-t">
      <thead><tr><th>Code</th><th>Name</th><th>Department</th><th class="num">Entries</th><th class="num">Earned (PKR)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($notIn as $w): $wid = (int)$w['id']; $e2 = $earned[$wid] ?? ['n' => 0, 'amt' => 0]; ?>
        <tr>
          <td class="code wrapcode"><b><?= e($w['worker_code']) ?></b></td><td><?= e($w['worker_name']) ?></td>
          <td style="color:#5a6b82"><?= e($w['department'] ?: '—') ?></td>
          <td class="num"><?= number_format($e2['n']) ?></td><td class="num"><?= number_format($e2['amt'], 2) ?></td>
          <td><form method="post" style="display:inline" onsubmit="return confirm('Make <?= e(addslashes($w['worker_name'])) ?> inactive?\n\nTheir entries and wages are kept.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="retire"><input type="hidden" name="worker_id" value="<?= $wid ?>">
              <button class="zp-b sm">Make inactive</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>

<?php page_footer(); ?>
