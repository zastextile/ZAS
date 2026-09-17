<?php
/* Item Master — fabric, accessories and packing.
   Finished products are NOT here: they stay in the existing Product Master
   (`products`) and are referenced by id wherever a finished product is
   needed. Nothing is duplicated between the two. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_perm('master') && !inv_perm('view')) { http_response_code(403); exit('You do not have permission to view the Item Master.'); }
$canEdit = inv_perm('master');

/* Fabric / Accessories / Other. Packing was retired — see inv_item_groups().
   Items still sitting in the old Packing group keep showing it until you
   move them from Inventory Setup, so nothing is rewritten behind you. */
$GROUPS = inv_item_groups();
$LEGACY = ['Packing'];
$STAGES = ['na' => 'Not applicable', 'grey' => 'Grey', 'raw' => 'Raw', 'finished' => 'Finished'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    inv_require('master');
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $group = in_array($_POST['item_group'] ?? '', array_merge($GROUPS, $LEGACY), true) ? $_POST['item_group'] : 'Other';
        $stage = array_key_exists($_POST['stage'] ?? '', $STAGES) ? $_POST['stage'] : 'na';
        $uom = strtoupper(trim((string)($_POST['uom'] ?? 'PCS')));
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));

        if ($name === '') { $_SESSION['error'] = 'Item name is required.'; redirect('inv_items.php'); }
        if ($uom === '') $uom = 'PCS';

        // Editing an existing item with the code box cleared must keep the
        // code it already has — silently minting a new one would orphan
        // every document that already refers to it.
        if ($code === '' && $id > 0) {
            try {
                $st = db()->prepare("SELECT code FROM inv_materials WHERE id=?");
                $st->execute([$id]);
                $code = (string)$st->fetchColumn();
            } catch (Throwable $e) {}
        }
        // Auto-code on create, from the group prefix, never reused.
        if ($code === '') {
            $prefixes = ['Fabric' => 'FB', 'Accessories' => 'AC', 'Other' => 'OT'];
            $p = $prefixes[$group] ?? 'OT';
            $n = 0;
            try {
                foreach (db()->query("SELECT code FROM inv_materials WHERE code LIKE '$p-%'")->fetchAll() as $r) {
                    $tail = (int)substr((string)$r['code'], strlen($p) + 1);
                    if ($tail > $n) $n = $tail;
                }
            } catch (Throwable $e) {}
            $code = $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
        }

        $fields = [
            'code' => $code, 'name' => $name, 'item_group' => $group,
            'material_type' => trim((string)($_POST['material_type'] ?? '')) ?: null,
            'stage' => $stage,
            'composition' => trim((string)($_POST['composition'] ?? '')) ?: null,
            'construction' => trim((string)($_POST['construction'] ?? '')) ?: null,
            'weave_knit' => trim((string)($_POST['weave_knit'] ?? '')) ?: null,
            'gsm' => ($_POST['gsm'] ?? '') !== '' ? inv_num($_POST['gsm']) : null,
            'width' => trim((string)($_POST['width'] ?? '')) ?: null,
            'colour' => trim((string)($_POST['colour'] ?? '')) ?: null,
            'finish_process' => trim((string)($_POST['finish_process'] ?? '')) ?: null,
            'uom' => $uom,
            'std_rate' => inv_num($_POST['std_rate'] ?? 0),
            'reorder_level' => inv_num($_POST['reorder_level'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
                $vals = array_values($fields); $vals[] = $id;
                db()->prepare("UPDATE inv_materials SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);
                inv_audit('material_edit', $id, $fields, 'Item Master updated');
                $_SESSION['flash'] = 'Item updated.';
            } else {
                $cols = implode(',', array_keys($fields)) . ',created_by';
                $ph = implode(',', array_fill(0, count($fields) + 1, '?'));
                $vals = array_values($fields); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_materials ($cols) VALUES ($ph)")->execute($vals);
                inv_audit('material_add', '', $fields, 'Item Master created');
                $_SESSION['flash'] = 'Item ' . $code . ' created.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not save — the item code may already be in use.';
        }
        redirect('inv_items.php' . ($group ? '?g=' . urlencode($group) : ''));
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("UPDATE inv_materials SET is_active = 1 - is_active, updated_at=NOW() WHERE id=?")->execute([$id]);
            inv_audit('material_toggle', $id, '', 'Active status changed');
        } catch (Throwable $e) {}
        redirect('inv_items.php');
    }
}

