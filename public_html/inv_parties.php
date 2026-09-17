<?php
/* Parties — suppliers, customers and job workers.
   Kept separate from the buyer names already typed on shipments and
   proformas: those are free text on each document and cannot carry an
   address, NTN or a running balance. Nothing here overwrites or replaces
   them — a gate pass can still name a party as plain text when it is a
   one-off. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_perm('master') && !inv_perm('view')) { http_response_code(403); exit('You do not have permission to view Parties.'); }
$canEdit = inv_perm('master');

$TYPES = [
    'supplier'  => 'Supplier',
    'customer'  => 'Customer',
    'jobworker' => 'Job worker',
    'both'      => 'Supplier &amp; customer',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    inv_require('master');
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['party_type'] ?? '', $TYPES) ? $_POST['party_type'] : 'supplier';
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));

        if ($name === '') { $_SESSION['error'] = 'Party name is required.'; redirect('inv_parties.php'); }

        // Keep the existing code when editing with the box cleared — a new
        // code would orphan every document already pointing at this party.
        if ($code === '' && $id > 0) {
            try { $st = db()->prepare("SELECT code FROM inv_parties WHERE id=?"); $st->execute([$id]); $code = (string)$st->fetchColumn(); }
            catch (Throwable $e) {}
        }
        if ($code === '') {
            $p = ['supplier' => 'SUP', 'customer' => 'CUS', 'jobworker' => 'JWP', 'both' => 'PTY'][$type];
            $n = 0;
            try {
                foreach (db()->query("SELECT code FROM inv_parties WHERE code LIKE '$p-%'")->fetchAll() as $r) {
                    $tail = (int)substr((string)$r['code'], strlen($p) + 1);
                    if ($tail > $n) $n = $tail;
                }
            } catch (Throwable $e) {}
            $code = $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
        }

        $f = [
            'code' => $code, 'name' => $name, 'party_type' => $type,
            'city'    => trim((string)($_POST['city'] ?? '')) ?: null,
            'phone'   => trim((string)($_POST['phone'] ?? '')) ?: null,
            'ntn'     => trim((string)($_POST['ntn'] ?? '')) ?: null,
            'address' => trim((string)($_POST['address'] ?? '')) ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_parties SET $sets WHERE id=?")->execute($vals);
                inv_audit('party_edit', $id, $f, 'Party updated');
                $_SESSION['flash'] = 'Party updated.';
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_parties ($cols) VALUES ($ph)")->execute($vals);
                inv_audit('party_add', '', $f, 'Party created');
                $_SESSION['flash'] = 'Party ' . $code . ' created.';
            }
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not save — the party code may already be in use.'; }
        redirect('inv_parties.php');
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("UPDATE inv_parties SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            inv_audit('party_toggle', $id, '', 'Active status changed');
        } catch (Throwable $e) {}
        redirect('inv_parties.php');
    }
}

$t = $_GET['t'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$showInactive = !empty($_GET['inactive']);

$where = []; $params = [];
if (!$showInactive) $where[] = 'is_active = 1';
if ($t !== '' && array_key_exists($t, $TYPES)) { $where[] = "(party_type = ? OR party_type = 'both')"; $params[] = $t; }
if ($q !== '') { $where[] = '(name LIKE ? OR code LIKE ? OR city LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }

$rows = [];
try {
    $sql = "SELECT * FROM inv_parties" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY name LIMIT 400";
    $st = db()->prepare($sql); $st->execute($params); $rows = $st->fetchAll();
} catch (Throwable $e) {}

$counts = [];
try {
    foreach (db()->query("SELECT party_type, COUNT(*) n FROM inv_parties WHERE is_active=1 GROUP BY party_type")->fetchAll() as $r) {
        $counts[$r['party_type']] = (int)$r['n'];
    }
} catch (Throwable $e) {}

/* Existing buyer names from shipments and proformas that are not yet in
   this master — a one-click way to build the customer list from real
   history instead of typing it. */
