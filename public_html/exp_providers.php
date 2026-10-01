<?php
/*
  SERVICE PROVIDERS — freight forwarders, transporters, clearing agents,
  commission agents, inspection companies, shipping lines.

  WHY THIS IS NOT inv_parties
  ---------------------------
  inv_parties already exists and already holds suppliers and job workers. It
  looked like the obvious home. It is not, for one concrete reason:

      inv_gate.php:439     inv_parties('')
      inv_jobwork.php:164  inv_parties('')

  Both call it with no type filter, which returns EVERY active party. Put a
  freight forwarder in that table and it appears in the Gate Inward party
  picker and the Job Work picker. Keeping it out would mean editing two
  working inventory screens.

  So providers live here, and `inv_party_id` is the bridge: a company that
  genuinely exists on both sides is pointed at, not retyped twice.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/export.php';
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Not permitted.'); }
exp_ensure_schema();

/* A provider master is reference data for the cost ledger, so it is gated on
   the same permission as costs rather than on a key of its own. */
$canEdit = is_admin() || exp_can('costs', 'c');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('No permission to change service providers.'); }

    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new Exception('Name cannot be empty.');

            $roles = array_values(array_intersect(
                array_keys(EXP_ROLES),
                array_map('strval', (array)($_POST['roles'] ?? []))
            ));
            if (!$roles) throw new Exception('Tick at least one role, otherwise this company will not appear in any dropdown.');

            $party = (int)($_POST['inv_party_id'] ?? 0) ?: null;
            $vals = [
                $name,
                trim((string)($_POST['city'] ?? '')),
                trim((string)($_POST['phone'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
                trim((string)($_POST['ntn'] ?? '')),
                trim((string)($_POST['address'] ?? '')),
                $party,
                trim((string)($_POST['notes'] ?? '')),
                isset($_POST['is_active']) ? 1 : 0,
            ];

            db()->beginTransaction();
            if ($id > 0) {
                $vals[] = $id;
                db()->prepare("UPDATE exp_providers SET name=?, city=?, phone=?, email=?, ntn=?, address=?, inv_party_id=?, notes=?, is_active=? WHERE id=?")
                    ->execute($vals);
            } else {
                $vals[] = current_user()['id'];
                db()->prepare("INSERT INTO exp_providers (name,city,phone,email,ntn,address,inv_party_id,notes,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute($vals);
                $id = (int)db()->lastInsertId();
            }

            /* Roles are replaced wholesale. The table is (provider_id, role)
               with nothing hanging off it, so there is nothing to orphan. */
            db()->prepare("DELETE FROM exp_provider_roles WHERE provider_id=?")->execute([$id]);
            $ins = db()->prepare("INSERT IGNORE INTO exp_provider_roles (provider_id, role) VALUES (?,?)");
            foreach ($roles as $r) $ins->execute([$id, $r]);

            db()->commit();
            audit_log(0, 'Service Provider', 'provider', '', $name . ' [' . implode(', ', $roles) . ']', 'Service provider saved');
            $_SESSION['flash'] = 'Service provider saved.';
            redirect('exp_providers.php');
        }

        /* Deactivated, not deleted — shipment_costs rows point at it. */
        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare("SELECT * FROM exp_providers WHERE id=?");
            $st->execute([$id]); $row = $st->fetch();
            if (!$row) throw new Exception('Not found.');
            $new = (int)$row['is_active'] === 1 ? 0 : 1;
            db()->prepare("UPDATE exp_providers SET is_active=? WHERE id=?")->execute([$new, $id]);
            audit_log(0, 'Service Provider', 'provider', (int)$row['is_active'] ? 'active' : 'inactive',
                      $new ? 'active' : 'inactive', 'Provider ' . ($new ? 'reactivated' : 'deactivated'));
            $_SESSION['flash'] = $new ? 'Switched on.' : 'Switched off — existing cost entries still show the name.';
            redirect('exp_providers.php');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
        redirect('exp_providers.php' . (!empty($_POST['id']) ? '?edit=' . (int)$_POST['id'] : ''));
    }
}

$roleFilter = (string)($_GET['role'] ?? '');
if ($roleFilter !== '' && !isset(EXP_ROLES[$roleFilter])) $roleFilter = '';

$list  = exp_providers($roleFilter, false);
$roles = exp_provider_role_map(array_map(fn($p) => (int)$p['id'], $list));

$edit = null; $editRoles = [];
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM exp_providers WHERE id=?");
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $st = db()->prepare("SELECT role FROM exp_provider_roles WHERE provider_id=?");
        $st->execute([(int)$edit['id']]);
        $editRoles = array_column($st->fetchAll(), 'role');
    }
}

/* Offered as a link target only. Nothing is read from or written to the
   inventory module beyond this one list. */
$parties = inv_parties('', false);

