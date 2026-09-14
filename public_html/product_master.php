<?php
/*
  MASTER PRODUCTS — rebuilt from scratch, to the layout you sent.
  ==============================================================

  THE LIST          # · Product Name · Status · Used In · Actions
  THE EDITOR        ONE PAGE. No tabs.

  NO TABS, ASKED FOR PLAINLY, AND IT IS THE RIGHT CALL.
  Tabs on a data-entry screen hide state. You fill in one tab, move to the next,
  and the work on the first is still unsaved with nothing on screen saying so —
  and that is the single most expensive thing a form can do to somebody. Here
  everything about a product is on one page and ONE Save writes all of it: the
  name, the sizes, the parts and every quantity. There is nothing else to
  remember to press.

  THE RULE THAT GOVERNS THIS WHOLE FILE: A PRODUCT IS NEVER SILENTLY LOST.

  Your product names are pointed at by costing, proformas, invoices and
  shipments. Deleting one does not just remove a row — it orphans every
  document that refers to it, and there is no way back from inside the app.
  So Delete is only offered when nothing anywhere uses the product, and the
  button is DISABLED rather than hidden, with the reason on the row, so it is
  obvious why. When something does use it, Deactivate is the answer: it leaves
  every document intact and simply stops the product appearing in new lists.

  SIZES ARE TYPED IN ONE PLACE AND USED EVERYWHERE ELSE.
  A size is a real thing with quantities and wages hanging off it. If it could
  be typed in the middle of the quantity grid, a typo would create a NEW size
  rather than correct one, and the quantities would quietly attach to the wrong
  place. So there is one list of sizes, in section 2, and section 3 simply gets
  one column per size.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

/* All of this page's CSS and JavaScript is inside the .php, so a stored copy is
   stale LAYOUT and stale BEHAVIOUR — which is exactly how an uploaded fix comes
   to look like it never arrived. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$search = trim((string)($_GET['q'] ?? ''));

$msg = ''; $err = '';

/* ------------------------------------------------------------------
   ACTIONS
   ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['product_id'] ?? 0);

    /* ONE PAGE, ONE FORM, SEVERAL BUTTONS.
     *
     * The tabs are gone. Everything about a product is on one screen and one
     * Save writes all of it. That removes the whole class of "I filled in tab 2
     * and lost it because I never pressed Save on tab 1", which is the most
     * expensive thing tabs do to a data-entry screen.
     *
     * Adding and removing a part still need a round trip to the server, so they
     * are ordinary submit buttons INSIDE the same form rather than forms of
     * their own — HTML does not allow a form inside a form, and nesting them is
     * how you get a button that silently submits the wrong thing. */
    /* THE ONE-TIME CATCH-UP. Changing a rate is an admin action, and this
       changes them in bulk on the Costing side, so it is admin only. */
    if ($action === 'sync_costing') {
        if (!is_admin()) { $_SESSION['zp_err'] = 'Only an admin may update Costing.'; }
        else {
            /* THE ONE-TIME SIZE MOVE runs here too. Quantities typed against the
               old production-only size list are re-pointed at the shared one.
               Nothing is deleted — the old table stays exactly as it is. */
            $mig = zp_size_migrate();
            $r = zp_bridge_sync_all();
            $_SESSION['zp_msg'] = $r['products']
                ? 'Costing updated from ' . $r['products'] . ' product' . ($r['products'] == 1 ? '' : 's')
                  . ' (' . $r['ops'] . ' operation rate' . ($r['ops'] == 1 ? '' : 's')
                  . ' sent over). Open a costing sheet and Workmanship will now show the real wage.'
                : 'No product has parts yet, so there was nothing to send to Costing.';
            if (!empty($mig['ran']) && ($mig['moved'] || $mig['added']))
                $_SESSION['zp_msg'] .= ' Sizes merged into one list: ' . $mig['moved'] . ' quantit'
                    . ($mig['moved'] === 1 ? 'y' : 'ies') . ' re-pointed, ' . $mig['added']
                    . ' size' . ($mig['added'] === 1 ? '' : 's') . ' carried across. The old size table was left untouched.';
            elseif (!empty($mig['note']) && empty($mig['ran']))
                $_SESSION['zp_err'] = 'The size merge did not finish: ' . $mig['note'];
        }
        redirect('product_master.php');
    }

    if ($action === 'save_all') {
        $do     = (string)($_POST['do'] ?? 'save');
        $rmPart = (int)($_POST['remove_part'] ?? 0);

        /* ---- the name and the basics ---- */
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $err = 'Product name is required.';
        } else {
            $dup = db()->prepare("SELECT id FROM products WHERE name=? AND id<>?");
            $dup->execute([$name, $pid]);
            if ($dup->fetchColumn()) {
                $err = 'A product called "' . $name . '" already exists. Open that one instead of making a second.';
            } else {
                $fields = [
                    $name,
                    trim((string)($_POST['description'] ?? '')),
                    trim((string)($_POST['category'] ?? '')),
                    trim((string)($_POST['default_unit'] ?? 'Pcs')),
                    ($_POST['fcl_40hc_qty'] ?? '') === '' ? null : (float)str_replace(',', '', (string)$_POST['fcl_40hc_qty']),
                    isset($_POST['is_active']) ? 1 : 0,
                ];
                if ($pid > 0) {
                    $fields[] = $pid;
                    db()->prepare("UPDATE products SET name=?, description=?, category=?, default_unit=?, fcl_40hc_qty=?, is_active=? WHERE id=?")
                        ->execute($fields);
                } else {
                    db()->prepare("INSERT INTO products (name, description, category, default_unit, fcl_40hc_qty, is_active) VALUES (?,?,?,?,?,?)")
                        ->execute($fields);
                    $pid = (int)db()->lastInsertId();
                }

                /* ---- sizes ---- */
                $labels = $_POST['size_label'] ?? [];
                $sz = zp_save_sizes($pid, is_array($labels) ? $labels : []);
                $note = '';
                if (!empty($sz['kept'])) {
                    $note = ' Kept ' . implode(', ', array_map(fn($k) => '"' . $k . '"', $sz['kept']))
                          . ' — ' . (count($sz['kept']) === 1 ? 'it has' : 'they have')
                          . ' quantities set against ' . (count($sz['kept']) === 1 ? 'it' : 'them') . '.';
                }

                /* ---- part added or removed on this same submit ---- */
                $extra = '';
                if ($do === 'add_part') {
                    $r = zp_add_product_part($pid, (int)($_POST['add_part_id'] ?? 0));
                    if ($r['ok']) $extra = ' Part added — set how many go in one set.';
                    else          $_SESSION['zp_err'] = $r['error'];
                } elseif ($rmPart > 0) {
                    zp_remove_product_part($pid, $rmPart);
                    $extra = ' Part removed from this product. The part itself, and any wages already booked, are untouched.';
                }

                /* ---- quantities ----
                   Applied AFTER the sizes, and only for sizes that still exist.
                   A size deleted in this same submit takes its id with it, and
                   writing a quantity against a dead id would leave a row nothing
                   can ever reach or correct. */
                $live = [];
                foreach (zp_sizes($pid) as $s2) $live[(int)$s2['id']] = true;
                $q = [];
                foreach (($_POST['qty'] ?? []) as $partId => $bySize) {
                    foreach ($bySize as $sizeId => $v) {
                        if (isset($live[(int)$sizeId])) $q[(int)$partId][(int)$sizeId] = $v;
                    }
                }
                if ($q) {
                    $qr = zp_save_qty($pid, $q);
                    if (!$qr['ok']) $_SESSION['zp_err'] = $qr['error'];
                }

                /* FEED COSTING. product_costing.php reads the OLD tables —
                   product_sizes for its size list, production_operations for
                   the Workmanship rate — and it is not alone: fifteen files do.
                   So the sizes, operations and per-set quantities saved above
                   are mirrored across now, on the same save. Without this a
                   size added here never reaches Costing and Workmanship costs
                   zero, which under-prices the product by its whole wage bill.
                   Nothing on the old side is deleted; see zp_bridge_* . */
                $bridge = zp_bridge_sync_product($pid);
                $fed = $bridge['ok']
                     ? ' Costing updated: ' . $bridge['sizes'] . ' size' . ($bridge['sizes'] == 1 ? '' : 's')
                       . ', ' . $bridge['ops'] . ' operation' . ($bridge['ops'] == 1 ? '' : 's') . '.'
                     : '';
                if (!$bridge['ok'])
                    $_SESSION['zp_err'] = 'Saved here, but Costing was NOT updated: ' . $bridge['error']
                                        . ' Workmanship on this product will read low until this is fixed.';

                if (empty($_SESSION['zp_err'])) $_SESSION['zp_msg'] = 'Saved.' . $note . $extra . $fed;
                redirect('product_master.php?edit=' . $pid);
            }
        }

    } elseif ($action === 'duplicate_product') {
        $src = zp_product($pid);
        if (!$src) { $_SESSION['zp_err'] = 'That product no longer exists.'; redirect('product_master.php'); }
        $base = $src['name'] . ' (Copy'; $name = $base . ')'; $n = 2;
        while (true) {
            $c = db()->prepare("SELECT COUNT(*) FROM products WHERE name=?"); $c->execute([$name]);
            if (!(int)$c->fetchColumn()) break;
            $name = $base . ' ' . $n . ')'; $n++;
            if ($n > 60) { $_SESSION['zp_err'] = 'Too many copies of that product already.'; redirect('product_master.php'); }
        }
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO products (name, description, category, default_unit, fcl_40hc_qty, is_active)
                           VALUES (?,?,?,?,?,0)")
                ->execute([$name, $src['description'] ?? '', $src['category'] ?? '',
                           $src['default_unit'] ?? 'Pcs', $src['fcl_40hc_qty'] ?? null]);
            $newId = (int)db()->lastInsertId();

            /* THE COPY ARRIVES INACTIVE. A duplicate is a starting point, not a
               finished product — it should not appear in anybody's picker until
               somebody has looked at it and turned it on. */
            $sizeMap = [];
            $ins = db()->prepare("INSERT INTO product_sizes (product_id, size_label, sort_order) VALUES (?,?,?)");
            foreach (zp_sizes($pid) as $s) {
                $ins->execute([$newId, $s['size_label'], (int)$s['sort_order']]);
                $sizeMap[(int)$s['id']] = (int)db()->lastInsertId();
            }
            $insP = db()->prepare("INSERT INTO zp_product_parts (product_id, part_id, sort_order) VALUES (?,?,?)");
            foreach (zp_product_parts($pid) as $p) $insP->execute([$newId, (int)$p['id'], (int)$p['sort_order']]);

            $insQ = db()->prepare("INSERT INTO zp_part_qty (product_id, part_id, size_id, qty) VALUES (?,?,?,?)");
            foreach (zp_qty_map($pid) as $partId => $bySize) {
                foreach ($bySize as $oldSize => $q) {
                    if (isset($sizeMap[$oldSize])) $insQ->execute([$newId, (int)$partId, $sizeMap[$oldSize], $q]);
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            $_SESSION['zp_err'] = 'Nothing was copied. ' . $e->getMessage();
            redirect('product_master.php');
        }
        $_SESSION['zp_msg'] = 'Copied, with its sizes, parts and quantities. The copy is switched OFF until you turn it on.';
        redirect('product_master.php?edit=' . $newId);

    } elseif ($action === 'delete_product') {
        /* CHECKED AGAIN HERE, NOT JUST ON THE BUTTON.
           A disabled button is a courtesy; this is the rule. Between the page
           being drawn and this request arriving, somebody could have used the
           product on an invoice. */
        $usage = zp_usage_map();
        /* zp_is_used() is true when a lookup could not RUN, not only when it
           found something. A table we failed to read is not evidence of safety. */
        if (zp_is_used($usage, $pid)) {
            db()->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$pid]);
            $where = $usage[$pid] ?? [];
            $_SESSION['zp_msg'] = $where
                ? 'That product is used in ' . implode(', ', $where)
                  . ', so it has been deactivated instead of deleted. Every document that refers to it still works.'
                : 'Some of the checks could not be run (' . implode(', ', zp_usage_unknown($usage))
                  . '), so the product was deactivated rather than deleted. A check that cannot run is never treated as "safe".';
        } else {
            db()->beginTransaction();
            try {
                db()->prepare("DELETE FROM zp_part_qty WHERE product_id=?")->execute([$pid]);
                db()->prepare("DELETE FROM zp_product_parts WHERE product_id=?")->execute([$pid]);
                /* THE SIZE LIST IS SHARED NOW. These ids are the ones Costing
                   and Proforma point at, so deleting a product's sizes here
                   would orphan a saved costing version. zp_save_sizes() checks
                   every referrer before removing anything; this path is only
                   reached for a product nothing uses at all, so its sizes go
                   with it — but only after the same check. */
                foreach (zp_sizes($pid) as $s9) {
                    if (zp_size_refs((int)$s9['id'])) continue;
                    db()->prepare("DELETE FROM product_sizes WHERE id=?")->execute([(int)$s9['id']]);
                }
                db()->prepare("DELETE FROM products WHERE id=?")->execute([$pid]);
                db()->commit();
                $_SESSION['zp_msg'] = 'Product deleted. Nothing anywhere was using it.';
            } catch (Throwable $e) {
                db()->rollBack();
                $_SESSION['zp_err'] = 'Nothing was deleted. ' . $e->getMessage();
            }
        }
        redirect('product_master.php');
    }
}

