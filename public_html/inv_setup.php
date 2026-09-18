<?php
/* Inventory module — installer, health check and settings.
   Admin only. Safe to open any number of times: creating the schema is
   idempotent, and nothing here writes to any existing table except the
   additive permission columns on `users` (all defaulting to 0). */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admin access required.'); }
require_once __DIR__ . '/includes/inventory.php';
/* This page names the three production stages, so it must be able to ask
   whether stage 3 is switched on. Without this require, production_dispatch_on()
   below is an undefined function and the whole settings page dies white. */
require_once __DIR__ . '/includes/production.php';

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($action === 'install') {
        inv_ensure_schema();
        inv_audit('module_install', '', 'schema created/verified', 'Inventory module setup');
        $_SESSION['flash'] = 'Inventory schema created and verified. Nothing existing was modified.';
        redirect('inv_setup.php');
    }
    if ($action === 'save_settings') {
        inv_ensure_schema();
        foreach (['stage1_label','stage2_label','stage3_label','backdate_days','default_location','neg_tolerance_pct','cost_variance_pct','gst_pct'] as $k) {
            if (isset($_POST[$k])) inv_set_setting($k, trim((string)$_POST[$k]));
        }
        foreach ($_POST['prefix'] ?? [] as $k => $v) {
            if (strpos($k, 'prefix_') === 0) inv_set_setting($k, strtoupper(trim((string)$v)));
        }
        inv_audit('settings_save', '', 'module settings updated', '');
        $_SESSION['flash'] = 'Settings saved.';
        redirect('inv_setup.php');
    }
    if ($action === 'add_location') {
        inv_ensure_schema();
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $kind = $_POST['kind'] ?? 'store';
        if ($code === '' || $name === '') { $_SESSION['error'] = 'Location code and name are both required.'; }
        else {
            try {
                db()->prepare("INSERT INTO inv_locations (code,name,kind) VALUES (?,?,?)")->execute([$code, $name, $kind]);
                inv_audit('location_add', '', ['code' => $code, 'name' => $name], '');
                $_SESSION['flash'] = 'Location added.';
            } catch (Throwable $e) { $_SESSION['error'] = 'Could not add location — the code may already exist.'; }
        }
        redirect('inv_setup.php');
    }
    if ($action === 'toggle_location') {
        inv_ensure_schema();
        $id = (int)($_POST['id'] ?? 0);
        try { db()->prepare("UPDATE inv_locations SET is_active = 1 - is_active WHERE id=?")->execute([$id]); } catch (Throwable $e) {}
        redirect('inv_setup.php');
    }
    /* ---- departments: the master list a document picks from ---- */
    if ($action === 'add_department') {
        inv_ensure_schema();
        $r = inv_dept_add((string)($_POST['name'] ?? ''));
        if ($r['ok']) { inv_audit('dept_add', '', ['name' => $r['name']], ''); $_SESSION['flash'] = 'Department added.'; }
        else          { $_SESSION['error'] = $r['error']; }
        redirect('inv_setup.php');
    }
    if ($action === 'toggle_department') {
        inv_ensure_schema();
        inv_dept_toggle((int)($_POST['id'] ?? 0));
        redirect('inv_setup.php');
    }
    /* ADOPT A SPELLING THE DOCUMENTS ALREADY USE.
       The whole reason to have a master list is that three spellings became
       three departments. This puts one of them on the list without retyping
       it, so what is already posted and what is picked from now on agree. */
    if ($action === 'adopt_department') {
        inv_ensure_schema();
        $r = inv_dept_add((string)($_POST['name'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok']
            ? 'Adopted "' . $r['name'] . '" — documents already using it now match the list.'
            : $r['error'];
        redirect('inv_setup.php');
    }
    if ($action === 'seed_materials') {
        inv_ensure_schema();
        $before = 0;
        try { $before = (int)db()->query("SELECT COUNT(*) FROM inv_materials")->fetchColumn(); } catch (Throwable $e) {}
        inv_seed_materials_preview(true);
        $after = 0;
        try { $after = (int)db()->query("SELECT COUNT(*) FROM inv_materials")->fetchColumn(); } catch (Throwable $e) {}
        inv_audit('materials_seed', $before, $after, 'Seeded from existing costing lines');
        $_SESSION['flash'] = ($after - $before) . ' material(s) created from your existing costings.';
        redirect('inv_setup.php');
    }
    /* Move whatever is still sitting in the retired Packing group. Nothing
       is rewritten on upload — this only runs when you press the button,
       and you choose the destination. */
    if ($action === 'retire_packing') {
        inv_ensure_schema();
        $to = in_array($_POST['to'] ?? '', ['Accessories', 'Other'], true) ? $_POST['to'] : 'Accessories';
        $alsoLines = !empty($_POST['also_lines']);
        $items = 0; $lines = 0;
        try {
            $st = db()->prepare("UPDATE inv_materials SET item_group=?, updated_at=NOW() WHERE item_group='Packing'");
            $st->execute([$to]); $items = $st->rowCount();
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not move the items: ' . $e->getMessage(); }
        if ($alsoLines) {
            try {
                $st2 = db()->prepare("UPDATE costing_lines SET line_group=? WHERE line_group='Packing'");
                $st2->execute([$to]); $lines = $st2->rowCount();
            } catch (Throwable $e) { $_SESSION['error'] = 'Items moved, but the costing lines did not: ' . $e->getMessage(); }
        }
        inv_audit('retire_packing', 'Packing', $to, "$items item(s), $lines costing line(s) moved");
        $_SESSION['flash'] = $items . ' item(s)' . ($alsoLines ? ' and ' . $lines . ' costing line(s)' : '')
            . ' moved from Packing to ' . $to . '.'
            . ($alsoLines && $lines ? ' Re-save or re-print an affected costing to see the new Net / Packing weight split.' : '');
        redirect('inv_setup.php');
    }
    if ($action === 'grant') {
        inv_ensure_schema();
        $uid = (int)($_POST['user_id'] ?? 0);
        $flags = ['view','gate','store','consume','post','adjust','master'];
        $sets = []; $vals = [];
        foreach ($flags as $f) { $sets[] = "inv_$f = ?"; $vals[] = isset($_POST['p'][$f]) ? 1 : 0; }
        $vals[] = $uid;
        try {
            db()->prepare("UPDATE users SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
            cache_bump('users');
            inv_audit('permissions', $uid, $_POST['p'] ?? [], 'Inventory permissions updated');
            $_SESSION['flash'] = 'Permissions updated.';
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not update permissions.'; }
        redirect('inv_setup.php');
    }
}

/* ---- current state ---- */
$tables = ['inv_locations','inv_parties','inv_materials','inv_contracts','inv_contract_items',
    'inv_gate','inv_gate_items','inv_store_move','inv_store_move_items','inv_consumption',
    'inv_consumption_items','inv_stock_ledger','inv_jobwork_charges','inv_allocations',
    'inv_order_charges','inv_settings'];
$state = []; $installed = true;
foreach ($tables as $t) {
    try { $state[$t] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    catch (Throwable $e) { $state[$t] = null; $installed = false; }
}

$permCols = true;
try { db()->query("SELECT inv_view FROM users LIMIT 1")->fetchColumn(); } catch (Throwable $e) { $permCols = false; }

$locations = $installed ? inv_locations(false) : [];
$seedPreview = $installed ? inv_seed_materials_preview(false) : [];
$seedNew = array_values(array_filter($seedPreview, fn($s) => !$s['exists']));
/* THE ONES WORTH LOOKING AT BEFORE PRESSING ANYTHING. A name that is not in
   the master but is very close to something that IS will become a second
   item for the same cloth the moment Create is pressed — and no screen ever
   shows that mistake afterwards. */
$seedNear = array_values(array_filter($seedNew, fn($s) => !empty($s['near'])));

$users = [];
try { $users = db()->query("SELECT id,name,email,role,inv_view,inv_gate,inv_store,inv_consume,inv_post,inv_adjust,inv_master FROM users WHERE is_active=1 AND role <> 'customer' ORDER BY role,name")->fetchAll(); }
catch (Throwable $e) { $users = []; }

$editUser = null;
if (!empty($_GET['user'])) {
    foreach ($users as $u2) if ((int)$u2['id'] === (int)$_GET['user']) $editUser = $u2;
}

page_header('Inventory Setup');
flash();
?>
<div class="topbar">
  <div><h1>Inventory Setup</h1><p class="lead">Install, verify and configure the Store &amp; Inventory module. Nothing here modifies your existing shipments, costings, proformas or production data.</p></div>
  <?php if ($installed): ?><a class="zbtn sec" href="inv_items.php">Item Master →</a><?php endif; ?>
</div>

<style>
.iv-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.iv-card h2{font-size:15.5px;margin:0 0 4px;font-weight:800}
.iv-card .sub{color:#8a97ab;font-size:12px;margin:0 0 16px}
.iv-grid{display:grid;gap:14px}
.iv-g2{grid-template-columns:1fr 1fr}.iv-g3{grid-template-columns:repeat(3,1fr)}.iv-g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:900px){.iv-g2,.iv-g3,.iv-g4{grid-template-columns:1fr}}
.iv-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.iv-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px}
.iv-tbl td{padding:3px 8px;border-top:1px solid #eef1f7}
.iv-tbl td.r,.iv-tbl th.r{text-align:right}
.iv-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.iv-ok{background:rgba(22,163,74,.10);color:#16a34a}
.iv-no{background:rgba(224,67,93,.10);color:#c0293f}
.iv-warn{background:rgba(217,119,6,.12);color:#d97706}
.iv-inp{width:100%;padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit}
.iv-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.iv-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.iv-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3}
.iv-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65}
.iv-note.ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.22);color:#1c5334}
.iv-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.iv-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
</style>

<?php /* OPTING IN TO THE SKIN — with a second word, and it is load-bearing.
         inv_setup.php and inv_verify.php BOTH use the .iv-* vocabulary,
         and they disagree about two names: .iv-btn is a primary on one
         page and a secondary on the other, and .iv-ok is a small badge on
         one and a full-width panel on the other. A single .zskin .iv-ok
         rule would turn one into the other.

         Rather than rewrite either page's classes, each wrapper says
         which page it is. The skin writes the two names that disagree
         against "ivset" alone. One word, no markup rewritten. */ ?>
<div class="zskin ivset">

<?php if (!$installed): ?>
<div class="iv-card">
  <h2>Step 1 — Install the module</h2>
  <p class="sub">Creates <?= count($tables) ?> new tables, all prefixed <code>inv_</code>, plus seven permission columns on the users table (every one defaulting to 0, so nobody gains access).</p>
  <div class="iv-note info" style="margin-bottom:16px">
    <b>What this does NOT do:</b> it does not alter, rename or drop any existing column; it does not write a single row to shipments, proformas, costings, products or production tables; and it does not change any existing screen. If you later decide against the module, dropping the <code>inv_</code> tables removes it completely.
  </div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="install">
    <button class="iv-btn" type="submit">Install inventory schema</button>
  </form>
</div>
<?php else: ?>

<div class="iv-card">
  <h2>Module health</h2>
  <p class="sub">Every table and its current row count. Re-running the installer is always safe.</p>
  <div class="iv-grid iv-g4">
    <?php foreach ($state as $t => $n): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 11px;background:#f7f9fc;border-radius:9px;font-size:12px">
        <span style="font-family:monospace;color:#5a6b82"><?= e(str_replace('inv_', '', $t)) ?></span>
        <?php if ($n === null): ?><span class="iv-pill iv-no">missing</span>
        <?php else: ?><b style="font-variant-numeric:tabular-nums"><?= (int)$n ?></b><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="install">
      <button class="iv-btn sec" type="submit">Re-verify schema</button>
    </form>
    <span style="font-size:12px;color:#5a6b82">Permission columns on users:
      <?php if ($permCols): ?><span class="iv-pill iv-ok">present</span><?php else: ?><span class="iv-pill iv-no">missing — re-run verify</span><?php endif; ?>
    </span>
  </div>
</div>

<div class="iv-card">
  <h2>Locations</h2>
  <p class="sub">Every stock movement records where it happened. Five sensible defaults were created; add your own units or stores as needed.</p>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>Code</th><th>Name</th><th>Kind</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($locations as $l): ?>
      <tr>
        <td style="font-family:monospace;font-weight:700"><?= e($l['code']) ?></td>
        <td><?= e($l['name']) ?></td>
        <td><?= e(ucfirst($l['kind'])) ?><?php if ($l['kind'] === 'custody'): ?> <span class="iv-pill iv-warn">not valued</span><?php endif; ?></td>
        <td><?= $l['is_active'] ? '<span class="iv-pill iv-ok">Active</span>' : '<span class="iv-pill iv-no">Inactive</span>' ?></td>
        <td class="r"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_location"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="iv-btn sec" style="padding:5px 11px;font-size:11.5px" type="submit"><?= $l['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="add_location">
    <div class="iv-grid iv-g4" style="align-items:end">
      <div><label class="iv-lbl">Code</label><input class="iv-inp" name="code" placeholder="UNIT2" maxlength="20"></div>
      <div><label class="iv-lbl">Name</label><input class="iv-inp" name="name" placeholder="Unit 2 Fabric Store" maxlength="120"></div>
      <div><label class="iv-lbl">Kind</label>
        <select class="iv-inp" name="kind">
          <option value="store">Store — raw material</option>
          <option value="floor">Production floor</option>
          <option value="fg">Finished goods store</option>
          <option value="jobworker">At job worker (our material, outside)</option>
          <option value="custody">Customer material custody (never valued)</option>
          <option value="other">Other</option>
        </select></div>
      <div><button class="iv-btn" type="submit">Add location</button></div>
    </div>
  </form>
</div>

<?php
/* DEPARTMENTS. Until now this was free text on three documents, so the same
   floor could be three departments and no report could add them up. The
   master list is what a document picks from FROM NOW ON; what is already on
   a posted document keeps the words it was posted with, which is the point
   of a posted document. The spellings already in use are listed underneath
   with a count so the real ones can be adopted without retyping. */
$deptMaster = inv_dept_master(false);
$deptUsed   = inv_dept_usage();
$deptOnList = [];
foreach ($deptMaster as $d) $deptOnList[mb_strtolower($d['name'])] = true;
$deptStray  = [];
foreach ($deptUsed as $name => $n)
    if (!isset($deptOnList[mb_strtolower($name)])) $deptStray[$name] = $n;
uasort($deptStray, fn($a, $b) => $b <=> $a);
?>
<div class="iv-card">
  <h2>Departments</h2>
  <p class="sub">The floors and sections that material is issued to. A gate pass, a store issue and a
    consumption all record one &mdash; and until this list existed each of them typed it by hand, so the same
    floor could be three departments.</p>

  <?php if ($deptMaster): ?>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>Department</th><th class="r">On documents</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deptMaster as $d): $used = 0;
      foreach ($deptUsed as $k => $n) if (strcasecmp($k, $d['name']) === 0) $used += $n; ?>
      <tr>
        <td style="font-weight:600"><?= e($d['name']) ?></td>
        <td class="r"><?= $used ? number_format($used) : '<span style="color:#b6c0cf">—</span>' ?></td>
        <td><?= $d['is_active'] ? '<span class="iv-pill iv-ok">Active</span>' : '<span class="iv-pill iv-no">Inactive</span>' ?></td>
        <td class="r"><form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_department">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="iv-btn sec" style="padding:5px 11px;font-size:11.5px" type="submit"><?= $d['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <div class="iv-note info">
      <b>The list is empty, and nothing has changed because of that.</b>
      While it is empty every department box carries on offering whatever has already been typed, exactly as
      before. The moment you add one, the boxes offer this list instead &mdash; so add the real ones and the
      spellings stop multiplying.
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="add_department">
    <div class="iv-grid iv-g4" style="align-items:end">
      <div style="grid-column:span 2"><label class="iv-lbl">Department name</label>
        <input class="iv-inp" name="name" placeholder="Stitching Floor 1" maxlength="80" autocomplete="off"></div>
      <div><button class="iv-btn" type="submit">Add department</button></div>
    </div>
  </form>

  <?php if ($deptStray): ?>
  <div style="margin-top:18px">
    <h3 style="font-size:13px;margin:0 0 4px;font-weight:800">Already used on documents, not on this list</h3>
    <p class="sub" style="margin:0 0 10px">These were typed by hand before the list existed. Adopt the ones
      you meant &mdash; a posted document is never rewritten, so adopting is what makes the two agree.</p>
    <div style="overflow-x:auto"><table class="iv-tbl">
      <thead><tr><th>Typed as</th><th class="r">On documents</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($deptStray as $name => $n): ?>
        <tr>
          <td style="font-family:monospace"><?= e($name) ?></td>
          <td class="r"><?= number_format($n) ?></td>
          <td class="r"><form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="adopt_department">
            <input type="hidden" name="name" value="<?= e($name) ?>">
            <button class="iv-btn sec" style="padding:5px 11px;font-size:11.5px" type="submit">Adopt</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
</div>

<div class="iv-card">
  <h2>Seed the Item Master from your existing costings</h2>
  <p class="sub">Reads distinct fabric, accessory and packing lines already saved in <code>costing_lines</code>, normalised through your existing alias dictionary. Read-only until you press the button.</p>
  <?php if (!$seedPreview): ?>
    <div class="iv-note info">No costing lines found to seed from. You can still add materials by hand in the Item Master.</div>
  <?php else: ?>
    <div class="iv-note ok" style="margin-bottom:14px">
      Found <b><?= count($seedPreview) ?></b> distinct item(s) in your costings — <b><?= count($seedNew) ?></b> would be created, <?= count($seedPreview) - count($seedNew) ?> already exist. Rates shown are the average used across your costings and can be edited afterwards.
    </div>

    <?php /* ==================================================================
             THE DUPLICATE WARNING — the cheapest moment to stop one
             ==================================================================
             "i want to corect that old costing but if i go manual one by one
              take time ... change item which already availble instead saving
              dublicate nmaes as separate"

             A costing that says "COTTON FABRIC 60s" and a master item called
             "Cotton Fabric 60S" are the same cloth. Pressing Create makes
             them two items, for ever, and nothing on any screen afterwards
             says they are the same. Once created, every stock figure for that
             cloth is split in two and neither half is right.

             So the near-matches are counted and named BEFORE the button, and
             the page that settles them is one click away. Nothing here is
             applied automatically: a wrong merge is worse than a duplicate,
             because a duplicate is visible and a wrong merge is not. */ ?>
    <?php if ($seedNear): ?>
      <div class="iv-note warn" style="margin-bottom:14px">
        <b><?= count($seedNear) ?> of these look like something you already have.</b>
        Creating them makes a second item for the same thing, and from then on your stock for it
        is split between two names. Settle them first in
        <a href="costing_items_link.php" style="font-weight:800">Link costing items</a> — that page
        points an old costing name at an existing item and writes it down, so the seed folds them
        together instead of duplicating. Look at the <b>Looks like</b> column below.
      </div>
    <?php endif; ?>
    <div style="overflow-x:auto;max-height:340px;overflow-y:auto"><table class="iv-tbl">
      <thead><tr><th>Item</th><th>Group</th><th>UOM</th><th class="r">Avg rate</th><th class="r">Used in</th><th>Looks like</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($seedPreview, 0, 120) as $s): ?>
        <tr>
          <td style="font-weight:600"><?= e($s['name']) ?></td>
          <td><?= e($s['item_group']) ?></td>
          <td style="font-family:monospace"><?= e($s['uom']) ?></td>
          <td class="r" style="font-variant-numeric:tabular-nums"><?= number_format($s['std_rate'], 2) ?></td>
          <?php /* THE COUNT IS A LINK. "if i go manual one by one take time"
                   — from here one click opens the page that settles this very
                   name against an existing item, instead of hunting for the
                   costings that used it. */ ?>
          <td class="r"><a href="costing_items_link.php?q=<?= urlencode($s['raw'] ?? $s['name']) ?>"
                 title="Open Link costing items and settle this name"><?= (int)$s['uses'] ?> costing(s)</a></td>
          <td>
            <?php if (!empty($s['near'])): ?>
              <a href="costing_items_link.php?q=<?= urlencode($s['raw'] ?? $s['name']) ?>"
                 style="color:#9a5710;font-weight:700"><?= e($s['near']['code']) ?> · <?= e($s['near']['name']) ?></a>
              <span style="color:#8a97ab;font-size:10.5px"><?= (int)$s['near']['score'] ?>%</span>
            <?php else: ?>
              <span style="color:#c9d3e0">—</span>
            <?php endif; ?>
          </td>
          <td><?= $s['exists']
                ? '<span class="iv-pill iv-ok">already in master</span>'
                : (!empty($s['near'])
                    ? '<span class="iv-pill iv-warn">would be a DUPLICATE</span>'
                    : '<span class="iv-pill iv-warn">will be created</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($seedPreview) > 120): ?><p style="font-size:11.5px;color:#8a97ab;margin:10px 0 0">Showing the first 120. All of them will be created.</p><?php endif; ?>
    <?php if ($seedNew): ?>
      <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="seed_materials">
        <button class="iv-btn" type="submit"
          <?= $seedNear ? 'onclick="return confirm(\'' . (int)count($seedNear)
              . ' of these look like items you already have. Creating them now makes a second item for the same thing.\\n\\nSettle them in Link costing items first?\\n\\nOK = create anyway, Cancel = go back\');"' : '' ?>>
          Create <?= count($seedNew) ?> material(s)</button>
        <a class="iv-btn sec" href="costing_items_link.php" style="margin-left:8px">Settle old names first</a>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php $pk = inv_legacy_packing_counts(); if ($pk['items'] || $pk['costing_lines']): ?>
<div class="iv-card" style="border-color:#fed7aa;background:#fff7ed">
  <h2>Packing group — retired</h2>
  <p class="sub">New items can only be Fabric, Accessories or Other. These rows still carry the old Packing group.</p>

  <p style="font-size:12.5px;color:#9a3412;line-height:1.7;margin:12px 0 0">
    <b><?= (int)$pk['items'] ?></b> Item Master item(s) and <b><?= (int)$pk['costing_lines'] ?></b> costing line(s)
    are still in Packing. Nothing was changed on upload — they keep working and keep printing exactly as they do
    today until you move them here.
  </p>

  <p style="font-size:12.5px;color:#5a6b82;line-height:1.7;margin:12px 0 0;background:#fff;border:1px solid #e3e9f2;border-radius:10px;padding:12px 14px">
    <b style="color:#152033">Read this before moving the costing lines.</b>
    <code>line_group = 'Packing'</code> is what splits <b>Net Weight</b> from <b>Packing Weight</b> on your costing
    sheets. Move those lines and their weight counts as Net instead, so Net Weight rises and Packing Weight becomes
    zero. <b>Gross Weight does not change</b> — it is Net + Packing either way. Only move them if you do not rely on
    the Packing Weight figure on printed costings or export paperwork.<br>
    The Item Master items on their own are just a classification of your materials — moving those changes no figure
    anywhere.
  </p>

  <form method="post" style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap"
        onsubmit="return confirm('Move everything out of the Packing group?');">
    <?= csrf_field() ?><input type="hidden" name="action" value="retire_packing">
    <label style="font-size:12.5px;color:#5a6b82">Move them to
      <select name="to" class="iv-inp" style="width:auto;padding:7px 9px">
        <option value="Accessories">Accessories (recommended)</option>
        <option value="Other">Other</option>
      </select>
    </label>
    <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer">
      <input type="checkbox" name="also_lines" value="1"> Also move the <?= (int)$pk['costing_lines'] ?> costing line(s)
    </label>
    <button class="iv-btn" type="submit" style="margin-left:auto">Move them</button>
  </form>
</div>
<?php endif; ?>

<div class="iv-card">
  <h2>Settings</h2>
  <p class="sub">Document prefixes and your own names for the three production stages.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <div class="iv-note info" style="margin-bottom:14px">
      <b>Stage names are labels only.</b> Your database keeps <code>Cutting</code>, <code>Stitching</code> and <code>Dispatch</code> as slots 1, 2 and 3, so every production record you already have stays valid.<?= production_dispatch_on() ? '' : ' Stage 3 is currently switched off across the app, so nothing is being booked against it.' ?> Renaming here changes what reports call them, and nothing else. The inventory module never reads these names — no stage moves stock.
    </div>
    <div class="iv-grid iv-g3">
      <div><label class="iv-lbl">Stage 1 name</label><input class="iv-inp" name="stage1_label" value="<?= e(inv_setting('stage1_label', 'Cutting')) ?>"></div>
      <div><label class="iv-lbl">Stage 2 name</label><input class="iv-inp" name="stage2_label" value="<?= e(inv_setting('stage2_label', 'Stitching')) ?>"></div>
      <?php /* Stage 3 is switched off in includes/production.php. The box stays
               — the stored NAME is still the name, and turning the stage back
               on must not also lose what it was called — but it is marked so
               nobody spends time renaming something nothing is using. */ ?>
      <div><label class="iv-lbl">Stage 3 name<?= production_dispatch_on() ? '' : ' — switched off' ?></label>
        <input class="iv-inp" name="stage3_label" value="<?= e(inv_setting('stage3_label', 'Dispatch')) ?>"
               <?= production_dispatch_on() ? '' : 'style="opacity:.55"' ?>></div>
    </div>
    <div class="iv-grid iv-g4" style="margin-top:14px">
      <?php foreach (['prefix_gate_in' => 'Gate inward', 'prefix_gate_out' => 'Gate outward', 'prefix_issue' => 'Store issue', 'prefix_return' => 'Store return',
                      'prefix_consumption' => 'Consumption', 'prefix_contract_pur' => 'Purchase contract', 'prefix_contract_sal' => 'Sales contract', 'prefix_contract_jw' => 'Job work contract'] as $k => $lbl): ?>
        <div><label class="iv-lbl"><?= e($lbl) ?></label><input class="iv-inp" name="prefix[<?= e($k) ?>]" value="<?= e(inv_setting($k)) ?>" maxlength="8" style="font-family:monospace"></div>
      <?php endforeach; ?>
    </div>
    <div class="iv-grid iv-g2" style="margin-top:14px">
      <div><label class="iv-lbl">Backdating limit (days)</label><input class="iv-inp" name="backdate_days" value="<?= e(inv_setting('backdate_days', '7')) ?>">
        <p style="font-size:11px;color:#8a97ab;margin:5px 0 0">A document dated more than this many days ago needs an admin. 0 disables the limit.</p></div>
      <div><label class="iv-lbl">Negative stock allowance (%)</label><input class="iv-inp" name="neg_tolerance_pct" value="<?= e(inv_setting('neg_tolerance_pct', '10')) ?>">
        <p style="font-size:11px;color:#8a97ab;margin:5px 0 0">How far below a lot's balance anyone may consume, as a % of that lot — always with a reason. Beyond it, only an admin can post. 0 blocks every overdraw. A lot holding nothing has no allowance, so drawing on it is always an admin decision.</p></div>
      <div><label class="iv-lbl">Cost review threshold (%)</label><input class="iv-inp" name="cost_variance_pct" value="<?= e(inv_setting('cost_variance_pct', '20')) ?>">
        <p style="font-size:11px;color:#8a97ab;margin:5px 0 0">How far a consumption's cost per unit may drift from the costing the order was priced on before a reason is required. A review, never a block — a real price rise has to be recordable. 0 turns the check off.</p></div>
      <div><label class="iv-lbl">Default GST rate (%)</label><input class="iv-inp" name="gst_pct" value="<?= e(inv_setting('gst_pct', '18')) ?>">
        <p style="font-size:11px;color:#8a97ab;margin:5px 0 0">The rate the GST tick box fills in on contracts and gate passes. Tax is never applied unless a document says it applies; prints then show the total both excluding and including it.</p></div>
      <div><label class="iv-lbl">Default location</label>
        <select class="iv-inp" name="default_location">
          <?php foreach ($locations as $l): if (!$l['is_active']) continue; ?>
            <option value="<?= (int)$l['id'] ?>" <?= inv_setting('default_location') == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div style="margin-top:16px"><button class="iv-btn" type="submit">Save settings</button></div>
  </form>
</div>

<div class="iv-card">
  <h2>Who can use the module</h2>
  <p class="sub">Same per-user flag approach your costing permissions already use. Everyone starts with nothing; Admin always has full access without a flag.</p>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>User</th><th>Role</th><th>View</th><th>Gate</th><th>Store</th><th>Consume</th><th>Post</th><th>Adjust</th><th>Masters</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u2):
      $isAdmin = $u2['role'] === 'admin';
      $flag = function ($v) use ($isAdmin) { return $isAdmin ? '<span class="iv-pill iv-ok">all</span>' : ((int)$v === 1 ? '<span class="iv-pill iv-ok">yes</span>' : '<span style="color:#cbd5e3">—</span>'); };
    ?>
      <tr>
        <td style="font-weight:600"><?= e($u2['name']) ?><br><span style="font-size:11px;color:#8a97ab"><?= e($u2['email']) ?></span></td>
        <td><?= e(ucwords(str_replace('_', ' ', $u2['role']))) ?></td>
        <td><?= $flag($u2['inv_view']) ?></td><td><?= $flag($u2['inv_gate']) ?></td><td><?= $flag($u2['inv_store']) ?></td>
        <td><?= $flag($u2['inv_consume']) ?></td><td><?= $flag($u2['inv_post']) ?></td><td><?= $flag($u2['inv_adjust']) ?></td><td><?= $flag($u2['inv_master']) ?></td>
        <td class="r"><?php if (!$isAdmin): ?><a class="iv-btn sec" style="padding:5px 11px;font-size:11.5px;text-decoration:none;display:inline-block" href="?user=<?= (int)$u2['id'] ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($editUser): ?>
  <form method="post" style="margin-top:18px;padding:16px;background:#f7f9fc;border-radius:12px"><?= csrf_field() ?>
    <input type="hidden" name="action" value="grant"><input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
    <b style="font-size:13px">Permissions for <?= e($editUser['name']) ?></b>
    <div class="iv-grid iv-g4" style="margin-top:12px">
      <?php foreach (['view' => 'View stock &amp; reports', 'gate' => 'Create gate passes', 'store' => 'Issue &amp; return material',
                      'consume' => 'Post consumption', 'post' => 'Post documents to stock', 'adjust' => 'Adjust &amp; reverse',
                      'master' => 'Manage item master'] as $k => $lbl): ?>
        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;background:#fff;padding:9px 11px;border-radius:9px;border:1px solid #e3e9f2">
          <input type="checkbox" name="p[<?= e($k) ?>]" value="1" <?= (int)$editUser['inv_' . $k] === 1 ? 'checked' : '' ?>>
          <span><?= $lbl ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px">
      <button class="iv-btn" type="submit">Save permissions</button>
      <a class="iv-btn sec" style="text-decoration:none;display:inline-block" href="inv_setup.php">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="iv-card" style="border-color:rgba(109,91,208,.28);background:rgba(109,91,208,.04)">
  <h2>Module status — all stages delivered</h2>
  <p class="sub">Every stage is now installed. Each part can still be checked on its own before you rely on the next.</p>
  <div style="overflow-x:auto"><table class="iv-tbl">
    <thead><tr><th>Stage</th><th>Delivers</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td><b>1</b></td><td>Schema, locations, permissions, settings, Item Master</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
      <tr><td><b>2</b></td><td>Parties, contracts (purchase / sales / job work both ways), folder-tree menu</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
      <tr><td><b>3</b></td><td>Gate Inward &amp; Outward, stock ledger live, current stock, printable passes</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
      <tr><td><b>4</b></td><td>Store issue &amp; return, floor balance</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
      <tr><td><b>5</b></td><td>Consumption — the converter — with standard materials loaded from your costings</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
      <tr><td><b>6</b></td><td>Job work charges both ways, Order Costing Control, production exception report</td><td><span class="iv-pill iv-ok">Delivered</span></td></tr>
    </tbody>
  </table></div>
</div>

<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