page_header('Service Providers');
flash();
echo exp_page_css();
?>
<style>
.rtab{padding:6px 12px;border-radius:18px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;font-size:12px;text-decoration:none;white-space:nowrap}
.rtab.on{background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;border-color:transparent}
.rolechip{display:inline-block;padding:3px 8px;border-radius:14px;font-size:10.5px;font-weight:700;background:rgba(47,127,224,.12);color:#2f7fe0;border:1px solid rgba(47,127,224,.25);margin:1px}
.off td{opacity:.45}
</style>

<div class="topbar">
  <div><h1>Service Providers</h1><p class="lead">One company, as many roles as it actually performs.</p></div>
</div>

<div class="xcard" style="display:flex;gap:7px;flex-wrap:wrap">
  <a class="rtab <?= $roleFilter === '' ? 'on' : '' ?>" href="exp_providers.php">All</a>
  <?php foreach (EXP_ROLES as $k => $label): ?>
    <a class="rtab <?= $roleFilter === $k ? 'on' : '' ?>" href="exp_providers.php?role=<?= e($k) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<div class="xcard">
  <h2><?= $edit ? 'Edit ' . e($edit['name']) : 'Add a Service Provider' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="xgrid">
      <label class="xlabel xspan2">Company Name<input class="xin" name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. ABC Logistics"></label>
      <label class="xlabel">City<input class="xin" name="city" value="<?= e($edit['city'] ?? '') ?>"></label>
      <label class="xlabel">Phone<input class="xin" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></label>
      <label class="xlabel">Email<input class="xin" name="email" value="<?= e($edit['email'] ?? '') ?>"></label>
      <label class="xlabel">NTN<input class="xin" name="ntn" value="<?= e($edit['ntn'] ?? '') ?>"></label>
      <label class="xlabel xspan2">Address<textarea class="xin" name="address" style="min-height:58px;resize:vertical"><?= e($edit['address'] ?? '') ?></textarea></label>
    </div>

    <div style="margin-top:14px">
      <div class="xlabel" style="margin-bottom:7px">Roles — tick every service this company provides</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach (EXP_ROLES as $k => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;padding:7px 11px;border:1px solid #cbd5e3;border-radius:10px;background:#f6f8fc;font-size:12.5px;cursor:pointer">
            <input type="checkbox" name="roles[]" value="<?= e($k) ?>" <?= in_array($k, $editRoles, true) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9">
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="xgrid" style="margin-top:14px;align-items:end">
      <label class="xlabel xspan2">Already in Inventory Parties? <span style="color:#8a97ab">(optional link, so the company is not held twice)</span>
        <select class="xin" name="inv_party_id">
          <option value="">— not linked —</option>
          <?php foreach ($parties as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)($edit['inv_party_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
              <?= e($p['code']) ?> — <?= e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel xspan2">Notes<input class="xin" name="notes" value="<?= e($edit['notes'] ?? '') ?>"></label>
      <label class="xlabel" style="display:flex;align-items:center;gap:8px;padding-top:18px">
        <input type="checkbox" name="is_active" <?= ($edit === null || (int)$edit['is_active'] === 1) ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9"> Active
      </label>
      <div style="display:flex;gap:8px">
        <button class="xbtn"><?= $edit ? 'Update' : 'Add Provider' ?></button>
        <?php if ($edit): ?><a class="xbtn sec" href="exp_providers.php">Cancel</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="xcard">
  <div class="xwrap">
    <table class="xtable">
      <thead><tr><th>Company</th><th>Roles</th><th>City</th><th>Phone</th><th>Linked</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="7" style="padding:22px;text-align:center;color:#8a97ab">No service providers<?= $roleFilter !== '' ? ' in this role' : '' ?> yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($list as $p): $on = (int)$p['is_active'] === 1; ?>
        <tr class="<?= $on ? '' : 'off' ?>">
          <td style="font-weight:600"><?= e($p['name']) ?></td>
          <td>
            <?php foreach (($roles[(int)$p['id']] ?? []) as $r): ?>
              <span class="rolechip"><?= e(EXP_ROLES[$r] ?? $r) ?></span>
            <?php endforeach; ?>
          </td>
          <td><?= e($p['city']) ?></td>
          <td><?= e($p['phone']) ?></td>
          <td><?= !empty($p['inv_party_id']) ? '<span class="xpill b">Inventory</span>' : '' ?></td>
          <td><?= $on ? '<span class="xpill g">Active</span>' : '<span class="xpill o">Off</span>' ?></td>
          <?php if ($canEdit): ?>
          <td style="white-space:nowrap">
            <a class="xbtn sec sm" href="exp_providers.php?edit=<?= (int)$p['id'] ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="xbtn <?= $on ? 'red' : 'sec' ?> sm"><?= $on ? 'Switch off' : 'Switch on' ?></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="xnote" style="margin-top:12px">
    A company with three roles is one row here and appears in all three dropdowns.
    Switching one off keeps every past cost entry readable — the name still shows.
  </div>
</div>
<?php page_footer(); ?>
