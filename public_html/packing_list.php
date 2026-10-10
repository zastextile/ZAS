<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
/* For pack_palette() in the strip below: this page did not need the
   packing helpers before, and calling one without this line is a fatal
   error on the live packing list rather than a missing strip. */
require_once __DIR__ . '/includes/packing.php';
require_login();
if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot access the packing list.'); }
exp_ensure_schema();
/* One indexed read of the version marker, not a run of DDL — this page
   reads the order's sizes and colours now, so the table has to exist. */
pack_ensure_schema();

/*
  Packing List V2.3 - Zero Balance Visible in Dashboard
  - Staff layout remains mobile-first.
  - Admin/Colleague layout remains PC/table mode.
  - Staff can add/delete wrong rows before Complete Packing List.
  - After staff clicks Complete Packing List, staff cannot add/delete/edit.
  - Admin/Colleague can still amend packing after staff completion, until shipment is final approved_locked.
  - Admin/Colleague can reopen packing for staff if needed.
  - Saved rows are live in database immediately after each save.
  - No OpenAI API/token used on this page.
*/

function zas_pack_ensure_columns_v21(): void {
    try { db()->exec("ALTER TABLE packing_items ADD COLUMN invoice_item_id INT NULL AFTER shipment_id"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE packing_items ADD COLUMN pack_unit_title VARCHAR(60) NOT NULL DEFAULT 'Carton' AFTER optional_value"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE packing_items ADD COLUMN qty_mode VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER qty_per_carton"); } catch (Throwable $e) {}

    try { db()->exec("ALTER TABLE shipments ADD COLUMN packing_status VARCHAR(20) NOT NULL DEFAULT 'open' AFTER status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN packing_completed_by INT NULL AFTER packing_status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN packing_completed_at DATETIME NULL AFTER packing_completed_by"); } catch (Throwable $e) {}
}
zas_pack_ensure_columns_v21();

