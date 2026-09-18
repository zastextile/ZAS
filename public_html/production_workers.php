<?php
/*
  PRODUCTION WORKERS — the people on the machines.
  ================================================

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $r = zp_save_worker(
            (int)($_POST['worker_id'] ?? 0),
            (string)($_POST['worker_code'] ?? ''),
            (string)($_POST['worker_name'] ?? ''),
            (string)($_POST['department'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0
        );
        if ($r['ok']) {
            /* SAVED AGAINST THE ID THE SAVE RETURNED, not the one that was
               posted — on a new worker the posted id is 0 and the ticks would
               be written against nobody. */
            zp_save_worker_stages((int)$r['id'], (array)($_POST['stage'] ?? []));
            $_SESSION['zp_msg'] = 'Worker saved.';
            redirect('production_workers.php');
        }
        $err = $r['error'];
        $editId = (int)($_POST['worker_id'] ?? 0);
    } elseif ($a === 'import') {
        /* THE PASTED SHEET COMES BACK AS FOUR PARALLEL ARRAYS, one per
           column, because that is what a grid of named inputs posts. They
           are zipped into rows here rather than in the engine — the engine
           should not have to know what shape a form happens to be. */
        $rows = [];
        $n = max(count((array)($_POST['w_code'] ?? [])), count((array)($_POST['w_name'] ?? [])));
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'code'   => $_POST['w_code'][$i]   ?? '',
                'name'   => $_POST['w_name'][$i]   ?? '',
                'dept'   => $_POST['w_dept'][$i]   ?? '',
                'stages' => $_POST['w_stages'][$i] ?? '',
            ];
        }
        $r = zp_import_workers($rows);
        if ($r['ok']) {
            $msgTxt = $r['saved'] . ' worker' . ($r['saved'] === 1 ? '' : 's') . ' added.';
            if ($r['warn']) $msgTxt .= ' ' . count($r['warn']) . ' of those names were already on the list ('
                . e(implode(', ', array_slice($r['warn'], 0, 4)))
                . (count($r['warn']) > 4 ? ' and ' . (count($r['warn']) - 4) . ' more' : '')
                . ') — check they are different people.';
            $_SESSION['zp_msg'] = $msgTxt;
            redirect('production_workers.php');
        }
        /* NOTHING WAS SAVED, so the sheet has to come back with the paste
           still on it. Sending them away to a blank grid would mean pasting
           two hundred rows again to fix one typo. */
        $err = implode(' | ', array_slice($r['errors'], 0, 8))
             . (count($r['errors']) > 8 ? ' … and ' . (count($r['errors']) - 8) . ' more' : '');
        $impRows = $rows;
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
    } elseif ($a === 'hrsave') {
        /* ONLY AN ADMIN SETS THE ADDRESS AND THE TOKEN. Everyone who can
           reach this screen may press Update; nobody but an admin may point
           it somewhere else. */
        if (!is_admin()) { $err = 'Only an admin can change the HR connection.'; }
        else {
            $r = zp_hr_save_settings((string)($_POST['hr_url'] ?? ''), (string)($_POST['hr_token'] ?? ''));
            if ($r['ok']) { $_SESSION['zp_msg'] = 'HR connection saved.'; redirect('production_workers.php#hr'); }
            $err = $r['error'];
        }
    } elseif ($a === 'hrcheck') {
        /* CHECK READS AND SHOWS. It writes nothing at all — the plan goes on
           screen and he decides. A sync that acts first and reports after is
           a sync nobody trusts twice. */
        $f = zp_hr_fetch();
        if (!$f['ok']) $err = $f['error'];
        else { $hrPlan = zp_hr_plan($f['rows']); $hrTotal = count($f['rows']); }
    } elseif ($a === 'hrapply') {
        /* APPLY RE-READS. It does not trust a plan posted back from the
           browser: between the check and the click, HR may have changed and
           the form could be made to say anything. The plan is worked out
           again here, from the live answer, and that is what is written. */
        $f = zp_hr_fetch();
        if (!$f['ok']) $err = $f['error'];
        else {
            $plan = zp_hr_plan($f['rows']);
            $r = zp_hr_apply($plan);
            if (!$r['ok']) $err = implode(' | ', array_slice($r['errors'], 0, 6));
            else {
                $parts = [];
                if ($r['added'])   $parts[] = $r['added'] . ' new worker' . ($r['added'] === 1 ? '' : 's') . ' added';
                if ($r['changed']) $parts[] = $r['changed'] . ' updated';
                if (!$parts)       $parts[] = 'Nothing changed — the list already matches HR';
                if ($plan['gone']) $parts[] = count($plan['gone']) . ' no longer in HR (left here untouched — see the list)';
                $_SESSION['zp_msg'] = implode('. ', $parts) . '.';
                redirect('production_workers.php#hr');
            }
        }
    } elseif ($a === 'delete') {
        $r = zp_delete_worker((int)($_POST['worker_id'] ?? 0));
        $_SESSION['zp_msg'] = $r['msg'];
        redirect('production_workers.php');
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

/* THE ALERT IS NOT ONLY SHOWN RIGHT AFTER A SYNC. Somebody who has left HR
   but is still on this list is a standing fact, not a one-off message, so
   the last known list is kept and shown every time the screen opens. He
   asked for an alert, and an alert you have to press a button to see is a
   report. */
$hrPlan  = $hrPlan  ?? null;
$hrTotal = $hrTotal ?? 0;

$workers = zp_workers();
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

/* The paste grid: whatever came back from a refused import, or six blank
   rows to start. Six because a grid with one row looks like a form; six
   looks like somewhere to paste. */
if (!isset($impRows) || !$impRows) {
    $impRows = array_fill(0, 6, ['code' => '', 'name' => '', 'dept' => '', 'stages' => '']);
}
$deptList = function_exists('inv_departments') ? [] : [];
foreach ($workers as $w) {
    $d = trim((string)$w['department']);
    if ($d !== '' && !in_array($d, $deptList, true)) $deptList[] = $d;
}
sort($deptList);

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

  <?php /* ==================================================================
           THE HR APP IS THE MASTER LIST OF PEOPLE
           ==================================================================
           One button. It reads the HR app, works out what WOULD change, and
           prints it. Nothing is written until Apply is pressed.

           Nobody is ever removed from here, and no stage allotment is ever
           touched — both of those are his rules, stated on screen so the
           person pressing the button knows them without asking. */ ?>
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

  <div class="zp-card" id="hr">
    <h2>Workers from the HR app</h2>
    <p class="sub">Employee number, name and department come from HR. Stages stay yours.
      The employee number becomes the worker code here, exactly as HR spells it —
      that is what matches a person between the two systems, so it is never
      shortened or cut. Up to <?= ZP_CODE_MAX ?> characters; anything longer is
      refused by name rather than silently trimmed.</p>

    <?php if (!zp_hr_configured()): ?>
      <div class="note info" style="margin-bottom:11px">
        Not connected yet<?= is_admin() ? ' — put the address and the token in below.' : '. Ask an admin to set it up.' ?>
      </div>
    <?php else: ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:11px">
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="action" value="hrcheck">
          <button class="zp-b pri" type="submit">Update from HR</button></form>
        <span style="font-size:11.5px;color:#8a97ab">
          <?= e(zp_hr_url()) ?> · token <?= e(zp_hr_token_masked()) ?>
          <?php $last = zp_meta_get('hr_last_sync'); if ($last): ?>
            · last applied <?= e($last) ?>
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>

    <?php if ($hrPlan !== null):
        $nAdd = count($hrPlan['add']); $nChg = count($hrPlan['change']); $nGone = count($hrPlan['gone']); ?>
      <div class="note <?= ($nAdd || $nChg) ? 'info' : 'ok' ?>" style="margin-bottom:11px">
        <b>HR answered with <?= (int)$hrTotal ?> employee(s).</b>
        <?php if (!$nAdd && !$nChg): ?>
          Nothing to change — the list here already matches.
        <?php else: ?>
          <?= $nAdd ?> to add, <?= $nChg ?> to update, <?= (int)$hrPlan['same'] ?> already correct.
        <?php endif; ?>
      </div>

      <?php if ($nAdd): ?>
        <h3 style="font-size:12.5px;margin:12px 0 5px;color:#152033">New people — these will be added</h3>
        <table class="zp-t"><thead><tr><th style="width:110px">Employee no.</th><th>Name</th><th>Department</th></tr></thead>
          <tbody><?php foreach ($hrPlan['add'] as $r): ?>
            <tr><td class="code"><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td>
                <td><?= $r['dept'] !== '' ? e($r['dept']) : '<span style="color:#b6c0cf">not stated</span>' ?></td></tr>
          <?php endforeach; ?></tbody></table>
      <?php endif; ?>

      <?php if ($nChg): ?>
        <h3 style="font-size:12.5px;margin:12px 0 5px;color:#152033">Changed in HR — these will be updated here</h3>
        <table class="zp-t"><thead><tr><th style="width:110px">Employee no.</th><th>What changes</th></tr></thead>
          <tbody><?php foreach ($hrPlan['change'] as $c): ?>
            <tr><td class="code"><?= e($c['code']) ?></td><td>
              <?php foreach ($c['diff'] as $field => $pair): ?>
                <div><?= $field === 'name' ? 'Name' : 'Department' ?>:
                  <span style="color:#8a97ab;text-decoration:line-through"><?= $pair[0] !== '' ? e($pair[0]) : '(blank)' ?></span>
                  &rarr; <b><?= $pair[1] !== '' ? e($pair[1]) : '(blank)' ?></b></div>
              <?php endforeach; ?>
            </td></tr>
          <?php endforeach; ?></tbody></table>
      <?php endif; ?>

      <?php /* THE ALERT HE ASKED FOR, IN SO MANY WORDS: "if any id or worker
               removed so you do not remove here even if its started job
               somewhere but give me alert". Nothing below is touched. */ ?>
      <?php if ($nGone): ?>
        <div class="note warn" style="margin-top:12px">
          <b><?= $nGone ?> worker(s) here are no longer in the HR app.</b>
          Nothing has been done to them — they are still active, still on every
          picker, and every wage already booked keeps their name on it. Switch
          anyone off by hand below if they have really left.
        </div>
        <table class="zp-t"><thead><tr><th style="width:110px">Employee no.</th><th>Name</th>
          <th>Department</th><th class="r" style="width:120px">Entries booked</th></tr></thead>
          <tbody><?php foreach ($hrPlan['gone'] as $g): ?>
            <tr><td class="code"><?= e($g['code']) ?></td><td><?= e($g['name']) ?></td>
                <td><?= e($g['dept']) ?></td>
                <td class="r"><?= $g['entries'] > 0
                    ? '<b style="color:#9a2740">' . number_format($g['entries']) . '</b>'
                    : '<span style="color:#b6c0cf">none</span>' ?></td></tr>
          <?php endforeach; ?></tbody></table>
      <?php endif; ?>

      <?php if ($nAdd || $nChg): ?>
        <form method="post" style="margin-top:12px"><?= csrf_field() ?>
          <input type="hidden" name="action" value="hrapply">
          <button class="zp-b ok" type="submit">Apply — add <?= $nAdd ?>, update <?= $nChg ?></button>
          <span style="font-size:11.5px;color:#8a97ab;margin-left:8px">
            Nobody is removed. No stage allotment is touched.</span>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (is_admin()): ?>
      <details style="margin-top:13px"<?= zp_hr_configured() ? '' : ' open' ?>>
        <summary style="cursor:pointer;font-size:12px;color:#5a6b82">Connection settings</summary>
        <form method="post" style="margin-top:9px"><?= csrf_field() ?>
          <input type="hidden" name="action" value="hrsave">
          <div class="fgrid">
            <div style="grid-column:span 3">
              <span class="lab">Address of the employee list</span>
              <input class="zin" name="hr_url" value="<?= e(zp_hr_url()) ?>"
                     placeholder="http://portal.example.com/zes/emp.php" autocomplete="off">
            </div>
            <div style="grid-column:span 2">
              <span class="lab">Token</span>
              <?php /* THE TOKEN IS NEVER PRINTED BACK. The box shows a mask,
                       and saving with the mask still in it leaves the stored
                       token alone — see zp_hr_save_settings(). */ ?>
              <input class="zin" name="hr_token" type="text" autocomplete="off"
                     placeholder="<?= zp_hr_token() !== '' ? e(zp_hr_token_masked()) . ' — leave blank to keep it' : 'paste the bearer token' ?>">
            </div>
          </div>
          <button class="zp-b" type="submit" style="margin-top:9px">Save connection</button>
          <p style="font-size:11px;color:#8a97ab;margin:8px 0 0">
            The token is stored in this app's own settings, never in a file that
            gets uploaded, and it is never shown in full again. If the address
            starts with <b>http://</b> rather than https, the token travels
            across your network in clear text — that is a matter for your network,
            not something this screen can fix, but you should know it.</p>
        </form>
      </details>
    <?php endif; ?>
  </div>

  <div class="zp-card">
    <h2><?= $edit ? 'Edit ' . e($edit['worker_name']) : 'Add a worker' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <input type="hidden" name="worker_id" value="<?= (int)$editId ?>">
      <div class="fgrid" style="margin-top:12px">
        <div>
          <span class="lab">Code</span>
          <input class="zin code" name="worker_code" maxlength="<?= ZP_CODE_MAX ?>" autocomplete="off"
                 value="<?= e($edit['worker_code'] ?? '') ?>" placeholder="leave blank"
                 title="Leave it blank and the next free code is used. You never have to invent one.">
        </div>
        <div style="grid-column:span 2">
          <span class="lab">Name</span>
          <input class="zin" name="worker_name" required maxlength="120" autocomplete="off"
                 value="<?= e($edit['worker_name'] ?? '') ?>" placeholder="Muhammad Aslam">
        </div>
        <div>
          <span class="lab">Department / Floor</span>
          <input class="zin" name="department" list="deptList" maxlength="60" autocomplete="off"
                 value="<?= e($edit['department'] ?? '') ?>" placeholder="Stitching Floor 1">
          <datalist id="deptList"><?php foreach ($depts as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
        </div>
        <div style="display:flex;align-items:flex-end">
          <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer;padding-bottom:7px">
            <input type="checkbox" name="is_active" value="1" <?= ($edit ? (int)$edit['is_active'] : 1) ? 'checked' : '' ?>>
            Active</label>
        </div>
      </div>

      <?php /* THE STAGES THIS PERSON WORKS.
               Checkboxes dressed as chips — a real <input type="checkbox">
               under each one, so the form posts without a line of JavaScript
               and works with the keyboard. Nothing ticked is a valid answer
               and it means NOT PRODUCTION, which is what the strip below
               says — see the rule at the top of this file. */ ?>
      <div style="margin-top:13px">
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
        <div class="zw-hint">
          <?= $editWs
              ? 'Untick them all and ' . e($edit['worker_name'] ?? 'this worker')
                . ' stops being offered on any production list — which is right for office and'
                . ' kitchen staff, and keeps every wage already booked to them.'
              : 'Nothing ticked = not offered on any production list. Use the department table'
                . ' above to set a whole floor at once.' ?>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
        <button class="zp-b pri"><?= $edit ? 'Save Changes' : 'Add Worker' ?></button>
        <?php if ($edit): ?><a class="zp-b" href="production_workers.php">New worker instead</a><?php endif; ?>
        <span style="font-size:11.5px;color:#8a97ab;align-self:center">
          Leave the code blank and the next free one is given automatically.</span>
      </div>
    </form>
  </div>

  <?php /* MANY AT ONCE. Three hundred people typed one at a time is a week
           of somebody's life. The same keyboard and the same paste that the
           Proforma and the Commercial Invoice grids use — assets/js/grid.js,
           shared so the three cannot drift apart. */ ?>
  <div class="zp-card">
    <h2><?= $edit ? 'Or add many at once' : 'Add many at once' ?></h2>
    <p style="margin:3px 0 12px;font-size:12.5px;color:#8a97ab;line-height:1.55">
      Copy the columns out of Excel &mdash; <b>Code, Name, Department, Stages</b> &mdash; click the first cell
      and paste. Rows are added as they are needed.
      <b>Leave Code empty</b> and the next free number is given. Several stages go in one cell,
      separated by a comma.</p>

    <form method="post" id="impForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <div style="overflow-x:auto">
      <table class="zp-t" id="impTbl">
        <thead><tr>
          <th style="width:34px">#</th>
          <th style="width:110px">Code</th>
          <th style="min-width:180px">Name</th>
          <th style="min-width:150px">Department</th>
          <th style="min-width:180px">Stages</th>
          <th style="width:32px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($impRows as $ix => $ir): ?>
          <tr>
            <td class="code"><?= $ix + 1 ?></td>
            <td><input class="zin code" data-c="code" name="w_code[]" maxlength="<?= ZP_CODE_MAX ?>" autocomplete="off"
                       placeholder="auto" value="<?= e((string)($ir['code'] ?? '')) ?>"></td>
            <td><input class="zin" data-c="name" name="w_name[]" maxlength="120" autocomplete="off"
                       value="<?= e((string)($ir['name'] ?? '')) ?>"></td>
            <td><input class="zin" data-c="dept" data-lov="wdept" name="w_dept[]" maxlength="60" autocomplete="off"
                       placeholder="Enter opens the list"
                       value="<?= e((string)($ir['dept'] ?? '')) ?>"></td>
            <td><input class="zin" data-c="stages" data-lov="wstage" name="w_stages[]" maxlength="200" autocomplete="off"
                       placeholder="<?= $stages ? 'Enter opens the list' : 'none set up yet' ?>"
                       value="<?= e((string)($ir['stages'] ?? '')) ?>"></td>
            <td><button type="button" class="zp-b sm red" tabindex="-1" onclick="zpImpDrop(this)">&times;</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div style="display:flex;gap:9px;margin-top:12px;flex-wrap:wrap;align-items:center">
        <button class="zp-b pri">Add these workers</button>
        <button type="button" class="zp-b" onclick="zpImpAdd()">+ Row</button>
        <button type="button" class="zp-b" onclick="zpImpClear()">Clear the sheet</button>
        <span style="font-size:11.5px;color:#8a97ab" id="impCount"></span>
      </div>
      <div class="note" style="margin-top:12px">
        <b>All of them go in, or none of them do.</b>
        A sheet half-saved is worse than one refused &mdash; you cannot tell which half went in, and
        pasting it again to be sure would create every one of them twice. If a row is wrong it is named,
        nothing is written, and your paste stays on screen to correct.
      </div>
    </form>
  </div>

  <div class="zp-card">
    <h2>Everyone</h2>
    <?php if (!$workers): ?>
      <div class="empty">No workers yet. Add the first one above &mdash; you only need a name.</div>
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
            <a class="zp-b sm" href="production_workers.php?w=<?= $wid ?>">Edit</a>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Remove <?= e(addslashes($w['worker_name'])) ?>?\n\nIf they have any production entries they will be set inactive instead, so their wages keep their name.')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="worker_id" value="<?= $wid ?>">
              <button class="zp-b sm red">Remove</button>
            </form>
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

    <div class="note" style="margin-top:14px">
      <b>Removing somebody who has worked does not delete them.</b>
      Their name is on every entry they were paid for. They are set <b>inactive</b> instead: off every picker
      from now on, still on every wage record that already exists. Only a worker with no entries at all is
      really deleted.
    </div>
  </div>
</div>
</div><?php /* closes .zskin */ ?>

<link rel="stylesheet" href="assets/css/lov.css?v=3">
<script src="assets/js/lov.js?v=3"></script>
<script src="assets/js/grid.js?v=2"></script>
<script>
var WDEPTS  = <?= json_encode(array_values($deptList), JSON_UNESCAPED_UNICODE) ?>;
var WSTAGES = <?= json_encode(array_map(fn($s) => (string)$s['name'], $stages), JSON_UNESCAPED_UNICODE) ?>;

/* TWO LISTS, OPENED BY THE KEYBOARD.
   These were <datalist> boxes, which need the mouse or a guess at which
   arrow key the browser decided on. They are the same picker the stock
   screens use now: focus or Enter opens it, typing narrows it, Enter takes
   the row, Escape puts it back. */
if (window.LOV) {
  /* WHAT IS ALREADY CHOSEN, AND WHAT IS BEING TYPED NOW.
     The stages cell holds a LIST, and the picker hands the whole cell over
     as the search text. A cell reading "Packing" therefore searched for
     "Packing" the instant it reopened, found only Packing, and a second
     stage could never be added — found by driving it, not by reading it.

     Everything that is already a real stage name is CHOSEN. Whatever is
     left over is what is being typed. So "Packing" searches for nothing and
     offers the lot; "Packing, st" searches for "st"; and "st" typed into an
     empty cell searches for "st" as it always did. */
  function wsPick(v){
    return String(v || '').split(/[,\/;|]+/).map(function(x){ return x.trim(); })
             .filter(function(x){ return x !== ''; });
  }
  function wsChosen(v){
    return wsPick(v).filter(function(x){
      return WSTAGES.some(function(t){ return t.toLowerCase() === x.toLowerCase(); }); });
  }
  function wsTyping(v){
    var parts = wsPick(v);
    if (!parts.length) return '';
    var last = parts[parts.length - 1];
    /* the last piece is only a search if it is not itself a chosen stage */
    return WSTAGES.some(function(t){ return t.toLowerCase() === last.toLowerCase(); }) ? '' : last;
  }
  LOV.register('wdept', {
    cols: [{ label:'Department', w:'1fr', cls:'nm', get:function(r,q){ return LOV.hl(r.v, q); } }],
    title: function(){ return 'Department'; },
    empty: function(f, q){
      return WDEPTS.length ? 'No department matches “' + LOV.esc(q) + '”. Type it in — it will be added.'
                           : 'No departments yet. Type one in.';
    },
    rows: function(f, q, showAll, cb){
      var out = [];
      WDEPTS.forEach(function(v){ var sc = LOV.score(q, v, '', ''); if(sc > 0) out.push({ v:v, sc:sc }); });
      out.sort(function(a,b){ return a.sc !== b.sc ? b.sc - a.sc : a.v.localeCompare(b.v); });
      cb(out, 0);
    },
    pick: function(f, r){ f.value = r.v; }
  });

  LOV.register('wstage', {
    cols: [
      { label:'Stage', w:'1fr', cls:'nm', get:function(r,q){ return LOV.hl(r.v, wsTyping(q)); } },
      { label:'', w:'92px', cls:'gg',
        get:function(r){ return r.on ? '<span style="color:#0b5f8a;font-weight:700">already on</span>' : ''; } }
    ],
    /* ENTER ADDS ONE AND LEAVES THE CURSOR WHERE IT IS, so the next Enter
       opens the list again and adds another. Without this the grid moves
       down a row the instant the list closes, and a second stage can only
       be added by coming back to the cell. */
    stayOnEnter: true,
    title: function(f){
      /* A PERSON CAN WORK SEVERAL STAGES, so the list says what taking one
         will do rather than leaving the operator to wonder whether it
         replaces what is already in the cell. */
      return (f && f.value.trim()) ? 'Add another stage — Enter again for more' : 'Stage';
    },
    empty: function(){ return WSTAGES.length ? 'No stage matches that.' : 'No stages set up yet.'; },
    rows: function(f, q, showAll, cb){
      var has = wsChosen(f.value).map(function(x){ return x.toLowerCase(); });
      var q2 = wsTyping(q);
      var out = [];
      WSTAGES.forEach(function(v){
        var sc = LOV.score(q2, v, '', ''); if(sc <= 0) return;
        out.push({ v:v, on: has.indexOf(v.toLowerCase()) >= 0, sc:sc });
      });
      /* The ones not yet on this person first — those are the ones being
         looked for. */
      out.sort(function(a,b){
        if(a.on !== b.on) return a.on ? 1 : -1;
        return a.sc !== b.sc ? b.sc - a.sc : a.v.localeCompare(b.v);
      });
      cb(out, 0);
    },
    pick: function(f, r){
      /* ADDS, IT DOES NOT REPLACE — and taking the same one twice does not
         put it in twice. wsChosen() drops the half-typed word that found the
         row, so it is never left behind in the cell as a fifth stage. */
      var cur = wsChosen(f.value);
      if(!cur.some(function(x){ return x.toLowerCase() === r.v.toLowerCase(); })) cur.push(r.v);
      f.value = cur.join(', ');
    },
    /* Typed something, chose nothing, tabbed away: put back only the stages
       that are really stages, so a half-typed word never survives as one. */
    revert: function(f){ f.value = wsChosen(f.value).join(', '); }
  });
}
</script>
<script>
/* THE SAME GRID THE PROFORMA AND THE INVOICE USE.
   Tab across, Enter down, paste a block from Excel and it fills across and
   down adding rows as it needs them. Shared rather than re-written, so the
   three cannot learn different keys. */
(function(){
  var tb = document.querySelector('#impTbl tbody');
  if(!tb || !window.GRID) return;

  function renumber(){
    [].forEach.call(tb.rows, function(tr, i){
      var c = tr.cells[0]; if(c) c.textContent = i + 1;
    });
    count();
  }
  /* WHAT WILL ACTUALLY BE ADDED, said before the button is pressed. A
     sheet of sixty rows where eleven are blank should not be a surprise
     at the other end. */
  function count(){
    var n = 0;
    [].forEach.call(tb.rows, function(tr){
      var f = tr.querySelector('[data-c="name"]');
      if(f && f.value.trim() !== '') n++;
    });
    var el = document.getElementById('impCount');
    if(el) el.textContent = n ? n + ' row' + (n === 1 ? '' : 's') + ' with a name — the rest are ignored'
                              : 'nothing to add yet';
  }

  function blankRow(){
    var last = tb.rows[tb.rows.length - 1];
    var tr = last.cloneNode(true);
    [].forEach.call(tr.querySelectorAll('input'), function(el){ el.value = ''; });
    tb.appendChild(tr);
    renumber();
    return tr;
  }

  window.zpImpAdd = function(){
    var tr = blankRow();
    var f = tr.querySelector('[data-c="code"]'); if(f) f.focus();
  };
  window.zpImpDrop = function(btn){
    var tr = btn.closest('tr'); if(!tr) return;
    /* Never leave the sheet with no rows at all — there would be nothing
       left to paste into and nothing to clone the next row from. */
    if(tb.rows.length > 1) tr.remove();
    else [].forEach.call(tr.querySelectorAll('input'), function(el){ el.value = ''; });
    renumber();
  };
  window.zpImpClear = function(){
    while(tb.rows.length > 1) tb.rows[tb.rows.length - 1].remove();
    [].forEach.call(tb.rows[0].querySelectorAll('input'), function(el){ el.value = ''; });
    renumber();
    var f = tb.rows[0].querySelector('[data-c="code"]'); if(f) f.focus();
  };

  /* LOV FIRST, GRID SECOND. Both listen on this same table, and the one
     registered first sees the key first. With the picker first, Enter opens
     the list and stops there; the grid's own check (window.LOV.isOpen) then
     covers the other order too, so between them it cannot go wrong. */
  if (window.LOV) LOV.attach(tb);
  GRID.attach(tb, {
    cols: ['code','name','dept','stages'],
    addRow: blankRow,
    afterChange: count
  });
  count();
})();
</script>
<?php page_footer(); ?>