if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['zp_err'])) { $err = $_SESSION['zp_err']; unset($_SESSION['zp_err']); }

/* ------------------------------------------------------------------
   DATA
   ------------------------------------------------------------------ */
$product   = $editId ? zp_product($editId) : null;
if ($editId && !$product) { $editId = 0; }
$isNew     = isset($_GET['edit']) && $editId === 0;

$usageMap  = zp_usage_map();
$sizes     = $editId ? zp_sizes($editId) : [];
$prodParts = $editId ? zp_product_parts($editId) : [];
$qtyMap    = $editId ? zp_qty_map($editId) : [];
$todo      = $editId ? zp_product_todo($editId) : [];
$libParts  = zp_parts(true);
$costMap   = zp_part_cost_map();

/* parts not yet on this product — what "Add Parts from Library" can offer */
$onProduct = array_map(fn($p) => (int)$p['id'], $prodParts);
$canAdd    = array_values(array_filter($libParts, fn($p) => !in_array((int)$p['id'], $onProduct, true)));

$products = [];
if (!$editId && !$isNew) {
    $sql = "SELECT id, name, category, default_unit, is_active FROM products";
    $params = [];
    if ($search !== '') { $sql .= " WHERE name LIKE ? OR category LIKE ?"; $params = ["%$search%", "%$search%"]; }
    $sql .= " ORDER BY name LIMIT 400";
    try { $st = db()->prepare($sql); $st->execute($params); $products = $st->fetchAll(); } catch (Throwable $e) {}
}