$suggest = [];
if ($canEdit) {
    try {
        $known = [];
        foreach (db()->query("SELECT LOWER(name) n FROM inv_parties")->fetchAll() as $r) $known[$r['n']] = true;
        $seen = [];
        foreach (db()->query("SELECT buyer_name nm, COUNT(*) c FROM shipments WHERE buyer_name<>'' GROUP BY buyer_name
                              UNION ALL
                              SELECT customer_name nm, COUNT(*) c FROM proforma_invoices WHERE customer_name<>'' GROUP BY customer_name")->fetchAll() as $r) {
            $nm = trim((string)$r['nm']); if ($nm === '') continue;
            $k = strtolower($nm);
            if (isset($known[$k])) continue;
            $seen[$k] = ['name' => $nm, 'uses' => (int)$r['c'] + (int)($seen[$k]['uses'] ?? 0)];
        }
        $suggest = array_values($seen);
        usort($suggest, fn($a, $b) => $b['uses'] <=> $a['uses']);
        $suggest = array_slice($suggest, 0, 12);
    } catch (Throwable $e) { $suggest = []; }
}

$edit = null;
if (!empty($_GET['edit'])) {
    try { $st = db()->prepare("SELECT * FROM inv_parties WHERE id=?"); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null; }
    catch (Throwable $e) {}
}
$prefill = trim((string)($_GET['name'] ?? ''));
$showForm = $canEdit && (isset($_GET['new']) || $edit || $prefill !== '');

page_header('Parties');
flash();
?>
<div class="topbar">
  <div><h1>Parties</h1><p class="lead">Suppliers, customers and job workers used by gate passes and contracts.</p></div>
  <?php if ($canEdit): ?><a class="zbtn sec" href="?new=1">+ New Party</a><?php endif; ?>
</div>

<style>
.ip-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ip-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.ip-tabs{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:11px;flex-wrap:wrap}
.ip-tab{padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:#5a6b82}
.ip-tab.on{background:#fff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.ip-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.ip-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.ip-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ip-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.ip-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.ip-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.ip-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.ip-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.t-supplier{background:rgba(14,168,201,.10);color:#0b7f9b}
.t-customer{background:rgba(22,163,74,.10);color:#16a34a}
.t-jobworker{background:rgba(109,91,208,.10);color:#5a4bb8}
.t-both{background:rgba(217,119,6,.12);color:#a8630a}
.ip-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.ip-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.ip-grid{grid-template-columns:1fr}}
.ip-chip{display:inline-flex;align-items:center;gap:7px;background:#f3f6fb;border:1px solid #e3e9f2;border-radius:20px;
  padding:6px 12px;font-size:12px;font-weight:600;color:#33415c;text-decoration:none;margin:0 6px 6px 0}
.ip-chip:hover{border-color:#0ea8c9;color:#0b7f9b}
.ip-chip small{color:#8a97ab;font-weight:700}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if ($showForm): $E = $edit ?: []; ?>
<div class="ip-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $edit ? 'Edit party — ' . e($edit['code']) : 'New party' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 16px">Only the name and type are required. Address and NTN print on gate passes and job work bills when they are filled in.</p>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($E['id'] ?? 0) ?>">
    <div class="ip-grid">
      <div><label class="ip-lbl">Code</label><input class="ip-inp" name="code" value="<?= e($E['code'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div style="grid-column:span 2"><label class="ip-lbl">Party name *</label><input class="ip-inp" name="name" value="<?= e($E['name'] ?? $prefill) ?>" required maxlength="190"></div>
      <div><label class="ip-lbl">Type *</label>
        <select class="ip-inp" name="party_type">
          <?php foreach ($TYPES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($E['party_type'] ?? 'supplier') === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="ip-lbl">City</label><input class="ip-inp" name="city" value="<?= e($E['city'] ?? '') ?>"></div>
      <div><label class="ip-lbl">Phone</label><input class="ip-inp" name="phone" value="<?= e($E['phone'] ?? '') ?>"></div>
      <div><label class="ip-lbl">NTN</label><input class="ip-inp" name="ntn" value="<?= e($E['ntn'] ?? '') ?>"></div>
      <div style="grid-column:span 1"><label class="ip-lbl">Address</label><input class="ip-inp" name="address" value="<?= e($E['address'] ?? '') ?>"></div>
    </div>
    <div style="margin-top:16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:600">
        <input type="checkbox" name="is_active" value="1" <?= (!$edit || (int)$E['is_active'] === 1) ? 'checked' : '' ?>> Active</label>
      <button class="ip-btn" type="submit"><?= $edit ? 'Save changes' : 'Create party' ?></button>
      <a class="ip-btn sec" href="inv_parties.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($suggest && !$showForm): ?>
<div class="ip-card" style="border-color:rgba(14,168,201,.25);background:rgba(14,168,201,.04)">
  <h2 style="font-size:14px;margin:0 0 4px;font-weight:800">Names already in your shipments and proformas</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 12px">Not yet in this master. Click one to create it — the name is filled in for you. Your existing documents are not changed either way.</p>
  <?php foreach ($suggest as $s): ?>
    <a class="ip-chip" href="?name=<?= urlencode($s['name']) ?>"><?= e($s['name']) ?> <small><?= (int)$s['uses'] ?>×</small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="ip-bar">
  <div class="ip-tabs">
    <a class="ip-tab <?= $t === '' ? 'on' : '' ?>" href="?">All</a>
    <?php foreach ($TYPES as $k => $lbl): if ($k === 'both') continue; ?>
      <a class="ip-tab <?= $t === $k ? 'on' : '' ?>" href="?t=<?= e($k) ?>"><?= $lbl ?> <?= (int)($counts[$k] ?? 0) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if ($t !== ''): ?><input type="hidden" name="t" value="<?= e($t) ?>"><?php endif; ?>
    <div><label class="ip-lbl">Search</label><input class="ip-inp" name="q" value="<?= e($q) ?>" placeholder="name, code or city" style="width:auto"></div>
    <label style="display:flex;gap:7px;align-items:center;font-size:12px;font-weight:600;padding-bottom:9px">
      <input type="checkbox" name="inactive" value="1" <?= $showInactive ? 'checked' : '' ?>> Show inactive</label>
    <button class="ip-btn sec" type="submit" style="cursor:pointer">Search</button>
  </form>
</div>

<div class="ip-card">
  <?php if (!$rows): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No parties yet.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="ip-tbl">
    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>City</th><th>Phone</th><th>NTN</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= (int)$r['is_active'] === 0 ? ' style="opacity:.55"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($r['code']) ?></td>
        <td style="font-weight:600"><?= e($r['name']) ?><?php if ($r['address']): ?><br><span style="font-size:11px;color:#8a97ab"><?= e($r['address']) ?></span><?php endif; ?></td>
        <td><span class="ip-pill t-<?= e($r['party_type']) ?>"><?= $TYPES[$r['party_type']] ?? e($r['party_type']) ?></span></td>
        <td><?= e($r['city'] ?: '—') ?></td>
        <td><?= e($r['phone'] ?: '—') ?></td>
        <td style="font-family:monospace"><?= e($r['ntn'] ?: '—') ?></td>
        <td><?= (int)$r['is_active'] === 1
              ? '<span class="ip-pill" style="background:rgba(22,163,74,.10);color:#16a34a">Active</span>'
              : '<span class="ip-pill" style="background:rgba(224,67,93,.10);color:#c0293f">Inactive</span>' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($canEdit): ?>
            <a class="ip-btn sec" style="padding:5px 10px;font-size:11.5px" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="ip-btn sec" style="padding:5px 10px;font-size:11.5px;cursor:pointer" type="submit"><?= (int)$r['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="font-size:11.5px;color:#8a97ab;margin:14px 0 0"><?= count($rows) ?> party(ies) shown.</p>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