function zas_pack_demo_css(): string {
    return '<style>
.topbar .lead,.lead{color:#5a6b82}
.card{padding:22px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:18px}
.card-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.card-head h2{font-size:15px;margin:0}
.readonly-note{padding:14px 16px;border-radius:14px;background:#f6f8fc;border:1px solid #e3e9f2;font-size:13px;color:#33415c;line-height:1.6}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
th{padding:5px 8px}
td{padding:3px 8px;border-top:1px solid #f6f8fc;color:#152033}
td.num,th.num{text-align:right}
.btn{padding:10px 16px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.btn.secondary{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.btn.orange{background:linear-gradient(100deg,#d97706,#c2410c);color:#fff}
.btn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3)}
.badge{padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap}
.badge.green{background:rgba(22,163,74,.16);color:#16a34a;border:1px solid rgba(22,163,74,.3)}
.badge.orange{background:rgba(217,119,6,.16);color:#d97706;border:1px solid rgba(217,119,6,.3)}
.badge.blue{background:rgba(47,127,224,.16);color:#2f7fe0;border:1px solid rgba(47,127,224,.3)}
.actions{display:flex;flex-wrap:wrap;gap:10px}
.alert{padding:14px 18px;border-radius:14px;font-size:13px}
.alert.error{background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.28);color:#b8283f}
.alert.success{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.28);color:#127a3f}
</style>';
}

function zas_pack_status(array $shipment): string {
    return $shipment['packing_status'] ?? 'open';
}

function zas_pack_is_completed(array $shipment): bool {
    return zas_pack_status($shipment) === 'completed';
}

function zas_pack_accessible_shipments_v21(): array {
    if (is_admin()) {
        return db()->query("SELECT * FROM shipments ORDER BY id DESC LIMIT 300")->fetchAll();
    }

    $ids = assigned_shipment_ids();
    if (!$ids) return [];

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM shipments WHERE id IN ($in) ORDER BY id DESC");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function zas_pack_selected_item_id_v21(array $pack, array $invoiceItems): int {
    if (!empty($pack['invoice_item_id'])) {
        return (int)$pack['invoice_item_id'];
    }

    foreach ($invoiceItems as $it) {
        if ($it['product_name'] === $pack['product_name']
            && (string)$it['des_col'] === (string)$pack['des_col']
            && (string)$it['optional_value'] === (string)$pack['optional_value']) {
            return (int)$it['id'];
        }
    }

    return 0;
}

function zas_pack_recalc_totals_v21(int $shipmentId): void {
    $stmt = db()->prepare("SELECT COALESCE(SUM(packages),0) units, COALESCE(SUM(net_weight),0) net, COALESCE(SUM(gross_weight),0) gross FROM packing_items WHERE shipment_id=?");
    $stmt->execute([$shipmentId]);
    $t = $stmt->fetch();

    db()->prepare("UPDATE shipments SET total_packages=?, total_net_weight=?, total_gross_weight=?, updated_by=?, updated_at=NOW() WHERE id=?")
        ->execute([
            (float)$t['units'],
            (float)$t['net'],
            (float)$t['gross'],
            current_user()['id'],
            $shipmentId
        ]);
}

/*
  V2.4 — A PACKAGE NUMBER ONCE PER KIND OF PACKAGE (was: twice, any kind).

  "Do not allow the same serial or the same carton number to repeat in
   the same shipment — but the same number can be used if the package
   type is different, like a roll."

  So the count is now taken per kind of package ($unitTitle, compared
  without case or spaces), and a number already used once by that kind
  is refused. It also counts the ranges entered on the packing phone
  that are not yet approved — approved ones are already rows here — so
  the desktop and the phone cannot hand out the same carton number.
  Everything else in this function is as it was.
*/
function zas_pack_serial_counts_v21(int $shipmentId, string $unitTitle = 'Carton'): array {
    $kind = mb_strtolower(trim($unitTitle !== '' ? $unitTitle : 'Carton'));
    $stmt = db()->prepare("SELECT carton_from, carton_to FROM packing_items WHERE shipment_id=?
                           AND LOWER(TRIM(COALESCE(NULLIF(pack_unit_title,''),'Carton')))=? ORDER BY id");
    $stmt->execute([$shipmentId, $kind]);
    $rows = $stmt->fetchAll();
    try {
        $g = db()->prepare("SELECT serial_from AS carton_from, serial_to AS carton_to FROM packing_groups
                            WHERE shipment_id=? AND LOWER(TRIM(unit_title))=?
                              AND id NOT IN (SELECT packing_group_id FROM packing_items
                                             WHERE shipment_id=? AND packing_group_id IS NOT NULL)");
        $g->execute([$shipmentId, $kind, $shipmentId]);
        $rows = array_merge($rows, $g->fetchAll());
    } catch (Throwable $e) { /* no phone ranges table yet — nothing to add */ }

    $counts = [];
    foreach ($rows as $r) {
        $from = (int)$r['carton_from'];
        $to = (int)$r['carton_to'];

        if ($from <= 0 || $to < $from) continue;
        if (($to - $from) > 200000) continue;

        for ($s = $from; $s <= $to; $s++) {
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
    }

    return $counts;
}

function zas_pack_find_over_serials_v21(array $currentCounts, int $from, int $to): array {
    $bad = [];

    if ($from <= 0 || $to < $from) return ['invalid'];
    if (($to - $from) > 200000) return ['too_large'];

    for ($s = $from; $s <= $to; $s++) {
        $newCount = ($currentCounts[$s] ?? 0) + 1;
        /* V2.4: once per kind of package (was "> 2", which allowed two uses) */
        if ($newCount > 1) {
            $bad[] = $s;
            if (count($bad) >= 10) break;
        }
    }

    return $bad;
}

function zas_pack_validate_qty_v21(int $shipmentId, int $itemId, array $item, float $newQty): void {
    $stmt = db()->prepare("SELECT COALESCE(SUM(total_qty),0) FROM packing_items WHERE shipment_id=? AND (invoice_item_id=? OR (product_name=? AND des_col=? AND optional_value=?))");
    $stmt->execute([$shipmentId, $itemId, $item['product_name'], $item['des_col'], $item['optional_value']]);

    $alreadyPacked = (float)$stmt->fetchColumn();
    $invoiceQty = (float)$item['qty'];

    if (($alreadyPacked + $newQty) > $invoiceQty + 0.0001) {
        throw new Exception('Packed qty cannot be more than invoice qty. Invoice Qty: ' . $invoiceQty . ', Already Packed: ' . $alreadyPacked . ', New Qty: ' . $newQty);
    }
}

/* REMOVED: zas_pack_filter_items_by_dept_v21().

   It filtered packing lines by the Department set on each invoice line —
   but nothing ever called it. It was written, never wired up, and left
   here looking as though department routing worked. It does not, and
   never did.

   That dead function is why I warned that removing the Dept dropdown
   would break packing routing. It would not have. Deleting it so the
   next person to read this file is not misled the same way. If per-user
   department routing is wanted later, it should be built deliberately —
   is_dept() in includes/auth.php is the piece that actually works. */

function zas_pack_insert_row_v21(int $shipmentId, int $itemId, array $item, string $unitTitle, int $from, int $to, string $qtyMode, float $qtyPerUnit, float $totalQty, float $net, float $gross): void {
    $lineStmt = db()->prepare("SELECT COALESCE(MAX(line_no),0)+1 FROM packing_items WHERE shipment_id=?");
    $lineStmt->execute([$shipmentId]);
    $lineNo = (int)$lineStmt->fetchColumn();

    $units = max(0, $to - $from + 1);

    $stmtPack = db()->prepare("INSERT INTO packing_items
        (shipment_id, invoice_item_id, line_no, product_name, des_col, optional_value, pack_unit_title, carton_from, carton_to, packages, qty_per_carton, qty_mode, total_qty, net_weight, gross_weight)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $stmtPack->execute([
        $shipmentId,
        $itemId,
        $lineNo,
        $item['product_name'],
        $item['des_col'],
        $item['optional_value'],
        $unitTitle,
        $from,
        $to,
        $units,
        $qtyPerUnit,
        $qtyMode,
        $totalQty,
        $net,
        $gross,
    ]);
}

$id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
$itemId = (int)($_GET['item_id'] ?? $_POST['invoice_item_id'] ?? 0);
$isStaffLayout = is_staff();
// $currentDept removed with the filter above — it was assigned and never read.

/* Shipment list screen */
if (!$id) {
    $rows = zas_pack_accessible_shipments_v21();

    page_header('Packing List');
    flash();

    if ($isStaffLayout): ?>
    <style>
    .staff-wrap{max-width:560px;margin:0 auto}.staff-head{background:linear-gradient(180deg,#0f2742,#16436d);color:#fff;border-radius:22px;padding:18px;margin-bottom:14px}.staff-title{font-size:22px;font-weight:900;margin:0}.staff-sub{font-size:13px;color:#dbeafe;margin-top:6px;line-height:1.55}.staff-card{background:#fff;border:1px solid #d9e4ef;border-radius:20px;padding:16px;margin-bottom:14px;box-shadow:0 10px 28px rgba(15,39,66,.08)}.staff-ship{font-size:18px;font-weight:900}.staff-meta{font-size:13px;color:#667085;line-height:1.55;margin-top:7px}.staff-btn{display:block;width:100%;text-align:center;border:0;border-radius:16px;background:#0f766e;color:#fff;font-size:18px;font-weight:900;padding:16px;margin-top:12px}.staff-badge{display:inline-block;border-radius:999px;padding:7px 10px;background:#eff8ff;color:#0f4c81;font-size:12px;font-weight:900;margin-top:8px}.staff-badge.done{background:#ecfdf3;color:#067647}
    @media(max-width:700px){.content{padding:10px!important}.staff-wrap{max-width:none}}
    </style>

    <div class="staff-wrap">
      <div class="staff-head">
        <h1 class="staff-title">Staff Packing</h1>
        <div class="staff-sub">Only assigned shipments are shown here. Rates and amounts are hidden.</div>
      </div>

      <?php if (!$rows): ?><div class="alert error">No shipment assigned to this user.</div><?php endif; ?>

      <?php foreach($rows as $r): ?>
      <div class="staff-card">
        <div class="staff-ship"><?= e($r['invoice_no']) ?></div>
        <div class="staff-meta">
          Buyer: <?= e($r['buyer_name']) ?><br>
          Destination: <?= e($r['destination_port']) ?><br>
          Units: <?= e(num_fmt($r['total_packages'],0)) ?> | Gross: <?= e(trim_num($r['total_gross_weight'], 3)) ?> kg
        </div>
        <?php if (zas_pack_is_completed($r)): ?>
          <div class="staff-badge done">Packing Completed</div>
        <?php else: ?>
          <div class="staff-badge">Packing Open</div>
        <?php endif; ?>
        <a class="staff-btn" href="packing_list.php?id=<?= e($r['id']) ?>">Open Packing</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <?= zas_pack_demo_css() ?>
    <div class="topbar">
      <div><h1>Packing List</h1><p class="lead">PC/table mode for Admin and Documentation users.</p></div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Invoice</th><th>Buyer</th><th>Destination</th><th>Packing Status</th><th>Shipment Status</th><th>Units</th><th>Gross Wt</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?= e($r['invoice_no']) ?></td>
              <td><?= e($r['buyer_name']) ?></td>
              <td><?= e($r['destination_port']) ?></td>
              <td><?= zas_pack_is_completed($r) ? '<span class="badge green">Completed</span>' : '<span class="badge orange">Open</span>' ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td class="num"><?= e(num_fmt($r['total_packages'],0)) ?></td>
              <td class="num"><?= e(trim_num($r['total_gross_weight'], 3)) ?></td>
              <td><a class="btn secondary" href="packing_list.php?id=<?= e($r['id']) ?>">Open Packing</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif;

    page_footer();
    exit;
}

/* Load shipment */
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment || !can_view_shipment($id)) {
    http_response_code(404);
    exit('Shipment not found or not assigned.');
}

$locked = $shipment['status'] === 'approved_locked';
$packingCompleted = zas_pack_is_completed($shipment);

$canEditBase = can_edit_packing($shipment);

/*
  Staff can edit only before staff-completion and before final shipment lock.
  Admin/Colleague can edit packing after staff-completion, as long as shipment is not approved_locked.
  If final shipment approved_locked, only Admin can amend per existing can_edit_packing().
*/
if (is_staff() && $packingCompleted) {
    $canEdit = false;
} else {
    $canEdit = $canEditBase;
}

$itemsStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
$itemsStmt->execute([$id]);
$invoiceItems = $itemsStmt->fetchAll();

$itemMap = [];
foreach ($invoiceItems as $it) {
    $itemMap[(int)$it['id']] = $it;
}

/* Product Master lookup (auto-weight) — keyed by normalized product name */
$pmByName = [];
try {
    foreach (db()->query("SELECT name, net_weight, gross_weight, std_pack_qty, cbm_per_unit, is_active FROM products")->fetchAll() as $pmRow) {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', (string)$pmRow['name'])));
        $pmByName[$key] = $pmRow;
    }
} catch (Throwable $e) { $pmByName = []; }
function pm_lookup(array $map, string $name): ?array {
    $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', $name)));
    return $map[$key] ?? null;
}

/* Complete / Reopen packing action */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_packing') {
    verify_csrf();

    if ($locked && !is_admin()) {
        http_response_code(403);
        exit('Locked shipment. Only Admin can amend.');
    }

    if (is_staff() && $packingCompleted) {
        http_response_code(403);
        exit('Packing already completed.');
    }

    try {
        db()->beginTransaction();

        db()->prepare("UPDATE shipments SET packing_status='completed', packing_completed_by=?, packing_completed_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([current_user()['id'], current_user()['id'], $id]);

        audit_log($id, 'Packing Completion', 'complete', '', 'Packing completed / submitted', is_staff() ? 'Staff completed packing list' : 'Admin/Colleague marked packing completed');

        db()->commit();

        $_SESSION['flash'] = 'Packing list completed. Staff cannot edit now.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }

    redirect('packing_list.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen_packing') {
    verify_csrf();

    if (is_staff()) {
        http_response_code(403);
        exit('Staff cannot reopen completed packing.');
    }

    if ($locked && !is_admin()) {
        http_response_code(403);
        exit('Locked shipment. Only Admin can amend.');
    }

    try {
        db()->beginTransaction();

        db()->prepare("UPDATE shipments SET packing_status='open', packing_completed_by=NULL, packing_completed_at=NULL, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([current_user()['id'], $id]);

        audit_log($id, 'Packing Reopen', 'reopen', 'completed', 'open', 'Admin/Colleague reopened packing for correction');

        db()->commit();

        $_SESSION['flash'] = 'Packing list reopened.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }

    redirect('packing_list.php?id=' . $id);
}

/* Add row */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_row') {
    verify_csrf();

    if (!$canEdit) {
        http_response_code(403);
        exit('You cannot edit this packing list.');
    }

    if ($locked && !is_admin()) {
        http_response_code(403);
        exit('Locked shipment. Only Admin can amend.');
    }

    try {
        $itemId = (int)($_POST['invoice_item_id'] ?? 0);
        if (!$itemId || empty($itemMap[$itemId])) throw new Exception('Please select valid invoice item.');

        $it = $itemMap[$itemId];

        $unitTitle = trim($_POST['pack_unit_title'] ?? 'Carton');
        if ($unitTitle === '') $unitTitle = 'Carton';

        $from = (int)($_POST['serial_from'] ?? 0);
        $to = (int)($_POST['serial_to'] ?? 0);

        if ($from <= 0 || $to < $from) throw new Exception('Serial range is not correct.');
        if (($to - $from) > 200000) throw new Exception('Serial range is too large. Please split into smaller rows.');

        $units = max(0, $to - $from + 1);

        $qtyMode = trim($_POST['qty_mode'] ?? 'auto');
        $qtyMode = ($qtyMode === 'direct') ? 'direct' : 'auto';

        $qtyPerUnit = (float)($_POST['qty_per_unit'] ?? 0);
        $directQty = (float)($_POST['direct_total_qty'] ?? 0);

        $totalQty = ($qtyMode === 'direct') ? $directQty : ($units * $qtyPerUnit);
        if ($totalQty <= 0) throw new Exception('Total quantity must be greater than zero.');

        $net = (float)($_POST['net_weight'] ?? 0);
        $gross = (float)($_POST['gross_weight'] ?? 0);

        zas_pack_validate_qty_v21($id, $itemId, $it, $totalQty);

        $counts = zas_pack_serial_counts_v21($id, $unitTitle);
        $badSerials = zas_pack_find_over_serials_v21($counts, $from, $to);
        if ($badSerials) {
            if ($badSerials[0] === 'invalid') throw new Exception('Serial range is not correct.');
            if ($badSerials[0] === 'too_large') throw new Exception('Serial range is too large.');
            throw new Exception($unitTitle . ' number already used on this shipment. A number can be used once per kind of package — the same number is fine only for a different kind, like a Roll. Problem serials: ' . implode(', ', $badSerials));
        }

        db()->beginTransaction();

        zas_pack_insert_row_v21($id, $itemId, $it, $unitTitle, $from, $to, $qtyMode, $qtyPerUnit, $totalQty, $net, $gross);
        zas_pack_recalc_totals_v21($id);

        /*
          If Admin/Colleague amends a completed packing list, keep it completed.
          Staff cannot amend completed packing due $canEdit=false.
        */
        if ($locked && is_admin()) {
            db()->prepare("UPDATE shipments SET revision_no=revision_no+1 WHERE id=?")->execute([$id]);
            db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);
            audit_log($id, 'Packing Row Add', 'add', '', 'Packing row added by Admin', trim($_POST['amendment_reason'] ?? 'Admin added packing row'));
        } else {
            audit_log($id, 'Packing Row Add', 'add', '', 'Packing row added', is_staff() ? 'Staff mobile packing entry' : 'Admin/Colleague packing entry');
        }

        db()->commit();

        $_SESSION['flash'] = 'Packing row saved. Admin/Colleague can see this update live.';
        redirect('packing_list.php?id=' . $id . (is_staff() ? '&item_id=' . $itemId : '#pcPackForm'));
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
        redirect('packing_list.php?id=' . $id . ($itemId && is_staff() ? '&item_id=' . $itemId : (is_staff() ? '' : '#pcPackForm')));
    }
}

/* Delete row */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_row') {
    verify_csrf();

    if (!$canEdit) {
        http_response_code(403);
        exit('You cannot edit this packing list.');
    }

    if ($locked && !is_admin()) {
        http_response_code(403);
        exit('Locked shipment. Only Admin can amend.');
    }

    $rowId = (int)($_POST['packing_id'] ?? 0);

    try {
        $stmt = db()->prepare("SELECT * FROM packing_items WHERE id=? AND shipment_id=?");
        $stmt->execute([$rowId, $id]);
        $oldRow = $stmt->fetch();

        if (!$oldRow) throw new Exception('Packing row not found.');

        db()->beginTransaction();

        db()->prepare("DELETE FROM packing_items WHERE id=? AND shipment_id=?")->execute([$rowId, $id]);
        zas_pack_recalc_totals_v21($id);

        if ($locked && is_admin()) {
            db()->prepare("UPDATE shipments SET revision_no=revision_no+1 WHERE id=?")->execute([$id]);
            db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);
            audit_log($id, 'Packing Row Delete', 'delete', $oldRow, 'Packing row deleted by Admin', trim($_POST['amendment_reason'] ?? 'Admin deleted packing row'));
        } else {
            audit_log($id, 'Packing Row Delete', 'delete', $oldRow, 'Packing row deleted', is_staff() ? 'Staff correction before completion' : 'Admin/Colleague correction');
        }

        db()->commit();

        $_SESSION['flash'] = 'Packing row deleted.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }

    redirect('packing_list.php?id=' . $id . ($itemId ? '&item_id=' . $itemId : ''));
}

/* Display data */
$packsStmt = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id");
$packsStmt->execute([$id]);
$packs = $packsStmt->fetchAll();

$packedByItem = [];
$serialCounts = [];
foreach ($packs as $p) {
    $sid = zas_pack_selected_item_id_v21($p, $invoiceItems);
    if ($sid) $packedByItem[$sid] = ($packedByItem[$sid] ?? 0) + (float)$p['total_qty'];

    $from = (int)$p['carton_from'];
    $to = (int)$p['carton_to'];
    if ($from > 0 && $to >= $from && ($to - $from) <= 200000) {
        for ($s = $from; $s <= $to; $s++) {
            $serialCounts[$s] = ($serialCounts[$s] ?? 0) + 1;
        }
    }
}

$selectedItem = $itemId && !empty($itemMap[$itemId]) ? $itemMap[$itemId] : null;
$selectedPacked = $selectedItem ? ($packedByItem[$itemId] ?? 0) : 0;
$selectedLeft = $selectedItem ? ((float)$selectedItem['qty'] - $selectedPacked) : 0;

/*
  Items with zero balance are hidden from packing-entry selection.
  They remain visible in Admin/Colleague balance table and saved rows.
*/
$packableInvoiceItems = [];
foreach ($invoiceItems as $it) {
    $packedQtyForItem = $packedByItem[(int)$it['id']] ?? 0;
    $leftQtyForItem = (float)$it['qty'] - (float)$packedQtyForItem;
    if ($leftQtyForItem > 0.0001) {
        $packableInvoiceItems[] = $it;
    }
}
$hasPackableItems = count($packableInvoiceItems) > 0;

page_header('Packing List');
flash();

/* STAFF MOBILE LAYOUT */
if ($isStaffLayout): ?>
<style>
.staff-wrap{max-width:560px;margin:0 auto}.staff-head{background:linear-gradient(180deg,#0f2742,#16436d);color:#fff;border-radius:22px;padding:18px;margin-bottom:14px}.staff-title{font-size:22px;font-weight:900;margin:0}.staff-sub{font-size:13px;color:#dbeafe;line-height:1.55;margin-top:6px}.staff-card{background:#fff;border:1px solid #d9e4ef;border-radius:20px;padding:16px;margin-bottom:14px;box-shadow:0 10px 28px rgba(15,39,66,.08)}.staff-card h2{font-size:18px;color:#0f4c81;margin:0 0 6px}.staff-note{font-size:14px;color:#667085;line-height:1.55;margin-top:6px}.staff-big-btn{display:block;width:100%;text-align:center;border:0;border-radius:16px;background:#0f766e;color:#fff;font-size:18px;font-weight:900;padding:16px 14px;margin-top:12px;cursor:pointer}.staff-big-btn.light{background:#e9f2fc;color:#0f4c81}.staff-big-btn.orange{background:#b54708}.staff-big-btn.red{background:#b42318}.staff-item{border:2px solid #d8eafd;border-radius:18px;padding:15px;margin-top:12px;background:#fff}.staff-item-title{font-size:17px;font-weight:900}.staff-item-desc{font-size:13px;color:#667085;line-height:1.5;margin-top:5px}.staff-pill-row{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.staff-pill{border-radius:999px;padding:7px 9px;font-size:12px;font-weight:900;background:#eff8ff;color:#0f4c81}.staff-pill.green{background:#ecfdf3;color:#067647}.staff-pill.red{background:#fef3f2;color:#b42318}.staff-pill.orange{background:#fff7ed;color:#b54708}.staff-field{margin-top:12px}.staff-field label{display:block;font-size:14px;font-weight:900;color:#475467;margin-bottom:6px}.staff-field input,.staff-field select,.staff-field textarea{width:100%;border:1px solid #d9e4ef;border-radius:16px;padding:15px 13px;font-size:18px;background:#fff;color:#152033}.staff-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}.staff-alert{border-radius:16px;padding:13px;font-size:14px;line-height:1.45;margin-top:12px}.staff-alert.ok{background:#ecfdf3;color:#067647;border:1px solid #b7ebc6}.staff-alert.err{background:#fef3f2;color:#b42318;border:1px solid #fecdca}.staff-alert.info{background:#eff8ff;color:#0f4c81;border:1px solid #cce4ff}.staff-serial{background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:13px;margin-top:12px;color:#9a3412;font-size:14px;line-height:1.5}.staff-row{background:#fff;border:1px solid #d9e4ef;border-radius:16px;padding:13px;margin-top:10px}.staff-row-title{font-size:15px;font-weight:900}.staff-row-sub{font-size:13px;color:#667085;line-height:1.5;margin-top:5px}.staff-delete-form{margin-top:8px}.staff-delete-form button{border:0;border-radius:12px;background:#fef3f2;color:#b42318;font-weight:900;padding:10px 12px;width:100%}.staff-backbar{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.staff-wrap{direction:ltr;text-align:left}.staff-field input,.staff-field select{text-align:left}.staff-field label{text-align:left}.staff-big-btn{text-decoration:none}
.staff-wrap{direction:ltr;text-align:left}.staff-field input,.staff-field select{text-align:left}.staff-field label{text-align:left}.staff-big-btn{text-decoration:none}
@media(max-width:700px){.content{padding:10px!important}.staff-wrap{max-width:none}}
</style>

<div class="staff-wrap">
  <div class="staff-head">
    <h1 class="staff-title">Packing - <?= e($shipment['invoice_no']) ?></h1>
    <div class="staff-sub">
      <?= e($shipment['buyer_name']) ?><br>
      <?= e($shipment['destination_port']) ?><br>
      Packing: <?= $packingCompleted ? 'Completed' : 'Open' ?>
    </div>
  </div>

  <?php if ($packingCompleted): ?>
  <div class="staff-alert info">
    Packing list completed. Staff cannot edit now. Admin/Colleague can amend if correction is required.
  </div>
  <?php endif; ?>

  <?php if (!$invoiceItems): ?><div class="alert error">No invoice items found. Add commercial invoice items first.</div><?php endif; ?>

<?php
/* THE ORDER'S SIZES AND COLOURS, AND A WAY IN TO SET THEM.
   The packing phone offers only what is set here, so a line that was
   never set up leaves the packer with nothing to pick and he types —
   which is how one colour ends up spelled three ways. This strip says
   where it stands before anybody picks up a phone. */
$palSet = 0; $palTot = 0;
foreach ($invoiceItems as $pit) {
    $palTot++;
    $pp = pack_palette((int)$id, (int)$pit['id']);
    if ($pp['size'] || $pp['colour']) $palSet++;
}
?>
<div class="card" style="border-left:4px solid <?= $palSet === $palTot && $palTot ? '#16a34a' : '#d97706' ?>">
  <div class="actions" style="align-items:center;gap:14px;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <b>Order sizes &amp; colours</b>
      <p class="lead" style="margin:3px 0 0">
        <?php if (!$palTot): ?>
          Add the invoice lines first, then set what each one is packed in.
        <?php elseif ($palSet === $palTot): ?>
          Set on all <?= (int)$palTot ?> line<?= $palTot === 1 ? '' : 's' ?>.
          The packing phones offer exactly this.
        <?php elseif ($palSet): ?>
          Set on <?= (int)$palSet ?> of <?= (int)$palTot ?> lines.
          On the rest the packer has nothing to pick from and will type it himself.
        <?php else: ?>
          Not set on any line yet. Type the customer's sizes and colours once here —
          the packing phones then offer only those.
        <?php endif; ?>
      </p>
    </div>
    <a class="btn<?= $palSet === $palTot && $palTot ? ' secondary' : '' ?>"
       href="pack_palette.php?id=<?= e($id) ?>">Set sizes &amp; colours</a>
  </div>
</div>
  <?php if ($locked && !is_admin()): ?><div class="alert error">This shipment is locked. Staff cannot edit now.</div><?php endif; ?>

  <?php if (!$selectedItem): ?>
    <div class="staff-card">
      <h2>Select Item</h2>
      <div class="staff-note">Staff cannot type a new item name. Select only the item already available in the commercial invoice.</div>

      <?php foreach($invoiceItems as $it):
        $packed = $packedByItem[(int)$it['id']] ?? 0;
        $left = (float)$it['qty'] - $packed;
      ?>
      <div class="staff-item">
        <div class="staff-item-title">Line <?= e($it['line_no']) ?> - <?= e($it['product_name']) ?></div>
        <div class="staff-item-desc"><?= e($it['des_col']) ?></div>
        <div class="staff-pill-row">
          <span class="staff-pill">Invoice: <?= e(trim_num($it['qty'], 3)) ?></span>
          <span class="staff-pill green">Packed: <?= e(trim_num($packed, 3)) ?></span>
          <span class="staff-pill red">Left: <?= e(trim_num($left, 3)) ?></span>
        </div>
        <?php if($canEdit && $left > 0): ?>
        <a class="staff-big-btn" href="packing_list.php?id=<?= e($id) ?>&item_id=<?= e($it['id']) ?>">Pack This Item</a>
        <?php elseif($left <= 0): ?>
        <div class="staff-alert ok">This item is fully packed. New row cannot be added.</div>
        <?php elseif($packingCompleted): ?>
        <div class="staff-alert info">Completed. View only.</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if(!$hasPackableItems): ?>
        <div class="staff-alert ok">This item is fully packed. New packing row cannot be added.</div>
      <?php endif; ?>
    </div>

    <?php if($canEdit && !$packingCompleted && $packs): ?>
    <div class="staff-card">
      <h2>Finish Packing</h2>
      <div class="staff-note">After all packing rows are entered, press this button. Staff cannot edit after completion.</div>
      <form method="post" onsubmit="return confirm('Complete packing list? After this, staff cannot edit.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="complete_packing">
        <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
        <button class="staff-big-btn orange" type="submit">Complete Packing List</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="staff-card"><a class="staff-big-btn light" href="packing_list.php">Back to Shipments</a></div>
  <?php else: ?>
    <div class="staff-card">
      <div class="staff-backbar">
        <a class="staff-big-btn light" href="packing_list.php?id=<?= e($id) ?>">Items</a>
        <a class="staff-big-btn light" href="packing_list.php">Shipments</a>
      </div>
    </div>

    <div class="staff-card">
      <h2>Selected Item</h2>
      <div class="staff-item-title"><?= e($selectedItem['product_name']) ?></div>
      <div class="staff-item-desc"><?= e($selectedItem['des_col']) ?></div>
      <div class="staff-pill-row">
        <span class="staff-pill">Invoice: <?= e(trim_num($selectedItem['qty'], 3)) ?></span>
        <span class="staff-pill green">Packed: <?= e(trim_num($selectedPacked, 3)) ?></span>
        <span class="staff-pill red">Left: <?= e(trim_num($selectedLeft, 3)) ?></span>
      </div>
      <div class="staff-serial"><strong>Serial Rule:</strong><br>One serial number can be used maximum <b>2 times</b>. Third use will not be saved.</div>
    </div>

    <?php if($canEdit && $selectedLeft > 0): ?>
    <form method="post" id="staffPackingForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_row">
      <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
      <input type="hidden" name="invoice_item_id" value="<?= e($selectedItem['id']) ?>">

      <div class="staff-card">
        <h2>New Packing Row</h2>
        <div class="staff-field"><label>Packing Unit Title</label><select name="pack_unit_title" id="staffUnitTitle"><option value="Carton">Carton</option><option value="Roll">Roll</option><option value="Bale">Bale</option><option value="Bundle">Bundle</option><option value="Pallet">Pallet</option><option value="Package">Package</option></select></div>
        <div class="staff-grid2">
          <div class="staff-field"><label>Serial From</label><input name="serial_from" id="staffSerialFrom" type="number" value="1" inputmode="numeric"></div>
          <div class="staff-field"><label>Serial To</label><input name="serial_to" id="staffSerialTo" type="number" value="1" inputmode="numeric"></div>
        </div>
        <div class="staff-field"><label>Qty Mode</label><select name="qty_mode" id="staffQtyMode"><option value="auto">Auto Qty: Units x Qty/Unit</option><option value="direct">Direct Qty: manual total</option></select></div>
        <div class="staff-grid2">
          <div class="staff-field"><label>Qty / Unit</label><input name="qty_per_unit" id="staffQtyPerUnit" type="number" step="0.001" value="0" inputmode="decimal"></div>
          <div class="staff-field"><label>Total Qty</label><input name="direct_total_qty" id="staffDirectTotalQty" type="number" step="0.001" value="0" inputmode="decimal"></div>
        </div>
        <div class="staff-grid2">
          <div class="staff-field"><label>Net Weight</label><input name="net_weight" type="number" step="0.001" value="0" inputmode="decimal"></div>
          <div class="staff-field"><label>Gross Weight</label><input name="gross_weight" type="number" step="0.001" value="0" inputmode="decimal"></div>
        </div>
        <div id="staffCalcMsg" class="staff-alert ok">Ready</div>
        <button class="staff-big-btn" type="submit">Save Packing Row</button>
      </div>
    </form>
    <?php elseif($selectedLeft <= 0): ?>
      <div class="staff-alert ok">This item is fully packed. No more quantity can be added.</div>
    <?php elseif($packingCompleted): ?>
      <div class="staff-alert info">Packing list completed. Staff cannot edit now. Admin/Colleague can amend if correction is required.</div>
    <?php endif; ?>

    <div class="staff-card">
      <h2>Saved Rows for This Item</h2>
      <?php $hasRows=false; foreach($packs as $p):
        $pid = zas_pack_selected_item_id_v21($p, $invoiceItems);
        if($pid !== (int)$selectedItem['id']) continue;
        $hasRows=true;
      ?>
      <div class="staff-row">
        <div class="staff-row-title"><?= e($p['pack_unit_title'] ?? 'Carton') ?> <?= e($p['carton_from']) ?> - <?= e($p['carton_to']) ?></div>
        <div class="staff-row-sub">
          Mode: <?= e(($p['qty_mode'] ?? 'auto') === 'direct' ? 'Direct Qty' : 'Auto Qty') ?><br>
          Units: <?= e(num_fmt($p['packages'],0)) ?> | Qty: <?= e(trim_num($p['total_qty'], 3)) ?><br>
          Net: <?= e(trim_num($p['net_weight'], 3)) ?> | Gross: <?= e(trim_num($p['gross_weight'], 3)) ?>
        </div>
        <?php if($canEdit): ?>
        <form class="staff-delete-form" method="post" onsubmit="return confirm('Delete this wrong packing row?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_row">
          <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
          <input type="hidden" name="invoice_item_id" value="<?= e($selectedItem['id']) ?>">
          <input type="hidden" name="packing_id" value="<?= e($p['id']) ?>">
          <button type="submit">Delete Wrong Row</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; if(!$hasRows): ?><div class="staff-note">No saved packing rows for this item yet.</div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var selectedLeft = <?= json_encode((float)$selectedLeft) ?>;
  var serialCounts = <?= json_encode($serialCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

  function n(id){var el=document.getElementById(id); if(!el)return 0; var x=parseFloat(String(el.value||'0').replace(/,/g,'')); return isNaN(x)?0:x;}
  function trimDec(v,d){var s=Number(v||0).toFixed(d); if(s.indexOf('.')>-1) s=s.replace(/0+$/,'').replace(/\.$/,''); return s;}
  function calc(){
    var from=n('staffSerialFrom'), to=n('staffSerialTo');
    var modeEl=document.getElementById('staffQtyMode');
    var mode=modeEl?modeEl.value:'auto';
    var qpuEl=document.getElementById('staffQtyPerUnit');
    var totalEl=document.getElementById('staffDirectTotalQty');
    var msg=document.getElementById('staffCalcMsg');
    if(!msg)return true;
    var units=(from>0 && to>=from)?(to-from+1):0;
    var totalQty=0;
    if(mode==='direct'){totalQty=n('staffDirectTotalQty'); if(qpuEl)qpuEl.readOnly=true; if(totalEl)totalEl.readOnly=false;}
    else{totalQty=units*n('staffQtyPerUnit'); if(qpuEl)qpuEl.readOnly=false; if(totalEl){totalEl.value=totalQty?trimDec(totalQty,3):'0'; totalEl.readOnly=true;}}
    if(from<=0 || to<from){msg.className='staff-alert err'; msg.textContent='Serial range is not correct.'; return false;}
    var bad=[];
    for(var s=from;s<=to;s++){
      var used=serialCounts[s] || serialCounts[String(s)] || 0;
      if((used+1)>2){bad.push(s); if(bad.length>=10)break;}
      if((s-from)>200000){msg.className='staff-alert err'; msg.textContent='Serial range is too large. Please split into smaller rows.'; return false;}
    }
    if(bad.length){msg.className='staff-alert err'; msg.textContent='Serial already used 2 times: '+bad.join(', ')+'. Third use is not allowed.'; return false;}
    if(totalQty<=0){msg.className='staff-alert err'; msg.textContent='Total Qty cannot be zero.'; return false;}
    if(totalQty>selectedLeft+0.0001){msg.className='staff-alert err'; msg.textContent='Entered qty is more than remaining balance. Left: '+selectedLeft+' | Entered: '+totalQty; return false;}
    msg.className='staff-alert ok'; msg.textContent='Units: '+units+' | Total Qty: '+totalQty.toLocaleString(undefined,{maximumFractionDigits:3})+' | Left after save: '+(selectedLeft-totalQty).toLocaleString(undefined,{maximumFractionDigits:3});
    return true;
  }
  document.addEventListener('DOMContentLoaded',function(){
    ['staffSerialFrom','staffSerialTo','staffQtyMode','staffQtyPerUnit','staffDirectTotalQty'].forEach(function(id){var el=document.getElementById(id); if(el){el.addEventListener('input',calc); el.addEventListener('change',calc);}});
    var form=document.getElementById('staffPackingForm'); if(form){form.addEventListener('submit',function(e){if(!calc()){e.preventDefault(); alert('Please correct packing entry before save.');}});}
    calc();
  });
})();
</script>

<?php
page_footer();
exit;
endif;

/* ADMIN / COLLEAGUE PC LAYOUT */
?>
<?= zas_pack_demo_css() ?>
<?php
/* THE TAB STRIP — on this layout only. Staff get the mobile layout above and
   have no permission for any of the other tabs, so drawing it there would
   produce an empty strip. Everything below is unchanged. */
exp_tab_strip($shipment, 'packing');
?>
<div class="topbar">
  <div>
    <h1>Packing List - <?= e($shipment['invoice_no']) ?></h1>
    <p class="lead">PC/table mode for Admin and Documentation users. Staff uses mobile mode.</p>
  </div>
  <div>
    <?= status_badge($shipment['status']) ?>
    <?= $packingCompleted ? '<span class="badge green">Packing Completed</span>' : '<span class="badge orange">Packing Open</span>' ?>
  </div>
</div>

<?php if (!$invoiceItems): ?><div class="alert error">No invoice items found. Add commercial invoice items first.</div><?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>Packing Control</h2>
  </div>
  <div class="readonly-note">
    Every row saved by staff is immediately visible here after refresh. Once staff completes packing, staff becomes view-only. Admin/Colleague can still amend before final shipment approval/lock.
  </div>
  <br>
  <div class="actions">
    <?php if(!$packingCompleted): ?>
    <form method="post" onsubmit="return confirm('Mark packing as completed?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="complete_packing">
      <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
      <button class="btn orange" type="submit">Mark Packing Completed</button>
    </form>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('Reopen packing for staff correction?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reopen_packing">
      <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
      <button class="btn secondary" type="submit">Reopen for Staff</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Invoice Item Balance</h2></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Line</th><th>Item</th><th>Des / Col</th><th class="num">Invoice Qty</th><th class="num">Packed Qty</th><th class="num">Left</th></tr></thead>
      <tbody>
      <?php foreach($invoiceItems as $it):
        $packed = $packedByItem[(int)$it['id']] ?? 0;
        $left = (float)$it['qty'] - $packed;
      ?>
      <tr>
        <td><?= e($it['line_no']) ?></td>
        <td><?= e($it['product_name']) ?></td>
        <td><?= e($it['des_col']) ?></td>
        <td class="num"><?= e(trim_num($it['qty'], 3)) ?></td>
        <td class="num"><?= e(trim_num($packed, 3)) ?></td>
        <td class="num"><?= e(trim_num($left, 3)) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($canEditBase && $hasPackableItems): ?>
<div class="card pc-compact-card">
  <div class="card-head"><h2>Add / Amend Packing Row</h2></div>
  <div class="readonly-note">Compact one-line entry for Admin/Colleague PC mode. Zero-balance items are hidden from this selection only.</div>

  <style>
    .pc-compact-scroll{border:1px solid #e3e9f2;border-radius:14px;background:#ffffff;padding:12px;margin-top:12px}
    .pc-compact-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}
    .pc-compact-field:first-child{grid-column:1/-1}
    .pc-compact-field label{display:block;font-size:11px;font-weight:800;color:#5a6b82;margin:0 0 5px;text-transform:uppercase;letter-spacing:.02em}
    .pc-compact-field input,.pc-compact-field select{width:100%;border:1px solid #cbd5e3;border-radius:10px;padding:10px 9px;font-size:13px;background:#ffffff;color:#152033}
    .pc-compact-field .btn{width:100%;height:40px;text-align:center}
    .pc-compact-message{margin-top:10px}
    @media(max-width:900px){
      .pc-compact-scroll{padding-bottom:12px}
    }
  </style>

  <form method="post" id="pcPackForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_row">
    <input type="hidden" name="shipment_id" value="<?= e($id) ?>">

    <div class="pc-compact-scroll">
      <div class="pc-compact-row">
        <div class="pc-compact-field">
          <label>Invoice Item</label>
          <select name="invoice_item_id" id="pcInvoiceItem">
            <?php foreach($packableInvoiceItems as $it): $pm = pm_lookup($pmByName, $it['product_name']); ?>
            <option value="<?= e($it['id']) ?>" data-left="<?= e(((float)$it['qty']) - ($packedByItem[(int)$it['id']] ?? 0)) ?>" data-pmnet="<?= e($pm['net_weight'] ?? 0) ?>" data-pmgross="<?= e($pm['gross_weight'] ?? 0) ?>" data-pmpack="<?= e($pm['std_pack_qty'] ?? 0) ?>" data-pmcbm="<?= e($pm['cbm_per_unit'] ?? 0) ?>" data-pmactive="<?= e($pm ? (int)$pm['is_active'] : 1) ?>" data-pmfound="<?= $pm ? 1 : 0 ?>">
              Line <?= e($it['line_no']) ?> - <?= e($it['product_name']) ?> | <?= e($it['des_col']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="pc-compact-field">
          <label>Unit</label>
          <select name="pack_unit_title">
            <option>Carton</option><option>Roll</option><option>Bale</option><option>Bundle</option><option>Pallet</option><option>Package</option>
          </select>
        </div>

        <div class="pc-compact-field">
          <label>From</label>
          <input name="serial_from" id="pcFrom" type="number" value="1">
        </div>

        <div class="pc-compact-field">
          <label>To</label>
          <input name="serial_to" id="pcTo" type="number" value="1">
        </div>

        <div class="pc-compact-field">
          <label>Mode</label>
          <select name="qty_mode" id="pcQtyMode">
            <option value="auto">Auto Qty</option>
            <option value="direct">Direct Qty</option>
          </select>
        </div>

        <div class="pc-compact-field">
          <label>Qty/Unit</label>
          <input name="qty_per_unit" id="pcQtyPerUnit" type="number" step="0.001" value="0">
        </div>

        <div class="pc-compact-field">
          <label>Total Qty</label>
          <input name="direct_total_qty" id="pcTotalQty" type="number" step="0.001" value="0">
        </div>

        <div class="pc-compact-field">
          <label>Net Wt</label>
          <input name="net_weight" type="number" step="0.001" value="0">
        </div>

        <div class="pc-compact-field">
          <label>Gross Wt</label>
          <input name="gross_weight" type="number" step="0.001" value="0">
        </div>

        <div class="pc-compact-field">
          <label>Action</label>
          <button class="btn green" type="submit">Save Row</button>
        </div>
      </div>
    </div>

    <div id="pcCalcMsg" class="alert success pc-compact-message">Ready</div>
    <div id="pcPmInfo" style="margin-top:8px;font-size:12px;color:#0ea8c9"></div>
  </form>
</div>
<?php elseif($canEditBase && !$hasPackableItems): ?>
<div class="card">
  <div class="alert success">All invoice items are fully packed. No item is available for new packing row.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h2>Saved Packing Rows</h2></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Line</th><th>Invoice Item</th><th>Unit</th><th class="num">Serial From</th><th class="num">Serial To</th><th class="num">Units</th><th>Mode</th><th class="num">Qty/Unit</th><th class="num">Total Qty</th><th class="num">Net</th><th class="num">Gross</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($packs as $p): ?>
        <tr>
          <td><?= e($p['line_no']) ?></td>
          <td><?= e($p['product_name']) ?> | <?= e($p['des_col']) ?></td>
          <td><?= e($p['pack_unit_title'] ?? 'Carton') ?></td>
          <td class="num"><?= e($p['carton_from']) ?></td>
          <td class="num"><?= e($p['carton_to']) ?></td>
          <td class="num"><?= e(num_fmt($p['packages'],0)) ?></td>
          <td><?= e(($p['qty_mode'] ?? 'auto') === 'direct' ? 'Direct Qty' : 'Auto Qty') ?></td>
          <td class="num"><?= e(trim_num($p['qty_per_carton'], 3)) ?></td>
          <td class="num"><?= e(trim_num($p['total_qty'], 3)) ?></td>
          <td class="num"><?= e(trim_num($p['net_weight'], 3)) ?></td>
          <td class="num"><?= e(trim_num($p['gross_weight'], 3)) ?></td>
          <td>
            <?php if($canEditBase): ?>
            <form method="post" onsubmit="return confirm('Delete this packing row?')" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_row">
              <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
              <input type="hidden" name="packing_id" value="<?= e($p['id']) ?>">
              <button class="btn red" type="submit">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="actions">
    <a class="btn secondary" href="pack_palette.php?id=<?= e($id) ?>">Order Sizes &amp; Colours</a>
    <a class="btn secondary" href="shipment_view.php?id=<?= e($id) ?>">Back to Shipment</a>
    <a class="btn secondary" href="packing_list.php">All Packing Shipments</a>
  </div>
</div>

<script>
(function(){
  var serialCounts = <?= json_encode($serialCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

  function n(id){var el=document.getElementById(id); if(!el)return 0; var x=parseFloat(String(el.value||'0').replace(/,/g,'')); return isNaN(x)?0:x;}
  function trimDec(v,d){var s=Number(v||0).toFixed(d); if(s.indexOf('.')>-1) s=s.replace(/0+$/,'').replace(/\.$/,''); return s;}
  function calc(){
    var item=document.getElementById('pcInvoiceItem');
    var left=item ? parseFloat(item.options[item.selectedIndex].getAttribute('data-left') || '0') : 0;
    // Product Master auto-weight suggestion
    var pmInfo=document.getElementById('pcPmInfo');
    if(item && pmInfo){
      var op=item.options[item.selectedIndex];
      var found=op.getAttribute('data-pmfound')==='1';
      var pmPack=parseFloat(op.getAttribute('data-pmpack')||'0');
      var pmNet=parseFloat(op.getAttribute('data-pmnet')||'0');
      var pmGross=parseFloat(op.getAttribute('data-pmgross')||'0');
      var pmCbm=parseFloat(op.getAttribute('data-pmcbm')||'0');
      var active=op.getAttribute('data-pmactive')!=='0';
      var qpuEl0=document.getElementById('pcQtyPerUnit');
      if(found && pmPack>0 && qpuEl0 && parseFloat(qpuEl0.value||'0')===0) qpuEl0.value=pmPack;
      if(found){
        pmInfo.innerHTML='Product Master: '+(pmPack>0?pmPack+' /carton':'no std pack')+' · net '+pmNet+' kg · gross '+pmGross+' kg per piece'+(pmCbm>0?' · CBM '+pmCbm+' /unit':'')+(active?'':' · <span style="color:#d97706">INACTIVE product</span>');
      } else { pmInfo.textContent='No Product Master match for this item (manual entry).'; }
    }
    var from=n('pcFrom'), to=n('pcTo');
    var mode=document.getElementById('pcQtyMode') ? document.getElementById('pcQtyMode').value : 'auto';
    var qpu=document.getElementById('pcQtyPerUnit');
    var total=document.getElementById('pcTotalQty');
    var msg=document.getElementById('pcCalcMsg');
    if(!msg)return true;

    var units=(from>0 && to>=from)?(to-from+1):0;
    var totalQty=0;

    if(mode==='direct'){totalQty=n('pcTotalQty'); if(qpu)qpu.readOnly=true; if(total)total.readOnly=false;}
    else{totalQty=units*n('pcQtyPerUnit'); if(qpu)qpu.readOnly=false; if(total){total.value=totalQty?trimDec(totalQty,3):'0'; total.readOnly=true;}}

    if(from<=0 || to<from){msg.className='alert error'; msg.textContent='Serial range is not correct.'; return false;}

    var bad=[];
    for(var s=from;s<=to;s++){
      var used=serialCounts[s] || serialCounts[String(s)] || 0;
      if((used+1)>2){bad.push(s); if(bad.length>=10)break;}
      if((s-from)>200000){msg.className='alert error'; msg.textContent='Serial range too large.'; return false;}
    }

    if(bad.length){msg.className='alert error'; msg.textContent='Serial already used 2 times: '+bad.join(', ')+'. Third use not allowed.'; return false;}
    if(totalQty<=0){msg.className='alert error'; msg.textContent='Total Qty must be greater than zero.'; return false;}
    if(totalQty>left+0.0001){msg.className='alert error'; msg.textContent='Qty more than invoice balance. Left: '+left+' | Entered: '+totalQty; return false;}

    msg.className='alert success';
    msg.textContent='Units: '+units+' | Total Qty: '+totalQty.toLocaleString(undefined,{maximumFractionDigits:3})+' | Left after save: '+(left-totalQty).toLocaleString(undefined,{maximumFractionDigits:3});
    // Auto-fill weights from Product Master when blank; warn on big difference
    (function(){
      var op=item?item.options[item.selectedIndex]:null; if(!op)return;
      var pmNet=parseFloat(op.getAttribute('data-pmnet')||'0'), pmGross=parseFloat(op.getAttribute('data-pmgross')||'0');
      if(pmGross<=0)return;
      var netEl=document.querySelector('[name=net_weight]'), grossEl=document.querySelector('[name=gross_weight]');
      var sugNet=pmNet*totalQty, sugGross=pmGross*totalQty;
      if(netEl && parseFloat(netEl.value||'0')===0 && sugNet>0) netEl.value=trimDec(sugNet,3);
      if(grossEl && parseFloat(grossEl.value||'0')===0 && sugGross>0) grossEl.value=trimDec(sugGross,3);
      var actGross=parseFloat(grossEl&&grossEl.value||'0');
      var pmInfo=document.getElementById('pcPmInfo');
      var pmCbm2=parseFloat(op.getAttribute('data-pmcbm')||'0');
      if(pmInfo && pmCbm2>0 && totalQty>0){
        pmInfo.innerHTML+=' <span style="color:#0ea8c9">· total CBM for this row: '+(pmCbm2*totalQty).toFixed(3)+' m³</span>';
      }
      if(pmInfo && actGross>0 && sugGross>0 && Math.abs(actGross-sugGross)/sugGross>0.2){
        pmInfo.innerHTML+=' <span style="color:#d97706">⚠ actual gross ('+actGross+') differs &gt;20% from standard ('+sugGross.toFixed(2)+')</span>';
      }
    })();
    return true;
  }

  document.addEventListener('DOMContentLoaded',function(){
    ['pcInvoiceItem','pcFrom','pcTo','pcQtyMode','pcQtyPerUnit','pcTotalQty'].forEach(function(id){
      var el=document.getElementById(id);
      if(el){el.addEventListener('input',calc); el.addEventListener('change',calc);}
    });

    var form=document.getElementById('pcPackForm');
    if(form){form.addEventListener('submit',function(e){if(!calc()){e.preventDefault(); alert('Please correct packing entry before save.');}});}
    calc();
  });
})();
</script>

<?php page_footer(); ?>
