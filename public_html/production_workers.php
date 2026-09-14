<?php
/*
  PRODUCTION WORKERS — the people on the machines.
  ================================================

  Deliberately the smallest screen in the module: a code, a name, a department,
  active or not. Everything else about a worker — what they made, what they were
  paid — lives in the entries and is read from there.

  TWO RULES.

  1. A CODE IS GIVEN, NOT DEMANDED. Ask a data-entry clerk to invent a unique
     code for 200 workers and you get W1, w1 and W01 for the same person. Leave
     it blank and the next free W001 is used.

  2. A WORKER WITH WAGES IS NEVER DELETED. Their name is on every entry they
     were paid for. Removing the row would leave those wages belonging to
     nobody, and no report could ever explain the gap. They go inactive
     instead — off every picker, still on every payslip that already exists.
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
        if ($r['ok']) { $_SESSION['zp_msg'] = 'Worker saved.'; redirect('production_workers.php'); }
        $err = $r['error'];
        $editId = (int)($_POST['worker_id'] ?? 0);
    } elseif ($a === 'delete') {
        $r = zp_delete_worker((int)($_POST['worker_id'] ?? 0));
        $_SESSION['zp_msg'] = $r['msg'];
        redirect('production_workers.php');
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

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

page_header('Production Workers');
?>
<style>
.zw-wrap{max-width:1180px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
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
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.on{background:rgba(22,163,74,.13);color:#15803d}
.pill.off{background:#eef1f6;color:#8a97ab}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
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

  <div class="zp-card">
    <h2><?= $edit ? 'Edit ' . e($edit['worker_name']) : 'Add a worker' ?></h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <input type="hidden" name="worker_id" value="<?= (int)$editId ?>">
      <div class="fgrid" style="margin-top:12px">
        <div>
          <span class="lab">Code</span>
          <input class="zin code" name="worker_code" maxlength="20" autocomplete="off"
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
      <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
        <button class="zp-b pri"><?= $edit ? 'Save Changes' : 'Add Worker' ?></button>
        <?php if ($edit): ?><a class="zp-b" href="production_workers.php">New worker instead</a><?php endif; ?>
        <span style="font-size:11.5px;color:#8a97ab;align-self:center">
          Leave the code blank and the next free one is given automatically.</span>
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
        <th style="width:170px">Department</th><th style="width:80px">Status</th>
        <th class="num" style="width:70px">Entries</th><th class="num" style="width:110px">Earned (PKR)</th>
        <th style="width:150px">Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($workers as $i => $w): $wid = (int)$w['id']; $e2 = $earned[$wid] ?? ['n' => 0, 'amt' => 0]; ?>
        <tr>
          <td class="code"><?= $i + 1 ?></td>
          <td class="code"><b><?= e($w['worker_code']) ?></b></td>
          <td><?= e($w['worker_name']) ?></td>
          <td style="color:#5a6b82"><?= e($w['department'] ?: '—') ?></td>
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

    <div class="note" style="margin-top:14px">
      <b>Removing somebody who has worked does not delete them.</b>
      Their name is on every entry they were paid for. They are set <b>inactive</b> instead: off every picker
      from now on, still on every wage record that already exists. Only a worker with no entries at all is
      really deleted.
    </div>
  </div>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