/* ---- listing ---- */
$g = $_GET['g'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$showInactive = !empty($_GET['inactive']);

$where = []; $params = [];
if (!$showInactive) $where[] = 'is_active = 1';
if ($g !== '' && in_array($g, array_merge($GROUPS, $LEGACY), true)) { $where[] = 'item_group = ?'; $params[] = $g; }
if ($q !== '') { $where[] = '(name LIKE ? OR code LIKE ? OR composition LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }

$items = [];
try {
    $sql = "SELECT * FROM inv_materials" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY item_group, name LIMIT 500";
    $st = db()->prepare($sql); $st->execute($params);
    $items = $st->fetchAll();
} catch (Throwable $e) {}

$counts = array_fill_keys(array_merge($GROUPS, $LEGACY), 0);   // Packing counted only so leftovers stay visible
try {
    foreach (db()->query("SELECT item_group, COUNT(*) n FROM inv_materials WHERE is_active=1 GROUP BY item_group")->fetchAll() as $r) {
        if (isset($counts[$r['item_group']])) $counts[$r['item_group']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

$edit = null;
if (!empty($_GET['edit'])) {
    try { $st = db()->prepare("SELECT * FROM inv_materials WHERE id=?"); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null; }
    catch (Throwable $e) {}
}
$showForm = $canEdit && (isset($_GET['new']) || $edit);

page_header('Item Master');
flash();
?>
<div class="topbar">
  <div><h1>Item Master</h1><p class="lead">Fabric, accessories and packing — everything you buy. Finished products stay in Product Master and are never duplicated here.</p></div>
  <div style="display:flex;gap:10px">
    <?php if ($canEdit): ?><a class="zbtn sec" href="?new=1">+ New Item</a><?php endif; ?>
    <?php if (is_admin()): ?><a class="zbtn sec" href="inv_setup.php">Setup →</a><?php endif; ?>
  </div>
</div>

<style>
.im-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.im-tabs{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:11px;flex-wrap:wrap}
.im-tab{padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:#5a6b82}
.im-tab.on{background:#fff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.im-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit}
.im-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.im-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.im-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.im-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.im-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.im-tbl td.r,.im-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.im-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px;white-space:nowrap}
.g-Fabric{background:rgba(14,168,201,.10);color:#0b7f9b}
.g-Accessories{background:rgba(109,91,208,.10);color:#5a4bb8}
.g-Packing{background:rgba(138,151,171,.14);color:#5a6b82}
.g-Other{background:rgba(217,119,6,.12);color:#a8630a}
.im-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.im-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.im-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.im-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.im-grid{grid-template-columns:1fr}}
.im-fab{display:none}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if ($showForm): $E = $edit ?: []; ?>
<div class="im-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $edit ? 'Edit item — ' . e($edit['code']) : 'New item' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 16px">Technical fields matter for fabric and are optional for everything else — fill in what is useful and leave the rest.</p>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($E['id'] ?? 0) ?>">
    <div class="im-grid">
      <div><label class="im-lbl">Item code</label><input class="im-inp" name="code" value="<?= e($E['code'] ?? '') ?>" placeholder="auto" style="width:100%;font-family:monospace"></div>
      <div style="grid-column:span 2"><label class="im-lbl">Item name *</label><input class="im-inp" name="name" value="<?= e($E['name'] ?? '') ?>" required maxlength="190" style="width:100%"></div>
      <div><label class="im-lbl">Group *</label>
        <select class="im-inp" name="item_group" id="grp" style="width:100%" onchange="fab()">
          <?php $cur = $E['item_group'] ?? 'Fabric';
                $opts = $GROUPS;
                if (in_array($cur, $LEGACY, true)) $opts[] = $cur;   // keep a leftover row's own group selectable
                foreach ($opts as $gg): ?>
            <option value="<?= e($gg) ?>" <?= $cur === $gg ? 'selected' : '' ?>><?= e($gg) ?><?= in_array($gg, $LEGACY, true) ? ' (retired — pick another)' : '' ?></option>
          <?php endforeach; ?>
        </select></div>

      <div><label class="im-lbl">Unit of measure *</label><input class="im-inp" name="uom" value="<?= e($E['uom'] ?? 'MTR') ?>" maxlength="20" style="width:100%;font-family:monospace"></div>
      <div><label class="im-lbl">Standard rate</label><input class="im-inp" name="std_rate" value="<?= e((string)($E['std_rate'] ?? '0')) ?>" style="width:100%;text-align:right"></div>
      <div><label class="im-lbl">Reorder level</label><input class="im-inp" name="reorder_level" value="<?= e((string)($E['reorder_level'] ?? '0')) ?>" style="width:100%;text-align:right"></div>
      <div><label class="im-lbl">Material type</label><input class="im-inp" name="material_type" value="<?= e($E['material_type'] ?? '') ?>" placeholder="Woven fabric" style="width:100%"></div>
    </div>

    <div class="im-grid im-fab" id="fabfields" style="margin-top:13px">
      <div><label class="im-lbl">Grey / raw / finished</label>
        <select class="im-inp" name="stage" style="width:100%">
          <?php foreach ($STAGES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($E['stage'] ?? 'na') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="im-lbl">Composition</label><input class="im-inp" name="composition" value="<?= e($E['composition'] ?? '') ?>" placeholder="100% Cotton" style="width:100%"></div>
      <div><label class="im-lbl">Construction</label><input class="im-inp" name="construction" value="<?= e($E['construction'] ?? '') ?>" placeholder="110x90 / 40x40" style="width:100%;font-family:monospace"></div>
      <div><label class="im-lbl">Weave / knit</label><input class="im-inp" name="weave_knit" value="<?= e($E['weave_knit'] ?? '') ?>" placeholder="Plain weave" style="width:100%"></div>
      <div><label class="im-lbl">GSM</label><input class="im-inp" name="gsm" value="<?= e((string)($E['gsm'] ?? '')) ?>" style="width:100%;text-align:right"></div>
      <div><label class="im-lbl">Width</label><input class="im-inp" name="width" value="<?= e($E['width'] ?? '') ?>" placeholder="240 cm" style="width:100%"></div>
      <div><label class="im-lbl">Colour</label><input class="im-inp" name="colour" value="<?= e($E['colour'] ?? '') ?>" style="width:100%"></div>
      <div><label class="im-lbl">Finish / process</label><input class="im-inp" name="finish_process" value="<?= e($E['finish_process'] ?? '') ?>" style="width:100%"></div>
    </div>

    <div style="margin-top:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600">
        <input type="checkbox" name="is_active" value="1" <?= (!$edit || (int)$E['is_active'] === 1) ? 'checked' : '' ?>> Active
      </label>
      <button class="im-btn" type="submit"><?= $edit ? 'Save changes' : 'Create item' ?></button>
      <a class="im-btn sec" href="inv_items.php">Cancel</a>
    </div>
  </form>
</div>
<script>
function fab(){
  var g=document.getElementById('grp').value;
  document.getElementById('fabfields').style.display=(g==='Fabric'?'grid':'none');
}
fab();
</script>
<?php endif; ?>

<div class="im-bar">
  <div class="im-tabs">
    <a class="im-tab <?= $g === '' ? 'on' : '' ?>" href="?">All <?= array_sum($counts) ?></a>
    <?php foreach ($GROUPS as $gg): ?>
      <a class="im-tab <?= $g === $gg ? 'on' : '' ?>" href="?g=<?= urlencode($gg) ?>"><?= e($gg) ?> <?= (int)$counts[$gg] ?></a>
    <?php endforeach; ?>
    <?php foreach ($LEGACY as $gg): if (empty($counts[$gg])) continue; ?>
      <a class="im-tab <?= $g === $gg ? 'on' : '' ?>" href="?g=<?= urlencode($gg) ?>" title="Retired group — move these to Accessories or Other"><?= e($gg) ?> <?= (int)$counts[$gg] ?> ⚠</a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if ($g !== ''): ?><input type="hidden" name="g" value="<?= e($g) ?>"><?php endif; ?>
    <div><label class="im-lbl">Search</label><input class="im-inp" name="q" value="<?= e($q) ?>" placeholder="name, code or composition"></div>
    <label style="display:flex;gap:7px;align-items:center;font-size:12px;font-weight:600;padding-bottom:9px">
      <input type="checkbox" name="inactive" value="1" <?= $showInactive ? 'checked' : '' ?>> Show inactive
    </label>
    <button class="im-btn sec" type="submit" style="cursor:pointer">Search</button>
  </form>
</div>

<div class="im-card">
  <?php if (!$items): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">
      No items yet.
      <?php if (is_admin()): ?><br><br><a class="im-btn sec" href="inv_setup.php">Seed the master from your existing costings →</a><?php endif; ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="im-tbl">
    <thead><tr>
      <th>Code</th><th>Item</th><th>Group</th><th>Composition</th><th>Construction</th>
      <th class="r">GSM</th><th>Width</th><th>Colour</th><th>UOM</th><th class="r">Std rate</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr<?= (int)$it['is_active'] === 0 ? ' style="opacity:.55"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($it['code']) ?></td>
        <td style="font-weight:600"><?= e($it['name']) ?><?php if ($it['material_type']): ?><br><span style="font-size:11px;color:#8a97ab"><?= e($it['material_type']) ?></span><?php endif; ?></td>
        <td><span class="im-pill g-<?= e($it['item_group']) ?>"><?= e($it['item_group']) ?></span>
            <?php if ($it['stage'] !== 'na'): ?><br><span style="font-size:10.5px;color:#8a97ab"><?= e(ucfirst($it['stage'])) ?></span><?php endif; ?></td>
        <td><?= e($it['composition'] ?: '—') ?></td>
        <td style="font-family:monospace;font-size:11.5px"><?= e($it['construction'] ?: '—') ?></td>
        <td class="r"><?= $it['gsm'] !== null && (float)$it['gsm'] > 0 ? number_format((float)$it['gsm'], 0) : '—' ?></td>
        <td><?= e($it['width'] ?: '—') ?></td>
        <td><?= e($it['colour'] ?: '—') ?></td>
        <td style="font-family:monospace"><?= e($it['uom']) ?></td>
        <td class="r"><?= number_format((float)$it['std_rate'], 2) ?></td>
        <td><?= (int)$it['is_active'] === 1 ? '<span class="im-pill" style="background:rgba(22,163,74,.10);color:#16a34a">Active</span>' : '<span class="im-pill" style="background:rgba(224,67,93,.10);color:#c0293f">Inactive</span>' ?></td>
        <td class="r" style="white-space:nowrap">
          <?php if ($canEdit): ?>
            <a class="im-btn sec" style="padding:5px 10px;font-size:11.5px" href="?edit=<?= (int)$it['id'] ?>">Edit</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button class="im-btn sec" style="padding:5px 10px;font-size:11.5px;cursor:pointer" type="submit"><?= (int)$it['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="font-size:11.5px;color:#8a97ab;margin:14px 0 0">
    <?= count($items) ?> item(s) shown<?= count($items) >= 500 ? ' (first 500 — narrow the search to see more)' : '' ?>.
    An item that has been used in any posted stock document can be deactivated but should never be deleted, so its history stays readable.
  </p>
  <?php endif; ?>
</div>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