page_header('Master Products');
?>
<style>
.zp-wrap{max-width:1560px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}
.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.ok{background:#16a34a;border-color:#16a34a;color:#fff}
.zp-b.ok:hover{background:#12823b;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}
.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-b[disabled],.zp-b.dis{opacity:.5;cursor:not-allowed;border-color:#e3e8ef;color:#98a5b8;background:#f7f9fc}
.zp-b[disabled]:hover,.zp-b.dis:hover{border-color:#e3e8ef;color:#98a5b8}

.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.zp-head h1{margin:0;font-size:20px;color:#152033}
.zp-head p{margin:2px 0 0;font-size:12.5px;color:#8a97ab}

.listsplit{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;align-items:start}
@media(min-width:1240px){.listsplit{grid-template-columns:minmax(0,1fr) 330px}}

table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tbody tr:hover{background:#fafcff}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.on{background:rgba(22,163,74,.13);color:#15803d}
.pill.off{background:#eef1f6;color:#8a97ab}

.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.zin[readonly]{background:#f6f8fc;color:#7d8ca1}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:13px}

.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.good{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.note.pink{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note ul{margin:6px 0 0;padding-left:18px}
.note li{margin:3px 0}

.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}

.sizebox{border:2px solid #1d76e2;border-radius:11px;padding:13px 15px;background:#fff}
.sizebox h3{margin:0 0 10px;font-size:13px;color:#152033}
.sizegrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px 14px}
.sizegrid label{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#33465f;cursor:pointer}
.sizegrid input{accent-color:#1d76e2;width:15px;height:15px}

.empty{padding:26px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.sizerow{display:flex;gap:8px;align-items:center;margin-bottom:7px}
.sizerow .zin{max-width:230px}
.totline{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px;
         padding:9px 12px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px;font-size:12.5px}
.totline b{font-size:15px;color:#152033;font-variant-numeric:tabular-nums}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zp-wrap">

<?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

<?php if (!$editId && !$isNew): /* ============ THE LIST ============ */ ?>

  <div class="zp-head">
    <div>
      <h1>Master Products</h1>
      <p>Manage your main products</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="get" style="display:flex;gap:6px">
        <input class="zin" name="q" value="<?= e($search) ?>" placeholder="Search product…" style="width:220px">
        <button class="zp-b">Search</button>
        <?php if ($search !== ''): ?><a class="zp-b" href="product_master.php">Clear</a><?php endif; ?>
      </form>
      <a class="zp-b" href="part_library.php">Part Library</a>
      <a class="zp-b" href="products_csv.php">Products CSV</a>
      <?php if (is_admin()): /* THE CATCH-UP. Everything saved between the
           rebuild and today has no mirror, so its Workmanship still costs zero.
           Saving each product would fix it one at a time; this does all of them
           in one go. Safe to press twice — it rewrites, it never doubles. */ ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Send every product\'s sizes and wage rates over to Costing?\n\nNothing is deleted. Any old operation rate that would double-count is switched off, not removed.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync_costing">
        <button class="zp-b" title="Fix Workmanship reading zero on old costings">Update Costing</button>
      </form>
      <?php endif; ?>
      <a class="zp-b pri" href="product_master.php?edit=0">+ Add New Product</a>
      <?php /* WHICH COPY OF THIS FILE IS ACTUALLY RUNNING.
               Three rounds were lost to not being able to tell "the upload did
               not land" from "the browser is showing a stored copy" — they need
               opposite fixes. This is read from disk on the server, so a cached
               page shows the OLD date and a fresh one shows the new. Compare it
               with the date on the zip. */ ?>
      <span class="code" style="font-size:10px;color:#a7b2c4;white-space:nowrap;align-self:center"
            title="Modified date of this file on the server. Older than the zip you uploaded means it never landed; newer but the page still looks old means the browser is showing a stored copy — reload with Ctrl+Shift+R.">
        build <?= e(date('d M H:i', (int)@filemtime(__FILE__))) ?></span>

    </div>
  </div>

  <div class="listsplit">
    <div class="zp-card" style="margin-bottom:0">
      <?php if (!$products): ?>
        <div class="empty">
          <?= $search !== '' ? 'No product matches “' . e($search) . '”.' : 'No products yet.' ?><br><br>
          <?php if ($search === ''): ?>
            You can bring your whole list in at once from
            <a href="products_csv.php">Products CSV</a>, or press <b>+ Add New Product</b>.
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr>
          <th style="width:40px">#</th><th>Product Name</th>
          <th style="width:92px">Status</th><th style="width:170px">Used In</th>
          <th style="width:232px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $i => $p): $pid = (int)$p['id']; $used = zp_is_used($usageMap, $pid); ?>
          <tr>
            <td class="code"><?= $i + 1 ?></td>
            <td>
              <a href="product_master.php?edit=<?= $pid ?>"
                 style="color:#152033;font-weight:700;text-decoration:none"><?= e($p['name']) ?></a>
              <?php if (trim((string)$p['category']) !== ''): ?>
                <div class="code" style="font-size:10.5px"><?= e($p['category']) ?></div><?php endif; ?>
            </td>
            <td><?= $p['is_active']
                  ? '<span class="pill on">&#10003; Active</span>'
                  : '<span class="pill off">Inactive</span>' ?></td>
            <td style="font-size:12px;color:#5a6b82"><?= e(zp_usage_label($usageMap, $pid)) ?></td>
            <td style="white-space:nowrap">
              <a class="zp-b sm" href="product_master.php?edit=<?= $pid ?>">Edit</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="duplicate_product">
                <input type="hidden" name="product_id" value="<?= $pid ?>">
                <button class="zp-b sm" title="Copy this product with its sizes, parts and quantities">Duplicate</button>
              </form>
              <?php if ($used): ?>
                <?php /* DISABLED, NOT HIDDEN. A missing button leaves you
                         wondering; a greyed one with the reason on it tells
                         you exactly what to do instead. */ ?>
                <button class="zp-b sm dis" disabled
                        title="<?= !empty($usageMap[$pid])
                          ? 'Used in ' . e(implode(', ', $usageMap[$pid])) . ' — deleting it would orphan those documents. Untick Active instead to take it out of new lists.'
                          : 'These checks could not be run: ' . e(implode(', ', zp_usage_unknown($usageMap))) . '. A check that cannot run is never treated as safe.' ?>">Delete</button>
              <?php else: ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Delete <?= e(addslashes($p['name'])) ?>?\n\nNothing is using it, so this really deletes it. It cannot be undone.')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete_product">
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <button class="zp-b sm red">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="note info" style="align-self:start">
      <b style="font-size:13px">ℹ Delete / Deactivate rules</b>
      <ul>
        <li>If a product is used in any <b>production or invoice</b>, <b>Delete is disabled</b>.</li>
        <li>You can <b>Deactivate</b> instead — it stops appearing in new lists, and every existing document still works.</li>
        <li>Once nothing uses it any more, <b>Delete becomes available</b> by itself.</li>
        <li>This keeps your lists clean and makes the mistake impossible rather than merely discouraged.</li>
      </ul>
    </div>
  </div>

<?php else: /* ============ THE EDITOR — ONE PAGE, NO TABS ============ */ ?>

  <div class="zp-head">
    <div style="display:flex;align-items:center;gap:12px">
      <a class="zp-b" href="product_master.php" title="Back to all products">&larr;</a>
      <div>
        <h1><?= $editId ? e($product['name']) : 'Add New Product' ?></h1>
        <p><?= $editId ? count($sizes) . ' size' . (count($sizes) === 1 ? '' : 's') . ' &middot; '
                        . count($prodParts) . ' part' . (count($prodParts) === 1 ? '' : 's')
                      : 'Name it, give it its sizes, then add its parts.' ?></p>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="zp-b" href="part_library.php">Part Library</a>
      <a class="zp-b" href="product_master.php">Cancel</a>
    </div>
  </div>

  <?php if ($editId && $todo): ?>
    <div class="note warn" style="margin-bottom:16px">
      <b>Before this product can be produced</b>
      <ul><?php foreach ($todo as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
    </div>
  <?php elseif ($editId): ?>
    <div class="note good" style="margin-bottom:16px"><b>Ready.</b> Every part has its operations and a cutting line.</div>
  <?php endif; ?>

  <?php /* ONE FORM AROUND EVERYTHING.
           Name, sizes, parts and quantities are all written by a single Save.
           There is no way to fill something in and lose it by never pressing the
           Save that belonged to it — which is the expensive thing tabs did. */ ?>
  <?php /* THE DRAFT BAR — hidden until there is genuinely something to offer.
           It uses the `hidden` attribute rather than an inline display:none, so
           that when it IS shown its own flex layout is the one that applies. An
           inline display that has to be overwritten is how a bar appears with
           its buttons stacked in the wrong place. */ ?>
  <div id="pmDraftBar" hidden
       style="padding:11px 14px;border-radius:10px;margin-bottom:14px;background:#fff6e8;
              border:1px solid #f3ddb8;color:#8a5a10;font-size:12.5px;display:flex;
              align-items:center;gap:11px;flex-wrap:wrap">
    <span><b>Unsaved work found.</b> <span id="pmDraftWhen"></span>
      It is only in this browser &mdash; nothing was sent to the server.</span>
    <span style="margin-left:auto;display:flex;gap:7px">
      <button type="button" class="zp-b ok" id="pmDraftYes" style="padding:5px 12px;font-size:12px">Put it back</button>
      <button type="button" class="zp-b" id="pmDraftNo" style="padding:5px 12px;font-size:12px">Discard</button>
    </span>
  </div>

  <form method="post" id="pmForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_all">
    <input type="hidden" name="product_id" value="<?= (int)$editId ?>">
    <input type="hidden" name="remove_part" value="0">

    <!-- ============ 1. the product itself ============ -->
    <div class="zp-card">
      <h2>1 &nbsp;The product</h2>
      <div class="fgrid" style="margin-top:13px">
        <div style="grid-column:1/-1">
          <span class="lab">Product Name</span>
          <input class="zin" name="name" required maxlength="200" autocomplete="off"
                 value="<?= e($product['name'] ?? '') ?>" placeholder="Bed Sheet Set">
        </div>
        <div>
          <span class="lab">Category</span>
          <input class="zin" name="category" maxlength="120" autocomplete="off"
                 value="<?= e($product['category'] ?? '') ?>" placeholder="Bedding">
        </div>
        <div>
          <span class="lab">Default Unit</span>
          <select class="zin" name="default_unit">
            <?php foreach (['Pcs', 'Sets', 'Pair', 'Mtr', 'Kg', 'Other'] as $u): ?>
              <option <?= ($product['default_unit'] ?? 'Pcs') === $u ? 'selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <span class="lab">40ft HC Capacity</span>
          <input class="zin num" name="fcl_40hc_qty" inputmode="decimal" autocomplete="off"
                 value="<?= $product && $product['fcl_40hc_qty'] !== null
                           ? e(rtrim(rtrim(number_format((float)$product['fcl_40hc_qty'], 2, '.', ''), '0'), '.')) : '' ?>"
                 placeholder="32000" title="Pieces that load into one 40ft HC container.">
        </div>
        <div style="display:flex;align-items:flex-end">
          <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer;padding-bottom:7px">
            <input type="checkbox" name="is_active" value="1"
                   <?= ($editId ? (int)$product['is_active'] : 1) ? 'checked' : '' ?>> Active
          </label>
        </div>
        <div style="grid-column:1/-1">
          <span class="lab">Description <span style="text-transform:none;font-weight:500">(optional)</span></span>
          <input class="zin" name="description" maxlength="255" autocomplete="off"
                 value="<?= e($product['description'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- ============ 2. sizes ============ -->
    <div class="zp-card">
      <h2>2 &nbsp;Its sizes</h2>
      <p style="margin:3px 0 12px;font-size:12.5px;color:#8a97ab;line-height:1.5">
        Each size becomes a quantity column in the table below. Single, Double, King &mdash; or 90x190, 135x190.</p>

      <div id="sizeRows">
        <?php foreach ($sizes as $s): ?>
          <div class="sizerow">
            <input class="zin" name="size_label[]" value="<?= e($s['size_label']) ?>" maxlength="60" autocomplete="off">
            <button type="button" class="zp-b sm red" onclick="this.closest('.sizerow').remove()">&times;</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:4px">
        <button type="button" class="zp-b" onclick="zpAddSize()">+ Add a size</button>
        <span style="font-size:11.5px;color:#8a97ab">Press Enter to add another.</span>
      </div>

      <?php if ($sizes): ?>
      <div class="note info" style="margin-top:13px">
        A size that already has quantities against it is <b>kept even if you clear its box</b>, and the save says
        which. Removing it would take those quantities with it, silently.
      </div>
      <?php endif; ?>
    </div>

    <!-- ============ 3. parts and per-size quantities ============ -->
    <div class="zp-card">
      <h2>3 &nbsp;What it is made of</h2>

      <?php if (!$editId): ?>
        <div class="empty">Save the product first &mdash; then you can add its parts here.</div>

      <?php elseif (!$sizes): ?>
        <div class="empty">Add at least one size above and press <b>Save</b>, then the parts table appears.</div>

      <?php else: ?>

        <?php if (!$libParts): ?>
          <div class="empty">
            Your Part Library is empty.<br><br>
            A part is a piece you make &mdash; a Bed Sheet, a Pillow Cover. Define it once, with its
            operations and rates, and use it on as many products as you like.<br><br>
            <a class="zp-b pri" href="part_library.php">Define a part in the Part Library</a>
          </div>
        <?php else: ?>
          <div style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
            <?php if ($canAdd): ?>
              <div style="min-width:250px">
                <span class="lab">Add a part from the library</span>
                <select class="zin" name="add_part_id">
                  <option value="">Choose a part&hellip;</option>
                  <?php foreach ($canAdd as $p): $cid = (int)$p['id']; ?>
                    <option value="<?= $cid ?>">
                      <?= e(zp_part_code($cid)) ?> &mdash; <?= e($p['part_name']) ?>
                      (<?= number_format((float)($costMap[$cid] ?? 0), 2) ?> / <?= e($p['uom']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="zp-b pri" name="do" value="add_part">+ Add this part</button>
            <?php else: ?>
              <span style="font-size:12.5px;color:#5a6b82">Every part in the library is already on this product.</span>
            <?php endif; ?>
            <a class="zp-b" href="part_library.php">Add another part to the library</a>
          </div>
        <?php endif; ?>

        <?php if ($prodParts): ?>
        <div style="overflow-x:auto">
        <table class="zp-t" id="qtyGrid" style="min-width:<?= 380 + count($sizes) * 96 ?>px">
          <thead><tr>
            <th style="width:74px">Part ID</th><th style="min-width:150px">Part Name</th>
            <?php foreach ($sizes as $s): ?>
              <th class="num" style="width:92px"><?= e($s['size_label']) ?><br>
                <span style="font-weight:600;text-transform:none;letter-spacing:0">Qty</span></th>
            <?php endforeach; ?>
            <th class="num" style="width:96px">Cost / Pc</th>
            <th style="width:64px">Action</th>
          </tr></thead>
          <tbody>
          <?php foreach ($prodParts as $p): $cid = (int)$p['id']; ?>
            <tr>
              <td class="code"><b><?= e(zp_part_code($cid)) ?></b></td>
              <td>
                <a href="part_library.php?part=<?= $cid ?>#partform"
                   style="color:#152033;font-weight:700;text-decoration:none"
                   title="Open this part to change its operations or rates"><?= e($p['part_name']) ?></a>
                <?php if (trim((string)$p['style']) !== ''): ?>
                  <div class="code" style="font-size:10.5px"><?= e($p['style']) ?></div><?php endif; ?>
              </td>
              <?php foreach ($sizes as $s): $sid = (int)$s['id']; ?>
                <td><input class="zin num qz" inputmode="decimal" autocomplete="off"
                           data-part="<?= $cid ?>" data-size="<?= $sid ?>"
                           name="qty[<?= $cid ?>][<?= $sid ?>]"
                           value="<?= e(rtrim(rtrim(number_format(zp_qty_for($qtyMap, $cid, $sid), 2, '.', ''), '0'), '.')) ?>"></td>
              <?php endforeach; ?>
              <td class="num"><?= number_format((float)($costMap[$cid] ?? 0), 2) ?></td>
              <td>
                <?php /* a plain submit button carrying which part to drop — no
                         nested form, and nothing to go wrong on Enter */ ?>
                <button type="submit" class="zp-b sm red" name="do" value="save"
                        title="Take this part off this product"
                        onclick="document.querySelector('input[name=remove_part]').value='<?= $cid ?>';
                                 return confirm('Take <?= e(addslashes($p['part_name'])) ?> off this product?\n\nThe part stays in the library, and any wages already booked are untouched.');">&times;</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#f7f9fc">
              <td colspan="2" style="font-weight:800;font-size:12px;color:#152033">Cost of one set</td>
              <?php foreach ($sizes as $s): ?>
                <td class="num" style="font-weight:800" data-settot="<?= (int)$s['id'] ?>">0.00</td>
              <?php endforeach; ?>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
        </div>

        <div class="note pink" style="margin-top:13px">
          <b>Change quantities per size right here.</b>
          Double pillow cover = <b>2</b>. Bed sheet = <b>1</b>, which is the default, so you only type what differs.
          The totals row updates as you type &mdash; that is what one set costs to make at each size.
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ============ one Save, for all of it ============ -->
    <div class="zp-card" style="position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button class="zp-b ok" name="do" value="save" style="padding:9px 20px;font-size:13.5px">Save</button>
      <a class="zp-b" href="product_master.php">Cancel</a>
      <span style="font-size:11.5px;color:#8a97ab">
        One Save writes the name, the sizes and every quantity &mdash; there is nothing else to remember to press.</span>
    </div>
  </form>

  <!-- ============ 4. operations and cost — a view, not a form ============ -->
  <?php if ($editId && $prodParts): ?>
  <div class="zp-card">
    <h2>4 &nbsp;Operations &amp; cost</h2>
    <p style="margin:3px 0 13px;font-size:12.5px;color:#8a97ab;line-height:1.5">
      Operations and rates belong to the <b>part</b>, not to the product &mdash; that is the point of the library.
      Change a rate once and every product using that part changes with it.</p>

    <?php foreach ($prodParts as $p): $cid = (int)$p['id']; $pops = zp_part_ops($cid, true); ?>
      <div style="border:1px solid #eef1f6;border-radius:10px;padding:12px 13px;margin-bottom:11px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:9px">
          <b style="font-size:13px;color:#152033">
            <span class="code"><?= e(zp_part_code($cid)) ?></span> &nbsp;<?= e($p['part_name']) ?></b>
          <a class="zp-b sm" href="part_library.php?part=<?= $cid ?>#partform">Edit in Part Library</a>
        </div>
        <?php if (!$pops): ?>
          <div class="note warn">No operations yet, so this part costs nothing and cannot be produced.
            Open it in the Part Library and add its Cutting line.</div>
        <?php else: ?>
          <table class="zp-t">
            <thead><tr>
              <th style="width:34px">#</th><th style="width:140px">Stage</th>
              <th>Operation</th><th class="num" style="width:110px">Rate / Pc</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pops as $n => $o): ?>
              <tr><td class="code"><?= $n + 1 ?></td><td><?= e($o['stage']) ?></td>
                  <td><?= e($o['operation_name']) ?></td>
                  <td class="num"><?= number_format((float)$o['rate'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f7f9fc">
              <td colspan="3" style="font-weight:800;font-size:12px">Cost of one <?= e($p['part_name']) ?></td>
              <td class="num" style="font-weight:800"><?= number_format((float)($costMap[$cid] ?? 0), 2) ?></td>
            </tr></tfoot>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($sizes): ?>
      <div style="margin-top:14px">
        <b style="font-size:12.5px;color:#152033">What one set costs to make</b>
        <?php foreach ($sizes as $s): $sid = (int)$s['id']; ?>
          <div class="totline">
            <span><b style="font-size:13px"><?= e($s['size_label']) ?></b></span>
            <span><b><?= number_format(zp_set_cost($editId, $sid), 2) ?></b></span>
          </div>
        <?php endforeach; ?>
        <div class="note info" style="margin-top:12px">
          This is the <b>making</b> cost &mdash; the wages to cut and stitch it. Not a selling price, and it
          carries no finishing cost and no mark-up.
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
/* ---- sizes: add a row, and let Enter do it ---- */
window.zpAddSize = function(){
  var wrap = document.getElementById('sizeRows');
  if (!wrap) return;
  var d = document.createElement('div');
  d.className = 'sizerow';
  d.innerHTML = '<input class="zin" name="size_label[]" maxlength="60" autocomplete="off" placeholder="Single">'
              + '<button type="button" class="zp-b sm red" onclick="this.closest(\'.sizerow\').remove()">&times;</button>';
  wrap.appendChild(d);
  d.querySelector('input').focus();
  return d;
};

/* ENTER IN A SIZE BOX ADDS THE NEXT SIZE — it must NOT submit the form.
   The whole page is one form now, so the browser's default (submit on Enter)
   would save and reload halfway through typing a list of sizes. Typing four
   sizes should be four words and four Enters. */
(function(){
  var wrap = document.getElementById('sizeRows');
  if (!wrap) return;
  wrap.addEventListener('keydown', function(ev){
    if (ev.key !== 'Enter' || ev.target.name !== 'size_label[]') return;
    ev.preventDefault();
    var row = ev.target.closest('.sizerow'), next = row && row.nextElementSibling;
    if (next && next.querySelector('input')) { next.querySelector('input').focus(); next.querySelector('input').select(); }
    else window.zpAddSize();
  });
})();

/* ---- Sizes & Parts: the set cost under each size column, live ----
   The number you are typing is the number you are asking about, so it has to
   move as you type. Recomputed from the grid itself, never from a stored
   total, so it cannot drift out of step with what is on screen. ---- */
(function(){
  var grid = document.getElementById('qtyGrid');
  if (!grid) return;
  var COST = <?= json_encode((object)array_map('floatval', $costMap)) ?>;

  function recalc(){
    var totals = {};
    [].slice.call(grid.querySelectorAll('.qz')).forEach(function(inp){
      var part = inp.getAttribute('data-part'), size = inp.getAttribute('data-size');
      var q = parseFloat(String(inp.value).replace(/,/g, ''));
      if (isNaN(q)) q = 0;
      var c = parseFloat(COST[part]);
      if (isNaN(c)) c = 0;
      totals[size] = (totals[size] || 0) + q * c;
    });
    [].slice.call(grid.querySelectorAll('[data-settot]')).forEach(function(td){
      var s = td.getAttribute('data-settot');
      td.textContent = (totals[s] || 0).toFixed(2);
    });
  }
  grid.addEventListener('input', function(ev){
    if (ev.target.classList && ev.target.classList.contains('qz')) recalc();
  });

  /* arrow keys move around the grid like a spreadsheet, because that is what
     it looks like and therefore what the hands expect */
  grid.addEventListener('keydown', function(ev){
    var el = ev.target;
    if (!el.classList || !el.classList.contains('qz')) return;
    var cell = el.closest('td'), row = el.closest('tr');
    var idx = [].indexOf.call(row.children, cell);
    var go = null;
    if (ev.key === 'ArrowDown' || ev.key === 'Enter') go = row.nextElementSibling;
    else if (ev.key === 'ArrowUp') go = row.previousElementSibling;
    else return;
    if (!go || !go.children[idx]) return;
    var t = go.children[idx].querySelector('.qz');
    if (!t) return;
    ev.preventDefault();
    t.focus(); t.select();
  });

  /* exposed so the draft restore can refresh the totals after it fills the
     grid back in — otherwise the boxes would show the restored numbers while
     the "cost of one set" row underneath still showed the old ones, which is
     exactly the kind of quiet disagreement this page is meant not to have */
  window.zpRecalcQty = recalc;

  recalc();
})();

/* ==================================================================
   THE DRAFT SAFETY NET
   ==================================================================

   WHAT THIS DOES NOT DO, because it matters more than what it does:

     * It NEVER sends anything to the server. Not once, not in the
       background. The only thing that writes to your database is you
       pressing Save, exactly as before.
     * It NEVER touches a proforma, an invoice, a shipment or a costing.
       This page writes to products, product_sizes, zp_product_parts and
       zp_part_qty and to nothing else at all — the draft cannot reach
       further than the page itself can.
     * It NEVER restores anything on its own. It offers, and you press
       "Put it back". Silently refilling a form you did not ask it to
       refill is how you end up saving last week's numbers.

   WHAT IT DOES: keeps a copy of what is typed in THIS browser, under a
   key that carries the product id so two products cannot mix. If a save
   is refused, or the session drops, or the tab is closed, the copy is
   still there and is offered back.

   Every single storage call is wrapped. A private window, blocked site
   data or a full quota all make localStorage throw, and none of those is
   a reason for the page to stop working.
   ================================================================== */
(function(){
  var form = document.getElementById('pmForm');
  var bar  = document.getElementById('pmDraftBar');
  if (!form || !bar) return;

  var PRODUCT_ID = <?= (int)$editId ?>;
  var KEY = 'zp_pm_draft_' + PRODUCT_ID;

  /* A SUCCESSFUL SAVE CLEARS THE COPY. The server redirects with a success
     message after every write, so a message on screen means the last action
     reached the database and the copy has done its job. A REFUSED save
     re-renders with an error and no redirect — the copy is deliberately left
     alone, which is the whole point of having it. */
  var SAVED = <?= $msg !== '' ? 'true' : 'false' ?>;

  function read(){
    var d = { name:'', category:'', unit:'', cap:'', desc:'', active:false, sizes:[], qty:{} };
    var f = function(n){ var e = form.querySelector('[name="' + n + '"]'); return e ? e.value : ''; };
    d.name     = f('name');
    d.category = f('category');
    d.unit     = f('default_unit');
    d.cap      = f('fcl_40hc_qty');
    d.desc     = f('description');
    var a = form.querySelector('[name="is_active"]');
    d.active = !!(a && a.checked);
    [].slice.call(form.querySelectorAll('[name="size_label[]"]')).forEach(function(i){ d.sizes.push(i.value); });
    [].slice.call(form.querySelectorAll('.qz')).forEach(function(i){ d.qty[i.name] = i.value; });
    return d;
  }

  var SERVER = JSON.stringify(read());     // what the page arrived showing

  function store(v){ try { localStorage.setItem(KEY, v); } catch (e) {} }
  function fetchDraft(){ try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function drop(){ try { localStorage.removeItem(KEY); } catch (e) {} }

  if (SAVED) drop();

  /* written as you type, at most once a second — a keystroke does not need
     its own write, and a busy grid should not thrash the disk */
  var pending = null;
  function schedule(){
    if (pending) return;
    pending = setTimeout(function(){
      pending = null;
      try { store(JSON.stringify({ at: Date.now(), d: read() })); } catch (e) {}
    }, 800);
  }
  form.addEventListener('input', schedule);
  form.addEventListener('change', schedule);

  function apply(d){
    var set = function(n, v){ var e = form.querySelector('[name="' + n + '"]'); if (e) e.value = v; };
    set('name', d.name); set('category', d.category); set('default_unit', d.unit);
    set('fcl_40hc_qty', d.cap); set('description', d.desc);
    var a = form.querySelector('[name="is_active"]'); if (a) a.checked = !!d.active;

    /* sizes are a variable-length list, so rebuild it to match the draft */
    var wrap = document.getElementById('sizeRows');
    if (wrap && Object.prototype.toString.call(d.sizes) === '[object Array]') {
      wrap.innerHTML = '';
      d.sizes.forEach(function(v){
        var row = window.zpAddSize();
        if (row) row.querySelector('input').value = v;
      });
    }
    /* QUANTITIES ARE MATCHED BY NAME, and a box that no longer exists is
       skipped rather than created. The draft describes the page as it was;
       parts may have been added or removed since, and inventing an input for
       one that is gone would post a quantity against nothing. */
    Object.keys(d.qty || {}).forEach(function(n){
      var e = form.querySelector('[name="' + n.replace(/"/g, '\\"') + '"]');
      if (e) e.value = d.qty[n];
    });
    if (window.zpRecalcQty) window.zpRecalcQty();
    var first = form.querySelector('[name="name"]');
    if (first) first.focus();
  }

  /* ---- offer it, if there is anything worth offering ---- */
  (function(){
    var raw = fetchDraft();
    if (!raw) return;
    var saved;
    try { saved = JSON.parse(raw); } catch (e) { drop(); return; }
    if (!saved || !saved.d) { drop(); return; }

    /* IT IS ONLY OFFERED WHEN IT DIFFERS FROM WHAT THE SERVER SENT.
       A copy identical to the page is not unsaved work, and a bar that cries
       wolf on every visit is a bar people stop reading. */
    if (JSON.stringify(saved.d) === SERVER) { drop(); return; }

    var when = document.getElementById('pmDraftWhen');
    if (when && saved.at) {
      var mins = Math.max(0, Math.round((Date.now() - saved.at) / 60000));
      when.textContent = mins < 1 ? 'From a moment ago.'
                       : mins < 60 ? 'From ' + mins + ' minute' + (mins === 1 ? '' : 's') + ' ago.'
                       : 'From ' + Math.round(mins / 60) + ' hour' + (Math.round(mins / 60) === 1 ? '' : 's') + ' ago.';
    }
    bar.hidden = false;

    document.getElementById('pmDraftYes').onclick = function(){
      apply(saved.d);
      bar.hidden = true;
    };
    document.getElementById('pmDraftNo').onclick = function(){
      drop();
      bar.hidden = true;
    };
  })();
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
